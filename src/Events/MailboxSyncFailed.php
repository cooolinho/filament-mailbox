<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Illuminate\Foundation\Events\Dispatchable;

class MailboxSyncFailed
{
    use Dispatchable;

    public function __construct(
        public Mailbox $mailbox,
        public string $error,
    ) {}
}
