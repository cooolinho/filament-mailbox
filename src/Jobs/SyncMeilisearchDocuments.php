<?php

namespace Cooolinho\FilamentMailbox\Jobs;

use Cooolinho\FilamentMailbox\Search\Meilisearch\MeilisearchSearchEngine;
use Cooolinho\FilamentMailbox\Search\SearchManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends a batch of messages to Meilisearch (adding or deleting).
 */
class SyncMeilisearchDocuments implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  array<int, int>  $messageIds
     */
    public function __construct(
        public array $messageIds,
        public bool $delete = false,
        public ?string $index = null,
    ) {
        $this->onConnection(config('filament-mailbox.search.queue_connection') ?? config('filament-mailbox.sync.queue_connection'));
        $this->onQueue(config('filament-mailbox.search.queue') ?? config('filament-mailbox.sync.queue'));
    }

    public function handle(SearchManager $search): void
    {
        $engine = $search->engine();

        if (! $engine instanceof MeilisearchSearchEngine) {
            return;
        }

        $this->delete
            ? $engine->sendDeletions($this->messageIds, $this->index)
            : $engine->sendDocuments($this->messageIds, $this->index);
    }
}
