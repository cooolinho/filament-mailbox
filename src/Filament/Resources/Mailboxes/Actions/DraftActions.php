<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxDraft;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\DraftService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class DraftActions
{
    /**
     * "Save as draft" in the footer of the compose, reply and forward modals.
     * It submits the modal with the "draft" argument, so required fields are not enforced.
     */
    public static function saveFromModal(Action $action): Action
    {
        return $action->makeModalSubmitAction('saveDraft', arguments: ['draft' => true])
            ->label(__('filament-mailbox::mailbox.drafts.actions.save_draft'))
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->visible(fn (): bool => DraftService::enabled());
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function isSavingDraft(array $arguments): bool
    {
        return (bool) ($arguments['draft'] ?? false) && DraftService::enabled();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function saveAndEdit(Mailbox $mailbox, array $data, string $mode, ?MailboxMessage $original, Component $livewire): void
    {
        $draft = app(DraftService::class)->save($mailbox, $data, null, Filament::auth()->user(), $mode, $original);

        Notification::make()
            ->success()
            ->title(__('filament-mailbox::mailbox.drafts.saved'))
            ->send();

        $livewire->redirect(MailboxResource::getUrl('draft', ['record' => $mailbox, 'draft' => $draft]));
    }

    /**
     * "Edit draft" for a message in the drafts folder: opens its draft, or makes
     * one from the message (e.g. written in another client).
     */
    public static function editMessage(\Closure $message): Action
    {
        return Action::make('editDraft')
            ->label(__('filament-mailbox::mailbox.drafts.actions.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('primary')
            ->visible(fn (): bool => DraftService::enabled() && static::isDraftMessage($message()))
            ->authorize(fn (): bool => Gate::allows('reply', $message()))
            ->action(function (DraftService $drafts, Component $livewire) use ($message): void {
                $record = $message();
                $draft = $record->draft;

                if ($draft && Gate::denies('update', $draft)) {
                    Notification::make()
                        ->warning()
                        ->title(__('filament-mailbox::mailbox.drafts.foreign'))
                        ->send();

                    return;
                }

                $draft ??= $drafts->importFromMessage($record, Filament::auth()->user());

                $livewire->redirect(MailboxResource::getUrl('draft', ['record' => $record->mailbox_id, 'draft' => $draft]));
            });
    }

    public static function isDraftMessage(MailboxMessage $message): bool
    {
        return $message->is_draft || $message->draft_id !== null;
    }

    public static function url(MailboxDraft $draft): string
    {
        return MailboxResource::getUrl('draft', ['record' => $draft->mailbox_id, 'draft' => $draft]);
    }
}
