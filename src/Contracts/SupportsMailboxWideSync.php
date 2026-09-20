<?php

namespace Cooolinho\FilamentMailbox\Contracts;

use Cooolinho\FilamentMailbox\Data\MailboxChanges;
use Cooolinho\FilamentMailbox\Data\SyncCursor;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;

/**
 * Providers with one change feed for all folders (ProviderCapability::MailboxWideSync).
 * SyncService then synchronises the mailbox at once instead of folder by folder;
 * messages keep one record and move between folders.
 */
interface SupportsMailboxWideSync
{
    /**
     * An empty cursor starts the initial synchronisation.
     */
    public function mailboxChanges(SyncCursor $cursor, int $limit): MailboxChanges;
}
