<?php

namespace Cooolinho\FilamentMailbox\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * Posts a message to a Slack incoming webhook, without further dependencies.
 */
class SlackWebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $url = method_exists($notifiable, 'routeNotificationFor') ? $notifiable->routeNotificationFor(static::class, $notification) : null;

        if (! is_string($url) || ! method_exists($notification, 'toSlackWebhook')) {
            return;
        }

        Http::timeout(10)->post($url, $notification->toSlackWebhook($notifiable))->throw();
    }
}
