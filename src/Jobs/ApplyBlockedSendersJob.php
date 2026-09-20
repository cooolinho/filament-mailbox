<?php

namespace Cooolinho\FilamentMailbox\Jobs;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\BlockedSenderMatcher;
use Cooolinho\FilamentMailbox\Services\MessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Moves newly imported inbox messages of blocked senders to spam.
 */
class ApplyBlockedSendersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  array<int, int>  $messageIds
     */
    public function __construct(
        public Mailbox $mailbox,
        public array $messageIds,
    ) {
        $this->onConnection(config('filament-mailbox.sync.queue_connection'));
        $this->onQueue(config('filament-mailbox.sync.queue'));
    }

    public function handle(BlockedSenderMatcher $matcher, MessageService $messages): void
    {
        $patterns = $this->mailbox->blockedSenders()->pluck('pattern');

        if (! config('filament-mailbox.spam.block_senders', true) || $patterns->isEmpty()) {
            return;
        }

        // Only messages that are still in the inbox; users may have moved them meanwhile.
        $blocked = $this->mailbox->messages()
            ->with(['mailbox', 'folder'])
            ->whereKey($this->messageIds)
            ->whereHas('folder', fn ($folders) => $folders->where('special_use', SpecialUse::Inbox))
            ->get()
            ->filter(fn (MailboxMessage $message): bool => $matcher->matches($message->from_address, $patterns));

        if ($blocked->isNotEmpty()) {
            $messages->markAsSpam($blocked);
        }
    }
}
