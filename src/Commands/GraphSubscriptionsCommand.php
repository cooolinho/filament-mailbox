<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Providers\Graph\GraphSubscriptionManager;
use Illuminate\Console\Command;

class GraphSubscriptionsCommand extends Command
{
    protected $signature = 'mailbox:graph-subscriptions';

    protected $description = 'Create and renew Microsoft Graph change notification subscriptions';

    public function handle(GraphSubscriptionManager $subscriptions): int
    {
        if (! GraphSubscriptionManager::enabled()) {
            $this->components->warn('Graph webhooks are disabled (MAILBOX_GRAPH_WEBHOOKS / MAILBOX_GRAPH_NOTIFICATION_URL).');

            return self::SUCCESS;
        }

        $failed = false;

        foreach (Mailbox::query()->active()->where('provider', ProviderType::Graph)->get() as $mailbox) {
            $result = $subscriptions->sync($mailbox);
            $failed = $failed || $result['failed'] > 0;

            $this->components->info("Mailbox [{$mailbox->name}]: {$result['created']} created, {$result['renewed']} renewed, {$result['failed']} failed.");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
