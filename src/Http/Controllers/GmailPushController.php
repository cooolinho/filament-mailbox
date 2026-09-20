<?php

namespace Cooolinho\FilamentMailbox\Http\Controllers;

use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Enums\SyncTrigger;
use Cooolinho\FilamentMailbox\Jobs\SyncMailboxJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Providers\Gmail\GmailWatchManager;
use Cooolinho\FilamentMailbox\Support\GoogleIdTokenVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Gmail push notifications from a Pub/Sub push subscription.
 *
 * The request must carry a valid Google OIDC token. The payload only names
 * the mailbox; the synchronisation reads the changes from the Gmail API.
 */
class GmailPushController
{
    public function __invoke(Request $request, GoogleIdTokenVerifier $verifier): Response
    {
        abort_unless(GmailWatchManager::enabled(), 404);

        $claims = $verifier->verify(
            (string) $request->bearerToken(),
            (string) config('filament-mailbox.gmail.push.audience'),
            config('filament-mailbox.gmail.push.service_account') ?: null,
        );

        abort_if($claims === null, 403);

        $payload = json_decode((string) base64_decode((string) $request->json('message.data'), true), true);
        $email = is_array($payload) && is_string($payload['emailAddress'] ?? null) ? $payload['emailAddress'] : null;

        // Acknowledge unknown mailboxes as well, otherwise Pub/Sub keeps retrying.
        if ($email) {
            Mailbox::query()
                ->active()
                ->where('provider', ProviderType::Gmail)
                ->where(fn ($query) => $query->where('email', $email)->orWhere('remote_user', $email))
                ->get()
                ->each(fn (Mailbox $mailbox) => SyncMailboxJob::dispatch($mailbox, SyncTrigger::Webhook));
        }

        return response('', 204);
    }
}
