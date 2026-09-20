<?php

namespace Cooolinho\FilamentMailbox\Http\Controllers;

use Cooolinho\FilamentMailbox\Jobs\SyncMailboxFolderJob;
use Cooolinho\FilamentMailbox\Models\MailboxGraphSubscription;
use Cooolinho\FilamentMailbox\Providers\Graph\GraphSubscriptionManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Microsoft Graph change notifications.
 *
 * Nothing from the payload is trusted beyond the subscription id and its
 * client state; a notification only triggers a folder synchronisation.
 */
class GraphWebhookController
{
    public function __invoke(Request $request): Response
    {
        abort_unless(GraphSubscriptionManager::enabled(), 404);

        // Validation handshake when the subscription is created.
        if ($request->query->has('validationToken')) {
            return response((string) $request->query('validationToken'), 200, ['Content-Type' => 'text/plain']);
        }

        $notifications = $request->json('value');

        abort_unless(is_array($notifications), 400);

        $folders = [];

        foreach ($notifications as $notification) {
            $subscription = is_array($notification) && is_string($notification['subscriptionId'] ?? null)
                ? MailboxGraphSubscription::query()->where('subscription_id', $notification['subscriptionId'])->first()
                : null;

            if (! $subscription || ! is_string($notification['clientState'] ?? null) || ! hash_equals($subscription->client_state, $notification['clientState'])) {
                abort(403);
            }

            $folders[$subscription->folder_id] = $subscription;
        }

        foreach ($folders as $subscription) {
            if ($subscription->folder) {
                SyncMailboxFolderJob::dispatch($subscription->folder);
            }
        }

        return response('', 202);
    }
}
