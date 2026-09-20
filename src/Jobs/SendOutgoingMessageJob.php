<?php

namespace Cooolinho\FilamentMailbox\Jobs;

use Cooolinho\FilamentMailbox\Models\MailboxOutgoingMessage;
use Cooolinho\FilamentMailbox\Services\OutboxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends a message of the outbox when it is due. Retries are handled by the
 * outbox (status and backoff), duplicates of this job do nothing.
 */
class SendOutgoingMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $outgoingMessageId,
    ) {
        $this->onConnection(config('filament-mailbox.outbox.queue_connection') ?? config('filament-mailbox.sync.queue_connection'));
        $this->onQueue(config('filament-mailbox.outbox.queue') ?? config('filament-mailbox.sync.queue'));
    }

    public function handle(OutboxService $outbox): void
    {
        if ($message = MailboxOutgoingMessage::query()->find($this->outgoingMessageId)) {
            $outbox->deliver($message);
        }
    }
}
