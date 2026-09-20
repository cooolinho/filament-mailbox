<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Cooolinho\FilamentMailbox\WebPush\VapidKeys;
use Illuminate\Console\Command;

class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'mailbox:vapid-keys';

    protected $description = 'Generate VAPID keys for web push notifications';

    public function handle(): int
    {
        $keys = VapidKeys::generate();

        $this->components->info('Add these lines to your .env file (keep the private key secret):');
        $this->line('MAILBOX_WEB_PUSH=true');
        $this->line('MAILBOX_VAPID_PUBLIC_KEY='.$keys['public']);
        $this->line('MAILBOX_VAPID_PRIVATE_KEY='.$keys['private']);
        $this->line('MAILBOX_VAPID_SUBJECT=mailto:admin@example.com');

        return self::SUCCESS;
    }
}
