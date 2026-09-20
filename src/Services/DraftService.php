<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Contracts\MailboxProvider;
use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Data\AttachmentData;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\MessageForwarded;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\ComposeMessageForm;
use Cooolinho\FilamentMailbox\Jobs\DeleteDraftFromServerJob;
use Cooolinho\FilamentMailbox\Jobs\SaveDraftToServerJob;
use Cooolinho\FilamentMailbox\Mail\MimeMessageBuilder;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxDraft;
use Cooolinho\FilamentMailbox\Models\MailboxDraftAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Support\ComposeContext;
use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\FileMessage;
use Illuminate\Contracts\Auth\Authenticatable;
use Cooolinho\FilamentMailbox\Services\Receipts\DeliveryReportService;
use Cooolinho\FilamentMailbox\Services\Receipts\ReadReceiptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Saving, sending and discarding drafts, and their copy in the drafts folder.
 *
 * The local draft is the source of truth while editing. After saving, a MIME
 * version is appended to the drafts folder (IMAP messages are immutable, so
 * the previous version is deleted). The synchronisation links the imported
 * version to the draft through its Message-ID instead of listing a duplicate.
 */
class DraftService
{
    public const HEADER = 'X-Filament-Mailbox-Draft';

    public function __construct(
        protected InlineImageProcessor $images,
        protected HtmlBodySanitizer $sanitizer,
        protected SignatureResolver $signatures,
        protected TemplateRepository $templates,
        protected ForwardBuilder $forwards,
        protected ReplyBuilder $replies,
        protected MessageService $messages,
        protected MimeMessageBuilder $mime,
        protected MailboxProviderFactory $providers,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('filament-mailbox.drafts.enabled', true);
    }

    /**
     * Create or update a draft from the compose form state.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(Mailbox $mailbox, array $data, ?MailboxDraft $draft, ?Authenticatable $user, string $mode = MailboxDraft::MODE_NEW, ?MailboxMessage $original = null): MailboxDraft
    {
        $draft ??= $this->newDraft($mailbox, $user, $mode, $original);
        $draft->uuid ??= (string) Str::uuid();
        $html = ComposeMessageForm::isHtmlFormat($data['format'] ?? null);
        $owner = $draft->user_id ?? $user?->getAuthIdentifier();

        DB::transaction(function () use ($draft, $data, $html, $mailbox, $user, $owner): void {
            $draft->fill([
                'to' => $this->addresses($data['to'] ?? []),
                'cc' => $this->addresses($data['cc'] ?? []),
                'bcc' => $this->addresses($data['bcc'] ?? []),
                'subject' => filled($data['subject'] ?? null) ? mb_substr((string) $data['subject'], 0, 255) : null,
                'body_format' => $html ? ComposeMessageForm::FORMAT_HTML : ComposeMessageForm::FORMAT_TEXT,
                'body' => filled($data['body'] ?? null) ? (string) $data['body'] : null,
                'body_html' => $this->storedHtml($data['body_html'] ?? null, $draft, $owner),
                'quoted' => filled($data['quoted'] ?? null) ? (string) $data['quoted'] : null,
                'quoted_html' => $this->storedHtml($data['quoted_html'] ?? null, $draft, $owner),
                'signature_id' => $this->signatures->find($mailbox, $user, $data['signature_id'] ?? null)?->getKey(),
                'as_attachment' => $draft->mode === MailboxDraft::MODE_FORWARD && (bool) ($data['as_attachment'] ?? false),
                'original_attachment_ids' => $draft->mode === MailboxDraft::MODE_FORWARD
                    ? array_values(array_map(intval(...), (array) ($data['original_attachments'] ?? [])))
                    : null,
            ]);

            $draft->revision = (int) $draft->revision + 1;
            $draft->updated_at = now();
            $draft->save();

            // Stored attachments that were deselected in the form are removed.
            if (array_key_exists('draft_attachments', $data)) {
                $draft->attachments()
                    ->whereKeyNot(array_map(intval(...), (array) $data['draft_attachments']))
                    ->get()
                    ->each->delete();
            }

            $new = [
                ...ComposeMessageForm::uploadedAttachments($data),
                ...$this->templates->attachments($mailbox, $draft->context(), $data['attachments_template_id'] ?? null, array_values((array) ($data['template_attachments'] ?? []))),
            ];

            foreach ($new as $attachment) {
                $this->storeAttachment($draft, $attachment);
            }
        });

        $this->queueServerSync($draft);

        return $draft->refresh();
    }

    /**
     * Form state of a draft for the editor.
     *
     * @return array<string, mixed>
     */
    public function formState(MailboxDraft $draft): array
    {
        return [
            'to' => $draft->to ?? [],
            'cc' => $draft->cc ?? [],
            'bcc' => $draft->bcc ?? [],
            'subject' => $draft->subject,
            'format' => $draft->body_format,
            'body' => $draft->body ?? '',
            // Empty rich editor states are null, the editor cannot parse an empty string.
            'body_html' => $draft->body_html ?: null,
            'quoted' => $draft->quoted ?? '',
            'quoted_html' => $draft->quoted_html ?: null,
            'signature_id' => $draft->signature_id,
            'as_attachment' => $draft->as_attachment,
            'original_attachments' => $draft->original_attachment_ids ?? [],
            'draft_attachments' => $draft->attachments()->pluck('id')->all(),
            'attachments' => [],
            'template_ids' => [],
            'template_attachments' => [],
            'attachments_template_id' => null,
            'request_read_receipt' => ReadReceiptService::requestByDefault($draft->mailbox),
            'request_delivery_receipt' => DeliveryReportService::requestSuccessByDefault(),
        ];
    }

    /**
     * The message to send (or to store on the server) for the form state of a draft.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function outgoing(MailboxDraft $draft, array $data, ?Authenticatable $user, ?string $messageId = null, array $headers = []): OutgoingMessageData
    {
        $original = $draft->replyTo;
        $forward = $draft->mode === MailboxDraft::MODE_FORWARD && $original !== null;

        $additional = $forward
            ? ((bool) ($data['as_attachment'] ?? false)
                ? [$this->forwards->messageAttachment($original)]
                : $this->forwards->originalAttachments($original, array_values((array) ($data['original_attachments'] ?? []))))
            : [];

        $stored = $draft->attachments()
            ->when(array_key_exists('draft_attachments', $data), fn ($query) => $query->whereKey(array_map(intval(...), (array) $data['draft_attachments'])))
            ->get()
            ->map(fn (MailboxDraftAttachment $attachment): AttachmentData => $attachment->toData())
            ->all();

        $outgoing = ComposeMessageForm::toData(
            $data,
            inReplyTo: $draft->in_reply_to,
            references: $this->references($draft),
            providerThreadId: $draft->context() === ComposeContext::Reply ? $original?->thread_id : null,
            additionalAttachments: [...$additional, ...$stored],
            forwardedMessageId: $forward && $original->message_id ? trim($original->message_id, '<>') : null,
            mailbox: $draft->mailbox,
            context: $draft->context(),
            imageDirectories: [$draft->directory()],
            user: $user,
        );

        if ($messageId === null && $headers === []) {
            return $outgoing;
        }

        return new OutgoingMessageData(...[...get_object_vars($outgoing), 'messageId' => $messageId, 'headers' => $headers]);
    }

    /**
     * After the message of a draft was sent (or queued): count templates, mark
     * a forwarded original and remove the draft with its server version.
     *
     * @param  array<string, mixed>  $data
     */
    public function sent(MailboxDraft $draft, array $data, OutgoingMessageData $outgoing, bool $markForwarded = true): void
    {
        ComposeMessageForm::afterSent($data, $draft->mailbox, $draft->context());

        // The outbox marks the original itself once the message is really sent.
        if ($markForwarded && $draft->mode === MailboxDraft::MODE_FORWARD && ($original = $draft->replyTo)) {
            try {
                $this->messages->markForwarded($original);
            } catch (Throwable $exception) {
                report($exception);
            }

            MessageForwarded::dispatch($original, [...$outgoing->to, ...$outgoing->cc, ...$outgoing->bcc], (bool) ($data['as_attachment'] ?? false));
        }

        $this->discard($draft);
    }

    /**
     * Delete the draft, its files, its synchronised copies and its version on the server.
     */
    public function discard(MailboxDraft $draft): void
    {
        $versions = $this->serverVersions($draft);

        $draft->messages()->get()->each->delete();
        $draft->attachments()->get()->each->delete();

        rescue(fn () => $this->images->disk()->deleteDirectory($draft->directory()), report: false);

        $draft->delete();

        if ($versions !== [] && $this->syncsToServer($draft->mailbox)) {
            DeleteDraftFromServerJob::dispatch($draft->mailbox_id, $versions);
        }
    }

    /**
     * Make a draft of a message in the drafts folder, e.g. written in another client.
     */
    public function importFromMessage(MailboxMessage $message, ?Authenticatable $user): MailboxDraft
    {
        $bcc = [];

        try {
            if ($raw = $this->messages->rawMessage($message)) {
                $bcc = array_map(fn (Address $address): string => $address->email(), (new FileMessage($raw))->bcc());
            }
        } catch (Throwable) {
            // BCC is only known from the MIME source.
        }

        $html = filled($message->html_body)
            // Inline images of the original are not kept.
            ? $this->images->process($this->sanitizer->outgoing($message->html_body), [])->html
            : null;

        $draft = new MailboxDraft([
            'mailbox_id' => $message->mailbox_id,
            'user_id' => $user?->getAuthIdentifier(),
            'mode' => MailboxDraft::MODE_NEW,
            'to' => array_column($message->to ?? [], 'address'),
            'cc' => array_column($message->cc ?? [], 'address'),
            'bcc' => $this->addresses($bcc),
            'subject' => $message->subject,
            'body_format' => $html ? ComposeMessageForm::FORMAT_HTML : ComposeMessageForm::FORMAT_TEXT,
            'body' => $message->text_body,
            'body_html' => $html,
            'in_reply_to' => $message->in_reply_to ? trim($message->in_reply_to, '<>') : null,
            'references' => $message->references,
        ]);

        DB::transaction(function () use ($draft, $message): void {
            $draft->save();

            foreach ($message->attachments as $attachment) {
                /** @var MailboxAttachment $attachment */
                $this->storeAttachment($draft, new AttachmentData(
                    filename: $attachment->filename,
                    mimeType: $attachment->mime_type ?: 'application/octet-stream',
                    contents: rescue(fn () => (string) Storage::disk($attachment->disk)->get($attachment->storage_path), '', report: false),
                ));
            }

            $draft->forceFill([
                'remote_folder_id' => $message->folder_id,
                'remote_id' => $message->remote_id,
                'server_message_id' => $message->message_id ? trim($message->message_id, '<>') : null,
                'server_revision' => $draft->revision,
                'server_synced_at' => now(),
            ])->saveQuietly();

            $message->forceFill(['draft_id' => $draft->getKey(), 'is_draft' => true])->save();
        });

        return $draft;
    }

    /**
     * Store the current version in the drafts folder and delete the previous one.
     * Returns false when the mailbox cannot store drafts on the server, or the
     * draft has no recipient yet (MIME messages need one).
     */
    public function pushToServer(MailboxDraft $draft): bool
    {
        $mailbox = $draft->mailbox;
        $folder = $mailbox->folderFor(SpecialUse::Drafts);

        if (! $folder || ! $this->syncsToServer($mailbox) || ! $draft->hasRecipients()) {
            return false;
        }

        $messageId = $draft->uuid.'.'.Str::lower(Str::random(12)).'@filament-mailbox';
        $outgoing = $this->outgoing($draft, $this->formState($draft), $draft->user, $messageId, [self::HEADER => $draft->uuid]);
        $raw = $this->mime->build($mailbox, $outgoing);
        $previous = $this->serverVersions($draft);

        $provider = $this->providers->make($mailbox);

        try {
            $identifier = $provider->appendMessage($folder->identifier(), $raw, new MessageFlags(seen: true, draft: true));

            $this->deleteVersions($draft->mailbox_id, $previous, $provider);
        } finally {
            $provider->disconnect();
        }

        $draft->timestamps = false;
        $draft->forceFill([
            'remote_folder_id' => $folder->getKey(),
            'remote_id' => $identifier?->remoteId,
            'server_message_id' => $messageId,
            'server_revision' => $draft->revision,
            'server_synced_at' => now(),
        ])->save();
        $draft->timestamps = true;

        return true;
    }

    /**
     * Delete versions of a draft on the server and their local copies.
     *
     * @param  array<int, array{0: int, 1: string}>  $versions  Folder id and remote id
     */
    public function deleteVersions(int $mailboxId, array $versions, ?MailboxProvider $provider = null): void
    {
        if ($versions === []) {
            return;
        }

        $mailbox = Mailbox::find($mailboxId);

        if (! $mailbox) {
            return;
        }

        $ownProvider = $provider === null;
        $provider ??= $this->providers->make($mailbox);

        try {
            foreach ($versions as [$folderId, $remoteId]) {
                $folder = $mailbox->folders()->find($folderId);

                if (! $folder) {
                    continue;
                }

                try {
                    // Without a trash folder argument the version is deleted permanently.
                    $provider->delete(new MessageIdentifier($folder->identifier(), $remoteId));
                } catch (Throwable $exception) {
                    report($exception);
                }

                MailboxMessage::query()->where('folder_id', $folder->getKey())->where('remote_id', $remoteId)->get()->each->delete();
            }
        } finally {
            if ($ownProvider) {
                $provider->disconnect();
            }
        }
    }

    public function syncsToServer(Mailbox $mailbox): bool
    {
        return (bool) config('filament-mailbox.drafts.sync_to_server', true)
            && $mailbox->supports(ProviderCapability::AppendMessages);
    }

    public function queueServerSync(MailboxDraft $draft): void
    {
        if ($draft->hasRecipients() && $this->syncsToServer($draft->mailbox) && $draft->mailbox->folderFor(SpecialUse::Drafts)) {
            SaveDraftToServerJob::dispatch($draft);
        }
    }

    /**
     * Versions of the draft known on the server: the last appended one and
     * synchronised copies linked to the draft.
     *
     * @return array<int, array{0: int, 1: string}>
     */
    public function serverVersions(MailboxDraft $draft): array
    {
        $versions = $draft->messages()
            ->whereNotNull('remote_id')
            ->get(['folder_id', 'remote_id'])
            ->map(fn (MailboxMessage $message): array => [(int) $message->folder_id, (string) $message->remote_id])
            ->all();

        if ($draft->remote_folder_id && $draft->remote_id) {
            $versions[] = [(int) $draft->remote_folder_id, (string) $draft->remote_id];
        }

        return array_values(array_unique($versions, SORT_REGULAR));
    }

    /**
     * Link a synchronised message in the drafts folder to its draft (by Message-ID).
     */
    public function draftForMessageId(int $mailboxId, ?string $messageId): ?MailboxDraft
    {
        if (blank($messageId)) {
            return null;
        }

        return MailboxDraft::query()
            ->where('mailbox_id', $mailboxId)
            ->where('server_message_id', trim($messageId, '<>'))
            ->first();
    }

    protected function newDraft(Mailbox $mailbox, ?Authenticatable $user, string $mode, ?MailboxMessage $original): MailboxDraft
    {
        $draft = new MailboxDraft([
            'mailbox_id' => $mailbox->getKey(),
            'user_id' => $user?->getAuthIdentifier(),
            'mode' => $original ? $mode : MailboxDraft::MODE_NEW,
            'reply_to_message_id' => $original?->getKey(),
        ]);

        $draft->setRelation('mailbox', $mailbox);

        if ($original && $draft->context() === ComposeContext::Reply) {
            $draft->in_reply_to = $original->message_id ? trim($original->message_id, '<>') : null;
            $draft->references = implode(' ', array_map(fn (string $id): string => '<'.$id.'>', $this->replies->references($original))) ?: null;
        }

        return $draft;
    }

    /**
     * @return array<int, string>
     */
    protected function references(MailboxDraft $draft): array
    {
        return array_values(array_filter(array_map(
            fn (string $id): string => trim($id, '<>'),
            preg_split('/\s+/', trim((string) $draft->references)) ?: [],
        )));
    }

    /**
     * Sanitised HTML with images of the compose uploads copied into the draft directory.
     */
    protected function storedHtml(mixed $state, MailboxDraft $draft, int|string|null $owner): ?string
    {
        $html = ComposeMessageForm::html($state);

        if (ComposeMessageForm::isEmptyHtml($html)) {
            return null;
        }

        $html = $this->images->relocate($html, [InlineImageProcessor::composeDirectory($owner)], $draft->directory());

        return $this->sanitizer->outgoing($html, keepImageIds: true) ?: null;
    }

    protected function storeAttachment(MailboxDraft $draft, AttachmentData $data): MailboxDraftAttachment
    {
        $disk = (string) config('filament-mailbox.attachments.disk');
        // The original filename is never used as part of the storage path.
        $path = $draft->directory().'/attachments/'.Str::uuid();

        Storage::disk($disk)->put($path, $data->contents);

        return $draft->attachments()->create([
            'filename' => AttachmentService::sanitizeFilename($data->filename),
            'mime_type' => mb_substr($data->mimeType, 0, 255),
            'size' => $data->size(),
            'disk' => $disk,
            'storage_path' => $path,
        ]);
    }

    /**
     * @param  mixed  $addresses
     * @return array<int, string>
     */
    protected function addresses(mixed $addresses): array
    {
        return array_values(array_filter(array_map(fn (mixed $address): string => trim((string) $address), (array) $addresses), filled(...)));
    }
}
