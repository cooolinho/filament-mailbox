<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Cooolinho\FilamentMailbox\Services\InlineImageProcessor;
use Illuminate\Console\Command;
use Throwable;

class PruneComposeUploadsCommand extends Command
{
    protected $signature = 'mailbox:prune-compose-uploads
        {--hours= : Remove uploads older than this many hours (default: compose.prune_uploads_after_hours)}';

    protected $description = 'Remove images uploaded in the rich editor that were not sent';

    public function handle(InlineImageProcessor $images): int
    {
        $hours = max(1, (int) ($this->option('hours') ?? config('filament-mailbox.compose.prune_uploads_after_hours', 24)));
        $threshold = now()->subHours($hours)->getTimestamp();
        $disk = $images->disk();
        $count = 0;

        foreach ($disk->allFiles(InlineImageProcessor::directory('compose')) as $path) {
            try {
                if ($disk->lastModified($path) < $threshold && $disk->delete($path)) {
                    $count++;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->components->info("Removed {$count} compose uploads.");

        return self::SUCCESS;
    }
}
