<?php

namespace Cooolinho\FilamentMailbox\Jobs;

use Cooolinho\FilamentMailbox\Services\SnoozeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Lets snoozed messages return. Idempotent: messages woken by another run are skipped.
 */
class WakeSnoozedMessagesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  array<int, int>  $messageIds
     */
    public function __construct(
        public array $messageIds,
    ) {
        $this->onConnection(config('filament-mailbox.sync.queue_connection'));
        $this->onQueue(config('filament-mailbox.sync.queue'));
    }

    public function handle(SnoozeService $snooze): void
    {
        $snooze->wake($this->messageIds);
    }
}
