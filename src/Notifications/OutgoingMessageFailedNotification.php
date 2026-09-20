<?php

namespace Cooolinho\FilamentMailbox\Notifications;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\MailboxOutgoingMessage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * Database notification for the sender of a message that could not be sent.
 */
class OutgoingMessageFailedNotification
{
    public function send(Authenticatable $user, MailboxOutgoingMessage $message): void
    {
        Notification::make()
            ->danger()
            ->title(__('filament-mailbox::mailbox.outbox.notifications.failed', ['mailbox' => $message->mailbox->name]))
            ->body(Str::limit($message->subject, 80))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->actions([
                Action::make('open')
                    ->label(__('filament-mailbox::mailbox.outbox.label'))
                    ->button()
                    ->url(MailboxResource::getUrl('outbox', ['record' => $message->mailbox_id], panel: NewMailNotifier::panelId()))
                    ->markAsRead(),
            ])
            ->sendToDatabase($user, isEventDispatched: true);
    }
}
