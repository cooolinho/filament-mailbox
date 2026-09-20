<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\MailboxSyncRun;
use Illuminate\Foundation\Events\Dispatchable;

class SyncRunFinished
{
    use Dispatchable;

    public function __construct(
        public MailboxSyncRun $run,
    ) {}
}
