<?php

namespace Cooolinho\FilamentMailbox\Providers;

use Cooolinho\FilamentMailbox\Contracts\MailboxProvider;
use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Data\SyncCursor;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;

abstract class AbstractMailboxProvider implements MailboxProvider
{
    public function supports(ProviderCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function fetchMessages(FolderIdentifier $folder, array $remoteIds): array
    {
        return [];
    }

    public function isStale(MessageIdentifier $message, SyncCursor $cursor): bool
    {
        return false;
    }

    public function setFlags(MessageIdentifier $message, MessageFlags $add, MessageFlags $remove): void
    {
        throw UnsupportedOperation::for('set flags', ProviderCapability::Flags);
    }

    public function markRead(MessageIdentifier $message): void
    {
        $this->setFlags($message, new MessageFlags(seen: true), new MessageFlags);
    }

    public function markUnread(MessageIdentifier $message): void
    {
        $this->setFlags($message, new MessageFlags, new MessageFlags(seen: true));
    }

    public function moveMessage(MessageIdentifier $message, FolderIdentifier $target): ?MessageIdentifier
    {
        throw UnsupportedOperation::for('move message', ProviderCapability::MoveMessages);
    }

    public function delete(MessageIdentifier $message, ?FolderIdentifier $trash = null): void
    {
        if ($trash && ! $trash->is($message->folder)) {
            $this->moveMessage($message, $trash);

            return;
        }

        $this->deletePermanently($message);
    }

    public function appendMessage(FolderIdentifier $folder, string $rawMime, MessageFlags $flags = new MessageFlags): ?MessageIdentifier
    {
        throw UnsupportedOperation::for('append message', ProviderCapability::AppendMessages);
    }

    public function rawMessage(MessageIdentifier $message): string
    {
        throw UnsupportedOperation::for('raw message');
    }

    public function disconnect(): void
    {
        //
    }

    abstract protected function deletePermanently(MessageIdentifier $message): void;
}
