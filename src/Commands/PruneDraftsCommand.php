<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Cooolinho\FilamentMailbox\Models\MailboxDraft;
use Cooolinho\FilamentMailbox\Services\DraftService;
use Illuminate\Console\Command;

class PruneDraftsCommand extends Command
{
    protected $signature = 'mailbox:prune-drafts
        {--days= : Discard drafts not changed for this many days (default: drafts.prune_after_days)}';

    protected $description = 'Discard drafts that were not changed for a long time, including their server versions';

    public function handle(DraftService $drafts): int
    {
        $days = $this->option('days') ?? config('filament-mailbox.drafts.prune_after_days');

        if ($days === null || (int) $days < 1) {
            $this->components->info('Pruning drafts is disabled.');

            return self::SUCCESS;
        }

        $count = 0;

        MailboxDraft::query()
            ->with('mailbox')
            ->where('updated_at', '<', now()->subDays((int) $days))
            ->chunkById(100, function ($stale) use ($drafts, &$count): void {
                foreach ($stale as $draft) {
                    $drafts->discard($draft);
                    $count++;
                }
            });

        $this->components->info("Discarded {$count} drafts.");

        return self::SUCCESS;
    }
}
