<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions;

use Cooolinho\FilamentMailbox\Enums\SyncTrigger;
use Cooolinho\FilamentMailbox\Jobs\SyncMailboxJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class SyncMailboxAction
{
    public static function make(): Action
    {
        return Action::make('sync')
            ->label(__('filament-mailbox::mailbox.actions.sync.label'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize('view')
            ->disabled(fn (Mailbox $record): bool => ! $record->is_active)
            ->action(function (Mailbox $record): void {
                SyncMailboxJob::dispatch($record, SyncTrigger::Manual);

                Notification::make()
                    ->success()
                    ->title(__('filament-mailbox::mailbox.actions.sync.started'))
                    ->send();
            });
    }
}
