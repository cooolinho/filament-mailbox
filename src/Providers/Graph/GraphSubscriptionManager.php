<?php

namespace Cooolinho\FilamentMailbox\Providers\Graph;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxGraphSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Creates and renews change notification subscriptions per folder.
 */
class GraphSubscriptionManager
{
    public function __construct(
        protected MailboxProviderFactory $providers,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('filament-mailbox.graph.webhooks.enabled') && filled(config('filament-mailbox.graph.webhooks.notification_url'));
    }

    /**
     * Subscribe all active folders of a Graph mailbox and renew subscriptions
     * that expire within the next 12 hours.
     *
     * @return array{created: int, renewed: int, failed: int}
     */
    public function sync(Mailbox $mailbox): array
    {
        $result = ['created' => 0, 'renewed' => 0, 'failed' => 0];

        if (! static::enabled() || $mailbox->provider !== ProviderType::Graph || ! $mailbox->is_active) {
            return $result;
        }

        $provider = $this->providers->make($mailbox);

        if (! $provider instanceof GraphProvider) {
            return $result;
        }

        $client = $provider->client();

        foreach ($mailbox->folders()->where('is_active', true)->get() as $folder) {
            try {
                $subscription = MailboxGraphSubscription::query()->where('folder_id', $folder->getKey())->first();

                if (! $subscription) {
                    $this->create($client, $folder);
                    $result['created']++;
                } elseif ($subscription->expires_at->isBefore(now()->addHours(12))) {
                    $this->renew($client, $subscription, $folder);
                    $result['renewed']++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $result['failed']++;
            }
        }

        // Folders that were removed or deactivated.
        MailboxGraphSubscription::query()
            ->whereHas('folder', fn ($query) => $query->where('mailbox_id', $mailbox->getKey())->where('is_active', false))
            ->get()
            ->each(fn (MailboxGraphSubscription $subscription) => $this->delete($client, $subscription));

        $provider->disconnect();

        return $result;
    }

    public function create(GraphClient $client, MailboxFolder $folder): MailboxGraphSubscription
    {
        $clientState = Str::random(64);
        $expires = $this->expiration();

        $response = $client->post('~/subscriptions', [
            'changeType' => 'created,updated,deleted',
            'notificationUrl' => (string) config('filament-mailbox.graph.webhooks.notification_url'),
            'resource' => $client->mailboxPath()."/mailFolders('{$folder->remote_id}')/messages",
            'expirationDateTime' => $expires->toIso8601ZuluString(),
            'clientState' => $clientState,
        ]);

        if (! isset($response['id'])) {
            throw new RuntimeException('Graph did not return a subscription id.');
        }

        return MailboxGraphSubscription::query()->create([
            'folder_id' => $folder->getKey(),
            'subscription_id' => (string) $response['id'],
            'client_state' => $clientState,
            'expires_at' => $expires,
        ]);
    }

    public function renew(GraphClient $client, MailboxGraphSubscription $subscription, MailboxFolder $folder): void
    {
        $expires = $this->expiration();

        try {
            $client->patch('~/subscriptions/'.rawurlencode($subscription->subscription_id), [
                'expirationDateTime' => $expires->toIso8601ZuluString(),
            ]);
        } catch (GraphRequestFailed $exception) {
            if (! $exception->isNotFound()) {
                throw $exception;
            }

            // Expired on the Graph side: subscribe again.
            $subscription->delete();
            $this->create($client, $folder);

            return;
        }

        $subscription->forceFill(['expires_at' => $expires])->save();
    }

    public function delete(GraphClient $client, MailboxGraphSubscription $subscription): void
    {
        rescue(fn () => $client->delete('~/subscriptions/'.rawurlencode($subscription->subscription_id)), report: false);

        $subscription->delete();
    }

    protected function expiration(): Carbon
    {
        // Mail subscriptions live at most 10080 minutes; 4230 is the documented safe default.
        return now()->addMinutes(min(10080, max(60, (int) config('filament-mailbox.graph.webhooks.lifetime_minutes', 4200))));
    }
}
