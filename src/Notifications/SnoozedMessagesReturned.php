<?php

namespace Cooolinho\FilamentMailbox\Notifications;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Database notification for the user who snoozed messages that returned.
 */
class SnoozedMessagesReturned
{
    /**
     * @param  Collection<int, MailboxMessage>  $messages  returned messages of one mailbox
     */
    public function send(Authenticatable $user, Collection $messages): void
    {
        $first = $messages->first();
        $mailbox = $first->mailbox;
        $single = $messages->count() === 1;
        $panel = NewMailNotifier::panelId();

        $url = $single
            ? MailboxResource::getUrl('message', ['record' => $mailbox, 'message' => $first], panel: $panel)
            : MailboxResource::getUrl('browse', ['record' => $mailbox, 'folder' => $first->folder_id], panel: $panel);

        Notification::make()
            ->title(trans_choice('filament-mailbox::mailbox.snooze.notification.title', $messages->count(), ['count' => $messages->count(), 'mailbox' => $mailbox->name]))
            ->body($single
                ? Str::limit($first->subject ?: __('filament-mailbox::mailbox.messages.no_subject'), 80)
                : Str::limit($messages->map(fn (MailboxMessage $message): string => $message->subject ?: __('filament-mailbox::mailbox.messages.no_subject'))->take(3)->implode(', '), 120))
            ->icon(Heroicon::OutlinedClock)
            ->iconColor('primary')
            ->actions([
                Action::make('open')
                    ->label(__('filament-mailbox::mailbox.snooze.notification.open'))
                    ->button()
                    ->url($url)
                    ->markAsRead(),
            ])
            ->sendToDatabase($user, isEventDispatched: true);
    }
}
