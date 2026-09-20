<?php

namespace Cooolinho\FilamentMailbox\Listeners;

use Cooolinho\FilamentMailbox\Events\MessagesImported;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Notifications\NewMailNotifier;
use Cooolinho\FilamentMailbox\Services\NotificationPreferences;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Auth\User;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Notifies assigned users about new unread messages - never for the first
 * synchronisation of a folder or after a reset.
 */
class NotifyUsersAboutNewMessages implements ShouldQueue
{
    use InteractsWithQueue;


    public function viaConnection(): ?string
    {
        return config('filament-mailbox.sync.queue_connection');
    }

    public function viaQueue(): ?string
    {
        return config('filament-mailbox.sync.queue');
    }

    public function shouldQueue(MessagesImported $event): bool
    {
        return NotificationPreferences::enabled() && ! $event->isInitial && $event->messageIds !== [];
    }

    public function handle(MessagesImported $event): void
    {
        try {
            $this->notify($event);
        } catch (Throwable $exception) {
            // Notifications must never break a synchronisation (sync queue) or be retried.
            report($exception);
        }
    }

    protected function notify(MessagesImported $event): void
    {
        // Resolved here, so failing dependencies are caught as well.
        $preferences = app(NotificationPreferences::class);
        $notifier = app(NewMailNotifier::class);

        $folder = $event->folder->fresh(['mailbox']);
        $mailbox = $folder?->mailbox;

        if (! $folder || ! $mailbox || ! $mailbox->is_active) {
            return;
        }

        $messages = MailboxMessage::query()
            ->where('folder_id', $folder->getKey())
            ->whereKey($event->messageIds)
            ->where('is_read', false)
            ->where('is_draft', false)
            ->latest('received_at')
            ->get();

        if ($messages->isEmpty()) {
            return;
        }

        foreach ($mailbox->users()->get() as $user) {
            /** @var User $user */
            // The assignment may have been removed since the import.
            if (! Gate::forUser($user)->allows('view', $mailbox) || ! $preferences->wantsNotification($user, $mailbox, $folder)) {
                continue;
            }

            $notifier->notify($user, $mailbox, $folder, $messages);
        }
    }
}
