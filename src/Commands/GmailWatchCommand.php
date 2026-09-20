<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Providers\Gmail\GmailWatchManager;
use Illuminate\Console\Command;
use Throwable;

class GmailWatchCommand extends Command
{
    protected $signature = 'mailbox:gmail-watch {--force : Renew every watch}';

    protected $description = 'Start or renew Gmail push notifications (users.watch)';

    public function handle(GmailWatchManager $watches): int
    {
        if (! GmailWatchManager::enabled()) {
            $this->components->warn('Gmail push is disabled (MAILBOX_GMAIL_PUSH / MAILBOX_GMAIL_PUBSUB_TOPIC).');

            return self::SUCCESS;
        }

        $failed = false;

        foreach (Mailbox::query()->active()->where('provider', ProviderType::Gmail)->get() as $mailbox) {
            try {
                $renewed = $watches->watch($mailbox, (bool) $this->option('force'));
                $this->components->info("Mailbox [{$mailbox->name}]: ".($renewed ? 'watch renewed.' : 'watch still valid.'));
            } catch (Throwable $exception) {
                report($exception);
                $failed = true;
                $this->components->error("Mailbox [{$mailbox->name}]: {$exception->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
