<?php

namespace Cooolinho\FilamentMailbox\Notifications;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\MailboxReceipt;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * Database notification for the sender when a read receipt arrives.
 */
class ReadReceiptReceivedNotification
{
    public function send(Authenticatable $user, MailboxReceipt $receipt): void
    {
        $request = $receipt->request;
        $outgoing = $request->outgoingMessage;

        Notification::make()
            ->success()
            ->title(__('filament-mailbox::mailbox.read_receipts.notification', ['recipient' => $receipt->recipient]))
            ->body($outgoing ? Str::limit($outgoing->subject, 80) : null)
            ->icon(Heroicon::OutlinedCheckBadge)
            ->actions([
                Action::make('open')
                    ->label(__('filament-mailbox::mailbox.outbox.label'))
                    ->button()
                    ->url(MailboxResource::getUrl('outbox', ['record' => $request->mailbox_id], panel: NewMailNotifier::panelId()))
                    ->markAsRead(),
            ])
            ->sendToDatabase($user, isEventDispatched: true);
    }
}
