<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Cooolinho\FilamentMailbox\Services\OutboxService;
use Illuminate\Console\Command;

class PruneOutboxCommand extends Command
{
    protected $signature = 'mailbox:prune-outbox
        {--days= : Remove sent, failed and cancelled messages older than this many days (default: outbox.keep_days)}';

    protected $description = 'Remove old sent, failed and cancelled messages from the outbox';

    public function handle(OutboxService $outbox): int
    {
        $days = (int) ($this->option('days') ?? config('filament-mailbox.outbox.keep_days', 30));

        if ($days < 1) {
            $this->components->info('Pruning the outbox is disabled.');

            return self::SUCCESS;
        }

        $this->components->info('Removed '.$outbox->prune($days).' outbox messages.');

        return self::SUCCESS;
    }
}
