<?php

namespace Cooolinho\FilamentMailbox\Monitoring;

use Cooolinho\FilamentMailbox\Models\MailboxAlert;
use Cooolinho\FilamentMailbox\Notifications\MailboxAlertNotification;
use Cooolinho\FilamentMailbox\Notifications\SlackWebhookChannel;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Sends alert notifications to users who may manage mailboxes and to the
 * configured mail address / Slack webhook.
 */
class AlertNotifier
{
    public function __construct(
        protected MailboxAuthorization $authorization,
    ) {}

    public function opened(MailboxAlert $alert): void
    {
        $this->send(new MailboxAlertNotification($alert, resolved: false));

        $alert->forceFill(['notified_at' => now()])->save();
    }

    public function resolved(MailboxAlert $alert): void
    {
        $this->send(new MailboxAlertNotification($alert, resolved: true));
    }

    /**
     * @return Collection<int, Authenticatable>
     */
    public function recipients(): Collection
    {
        $model = config('filament-mailbox.user_model');

        if (! is_string($model) || ! class_exists($model)) {
            return new Collection;
        }

        return $model::query()
            ->lazy()
            ->filter(fn (Authenticatable $user): bool => method_exists($user, 'notify') && $this->authorization->canManage($user))
            ->values()
            ->collect();
    }

    protected function send(MailboxAlertNotification $notification): void
    {
        $channels = (array) config('filament-mailbox.monitoring.alerts.channels', ['database']);

        if (array_intersect($channels, ['database', 'mail']) !== []) {
            Notification::send($this->recipients(), $notification);
        }

        if (in_array('mail', $channels, true) && filled($address = config('filament-mailbox.monitoring.alerts.mail_to'))) {
            Notification::route('mail', $address)->notify($notification);
        }

        if (in_array('slack', $channels, true) && filled($webhook = config('filament-mailbox.monitoring.alerts.slack_webhook'))) {
            Notification::route(SlackWebhookChannel::class, $webhook)->notify($notification);
        }
    }
}
