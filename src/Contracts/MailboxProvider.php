<?php

namespace Cooolinho\FilamentMailbox\Contracts;

use Cooolinho\FilamentMailbox\Data\FolderChanges;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Data\SyncCursor;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Exceptions\ConnectionFailed;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;

/**
 * Provider-agnostic access to a remote mailbox.
 *
 * The application layer only talks to this contract, never to a concrete
 * protocol implementation. Extend AbstractMailboxProvider to get sensible
 * defaults for optional operations.
 */
interface MailboxProvider
{
    /**
     * @return array<int, ProviderCapability>
     */
    public function capabilities(): array;

    public function supports(ProviderCapability $capability): bool;

    /**
     * @throws ConnectionFailed
     */
    public function testConnection(): void;

    /**
     * @return iterable<FolderData>
     */
    public function folders(): iterable;

    /**
     * Fetch the next batch of changes since the given cursor.
     *
     * An empty cursor starts a full synchronisation of the folder.
     */
    public function changes(FolderIdentifier $folder, SyncCursor $cursor, int $limit): FolderChanges;

    /**
     * Load the complete messages for the given remote ids of a folder.
     * Used for FolderChanges::$includesNew; unknown ids are skipped.
     *
     * @param  array<int, string>  $remoteIds
     * @return array<int, MessageData>
     */
    public function fetchMessages(FolderIdentifier $folder, array $remoteIds): array;

    /**
     * Whether a locally stored identifier can no longer be addressed on the
     * server, e.g. because the IMAP UIDVALIDITY changed. Never connects.
     */
    public function isStale(MessageIdentifier $message, SyncCursor $cursor): bool;

    /**
     * @throws UnsupportedOperation
     */
    public function setFlags(MessageIdentifier $message, MessageFlags $add, MessageFlags $remove): void;

    public function markRead(MessageIdentifier $message): void;

    public function markUnread(MessageIdentifier $message): void;

    /**
     * Move the message and return its new identity, or null when the server
     * does not report it (the message then shows up with the next sync).
     *
     * @throws UnsupportedOperation
     */
    public function moveMessage(MessageIdentifier $message, FolderIdentifier $target): ?MessageIdentifier;

    /**
     * Move the message to the given trash folder, or delete it permanently
     * when no (other) trash folder is given.
     */
    public function delete(MessageIdentifier $message, ?FolderIdentifier $trash = null): void;

    /**
     * Store a raw MIME message in the folder.
     *
     * @throws UnsupportedOperation
     */
    public function appendMessage(FolderIdentifier $folder, string $rawMime, MessageFlags $flags = new MessageFlags): ?MessageIdentifier;

    /**
     * @throws UnsupportedOperation
     */
    public function rawMessage(MessageIdentifier $message): string;

    public function disconnect(): void;
}
