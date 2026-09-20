<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Data\SyncResult;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Illuminate\Foundation\Events\Dispatchable;

class MailboxSynced
{
    use Dispatchable;

    public function __construct(
        public Mailbox $mailbox,
        public SyncResult $result,
    ) {}
}
