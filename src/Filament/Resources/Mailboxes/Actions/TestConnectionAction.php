<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions;

use Cooolinho\FilamentMailbox\Exceptions\ConnectionFailed;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Services\ConnectionTester;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class TestConnectionAction
{
    public static function make(): Action
    {
        return Action::make('testConnection')
            ->label(__('filament-mailbox::mailbox.actions.test_connection.label'))
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->authorize('update')
            ->action(function (mixed $livewire, ?Mailbox $record, ConnectionTester $tester): void {
                // On the edit page, test the values currently entered in the form without saving them.
                $mailbox = $livewire instanceof EditRecord
                    ? $livewire->getRecord()->replicate()->fill($livewire->form->getState())
                    : $record;

                try {
                    $tester->test($mailbox);
                } catch (ConnectionFailed $exception) {
                    Notification::make()
                        ->danger()
                        ->title(__('filament-mailbox::mailbox.actions.test_connection.failure'))
                        ->body($exception->getMessage())
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('filament-mailbox::mailbox.actions.test_connection.success'))
                    ->send();
            });
    }
}
