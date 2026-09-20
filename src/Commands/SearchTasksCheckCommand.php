<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Cooolinho\FilamentMailbox\Search\Meilisearch\MeilisearchSearchEngine;
use Cooolinho\FilamentMailbox\Search\SearchManager;
use Illuminate\Console\Command;

class SearchTasksCheckCommand extends Command
{
    protected $signature = 'mailbox:search-tasks-check';

    protected $description = 'Check open Meilisearch indexing tasks and repeat failed batches';

    public function handle(SearchManager $search): int
    {
        $engine = $search->engine();

        if (! $engine instanceof MeilisearchSearchEngine) {
            $this->components->info('The configured search engine has no asynchronous tasks.');

            return self::SUCCESS;
        }

        ['checked' => $checked, 'failed' => $failed] = $engine->checkTasks();

        $this->components->info("Checked {$checked} indexing tasks, {$failed} failed.");

        return self::SUCCESS;
    }
}
