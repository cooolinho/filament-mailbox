<?php

namespace Cooolinho\FilamentMailbox\Notifications;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\MailboxReceipt;
use Cooolinho\FilamentMailbox\Models\MailboxReceiptRequest;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Database notification for the sender when a message could not be delivered to recipients.
 */
class DeliveryFailedNotification
{
    /**
     * @param  Collection<int, MailboxReceipt>  $receipts
     */
    public function send(Authenticatable $user, MailboxReceiptRequest $request, Collection $receipts): void
    {
        Notification::make()
            ->danger()
            ->title(__('filament-mailbox::mailbox.delivery_receipts.notification', ['recipients' => Str::limit($receipts->pluck('recipient')->implode(', '), 120)]))
            ->body($request->outgoingMessage ? Str::limit($request->outgoingMessage->subject, 80) : null)
            ->icon(Heroicon::OutlinedExclamationTriangle)
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
