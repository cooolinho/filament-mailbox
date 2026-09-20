<?php

namespace Cooolinho\FilamentMailbox\Jobs;

use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Search\SearchManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Writes a batch of messages to the search index (reindex command).
 */
class IndexMessagesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<int, int>  $messageIds
     */
    public function __construct(
        public array $messageIds,
    ) {
        $this->onConnection(config('filament-mailbox.search.queue_connection') ?? config('filament-mailbox.sync.queue_connection'));
        $this->onQueue(config('filament-mailbox.search.queue') ?? config('filament-mailbox.sync.queue'));
    }

    public function handle(SearchManager $search): void
    {
        $engine = $search->engine();
        $messages = MailboxMessage::withTrashed()->with(['mailbox', 'folder'])->whereKey($this->messageIds)->get();

        foreach ($messages as $message) {
            $message->trashed() ? $engine->remove([(int) $message->getKey()]) : $engine->index($message);
        }

        // Messages deleted meanwhile.
        $engine->remove(array_values(array_diff($this->messageIds, $messages->modelKeys())));
    }
}
