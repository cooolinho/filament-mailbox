<?php

namespace Cooolinho\FilamentMailbox\Notifications;

use Cooolinho\FilamentMailbox\Enums\AlertSeverity;
use Cooolinho\FilamentMailbox\Models\MailboxAlert;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MailboxAlertNotification extends Notification
{
    public function __construct(
        public MailboxAlert $alert,
        public bool $resolved = false,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return array_keys($notifiable->routes);
        }

        return array_values(array_intersect((array) config('filament-mailbox.monitoring.alerts.channels', ['database']), ['database', 'mail']));
    }

    /**
     * Filament database notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->title())
            ->body($this->alert->message);

        $this->resolved
            ? $notification->success()
            : ($this->alert->severity === AlertSeverity::Critical ? $notification->danger() : $notification->warning());

        return $notification->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->alert->message);
    }

    /**
     * @return array{text: string}
     */
    public function toSlackWebhook(object $notifiable): array
    {
        return ['text' => ($this->resolved ? ':white_check_mark: ' : ':rotating_light: ').$this->title()."\n".$this->alert->message];
    }

    public function title(): string
    {
        return __($this->resolved ? 'filament-mailbox::mailbox.monitoring.notifications.resolved' : 'filament-mailbox::mailbox.monitoring.notifications.opened', [
            'rule' => $this->alert->rule->getLabel(),
            'mailbox' => $this->alert->mailbox?->name ?? '-',
        ]);
    }
}
