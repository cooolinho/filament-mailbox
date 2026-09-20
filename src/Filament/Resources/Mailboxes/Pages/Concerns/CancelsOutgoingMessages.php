<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\Concerns;

use Cooolinho\FilamentMailbox\Models\MailboxOutgoingMessage;
use Cooolinho\FilamentMailbox\Services\OutboxService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;

/**
 * "Undo" of the sent/scheduled notification. The id comes from the browser:
 * the message is authorised again and only cancelled while it is not sent.
 */
trait CancelsOutgoingMessages
{
    #[On(OutboxService::CANCEL_EVENT)]
    public function cancelOutgoingMessage(int|string $id, OutboxService $outbox): void
    {
        $message = is_numeric($id) ? MailboxOutgoingMessage::query()->find((int) $id) : null;

        if (! $message || Gate::denies('update', $message)) {
            return;
        }

        if (! $outbox->cancel($message)) {
            Notification::make()
                ->warning()
                ->title(__('filament-mailbox::mailbox.outbox.too_late'))
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('filament-mailbox::mailbox.outbox.cancelled'))
            ->body(__('filament-mailbox::mailbox.outbox.cancelled_body'))
            ->send();
    }
}
