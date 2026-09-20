<?php

namespace Cooolinho\FilamentMailbox\Providers\Gmail;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Illuminate\Support\Carbon;

/**
 * Registers Gmail push notifications (users.watch). A watch expires after
 * seven days and is renewed daily.
 */
class GmailWatchManager
{
    public function __construct(
        protected MailboxProviderFactory $providers,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('filament-mailbox.gmail.push.enabled') && filled(config('filament-mailbox.gmail.push.topic'));
    }

    /**
     * Start or renew the watch when it expires within a day. Returns whether a request was made.
     */
    public function watch(Mailbox $mailbox, bool $force = false): bool
    {
        if (! static::enabled() || $mailbox->provider !== ProviderType::Gmail || ! $mailbox->is_active) {
            return false;
        }

        if (! $force && $mailbox->watch_expires_at?->isAfter(now()->addDay())) {
            return false;
        }

        $provider = $this->providers->make($mailbox);

        try {
            if (! $provider instanceof GmailProvider) {
                return false;
            }

            $response = $provider->client()->post('watch', [
                'topicName' => (string) config('filament-mailbox.gmail.push.topic'),
                'labelIds' => ['INBOX'],
                'labelFilterBehavior' => 'include',
            ]);
        } finally {
            $provider->disconnect();
        }

        $mailbox->forceFill([
            'watch_expires_at' => isset($response['expiration']) ? Carbon::createFromTimestampMs((int) $response['expiration']) : now()->addDays(7),
        ])->save();

        return true;
    }
}
