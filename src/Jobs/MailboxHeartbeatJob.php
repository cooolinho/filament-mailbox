<?php

namespace Cooolinho\FilamentMailbox\Jobs;

use Cooolinho\FilamentMailbox\Health\Checks\QueueHeartbeatCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Proves that a worker processes the sync queue.
 */
class MailboxHeartbeatJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onConnection(config('filament-mailbox.sync.queue_connection'));
        $this->onQueue(config('filament-mailbox.sync.queue'));
    }

    public function handle(): void
    {
        QueueHeartbeatCheck::beat();
    }
}
