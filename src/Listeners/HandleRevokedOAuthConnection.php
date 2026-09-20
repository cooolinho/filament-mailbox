<?php

namespace Cooolinho\FilamentMailbox\Listeners;

use Cooolinho\FilamentMailbox\Events\OAuthConnectionRevoked;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Records the error on the affected mailboxes and notifies their users and
 * the person who connected the account (database notifications, if the user
 * model is notifiable).
 */
class HandleRevokedOAuthConnection
{
    public function handle(OAuthConnectionRevoked $event): void
    {
        $connection = $event->connection;
        $message = __('filament-mailbox::mailbox.oauth.needs_reconnect_body', ['account' => $connection->account_email]);

        $mailboxes = $connection->mailboxes()->with('users')->get();

        $mailboxes->each(fn (Mailbox $mailbox) => $mailbox->forceFill(['last_sync_error' => $message])->save());

        $userModel = config('filament-mailbox.user_model');

        $recipients = $mailboxes
            ->flatMap(fn (Mailbox $mailbox): Collection => $mailbox->users)
            ->when($connection->created_by && class_exists($userModel), fn (Collection $users) => $users->push($userModel::find($connection->created_by)))
            ->filter(fn ($user): bool => $user !== null && method_exists($user, 'notify'))
            ->unique(fn ($user) => $user->getKey());

        if ($recipients->isEmpty() || ! Schema::hasTable('notifications')) {
            return;
        }

        try {
            Notification::make()
                ->warning()
                ->title(__('filament-mailbox::mailbox.oauth.needs_reconnect'))
                ->body($message)
                ->actions($mailboxes->count() === 1 ? [
                    Action::make('reconnect')
                        ->label(__('filament-mailbox::mailbox.oauth.actions.reconnect'))
                        ->url(MailboxResource::getUrl('edit', ['record' => $mailboxes->first()])),
                ] : [])
                ->sendToDatabase($recipients->values());
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
