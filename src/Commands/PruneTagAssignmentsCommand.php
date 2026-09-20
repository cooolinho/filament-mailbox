<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Cooolinho\FilamentMailbox\Services\TagService;
use Illuminate\Console\Command;

class PruneTagAssignmentsCommand extends Command
{
    protected $signature = 'mailbox:prune-tag-assignments
        {--days=30 : Keep assignments of messages deleted less than this many days ago}';

    protected $description = 'Remove tag assignments of messages that were deleted and not imported again';

    public function handle(TagService $tags): int
    {
        $count = $tags->prune(max(0, (int) $this->option('days')));

        $this->components->info("Removed {$count} tag assignments.");

        return self::SUCCESS;
    }
}
