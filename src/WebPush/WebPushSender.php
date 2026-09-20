<?php

namespace Cooolinho\FilamentMailbox\WebPush;

use Cooolinho\FilamentMailbox\Models\MailboxPushSubscription;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends payload-less web pushes: the service worker fetches the notifications
 * of the signed-in user itself, so no mail data passes the push services.
 */
class WebPushSender
{
    public static function enabled(): bool
    {
        $config = (array) config('filament-mailbox.notifications.web_push', []);

        return (bool) ($config['enabled'] ?? false)
            && filled($config['public_key'] ?? null)
            && filled($config['private_key'] ?? null)
            && filled($config['subject'] ?? null);
    }

    /**
     * Only endpoints of known push services are accepted (the server sends requests to them).
     */
    public static function isAllowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        if (($parts['scheme'] ?? null) !== 'https' || blank($parts['host'] ?? null) || strlen($endpoint) > 2048) {
            return false;
        }

        foreach ((array) config('filament-mailbox.notifications.web_push.allowed_hosts', []) as $pattern) {
            if (Str::is((string) $pattern, strtolower($parts['host']))) {
                return true;
            }
        }

        return false;
    }

    public function send(Authenticatable $user): int
    {
        if (! static::enabled()) {
            return 0;
        }

        $config = (array) config('filament-mailbox.notifications.web_push');
        $sent = 0;

        foreach (MailboxPushSubscription::query()->where('user_id', $user->getAuthIdentifier())->get() as $subscription) {
            if (! static::isAllowedEndpoint($subscription->endpoint)) {
                $subscription->delete();

                continue;
            }

            $url = parse_url($subscription->endpoint);
            $audience = $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');

            try {
                $response = Http::timeout(10)
                    ->withHeaders([
                        'TTL' => (string) ($config['ttl'] ?? 3600),
                        'Urgency' => 'normal',
                        'Authorization' => 'vapid t='.VapidKeys::jwt($audience, $config['subject'], $config['public_key'], $config['private_key']).', k='.$config['public_key'],
                    ])
                    ->withBody('', 'application/octet-stream')
                    ->post($subscription->endpoint);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            // The subscription expired or was revoked in the browser.
            if (in_array($response->status(), [404, 410], true)) {
                $subscription->delete();

                continue;
            }

            $sent += $response->successful() ? 1 : 0;
        }

        return $sent;
    }
}
