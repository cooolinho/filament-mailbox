<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Contracts\MailboxProvider;
use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\MessagesArchived;
use Cooolinho\FilamentMailbox\Events\MessagesMarkedAsNotSpam;
use Cooolinho\FilamentMailbox\Events\MessagesMarkedAsSpam;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Support\CredentialRedactor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;
use InvalidArgumentException;

/**
 * Applies message operations on the remote mailbox first and mirrors them locally.
 *
 * If the remote operation fails, the exception is rethrown and the local
 * copy is left untouched for the remaining messages.
 */
class MessageService
{
    public const FORWARDED_KEYWORD = '$Forwarded';

    public const JUNK_KEYWORD = '$Junk';

    public const NOT_JUNK_KEYWORD = '$NotJunk';

    /** Folders whose messages are never archived. */
    public const NOT_ARCHIVABLE = [SpecialUse::Archive, SpecialUse::Trash, SpecialUse::Drafts, SpecialUse::All];


    public function __construct(
        protected MailboxProviderFactory $providers,
    ) {}

    /**
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     *
     * @throws UnsupportedOperation
     */
    public function markRead(MailboxMessage|iterable $messages): void
    {
        $this->apply($messages, fn (MailboxMessage $message): bool => ! $message->is_read, function (MailboxProvider $provider, MailboxMessage $message, ?MessageIdentifier $identifier): void {
            $this->ensureSupported($provider, ProviderCapability::Flags, 'mark read');

            if ($identifier) {
                $provider->markRead($identifier);
            }

            $message->forceFill(['is_read' => true])->save();
        });
    }

    /**
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     *
     * @throws UnsupportedOperation
     */
    public function markUnread(MailboxMessage|iterable $messages): void
    {
        $this->apply($messages, fn (MailboxMessage $message): bool => $message->is_read, function (MailboxProvider $provider, MailboxMessage $message, ?MessageIdentifier $identifier): void {
            $this->ensureSupported($provider, ProviderCapability::Flags, 'mark unread');

            if ($identifier) {
                $provider->markUnread($identifier);
            }

            $message->forceFill(['is_read' => false])->save();
        });
    }

    /**
     * Star or unstar messages (\Flagged). Without the Flagged capability only
     * the local copy is changed.
     *
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     */
    public function setFlagged(MailboxMessage|iterable $messages, bool $flagged): void
    {
        $this->apply($messages, fn (MailboxMessage $message): bool => $message->is_flagged !== $flagged, function (MailboxProvider $provider, MailboxMessage $message, ?MessageIdentifier $identifier) use ($flagged): void {
            if ($identifier && $provider->supports(ProviderCapability::Flagged)) {
                $flags = new MessageFlags(flagged: true);

                $flagged
                    ? $provider->setFlags($identifier, $flags, new MessageFlags)
                    : $provider->setFlags($identifier, new MessageFlags, $flags);
            }

            $message->forceFill(['is_flagged' => $flagged])->save();
        });
    }

    /**
     * Move messages to another folder of the same mailbox.
     *
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     *
     * @throws UnsupportedOperation
     */
    public function move(MailboxMessage|iterable $messages, MailboxFolder $target): void
    {
        $this->apply($messages, fn (MailboxMessage $message): bool => $message->folder_id !== $target->getKey(), function (MailboxProvider $provider, MailboxMessage $message, ?MessageIdentifier $identifier) use ($target): void {
            $this->moveTo($provider, $message, $identifier, $target);
        });
    }

    /**
     * Move messages to the archive folder of their mailbox.
     *
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     * @return array<int, int> Original folder ids keyed by message id, e.g. to undo
     *
     * @throws UnsupportedOperation
     */
    public function archive(MailboxMessage|iterable $messages): array
    {
        $moves = [];

        $this->apply($messages, $this->canArchive(...), function (MailboxProvider $provider, MailboxMessage $message, ?MessageIdentifier $identifier) use (&$moves): void {
            $origin = $message->folder_id;

            $this->moveTo($provider, $message, $identifier, $this->archiveFolder($message));

            $moves[$message->getKey()] = $origin;
        });

        $this->dispatchFor($messages, $moves, MessagesArchived::class);

        return $moves;
    }

    /**
     * Move messages to the spam folder. Keyword providers get "$Junk" (and
     * lose "$NotJunk") first, so server-side filters can learn from it.
     *
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     * @return array<int, int> Original folder ids keyed by message id
     *
     * @throws UnsupportedOperation
     */
    public function markAsSpam(MailboxMessage|iterable $messages): array
    {
        $moves = $this->moveWithKeywords(
            $messages,
            $this->canMarkAsSpam(...),
            fn (MailboxMessage $message): ?MailboxFolder => $message->mailbox->spamFolder(),
            add: [self::JUNK_KEYWORD],
            remove: [self::NOT_JUNK_KEYWORD],
        );

        $this->dispatchFor($messages, $moves, MessagesMarkedAsSpam::class);

        return $moves;
    }

    /**
     * Move messages from the spam folder to the inbox ("$NotJunk").
     *
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     * @return array<int, int> Original folder ids keyed by message id
     *
     * @throws UnsupportedOperation
     */
    public function markAsNotSpam(MailboxMessage|iterable $messages): array
    {
        $moves = $this->moveWithKeywords(
            $messages,
            $this->canMarkAsNotSpam(...),
            fn (MailboxMessage $message): ?MailboxFolder => $message->mailbox->folderFor(SpecialUse::Inbox),
            add: [self::NOT_JUNK_KEYWORD],
            remove: [self::JUNK_KEYWORD],
        );

        $this->dispatchFor($messages, $moves, MessagesMarkedAsNotSpam::class);

        return $moves;
    }

    public function canMarkAsSpam(MailboxMessage $message): bool
    {
        $target = $message->mailbox->spamFolder();

        return $target !== null
            && $message->folder !== null
            && ! $this->isSpam($message)
            && ! in_array($message->folder->special_use, [SpecialUse::Trash, SpecialUse::Drafts, SpecialUse::Sent], true)
            && $message->mailbox->supports(ProviderCapability::MoveMessages);
    }

    public function canMarkAsNotSpam(MailboxMessage $message): bool
    {
        return $this->isSpam($message)
            && $message->mailbox->folderFor(SpecialUse::Inbox) !== null
            && $message->mailbox->supports(ProviderCapability::MoveMessages);
    }

    /**
     * Whether the message is in the spam folder of its mailbox.
     */
    public function isSpam(MailboxMessage $message): bool
    {
        $folder = $message->folder;

        return $folder !== null
            && ($folder->special_use === SpecialUse::Junk || $folder->getKey() === $message->mailbox->spam_folder_id);
    }

    /**
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     * @param  callable(MailboxMessage): bool  $filter
     * @param  callable(MailboxMessage): ?MailboxFolder  $target
     * @param  array<int, string>  $add
     * @param  array<int, string>  $remove
     * @return array<int, int>
     */
    protected function moveWithKeywords(MailboxMessage|iterable $messages, callable $filter, callable $target, array $add, array $remove): array
    {
        $moves = [];

        $this->apply($messages, $filter, function (MailboxProvider $provider, MailboxMessage $message, ?MessageIdentifier $identifier) use ($target, $add, $remove, &$moves): void {
            $origin = $message->folder_id;

            // Keywords first: they travel with the message, and servers may train on the move.
            if (config('filament-mailbox.spam.use_keywords', true) && $provider->supports(ProviderCapability::Keywords)) {
                $this->changeKeywords($provider, $message, $identifier, $add, $remove);
            }

            $this->moveTo($provider, $message, $identifier, $target($message));

            $moves[$message->getKey()] = $origin;
        });

        return $moves;
    }

    /**
     * @param  array<int, string>  $add
     * @param  array<int, string>  $remove
     */
    protected function changeKeywords(MailboxProvider $provider, MailboxMessage $message, ?MessageIdentifier $identifier, array $add, array $remove): void
    {
        if ($identifier) {
            try {
                $provider->setFlags($identifier, new MessageFlags(keywords: $add), new MessageFlags(keywords: $remove));
            } catch (Throwable $exception) {
                // Some servers do not allow new keywords; moving still works.
                Log::channel(config('filament-mailbox.sync.log_channel'))->warning('Could not set spam keywords.', [
                    'mailbox_id' => $message->mailbox_id,
                    'message_id' => $message->getKey(),
                    'error' => CredentialRedactor::redact($exception->getMessage(), $message->mailbox),
                ]);

                return;
            }
        }

        $keywords = array_values(array_diff(array_unique([...$message->keywords ?? [], ...$add]), $remove));

        $message->forceFill(['keywords' => $keywords === [] ? null : $keywords]);
    }

    /**
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     * @param  array<int, int>  $moves
     * @param  class-string  $event
     */
    protected function dispatchFor(MailboxMessage|iterable $messages, array $moves, string $event): void
    {
        foreach (Collection::make($messages instanceof MailboxMessage ? [$messages] : $messages)->whereIn('id', array_keys($moves))->groupBy('mailbox_id') as $group) {
            $event::dispatch($group->first()->mailbox, $group->modelKeys());
        }
    }

    /**
     * Whether a message can be archived: an archive folder exists (or can be
     * created), the provider can move messages and the message is not in the
     * archive, trash, drafts or a virtual "all mail" folder.
     */
    public function canArchive(MailboxMessage $message): bool
    {
        $target = $message->mailbox->archiveFolder();

        return ($target !== null || $this->canCreateArchiveFolder($message->mailbox))
            && $message->folder !== null
            && $message->folder_id !== $target?->getKey()
            && ! in_array($message->folder->special_use, self::NOT_ARCHIVABLE, true)
            && $message->mailbox->supports(ProviderCapability::MoveMessages);
    }

    /**
     * With archive.create_folder_if_missing a mailbox without archive folder
     * gets one on the first archiving.
     */
    public function canCreateArchiveFolder(Mailbox $mailbox): bool
    {
        return (bool) config('filament-mailbox.archive.create_folder_if_missing', false)
            && app(FolderManager::class)->supports($mailbox);
    }

    protected function archiveFolder(MailboxMessage $message): MailboxFolder
    {
        $mailbox = $message->mailbox;

        if ($folder = $mailbox->archiveFolder()) {
            return $folder;
        }

        if (! $this->canCreateArchiveFolder($mailbox)) {
            throw UnsupportedOperation::for('archive without archive folder', ProviderCapability::FolderManagement);
        }

        $name = trim((string) config('filament-mailbox.archive.folder_name', 'Archive')) ?: 'Archive';

        // A top-level folder of that name that only lacks the \Archive role is used as it is.
        $folder = $mailbox->folders()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->get()
            ->first(fn (MailboxFolder $folder): bool => mb_strtolower($folder->name) === mb_strtolower($name))
            // Resolved lazily: FolderManager depends on this service.
            ?? app(FolderManager::class)->create($mailbox, $name);

        // Remembered as the archive folder of the mailbox.
        $mailbox->forceFill(['archive_folder_id' => $folder->getKey()])->save();

        return $folder;
    }

    /**
     * Move a message on the server and mirror it locally. Without a new
     * identity the local copy is soft-deleted and remembers its target, so
     * the next sync of the target folder restores it instead of importing a
     * duplicate.
     */
    protected function moveTo(MailboxProvider $provider, MailboxMessage $message, ?MessageIdentifier $identifier, MailboxFolder $target): void
    {
        if ($message->mailbox_id !== $target->mailbox_id) {
            throw new InvalidArgumentException('Messages can only be moved within their mailbox.');
        }

        $this->ensureSupported($provider, ProviderCapability::MoveMessages, 'move message');

        $moved = $identifier ? $provider->moveMessage($identifier, $target->identifier()) : null;

        if ($moved === null) {
            $message->forceFill(['pending_move_to' => $target->getKey()])->save();
            $message->delete();

            return;
        }

        $message->forceFill(['folder_id' => $target->getKey(), 'remote_id' => $moved->remoteId, 'pending_move_to' => null])->save();
        $message->setRelation('folder', $target);
    }

    /**
     * Move messages to the trash folder, or delete them permanently when they
     * already are in the trash or no trash folder exists.
     *
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     */
    public function delete(MailboxMessage|iterable $messages): void
    {
        $this->apply($messages, fn (): bool => true, function (MailboxProvider $provider, MailboxMessage $message, ?MessageIdentifier $identifier): void {
            if ($identifier) {
                $trash = $message->mailbox->folderFor(SpecialUse::Trash);

                $provider->delete(
                    $identifier,
                    $trash && $trash->isNot($message->folder) ? $trash->identifier() : null,
                );
            }

            $message->delete();
        });
    }

    /**
     * Remember that a message was forwarded. Providers with keywords get the
     * "$Forwarded" keyword, so other mail clients show it too.
     */
    public function markForwarded(MailboxMessage $message): void
    {
        $this->apply($message, fn (): bool => true, function (MailboxProvider $provider, MailboxMessage $message, ?MessageIdentifier $identifier): void {
            $keywords = $message->keywords ?? [];

            if ($identifier && $provider->supports(ProviderCapability::Keywords) && ! in_array(self::FORWARDED_KEYWORD, $keywords, true)) {
                $provider->setFlags($identifier, new MessageFlags(keywords: [self::FORWARDED_KEYWORD]), new MessageFlags);

                $keywords[] = self::FORWARDED_KEYWORD;
            }

            $message->forceFill(['forwarded_at' => now(), 'keywords' => $keywords === [] ? null : $keywords])->save();
        });
    }

    /**
     * Add keywords to a message, on the server when the provider supports
     * keywords (errors are logged) and locally in any case.
     *
     * @param  array<int, string>  $keywords
     */
    public function addKeywords(MailboxMessage $message, array $keywords): void
    {
        $this->apply($message, fn (): bool => true, function (MailboxProvider $provider, MailboxMessage $message, ?MessageIdentifier $identifier) use ($keywords): void {
            $missing = array_values(array_diff($keywords, $message->keywords ?? []));

            if ($missing === []) {
                return;
            }

            if ($provider->supports(ProviderCapability::Keywords)) {
                $this->changeKeywords($provider, $message, $identifier, $missing, []);
            } else {
                $message->forceFill(['keywords' => array_values(array_unique([...$message->keywords ?? [], ...$missing]))]);
            }

            $message->save();
        });
    }

    /**
     * The original MIME source of a message, or null when the provider cannot deliver it.
     */
    public function rawMessage(MailboxMessage $message): ?string
    {
        $raw = null;

        $this->apply($message, fn (): bool => true, function (MailboxProvider $provider, MailboxMessage $message, ?MessageIdentifier $identifier) use (&$raw): void {
            $raw = $identifier ? $provider->rawMessage($identifier) : null;
        });

        return filled($raw) ? $raw : null;
    }

    /**
     * The remote identity of a message, or null when it can no longer be
     * addressed on the server (then only the local copy is changed).
     */
    public function identifier(MailboxMessage $message, ?MailboxProvider $provider = null): ?MessageIdentifier
    {
        $folder = $message->folder;

        if (! $folder || blank($message->remote_id)) {
            return null;
        }

        $identifier = new MessageIdentifier($folder->identifier(), $message->remote_id);

        if ($provider?->isStale($identifier, $folder->cursor())) {
            return null;
        }

        return $identifier;
    }

    protected function ensureSupported(MailboxProvider $provider, ProviderCapability $capability, string $operation): void
    {
        if (! $provider->supports($capability)) {
            throw UnsupportedOperation::for($operation, $capability);
        }
    }

    /**
     * @param  MailboxMessage|iterable<MailboxMessage>  $messages
     * @param  callable(MailboxMessage): bool  $filter
     * @param  callable(MailboxProvider, MailboxMessage, ?MessageIdentifier): void  $operation
     */
    protected function apply(MailboxMessage|iterable $messages, callable $filter, callable $operation): void
    {
        $messages = Collection::make($messages instanceof MailboxMessage ? [$messages] : $messages)
            ->filter($filter);

        // One connection per mailbox.
        foreach ($messages->groupBy('mailbox_id') as $group) {
            $group = Collection::make($group)->loadMissing('mailbox', 'folder');

            $provider = $this->providers->make($group->first()->mailbox);

            try {
                foreach ($group as $message) {
                    $operation($provider, $message, $this->identifier($message, $provider));
                }
            } finally {
                $provider->disconnect();
            }
        }
    }
}
