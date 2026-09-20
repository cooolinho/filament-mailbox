<?php

namespace Cooolinho\FilamentMailbox\Providers\Gmail;

use Closure;
use Cooolinho\FilamentMailbox\Contracts\SupportsLabels;
use Cooolinho\FilamentMailbox\Contracts\SupportsMailboxWideSync;
use Cooolinho\FilamentMailbox\Contracts\SupportsSending;
use Cooolinho\FilamentMailbox\Data\FolderChanges;
use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Data\LabelData;
use Cooolinho\FilamentMailbox\Data\MailboxChanges;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Data\SyncCursor;
use Cooolinho\FilamentMailbox\Enums\LabelColor;
use Cooolinho\FilamentMailbox\Enums\LabelSource;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Exceptions\ConnectionFailed;
use Cooolinho\FilamentMailbox\Exceptions\ProviderOperationFailed;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\OAuth\OAuthTokenManager;
use Cooolinho\FilamentMailbox\OAuth\Providers\AbstractOAuthProvider;
use Cooolinho\FilamentMailbox\Providers\AbstractMailboxProvider;
use Cooolinho\FilamentMailbox\Providers\MimeMessageMapper;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Google Workspace / Gmail through the Gmail REST API.
 *
 * History ids are mailbox-wide, so the mailbox is synchronised at once
 * (ProviderCapability::MailboxWideSync). Messages are stored once in their
 * primary system folder, user labels are mailbox labels.
 */
class GmailProvider extends AbstractMailboxProvider implements SupportsLabels, SupportsMailboxWideSync, SupportsSending
{
    public const HISTORY_TYPES = ['messageAdded', 'messageDeleted', 'labelAdded', 'labelRemoved'];

    protected GmailClient $client;

    public function __construct(
        protected Mailbox $mailbox,
        ?GmailClient $client = null,
    ) {
        $this->client = $client ?? new GmailClient($mailbox, app(OAuthTokenManager::class));
    }

    public function capabilities(): array
    {
        return ProviderType::Gmail->capabilities();
    }

    public function testConnection(): void
    {
        $this->attempt('test connection', fn () => $this->client->get('profile'), connection: true);
    }

    public function folders(): iterable
    {
        // Validates the connection; folders are the fixed system labels.
        $this->testConnection();

        return GmailLabelMapper::folders();
    }

    public function mailboxChanges(SyncCursor $cursor, int $limit): MailboxChanges
    {
        return $this->attempt('fetch changes', function () use ($cursor, $limit): MailboxChanges {
            if ($cursor->get('history_id') === null) {
                return $this->initialChanges($cursor, $limit);
            }

            try {
                $history = $this->client->get('history', array_filter([
                    'startHistoryId' => (string) $cursor->get('history_id'),
                    'historyTypes' => static::HISTORY_TYPES,
                    'maxResults' => max(1, $limit),
                    'pageToken' => $cursor->get('page_token'),
                ], fn ($value): bool => $value !== null));
            } catch (GmailRequestFailed $exception) {
                if (! $exception->isNotFound()) {
                    throw $exception;
                }

                // History is only kept for a limited time: resync the recent messages.
                return $this->initialChanges(new SyncCursor(['resync' => true]), $limit);
            }

            [$changed, $deleted] = GmailHistoryProcessor::process($history['history'] ?? []);
            [$messages, $gone] = $this->states($changed);
            $next = $history['nextPageToken'] ?? null;

            return new MailboxChanges(
                messages: $messages,
                deleted: [...$deleted, ...$gone],
                cursor: new SyncCursor($next
                    ? ['history_id' => $cursor->get('history_id'), 'page_token' => $next]
                    : ['history_id' => (string) ($history['historyId'] ?? $cursor->get('history_id'))]),
                hasMore: $next !== null,
            );
        });
    }

    /**
     * Folder view on the mailbox-wide feed: messages that left the folder are
     * reported as deleted.
     */
    public function changes(FolderIdentifier $folder, SyncCursor $cursor, int $limit): FolderChanges
    {
        $changes = $this->mailboxChanges($cursor, $limit);
        $updated = $deleted = [];

        foreach ($changes->messages as $id => $change) {
            $change['folder'] === $folder->remoteId ? $updated[(string) $id] = $change['flags'] : $deleted[] = (string) $id;
        }

        return new FolderChanges(
            created: [],
            updated: $updated,
            deleted: [...$changes->deleted, ...$deleted],
            cursor: $changes->cursor,
            hasMore: $changes->hasMore,
            includesNew: true,
        );
    }

    public function fetchMessages(FolderIdentifier $folder, array $remoteIds): array
    {
        return $this->attempt('fetch messages', function () use ($remoteIds): array {
            $messages = [];

            foreach ($this->client->batchGet(array_combine($remoteIds, array_map(fn (string $id): string => 'messages/'.rawurlencode($id).'?format=raw', $remoteIds))) as $id => $message) {
                if (! is_array($message) || ! is_string($message['raw'] ?? null)) {
                    continue;
                }

                $messages[] = MimeMessageMapper::fromRaw(
                    AbstractOAuthProvider::base64UrlDecode($message['raw']),
                    (string) $id,
                    GmailLabelMapper::flags($message['labelIds'] ?? []),
                    isset($message['internalDate']) ? Carbon::createFromTimestampMs((int) $message['internalDate']) : null,
                )->with(['threadId' => $message['threadId'] ?? null]);
            }

            return $messages;
        });
    }

    public function setFlags(MessageIdentifier $message, MessageFlags $add, MessageFlags $remove): void
    {
        [$adds, $removes] = GmailLabelMapper::modification($add, $remove);

        $this->modify($message->remoteId, $adds, $removes);
    }

    public function moveMessage(MessageIdentifier $message, FolderIdentifier $target): ?MessageIdentifier
    {
        $this->attempt('move message', function () use ($message, $target): void {
            $id = rawurlencode($message->remoteId);

            if ($target->remoteId === 'TRASH') {
                $this->client->post("messages/{$id}/trash");

                return;
            }

            if ($message->folder->remoteId === 'TRASH') {
                $this->client->post("messages/{$id}/untrash");
            }

            $source = $message->folder->remoteId;

            $this->client->post("messages/{$id}/modify", [
                'addLabelIds' => $target->remoteId === GmailLabelMapper::ARCHIVE ? [] : [$target->remoteId],
                // Moving out of the inbox archives the message.
                'removeLabelIds' => in_array($source, [GmailLabelMapper::ARCHIVE, 'TRASH'], true) ? [] : [$source],
            ]);
        });

        return new MessageIdentifier($target, $message->remoteId);
    }

    public function rawMessage(MessageIdentifier $message): string
    {
        return $this->attempt('raw message', fn (): string => AbstractOAuthProvider::base64UrlDecode(
            (string) ($this->client->get('messages/'.rawurlencode($message->remoteId), ['format' => 'raw'])['raw'] ?? ''),
        ));
    }

    public function send(string $rawMime, OutgoingMessageData $data): void
    {
        // Gmail files the message under "Sent" and in the given thread.
        $this->attempt('send', fn () => $this->client->post('messages/send', array_filter([
            'raw' => AbstractOAuthProvider::base64UrlEncode($rawMime),
            'threadId' => $data->providerThreadId,
        ])));
    }

    public function labelSource(): LabelSource
    {
        return LabelSource::Gmail;
    }

    public function labels(): array
    {
        return $this->attempt('labels', fn (): array => array_values(array_filter(array_map(
            fn (array $label): ?LabelData => GmailLabelMapper::label($label),
            $this->client->get('labels')['labels'] ?? [],
        ))));
    }

    public function createLabel(string $name, ?LabelColor $color = null): LabelData
    {
        return $this->attempt('create label', function () use ($name, $color): LabelData {
            $label = $this->client->post('labels', [
                'name' => $name,
                'labelListVisibility' => 'labelShow',
                'messageListVisibility' => 'show',
            ]);

            return new LabelData(LabelSource::Gmail, (string) $label['id'], $name, $color);
        });
    }

    public function renameLabel(LabelData $label, string $name, ?LabelColor $color = null): LabelData
    {
        return $this->attempt('rename label', function () use ($label, $name, $color): LabelData {
            if ($name !== $label->name) {
                $this->client->patch('labels/'.rawurlencode($label->remoteKey), ['name' => $name]);
            }

            return new LabelData($label->source, $label->remoteKey, $name, $color);
        });
    }

    public function deleteLabel(LabelData $label): void
    {
        $this->attempt('delete label', function () use ($label): void {
            try {
                $this->client->delete('labels/'.rawurlencode($label->remoteKey));
            } catch (GmailRequestFailed $exception) {
                if (! $exception->isNotFound()) {
                    throw $exception;
                }
            }
        });
    }

    public function changeLabels(MessageIdentifier $message, array $add, array $remove): void
    {
        $this->modify($message->remoteId, $add, $remove);
    }

    public function client(): GmailClient
    {
        return $this->client;
    }

    /**
     * Permanent deletion needs the full mail.google.com scope and is not offered.
     */
    protected function deletePermanently(MessageIdentifier $message): void
    {
        throw UnsupportedOperation::for('delete permanently', ProviderCapability::PermanentDelete);
    }

    /**
     * @param  array<int, string>  $add
     * @param  array<int, string>  $remove
     */
    protected function modify(string $id, array $add, array $remove): void
    {
        if ($add === [] && $remove === []) {
            return;
        }

        $this->attempt('modify labels', fn () => $this->client->post('messages/'.rawurlencode($id).'/modify', [
            'addLabelIds' => array_values($add),
            'removeLabelIds' => array_values($remove),
        ]));
    }

    /**
     * Initial (or resync) listing of the messages of the last N days.
     */
    protected function initialChanges(SyncCursor $cursor, int $limit): MailboxChanges
    {
        // The history id is taken before listing, so changes during the listing are not lost.
        $historyId = $cursor->get('pending_history_id') ?? (string) ($this->client->get('profile')['historyId'] ?? '');
        $days = max(1, (int) ($this->mailbox->initial_sync_days ?: config('filament-mailbox.gmail.initial_sync_days', 90)));

        $list = $this->client->get('messages', array_filter([
            'q' => "newer_than:{$days}d",
            'includeSpamTrash' => 'true',
            'maxResults' => max(1, $limit),
            'pageToken' => $cursor->get('page_token'),
        ], fn ($value): bool => $value !== null));

        [$messages] = $this->states(array_map(fn (array $message): string => (string) $message['id'], $list['messages'] ?? []));
        $next = $list['nextPageToken'] ?? null;

        return new MailboxChanges(
            messages: $messages,
            deleted: [],
            cursor: new SyncCursor($next
                ? ['pending_history_id' => $historyId, 'page_token' => $next, 'resync' => $cursor->get('resync')]
                : ['history_id' => $historyId]),
            hasMore: $next !== null,
            reset: (bool) $cursor->get('resync'),
        );
    }

    /**
     * Current folder and flags of messages; ids that no longer exist are returned separately.
     *
     * @param  array<int, string>  $ids
     * @return array{array<string, array{folder: string, flags: MessageFlags}>, array<int, string>}
     */
    protected function states(array $ids): array
    {
        if ($ids === []) {
            return [[], []];
        }

        $states = $gone = [];
        $paths = array_combine($ids, array_map(fn (string $id): string => 'messages/'.rawurlencode($id).'?format=minimal', $ids));

        foreach ($this->client->batchGet($paths) as $id => $message) {
            if ($message === null) {
                $gone[] = (string) $id;

                continue;
            }

            $labels = $message['labelIds'] ?? [];
            $states[(string) $id] = ['folder' => GmailLabelMapper::folder($labels), 'flags' => GmailLabelMapper::flags($labels)];
        }

        return [$states, $gone];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function attempt(string $operation, Closure $callback, bool $connection = false): mixed
    {
        try {
            return $callback();
        } catch (ConnectionFailed|UnsupportedOperation $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $connection
                ? ConnectionFailed::for($this->mailbox, $exception)
                : ProviderOperationFailed::for($this->mailbox, $operation, $exception);
        }
    }
}
