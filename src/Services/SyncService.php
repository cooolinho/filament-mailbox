<?php

namespace Cooolinho\FilamentMailbox\Services;

use Closure;
use Cooolinho\FilamentMailbox\Contracts\MailboxProvider;
use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Contracts\ManagesFolders;
use Cooolinho\FilamentMailbox\Contracts\SupportsLabels;
use Cooolinho\FilamentMailbox\Contracts\SupportsMailboxWideSync;
use Cooolinho\FilamentMailbox\Contracts\SyncObserver;
use Cooolinho\FilamentMailbox\Data\AddressData;
use Cooolinho\FilamentMailbox\Data\FolderChanges;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Data\SyncCursor;
use Cooolinho\FilamentMailbox\Data\SyncResult;
use Cooolinho\FilamentMailbox\Enums\LabelSource;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\FolderSynced;
use Cooolinho\FilamentMailbox\Events\FolderSyncFailed;
use Cooolinho\FilamentMailbox\Events\LabelsChanged;
use Cooolinho\FilamentMailbox\Events\MailboxSynced;
use Cooolinho\FilamentMailbox\Events\MailboxSyncFailed;
use Cooolinho\FilamentMailbox\Events\MessagesImported;
use Cooolinho\FilamentMailbox\Exceptions\OAuthReconnectRequired;
use Cooolinho\FilamentMailbox\Jobs\ApplyBlockedSendersJob;
use Cooolinho\FilamentMailbox\Exceptions\SyncFailed;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Monitoring\NullSyncObserver;
use Cooolinho\FilamentMailbox\Statistics\StatisticsRecorder;
use Cooolinho\FilamentMailbox\Support\CredentialRedactor;
use Illuminate\Database\Eloquent\Collection;
use Cooolinho\FilamentMailbox\Services\Receipts\DeliveryReportService;
use Cooolinho\FilamentMailbox\Services\Receipts\ReadReceiptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncService
{
    /** @var array<int, array<int, int>> New inbox message ids per mailbox, checked against blocked senders */
    protected array $importedInbox = [];

    /** @var array<int, int> Ids of messages created by the current batch. */
    protected array $createdIds = [];

    protected SyncObserver $observer;

    /** Flag changes and deletions of the folder currently synchronised. */
    protected int $updated = 0;

    protected int $deleted = 0;

    public function __construct(
        protected MailboxProviderFactory $providers,
        protected AttachmentService $attachments,
        protected LabelSynchronizer $labels,
        protected TagService $tags,
    ) {
        $this->observer = new NullSyncObserver;
    }

    /**
     * @throws SyncFailed when the mailbox cannot be synchronised at all
     */
    public function syncMailbox(Mailbox $mailbox, ?SyncObserver $observer = null): SyncResult
    {
        return $this->observed($observer, fn (): SyncResult => $this->runMailboxSync($mailbox));
    }

    protected function runMailboxSync(Mailbox $mailbox): SyncResult
    {
        $result = new SyncResult;
        $provider = $this->providers->make($mailbox);

        try {
            $folders = $this->syncFolders($mailbox, $provider);
        } catch (Throwable $exception) {
            $provider->disconnect();

            $message = CredentialRedactor::redact($exception->getMessage(), $mailbox);

            $mailbox->forceFill(['last_sync_error' => $message])->save();

            $this->log('Mailbox synchronisation failed.', $mailbox, ['error' => $message]);

            MailboxSyncFailed::dispatch($mailbox, $message);

            throw SyncFailed::because($message, permanent: $exception instanceof OAuthReconnectRequired, cause: $exception::class);
        }

        $result->folders = $folders->count();

        if ($provider instanceof SupportsMailboxWideSync && $provider->supports(ProviderCapability::MailboxWideSync)) {
            try {
                $result->imported += $this->syncMailboxWide($mailbox, $provider, $folders);
                $result->updated += $this->updated;
                $result->deleted += $this->deleted;
            } catch (Throwable $exception) {
                $message = CredentialRedactor::redact($exception->getMessage(), $mailbox);
                $result->errors['mailbox'] = $message;

                $this->log('Mailbox synchronisation failed.', $mailbox, ['error' => $message]);
            }

            $folders = new Collection;
        }

        foreach ($folders as $folder) {
            try {
                $result->imported += $this->syncFolder($folder, $provider);
                $result->updated += $this->updated;
                $result->deleted += $this->deleted;
            } catch (Throwable $exception) {
                $message = CredentialRedactor::redact($exception->getMessage(), $mailbox);
                $result->errors[$folder->full_name] = $message;

                $this->log('Mailbox folder synchronisation failed.', $mailbox, [
                    'folder' => $folder->full_name,
                    'error' => $message,
                ]);

                FolderSyncFailed::dispatch($folder, $message);
            }
        }

        // After the folders, so labels no longer used by any message can be removed.
        if ($provider instanceof SupportsLabels) {
            try {
                $this->labels->syncCatalog($mailbox, $provider->labelSource(), $this->measure('labels', fn () => $provider->labels()));
            } catch (Throwable $exception) {
                $result->errors['labels'] = CredentialRedactor::redact($exception->getMessage(), $mailbox);
            }
        }

        $provider->disconnect();

        $mailbox->forceFill([
            'last_synced_at' => now(),
            'last_sync_error' => $result->successful()
                ? null
                : collect($result->errors)->map(fn (string $error, string $folder) => "{$folder}: {$error}")->implode("\n"),
        ])->save();

        MailboxSynced::dispatch($mailbox, $result);

        return $result;
    }

    /**
     * Mirror the remote folder structure and return the active folders.
     *
     * @return Collection<int, MailboxFolder>
     */
    public function syncFolders(Mailbox $mailbox, MailboxProvider $provider): Collection
    {
        // Folder operations must not run in between listing and deactivating.
        return FolderManager::locked($mailbox, fn (): Collection => $this->mirrorFolders($mailbox, $provider));
    }

    /**
     * @return Collection<int, MailboxFolder>
     */
    protected function mirrorFolders(Mailbox $mailbox, MailboxProvider $provider): Collection
    {
        /** @var array<string, FolderData> $remote */
        $remote = collect($this->measure('folders', fn () => $provider->folders()))->keyBy('remoteId')->all();
        $subscribed = $this->subscribedFolders($mailbox, $provider);

        DB::transaction(function () use ($mailbox, $remote, $subscribed): void {
            foreach ($remote as $data) {
                $mailbox->folders()->updateOrCreate(
                    ['remote_id' => $data->remoteId],
                    [
                        'full_name' => $data->fullName,
                        'name' => $data->name,
                        'delimiter' => $data->delimiter,
                        'special_use' => $data->specialUse,
                        'is_active' => true,
                        ...($subscribed === null ? [] : ['is_subscribed' => in_array($data->remoteId, $subscribed, true)]),
                    ],
                );
            }

            $ids = $mailbox->folders()->pluck('id', 'remote_id');

            foreach ($remote as $data) {
                $mailbox->folders()
                    ->where('remote_id', $data->remoteId)
                    ->update(['parent_id' => $data->parentRemoteId ? ($ids[$data->parentRemoteId] ?? null) : null]);
            }

            // Folders removed on the server are kept (with their messages) but deactivated.
            $mailbox->folders()
                ->whereNotIn('remote_id', array_map('strval', array_keys($remote)))
                ->update(['is_active' => false]);
        });

        return $mailbox->folders()->where('is_active', true)->orderBy('id')->get();
    }

    /**
     * Synchronise a mailbox with one change feed for all folders. Messages exist
     * once and are moved between folders when their folder changes.
     *
     * @param  Collection<int, MailboxFolder>  $folders  Active folders
     */
    public function syncMailboxWide(Mailbox $mailbox, SupportsMailboxWideSync&MailboxProvider $provider, Collection $folders): int
    {
        return $this->observedFolder('mailbox', fn (): int => $this->runMailboxWideSync($mailbox, $provider, $folders));
    }

    /**
     * @param  Collection<int, MailboxFolder>  $folders
     */
    protected function runMailboxWideSync(Mailbox $mailbox, SupportsMailboxWideSync&MailboxProvider $provider, Collection $folders): int
    {
        $folders = $folders->keyBy('remote_id');
        $source = $provider instanceof SupportsLabels ? $provider->labelSource() : null;
        $chunkSize = max(1, (int) config('filament-mailbox.sync.chunk_size', 50));
        $imported = 0;
        // No notifications for messages that already existed before the first synchronisation.
        $initial = blank($mailbox->sync_cursor);

        do {
            $changes = $this->measure('mailboxChanges', fn () => $provider->mailboxChanges(new SyncCursor($mailbox->sync_cursor ?? []), $chunkSize));
            $labelled = [];
            $new = [];

            foreach (array_chunk($changes->messages, 500, preserve_keys: true) as $chunk) {
                $existing = MailboxMessage::withTrashed()
                    ->where('mailbox_id', $mailbox->getKey())
                    ->whereIn('remote_id', array_map('strval', array_keys($chunk)))
                    ->get()
                    ->keyBy('remote_id');

                foreach ($chunk as $remoteId => $change) {
                    $folder = $folders->get($change['folder']);
                    $message = $existing->get((string) $remoteId);

                    if (! $folder) {
                        continue;
                    }

                    if (! $message) {
                        $new[$change['folder']][(string) $remoteId] = $change['flags'];

                        continue;
                    }

                    $message->forceFill(['folder_id' => $folder->getKey(), ...$this->flagAttributes($change['flags'])]);
                    $this->readReceipts()->applyKeywords($message);

                    if ($message->trashed() || $message->isDirty()) {
                        $message->trashed() ? $message->restore() : $message->save();
                        $this->updated++;
                    }

                    $this->syncLabels($message, $source, $change['flags']->keywords, $labelled);
                }
            }

            foreach ($new as $folderId => $flags) {
                $folder = $folders->get($folderId);
                $batch = 0;
                $this->createdIds = [];

                foreach ($this->measure('fetchMessages', fn () => $provider->fetchMessages($folder->identifier(), array_map('strval', array_keys($flags)))) as $data) {
                    if ($this->importMessage($folder, $data->with(['flags' => $flags[$data->remoteId] ?? $data->flags]), $source, $labelled)) {
                        $batch++;
                    }
                }

                if ($batch > 0) {
                    MessagesImported::dispatch($folder, $batch, $this->createdIds, $initial);
                    $imported += $batch;
                }
            }

            // Messages deleted on the server are soft-deleted locally.
            foreach (array_chunk($changes->deleted, 500) as $remoteIds) {
                $deleted = MailboxMessage::query()
                    ->where('mailbox_id', $mailbox->getKey())
                    ->whereIn('remote_id', $remoteIds)
                    ->get();

                $deleted->each->delete();
                $this->deleted += $deleted->count();
            }

            if ($labelled !== []) {
                LabelsChanged::dispatch($mailbox, array_values(array_unique($labelled)));
            }

            $mailbox->forceFill(['sync_cursor' => $changes->cursor->state])->save();
        } while ($changes->hasMore);

        $folders->each(fn (MailboxFolder $folder) => $folder->forceFill(['last_synced_at' => now()])->save());

        $this->applyBlockedSenders($mailbox);

        return $imported;
    }

    /**
     * Synchronise a single folder and return the number of imported messages.
     */
    public function syncFolder(MailboxFolder $folder, MailboxProvider $provider, ?SyncObserver $observer = null): int
    {
        return $this->observed($observer, fn (): int => $this->observedFolder($folder->full_name, fn (): int => $this->runFolderSync($folder, $provider)));
    }

    protected function runFolderSync(MailboxFolder $folder, MailboxProvider $provider): int
    {
        $identifier = $folder->identifier();
        $imported = 0;
        $initial = $folder->last_synced_at === null;
        $chunkSize = max(1, (int) config('filament-mailbox.sync.chunk_size', 50));

        do {
            $changes = $this->measure('changes', fn () => $provider->changes($identifier, $folder->cursor(), $chunkSize));

            if ($changes->reset) {
                // Local identities are meaningless now: start over.
                $folder->messages()->delete();
                $initial = true;
            }

            $batch = 0;
            $this->createdIds = [];

            $source = $provider instanceof SupportsLabels ? $provider->labelSource() : null;
            $labelled = [];

            foreach ($changes->created as $message) {
                if ($this->importMessage($folder, $message, $source, $labelled)) {
                    $batch++;
                }
            }

            $batch += $this->importUpdatedNewMessages($folder, $provider, $changes, $source, $labelled);

            $this->applyChanges($folder, $changes, $source, $labelled);

            if ($labelled !== []) {
                LabelsChanged::dispatch($folder->mailbox, array_values(array_unique($labelled)));
            }

            // The cursor is only advanced once the batch is stored.
            $folder->forceFill(['sync_cursor' => $changes->cursor->state])->save();

            if ($batch > 0) {
                MessagesImported::dispatch($folder, $batch, $this->createdIds, $initial);
            }

            $imported += $batch;
        } while ($changes->hasMore);

        $folder->forceFill(['last_synced_at' => now()])->save();

        $this->updateStatistics($folder, $provider);
        $this->applyBlockedSenders($folder->mailbox);

        FolderSynced::dispatch($folder, $imported);

        return $imported;
    }

    /**
     * Import a new message. Returns false when it already exists; a locally
     * deleted copy that shows up again (e.g. moved back) is restored.
     */
    /**
     * @param  array<int, int>  $labelled  Receives the ids of messages whose labels changed
     */
    public function importMessage(MailboxFolder $folder, MessageData $data, ?LabelSource $labelSource = null, array &$labelled = []): bool
    {
        $existing = MailboxMessage::withTrashed()
            ->where('folder_id', $folder->getKey())
            ->where('remote_id', $data->remoteId)
            ->first();

        if ($existing) {
            $existing->forceFill($this->flagAttributes($data->flags));
            $this->readReceipts()->applyKeywords($existing);

            $restored = $existing->trashed();
            $restored ? $existing->restore() : $existing->save();

            $this->syncLabels($existing, $labelSource, $data->flags->keywords, $labelled);

            return $restored;
        }

        if ($this->restoreMovedMessage($folder, $data, $labelSource, $labelled)) {
            return true;
        }

        $message = new MailboxMessage([
            'mailbox_id' => $folder->mailbox_id,
            'folder_id' => $folder->getKey(),
            'remote_id' => $data->remoteId,
            'thread_id' => $data->threadId,
            'message_id' => $data->messageId,
            'in_reply_to' => $data->inReplyTo,
            'references' => $data->references === [] ? null : implode(' ', $data->references),
            'from_address' => $data->from?->address,
            'from_name' => $data->from?->name,
            'reply_to' => $this->addresses($data->replyTo),
            'to' => $this->addresses($data->to),
            'cc' => $this->addresses($data->cc),
            'subject' => $data->subject,
            'text_body' => $data->textBody,
            'html_body' => $data->htmlBody,
            ...$this->flagAttributes($data->flags),
            'has_attachments' => $data->attachments !== [],
            'is_draft' => $data->flags->draft || $folder->special_use === SpecialUse::Drafts,
            'is_auto_generated' => $data->isAutoGenerated,
            ...$this->readReceipts()->importAttributes($folder, $data),
            'sent_at' => $data->sentAt,
            'received_at' => $data->receivedAt ?? $data->sentAt ?? now(),
        ]);

        // A version stored by the app is linked to its draft instead of being listed separately.
        $draft = $message->is_draft ? app(DraftService::class)->draftForMessageId($folder->mailbox_id, $data->messageId) : null;
        $message->draft_id = $draft?->getKey();

        try {
            DB::transaction(function () use ($message, $data): void {
                $message->save();

                foreach ($data->attachments as $attachment) {
                    $this->attachments->store($message, $attachment);
                }
            });

            $this->syncLabels($message, $labelSource, $data->flags->keywords, $labelled);
            $this->tags->reattach($message);

            // A malformed report never breaks the synchronisation.
            if ($message->is_receipt) {
                rescue(fn () => $this->readReceipts()->processReport($message, $data));
            }

            if (app(DeliveryReportService::class)->isReport($data)) {
                rescue(fn () => app(DeliveryReportService::class)->process($message, $data));
            }
            app(StatisticsRecorder::class)->messageImported($message, $folder);

            // Servers without UIDPLUS do not report the identity of the appended version.
            if ($draft && blank($draft->remote_id)) {
                $draft->timestamps = false;
                $draft->forceFill(['remote_folder_id' => $folder->getKey(), 'remote_id' => $message->remote_id])->save();
            }

            if ($folder->special_use === SpecialUse::Inbox) {
                $this->importedInbox[$folder->mailbox_id][] = $message->getKey();
            }

            $this->createdIds[] = $message->getKey();
        } catch (Throwable $exception) {
            if ($message->exists) {
                $this->attachments->deleteFiles($message);
            }

            throw $exception;
        }

        return true;
    }

    /**
     * @return ?array<int, string>
     */
    protected function subscribedFolders(Mailbox $mailbox, MailboxProvider $provider): ?array
    {
        if (! $provider instanceof ManagesFolders) {
            return null;
        }

        try {
            return $this->measure('subscribedFolders', fn () => $provider->subscribedFolders());
        } catch (Throwable $exception) {
            $this->log('Could not read folder subscriptions.', $mailbox, ['error' => CredentialRedactor::redact($exception->getMessage(), $mailbox)]);

            return null;
        }
    }

    /**
     * Message count, unseen count and size as reported by the server. Optional:
     * failures never fail the synchronisation.
     */
    protected function updateStatistics(MailboxFolder $folder, MailboxProvider $provider): void
    {
        if (! $provider instanceof ManagesFolders || ! $provider->supports(ProviderCapability::FolderManagement)) {
            return;
        }

        try {
            $statistics = $this->measure('folderStatistics', fn () => $provider->folderStatistics($folder->identifier()));
        } catch (Throwable $exception) {
            $this->log('Could not read folder statistics.', $folder->mailbox, [
                'folder' => $folder->full_name,
                'error' => CredentialRedactor::redact($exception->getMessage(), $folder->mailbox),
            ]);

            return;
        }

        $folder->forceFill([
            'message_count' => $statistics->messages,
            'unseen_count' => $statistics->unseen,
            'size_bytes' => $statistics->size,
            'statistics_updated_at' => now(),
        ])->save();
    }

    /**
     * Queue moving new inbox messages of blocked senders to spam - outside the
     * import, so a failing move never breaks the synchronisation.
     */
    protected function applyBlockedSenders(Mailbox $mailbox): void
    {
        $ids = $this->importedInbox[$mailbox->getKey()] ?? [];
        unset($this->importedInbox[$mailbox->getKey()]);

        if ($ids !== [] && config('filament-mailbox.spam.block_senders', true) && $mailbox->blockedSenders()->exists()) {
            ApplyBlockedSendersJob::dispatch($mailbox, $ids);
        }
    }

    /**
     * A message moved without a known new identity is restored in its target
     * folder instead of being imported again (keeps attachments, labels and tags).
     *
     * @param  array<int, int>  $labelled
     */
    protected function restoreMovedMessage(MailboxFolder $folder, MessageData $data, ?LabelSource $labelSource, array &$labelled): bool
    {
        if (blank($data->messageId)) {
            return false;
        }

        $moved = MailboxMessage::onlyTrashed()
            ->where('mailbox_id', $folder->mailbox_id)
            ->where('pending_move_to', $folder->getKey())
            ->where('message_id', $data->messageId)
            ->oldest('deleted_at')
            ->first();

        if (! $moved) {
            return false;
        }

        $moved->forceFill([
            'folder_id' => $folder->getKey(),
            'remote_id' => $data->remoteId,
            'pending_move_to' => null,
            ...$this->flagAttributes($data->flags),
        ]);
        $this->readReceipts()->applyKeywords($moved);
        $moved->restore();

        $this->syncLabels($moved, $labelSource, $data->flags->keywords, $labelled);

        return true;
    }

    /**
     * Import messages that a delta API only reported in $updated.
     *
     * @param  array<int, int>  $labelled
     */
    protected function importUpdatedNewMessages(MailboxFolder $folder, MailboxProvider $provider, FolderChanges $changes, ?LabelSource $source, array &$labelled): int
    {
        if (! $changes->includesNew || $changes->updated === []) {
            return 0;
        }

        $imported = 0;
        $remoteIds = array_map('strval', array_keys($changes->updated));

        foreach (array_chunk($remoteIds, 500) as $chunk) {
            $existing = MailboxMessage::withTrashed()
                ->where('folder_id', $folder->getKey())
                ->whereIn('remote_id', $chunk)
                ->get(['id', 'remote_id', 'deleted_at'])
                ->keyBy('remote_id');

            // Locally deleted copies are restored without downloading them again.
            foreach ($existing->filter->trashed() as $remoteId => $message) {
                if ($this->importMessage($folder, new MessageData((string) $remoteId, flags: $changes->updated[$remoteId]), $source, $labelled)) {
                    $imported++;
                }
            }

            $missing = array_values(array_filter($chunk, fn (string $remoteId): bool => ! $existing->has($remoteId)));

            foreach ($missing === [] ? [] : $this->measure('fetchMessages', fn () => $provider->fetchMessages($folder->identifier(), $missing)) as $data) {
                $flags = $changes->updated[$data->remoteId] ?? $data->flags;

                if ($this->importMessage($folder, $data->with(['flags' => $flags]), $source, $labelled)) {
                    $imported++;
                }
            }
        }

        return $imported;
    }

    /**
     * Mirror flag changes and deletions reported by the provider.
     */
    /**
     * @param  array<int, int>  $labelled
     */
    protected function applyChanges(MailboxFolder $folder, FolderChanges $changes, ?LabelSource $labelSource = null, array &$labelled = []): void
    {
        $removed = $changes->deleted;
        $updated = $changes->updated;

        if ($updated !== [] || $changes->snapshot) {
            $folder->messages()
                ->select(['id', 'mailbox_id', 'remote_id', 'is_read', 'is_flagged', 'is_answered', 'keywords', 'mdn_status'])
                ->chunkById(500, function ($messages) use ($updated, $changes, $labelSource, &$removed, &$labelled): void {
                    foreach ($messages as $message) {
                        $flags = $updated[$message->remote_id] ?? null;

                        if ($flags === null) {
                            if ($changes->snapshot) {
                                $removed[] = $message->remote_id;
                            }

                            continue;
                        }

                        $message->forceFill($this->flagAttributes($flags));

                        if ($message->isDirty('keywords')) {
                            $this->syncLabels($message, $labelSource, $flags->keywords, $labelled);
                            $this->readReceipts()->applyKeywords($message);
                        }

                        if ($message->isDirty()) {
                            $message->save();
                            $this->updated++;
                        }
                    }
                });
        }

        // Messages deleted on the server are soft-deleted locally.
        foreach (array_chunk(array_values(array_unique($removed)), 500) as $remoteIds) {
            $deleted = $folder->messages()->whereIn('remote_id', $remoteIds)->get();

            $deleted->each->delete();
            $this->deleted += $deleted->count();
        }
    }

    /**
     * @param  array<int, string>  $keywords
     * @param  array<int, int>  $labelled
     */
    protected function syncLabels(MailboxMessage $message, ?LabelSource $source, array $keywords, array &$labelled): void
    {
        if ($source && $this->labels->syncMessageLabels($message, $source, $keywords)) {
            $labelled[] = $message->getKey();
        }
    }

    protected function readReceipts(): ReadReceiptService
    {
        return app(ReadReceiptService::class);
    }

    /**
     * @return array{is_read: bool, is_flagged: bool, is_answered: bool, keywords: ?array<int, string>}
     */
    protected function flagAttributes(MessageFlags $flags): array
    {
        return [
            'is_read' => $flags->seen,
            'is_flagged' => $flags->flagged,
            'is_answered' => $flags->answered,
            'keywords' => $flags->keywords === [] ? null : $flags->keywords,
        ];
    }

    /**
     * @param  array<int, AddressData>  $addresses
     * @return array<int, array{address: string, name: ?string}>
     */
    protected function addresses(array $addresses): array
    {
        return array_map(fn (AddressData $address): array => $address->toArray(), $addresses);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function log(string $message, Mailbox $mailbox, array $context = []): void
    {
        Log::channel(config('filament-mailbox.sync.log_channel'))->warning($message, [
            ...$this->observer->logContext(),
            'mailbox_id' => $mailbox->getKey(),
            ...$context,
        ]);
    }

    /**
     * Run with the given observer, or keep the current one.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function observed(?SyncObserver $observer, Closure $callback): mixed
    {
        if ($observer === null) {
            return $callback();
        }

        $previous = $this->observer;
        $this->observer = $observer;

        try {
            return $callback();
        } finally {
            $this->observer = $previous;
        }
    }

    /**
     * @param  Closure(): int  $callback  Returns the number of imported messages
     */
    protected function observedFolder(string $name, Closure $callback): int
    {
        $this->updated = 0;
        $this->deleted = 0;
        $this->observer->folderStarted($name);

        try {
            $imported = $callback();
        } catch (Throwable $exception) {
            $this->observer->folderFailed($name, $exception);

            throw $exception;
        }

        $this->observer->folderFinished($name, $imported, $this->updated, $this->deleted);

        return $imported;
    }

    /**
     * Time a provider call for the observer.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    protected function measure(string $operation, Closure $call): mixed
    {
        $start = hrtime(true);

        try {
            $result = $call();
        } catch (Throwable $exception) {
            $this->observer->providerCall($operation, (hrtime(true) - $start) / 1_000_000, $exception);

            throw $exception;
        }

        $this->observer->providerCall($operation, (hrtime(true) - $start) / 1_000_000);

        return $result;
    }
}
