<?php

namespace Cooolinho\FilamentMailbox\Notifications;

use Cooolinho\FilamentMailbox\FilamentMailboxPlugin;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\NotificationPreferences;
use Cooolinho\FilamentMailbox\WebPush\WebPushSender;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * One bundled "new e-mail" notification per user, mailbox and sync batch, throttled.
 */
class NewMailNotifier
{
    /** Marks the notifications of this package in the notifications table. */
    public const MARKER = 'filament_mailbox_new_mail';

    public function __construct(
        protected NotificationPreferences $preferences,
        protected WebPushSender $push,
    ) {}

    /**
     * @param  Collection<int, MailboxMessage>  $messages  new unread messages, newest first
     * @return bool whether a notification was sent (false when throttled)
     */
    public function notify(Authenticatable $user, Mailbox $mailbox, MailboxFolder $folder, Collection $messages): bool
    {
        $key = 'filament-mailbox-notify:'.$user->getAuthIdentifier().':'.$mailbox->getKey();
        $pendingKey = $key.':pending';
        $throttle = max(0, (int) config('filament-mailbox.notifications.throttle_seconds', 120));

        if ($throttle > 0 && RateLimiter::tooManyAttempts($key, 1)) {
            // Summarised with the next notification.
            Cache::put($pendingKey, (int) Cache::get($pendingKey, 0) + $messages->count(), now()->addDay());

            return false;
        }

        if ($throttle > 0) {
            RateLimiter::hit($key, $throttle);
        }

        $pending = (int) Cache::pull($pendingKey, 0);
        $count = $messages->count() + $pending;
        $single = $count === 1 ? $messages->first() : null;
        $showContent = $this->preferences->showContent($user);
        $url = $this->url($mailbox, $folder, $single);

        $title = match (true) {
            $single && $showContent => $single->fromLabel() ?: __('filament-mailbox::mailbox.notifications.new_message', ['mailbox' => $mailbox->name]),
            $single !== null => __('filament-mailbox::mailbox.notifications.new_message', ['mailbox' => $mailbox->name]),
            default => trans_choice('filament-mailbox::mailbox.notifications.new_messages', $count, ['count' => $count, 'mailbox' => $mailbox->name]),
        };

        $body = match (true) {
            ! $showContent => null,
            $single !== null => Str::limit($single->subject ?: __('filament-mailbox::mailbox.messages.no_subject'), 80),
            default => Str::limit($messages->map(fn (MailboxMessage $message): string => $message->fromLabel())->filter()->unique()->take(3)->implode(', '), 120),
        };

        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->icon(Heroicon::OutlinedEnvelope)
            ->iconColor('primary')
            ->actions([
                Action::make('open')
                    ->label(__('filament-mailbox::mailbox.notifications.open'))
                    ->button()
                    ->url($url)
                    ->markAsRead(),
            ])
            ->viewData([self::MARKER => ['mailbox_id' => $mailbox->getKey(), 'url' => $url]]);

        $notification->sendToDatabase($user, isEventDispatched: true);

        if (config('filament-mailbox.notifications.broadcast', false)) {
            $notification->broadcast($user);
        }

        $this->push->send($user);

        return true;
    }

    protected function url(Mailbox $mailbox, MailboxFolder $folder, ?MailboxMessage $message): string
    {
        $panel = static::panelId();

        return $message
            ? MailboxResource::getUrl('message', ['record' => $mailbox, 'message' => $message], panel: $panel)
            : MailboxResource::getUrl('browse', ['record' => $mailbox, 'folder' => $folder->getKey()], panel: $panel);
    }

    /**
     * Queued listeners have no current panel: the configured one or the first with the plugin.
     */
    public static function panelId(): ?string
    {
        if ($panel = config('filament-mailbox.notifications.panel')) {
            return (string) $panel;
        }

        foreach (Filament::getPanels() as $panel) {
            if ($panel->hasPlugin(FilamentMailboxPlugin::ID)) {
                return $panel->getId();
            }
        }

        return null;
    }
}
