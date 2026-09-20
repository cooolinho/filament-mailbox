<?php

namespace Cooolinho\FilamentMailbox\Jobs;

use Cooolinho\FilamentMailbox\Models\MailboxDraft;
use Cooolinho\FilamentMailbox\Services\DraftService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Stores the current version of a draft in the drafts folder.
 *
 * Debounced: while a job for the draft waits, further saves do not queue
 * another one; the job always stores the latest state.
 */
class SaveDraftToServerJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public bool $deleteWhenMissingModels = true;

    public int $uniqueFor;

    public function __construct(
        public MailboxDraft $draft,
    ) {
        $this->onConnection(config('filament-mailbox.sync.queue_connection'));
        $this->onQueue(config('filament-mailbox.sync.queue'));
        $this->delay((int) config('filament-mailbox.drafts.server_sync_delay_seconds', 10));
        $this->uniqueFor = max(60, (int) config('filament-mailbox.drafts.server_sync_delay_seconds', 10) * 6);
    }

    public function uniqueId(): string
    {
        return (string) $this->draft->getKey();
    }

    public function handle(DraftService $drafts): void
    {
        $draft = $this->draft->fresh();

        if (! $draft || $draft->isSynced()) {
            return;
        }

        $drafts->pushToServer($draft);
    }
}
