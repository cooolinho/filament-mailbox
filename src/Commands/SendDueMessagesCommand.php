<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Cooolinho\FilamentMailbox\Services\OutboxService;
use Illuminate\Console\Command;

class SendDueMessagesCommand extends Command
{
    protected $signature = 'mailbox:send-due';

    protected $description = 'Queue scheduled messages of the outbox that are due and fail messages hanging in "sending"';

    public function handle(OutboxService $outbox): int
    {
        $count = $outbox->dispatchDue();

        $this->components->info("Queued {$count} due messages.");

        return self::SUCCESS;
    }
}
