<?php

namespace Cooolinho\FilamentMailbox\Tests\Fixtures;

use Cooolinho\FilamentMailbox\Contracts\ManagesFolders;
use Cooolinho\FilamentMailbox\Contracts\SupportsLabels;
use Cooolinho\FilamentMailbox\Data\FolderChanges;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Data\FolderStatistics;
use Cooolinho\FilamentMailbox\Data\LabelData;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Data\SyncCursor;
use Cooolinho\FilamentMailbox\Enums\LabelColor;
use Cooolinho\FilamentMailbox\Enums\LabelSource;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;
use Cooolinho\FilamentMailbox\Providers\AbstractMailboxProvider;
use Cooolinho\FilamentMailbox\Providers\Imap\ImapProvider;
use Cooolinho\FilamentMailbox\Support\FolderPath;
use Cooolinho\FilamentMailbox\Support\ImapKeyword;
use DirectoryTree\ImapEngine\FileMessage;
use RuntimeException;
use Throwable;

/**
 * In-memory mailbox provider for tests. It mimics the IMAP behaviour:
 * messages are addressed by "<uidvalidity>:<uid>" and synchronised by UID.
 */
class FakeMailboxProvider extends AbstractMailboxProvider implements ManagesFolders, SupportsLabels
{
    /** @var array<string, FolderData> */
    public array $folders = [];

    /** @var array<string, int> */
    public array $uidValidity = [];

    /** @var array<string, array<int, MessageData>> Messages per folder, keyed by UID */
    public array $messages = [];

    /** @var array<int, array{string, MessageIdentifier, mixed}> */
    public array $calls = [];

    /** @var array<int, ProviderCapability>|null */
    public ?array $capabilities = null;

    /** @var array<int, string> Keywords defined on the server besides those used by messages */
    public array $keywords = [];

    public bool $allowNewKeywords = true;

    public ?Throwable $connectionException = null;

    public bool $disconnected = false;

    /** @var array<int, string> Remote ids of subscribed folders; null entries are not tracked */
    public array $subscribed = [];

    public string $delimiter = '/';

    /** @var array<string, array<int, string>> Raw MIME of appended messages per folder, keyed by UID */
    public array $raw = [];

    public function addFolder(FolderData $folder, int $uidValidity = 1): static
    {
        $this->folders[$folder->remoteId] = $folder;
        $this->uidValidity[$folder->remoteId] = $uidValidity;

        return $this;
    }

    /**
     * @param  MessageData|int  $message  A message whose remote id is a UID, or a UID for a generated message
     */
    public function addMessage(string $folder, MessageData|int $message): static
    {
        $message = is_int($message) ? new MessageData((string) $message, subject: "Message {$message}") : $message;
        $uid = (int) $message->remoteId;

        $this->messages[$folder][$uid] = $message;
        ksort($this->messages[$folder]);

        return $this;
    }

    public function setSeen(string $folder, int $uid, bool $seen): static
    {
        return $this->updateFlags($folder, $uid, fn (MessageFlags $flags) => $flags->with(['seen' => $seen]));
    }

    public function removeFolder(string $remoteId): static
    {
        unset($this->folders[$remoteId], $this->uidValidity[$remoteId]);

        return $this;
    }

    public function capabilities(): array
    {
        return $this->capabilities ?? [
            ProviderCapability::Folders,
            ProviderCapability::FolderManagement,
            ProviderCapability::FolderSubscriptions,
            ProviderCapability::MoveMessages,
            ProviderCapability::Flags,
            ProviderCapability::Flagged,
            ProviderCapability::Keywords,
            ProviderCapability::AppendMessages,
            ProviderCapability::PermanentDelete,
        ];
    }

    public function testConnection(): void
    {
        if ($this->connectionException) {
            throw $this->connectionException;
        }
    }

    public function folders(): iterable
    {
        $this->testConnection();

        return array_values($this->folders);
    }

    public function changes(FolderIdentifier $folder, SyncCursor $cursor, int $limit): FolderChanges
    {
        $uidValidity = $this->folderUidValidity($folder);
        $known = $cursor->get('uid_validity');
        $reset = $known !== null && (int) $known !== $uidValidity;
        $lastUid = $reset ? 0 : (int) $cursor->get('last_uid', 0);

        $new = array_slice(array_filter(
            $this->messages[$folder->remoteId] ?? [],
            fn (int $uid): bool => $uid > $lastUid,
            ARRAY_FILTER_USE_KEY,
        ), 0, $limit, preserve_keys: true);

        $hasMore = count($new) >= $limit;

        return new FolderChanges(
            created: array_values(array_map(
                fn (MessageData $message, int $uid) => $message->with(['remoteId' => ImapProvider::remoteId($uidValidity, $uid)]),
                $new,
                array_keys($new),
            )),
            updated: $hasMore ? [] : collect($this->messages[$folder->remoteId] ?? [])
                ->mapWithKeys(fn (MessageData $message, int $uid): array => [ImapProvider::remoteId($uidValidity, $uid) => $message->flags])
                ->all(),
            deleted: [],
            cursor: $cursor->with(['uid_validity' => $uidValidity, 'last_uid' => max($lastUid, (int) max([0, ...array_keys($new)]))]),
            hasMore: $hasMore,
            reset: $reset,
            snapshot: ! $hasMore,
        );
    }

    public function isStale(MessageIdentifier $message, SyncCursor $cursor): bool
    {
        [$uidValidity] = ImapProvider::parseRemoteId($message->remoteId);

        return $cursor->get('uid_validity') !== null && (int) $cursor->get('uid_validity') !== $uidValidity;
    }

    public function setFlags(MessageIdentifier $message, MessageFlags $add, MessageFlags $remove): void
    {
        $this->calls[] = ['setFlags', $message, [$add, $remove]];

        [, $uid] = ImapProvider::parseRemoteId($message->remoteId);

        if (isset($this->messages[$message->folder->remoteId][$uid])) {
            $this->updateFlags($message->folder->remoteId, $uid, fn (MessageFlags $flags) => $flags->apply($add, $remove));
        }
    }

    public function markRead(MessageIdentifier $message): void
    {
        $this->record('markRead', $message, null, fn () => parent::markRead($message));
    }

    public function markUnread(MessageIdentifier $message): void
    {
        $this->record('markUnread', $message, null, fn () => parent::markUnread($message));
    }

    public function moveMessage(MessageIdentifier $message, FolderIdentifier $target): ?MessageIdentifier
    {
        $this->calls[] = ['moveMessage', $message, $target];

        [, $uid] = ImapProvider::parseRemoteId($message->remoteId);
        $data = $this->messages[$message->folder->remoteId][$uid] ?? null;

        if (! $data) {
            return null;
        }

        unset($this->messages[$message->folder->remoteId][$uid]);

        $newUid = $this->nextUid($target->remoteId);
        $this->addMessage($target->remoteId, $data->with(['remoteId' => (string) $newUid]));

        return new MessageIdentifier($target, ImapProvider::remoteId($this->folderUidValidity($target), $newUid));
    }

    public function delete(MessageIdentifier $message, ?FolderIdentifier $trash = null): void
    {
        $this->record('delete', $message, $trash, fn () => parent::delete($message, $trash));
    }

    public function appendMessage(FolderIdentifier $folder, string $rawMime, MessageFlags $flags = new MessageFlags): ?MessageIdentifier
    {
        $uid = $this->nextUid($folder->remoteId);
        $mime = new FileMessage($rawMime);
        $this->addMessage($folder->remoteId, new MessageData((string) $uid, messageId: $mime->messageId(), subject: $mime->subject(), textBody: $rawMime, flags: $flags));
        $this->raw[$folder->remoteId][$uid] = $rawMime;

        return new MessageIdentifier($folder, ImapProvider::remoteId($this->folderUidValidity($folder), $uid));
    }

    public function rawMessage(MessageIdentifier $message): string
    {
        [, $uid] = ImapProvider::parseRemoteId($message->remoteId);

        return $this->messages[$message->folder->remoteId][$uid]->textBody
            ?? throw new RuntimeException('Message not found.');
    }

    public function identifiesFoldersByPath(): bool
    {
        return true;
    }

    public function createFolder(string $name, ?FolderIdentifier $parent): FolderData
    {
        $this->calls[] = ['createFolder', null, [$name, $parent?->remoteId]];

        $path = FolderPath::imap($name, $parent?->remoteId, $this->delimiter);

        if (isset($this->folders[$path]) || ($parent && ! isset($this->folders[$parent->remoteId]))) {
            throw new RuntimeException("Cannot create folder [{$path}].");
        }

        $this->addFolder($folder = new FolderData($name, $path, $this->delimiter, parentRemoteId: $parent?->remoteId));
        $this->subscribed[] = $path;

        return $folder;
    }

    public function renameFolder(FolderIdentifier $folder, string $newName, ?FolderIdentifier $newParent): FolderData
    {
        $this->calls[] = ['renameFolder', null, [$folder->remoteId, $newName, $newParent?->remoteId]];

        $path = FolderPath::imap($newName, $newParent?->remoteId, $this->delimiter);

        foreach ($this->folders as $remoteId => $data) {
            if (! FolderPath::isWithin($remoteId, $folder->remoteId, $this->delimiter)) {
                continue;
            }

            $newPath = FolderPath::replacePrefix($remoteId, $folder->remoteId, $path, $this->delimiter);
            $parent = $data->parentRemoteId === null ? null : FolderPath::replacePrefix($data->parentRemoteId, $folder->remoteId, $path, $this->delimiter);

            unset($this->folders[$remoteId]);
            $this->folders[$newPath] = new FolderData(
                $remoteId === $folder->remoteId ? $newName : $data->name,
                $newPath,
                $this->delimiter,
                $data->specialUse,
                $remoteId === $folder->remoteId ? $newParent?->remoteId : $parent,
            );
            $this->uidValidity[$newPath] = $this->uidValidity[$remoteId];
            $this->messages[$newPath] = $this->messages[$remoteId] ?? [];
            unset($this->uidValidity[$remoteId], $this->messages[$remoteId]);
        }

        return $this->folders[$path];
    }

    public function deleteFolder(FolderIdentifier $folder): void
    {
        $this->calls[] = ['deleteFolder', null, $folder->remoteId];

        unset($this->folders[$folder->remoteId], $this->uidValidity[$folder->remoteId], $this->messages[$folder->remoteId]);
    }

    public function subscribeFolder(FolderIdentifier $folder, bool $subscribed): void
    {
        $this->calls[] = ['subscribeFolder', null, [$folder->remoteId, $subscribed]];

        $this->subscribed = array_values(array_diff($this->subscribed, [$folder->remoteId]));

        if ($subscribed) {
            $this->subscribed[] = $folder->remoteId;
        }
    }

    public function subscribedFolders(): ?array
    {
        return $this->subscribed;
    }

    public function folderStatistics(FolderIdentifier $folder): FolderStatistics
    {
        $messages = $this->messages[$folder->remoteId] ?? [];

        return new FolderStatistics(
            count($messages),
            count(array_filter($messages, fn (MessageData $message): bool => ! $message->flags->seen)),
            array_sum(array_map(fn (MessageData $message): int => strlen((string) $message->textBody), $messages)),
        );
    }

    public function labelSource(): LabelSource
    {
        return LabelSource::ImapKeyword;
    }

    public function labels(): array
    {
        $used = collect($this->messages)->flatten(1)->flatMap(fn (MessageData $message): array => $message->flags->keywords);

        return $used->merge($this->keywords)
            ->unique()
            ->values()
            ->map(fn (string $keyword): LabelData => new LabelData(LabelSource::ImapKeyword, $keyword, ImapKeyword::toName($keyword)))
            ->all();
    }

    public function createLabel(string $name, ?LabelColor $color = null): LabelData
    {
        if (! $this->allowNewKeywords) {
            throw UnsupportedOperation::for('create keyword', ProviderCapability::Keywords);
        }

        $keyword = ImapKeyword::fromName($name);
        $this->keywords[] = $keyword;

        return new LabelData(LabelSource::ImapKeyword, $keyword, $name, $color);
    }

    public function renameLabel(LabelData $label, string $name, ?LabelColor $color = null): LabelData
    {
        return new LabelData($label->source, $label->remoteKey, $name, $color);
    }

    public function deleteLabel(LabelData $label): void
    {
        $this->keywords = array_values(array_diff($this->keywords, [$label->remoteKey]));
    }

    public function changeLabels(MessageIdentifier $message, array $add, array $remove): void
    {
        $this->record('changeLabels', $message, [$add, $remove], fn () => $this->setFlags($message, new MessageFlags(keywords: $add), new MessageFlags(keywords: $remove)));
    }

    public function disconnect(): void
    {
        $this->disconnected = true;
    }

    protected function deletePermanently(MessageIdentifier $message): void
    {
        [, $uid] = ImapProvider::parseRemoteId($message->remoteId);

        unset($this->messages[$message->folder->remoteId][$uid]);
    }

    protected function folderUidValidity(FolderIdentifier $folder): int
    {
        if (! isset($this->folders[$folder->remoteId])) {
            throw new RuntimeException("Unknown folder [{$folder->remoteId}].");
        }

        return $this->uidValidity[$folder->remoteId];
    }

    /** @var array<string, int> */
    protected array $lastUids = [];

    /**
     * UIDs are never reused, like on IMAP servers.
     */
    protected function nextUid(string $folder): int
    {
        return $this->lastUids[$folder] = max($this->lastUids[$folder] ?? 0, (int) max([0, ...array_keys($this->messages[$folder] ?? [])])) + 1;
    }

    /**
     * Record a call without the calls it delegates to.
     */
    protected function record(string $operation, MessageIdentifier $message, mixed $argument, callable $callback): void
    {
        $count = count($this->calls);

        $callback();

        array_splice($this->calls, $count);
        $this->calls[] = [$operation, $message, $argument];
    }

    /**
     * @param  callable(MessageFlags): MessageFlags  $callback
     */
    protected function updateFlags(string $folder, int $uid, callable $callback): static
    {
        $message = $this->messages[$folder][$uid];

        $this->messages[$folder][$uid] = $message->with(['flags' => $callback($message->flags)]);

        return $this;
    }
}
