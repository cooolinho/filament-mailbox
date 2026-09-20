<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions;

use Closure;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\BlockedSenderMatcher;
use Cooolinho\FilamentMailbox\Services\MessageService;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class BlockSenderAction
{
    /**
     * @param  Closure(): MailboxMessage  $message
     * @param  ?Closure(MailboxMessage, int): void  $movedToSpam  receives the folder the message was in; default: open that folder
     */
    public static function make(Closure $message, ?Closure $movedToSpam = null): Action
    {
        return Action::make('blockSender')
            ->label(__('filament-mailbox::mailbox.spam.block.label'))
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('gray')
            // Managing the block list is part of managing the mailbox.
            ->authorize(fn (): bool => Gate::allows('update', $message()->mailbox) && Gate::allows('update', $message()))
            ->visible(fn (): bool => config('filament-mailbox.spam.block_senders', true) && filled($message()->from_address))
            ->modalDescription(__('filament-mailbox::mailbox.spam.block.description'))
            ->modalWidth('md')
            ->fillForm(fn (MessageService $messages): array => [
                'pattern' => BlockedSenderMatcher::normalize((string) $message()->from_address),
                'move_to_spam' => $messages->canMarkAsSpam($message()),
            ])
            ->schema(fn (MessageService $messages): array => [
                Radio::make('pattern')
                    ->hiddenLabel()
                    ->options(fn (): array => static::options($message()))
                    ->required(),
                Toggle::make('move_to_spam')
                    ->label(__('filament-mailbox::mailbox.spam.block.move'))
                    ->visible(fn (): bool => $messages->canMarkAsSpam($message())),
            ])
            ->action(function (array $data, MessageService $messages, Component $livewire) use ($message, $movedToSpam): void {
                $record = $message();

                // Only the address or the domain of this sender, never free input.
                abort_unless(array_key_exists($data['pattern'], static::options($record)), 422);

                $record->mailbox->blockedSenders()->firstOrCreate(
                    ['pattern' => $data['pattern']],
                    ['created_by' => auth()->id()],
                );

                if (($data['move_to_spam'] ?? false) && $messages->canMarkAsSpam($record)) {
                    $folderId = $record->folder_id;
                    $folderUrl = MailboxResource::getUrl('browse', ['record' => $record->mailbox_id, 'folder' => $folderId]);

                    if (MessageActions::moveAndNotify(fn (): array => $messages->markAsSpam($record), __('filament-mailbox::mailbox.spam.block.success'))) {
                        $movedToSpam ? $movedToSpam($record, $folderId) : $livewire->redirect($folderUrl);
                    }

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('filament-mailbox::mailbox.spam.block.success'))
                    ->send();
            });
    }

    /**
     * @return array<string, string>
     */
    public static function options(MailboxMessage $message): array
    {
        $address = BlockedSenderMatcher::normalize((string) $message->from_address);
        $domain = BlockedSenderMatcher::domainPattern($address);

        return [
            $address => __('filament-mailbox::mailbox.spam.block.address', ['address' => $address]),
            $domain => __('filament-mailbox::mailbox.spam.block.domain', ['domain' => substr($domain, 2)]),
        ];
    }
}
