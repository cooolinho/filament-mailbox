<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Cooolinho\FilamentMailbox\Jobs\IndexMessagesJob;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Search\Contracts\SupportsReindex;
use Cooolinho\FilamentMailbox\Search\SearchManager;
use Illuminate\Console\Command;

class SearchReindexCommand extends Command
{
    protected $signature = 'mailbox:search-reindex
        {mailbox?* : IDs of the mailboxes (default: all)}
        {--chunk=500 : Messages per batch}
        {--now : Index in this process instead of dispatching queue jobs}
        {--swap : Build a fresh index and switch to it at the end (Meilisearch, needs --now)}';

    protected $description = 'Write all messages to the index of the configured search engine';

    public function handle(SearchManager $search): int
    {
        if (! $search->indexes()) {
            $this->components->info('The "'.$search->engine()->name().'" search engine needs no index.');

            return self::SUCCESS;
        }

        $chunk = max(1, min(5000, (int) $this->option('chunk')));
        $batches = 0;
        $engine = $search->engine();
        $swap = (bool) $this->option('swap') && $engine instanceof SupportsReindex;

        if ($swap && ! $this->option('now')) {
            $this->components->error('--swap needs --now, so the index is only switched after everything was written.');

            return self::FAILURE;
        }

        if ($swap) {
            $engine->beginReindex();
        }

        MailboxMessage::query()
            ->when($this->argument('mailbox') !== [], fn ($query) => $query->whereIn('mailbox_id', $this->argument('mailbox')))
            ->select('id')
            ->chunkById($chunk, function ($messages) use (&$batches): void {
                $job = new IndexMessagesJob($messages->modelKeys());
                $this->option('now') ? app()->call([$job, 'handle']) : dispatch($job);
                $batches++;
            });

        if ($swap) {
            $engine->finishReindex();
        }

        $this->components->info(($this->option('now') ? 'Indexed' : 'Queued')." {$batches} batches for the \"{$engine->name()}\" search engine.");

        return self::SUCCESS;
    }
}
