<?php

namespace Cooolinho\FilamentMailbox\Jobs;

use Cooolinho\FilamentMailbox\Services\DraftService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Deletes the server versions of a sent or discarded draft.
 */
class DeleteDraftFromServerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  array<int, array{0: int, 1: string}>  $versions  Folder id and remote id
     */
    public function __construct(
        public int $mailboxId,
        public array $versions,
    ) {
        $this->onConnection(config('filament-mailbox.sync.queue_connection'));
        $this->onQueue(config('filament-mailbox.sync.queue'));
    }

    public function handle(DraftService $drafts): void
    {
        $drafts->deleteVersions($this->mailboxId, $this->versions);
    }
}
