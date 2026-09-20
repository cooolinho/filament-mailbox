<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Cooolinho\FilamentMailbox\Jobs\WakeSnoozedMessagesJob;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\SnoozeService;
use Illuminate\Console\Command;

class WakeSnoozedMessagesCommand extends Command
{
    protected $signature = 'mailbox:wake-snoozed
        {--now : Wake the messages in this process instead of dispatching queue jobs}';

    protected $description = 'Let snoozed messages whose time has come return to their folders';

    public function handle(SnoozeService $snooze): int
    {
        if (! SnoozeService::enabled()) {
            $this->components->info('Snooze is disabled.');

            return self::SUCCESS;
        }

        $count = 0;

        MailboxMessage::query()
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now())
            ->select('id')
            ->chunkById(100, function ($messages) use ($snooze, &$count): void {
                $ids = $messages->modelKeys();

                if ($this->option('now')) {
                    $count += $snooze->wake($ids);

                    return;
                }

                WakeSnoozedMessagesJob::dispatch($ids);
                $count += count($ids);
            });

        $this->components->info($this->option('now') ? "Woke {$count} snoozed messages." : "Queued {$count} snoozed messages.");

        return self::SUCCESS;
    }
}
