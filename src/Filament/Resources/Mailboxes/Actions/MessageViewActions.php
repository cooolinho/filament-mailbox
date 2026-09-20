<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions;

use Closure;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\MessageService;
use Cooolinho\FilamentMailbox\Services\SnoozeService;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Actions of an opened message, shared by the message page and the preview pane.
 */
class MessageViewActions
{
    /**
     * @param  Closure(): MailboxMessage  $message
     * @param  Closure(MailboxMessage, int): void  $removed  after the message left the list (moved or deleted), with the folder it was in
     * @param  Closure(MailboxMessage): void  $markedUnread
     * @return array<int, Action>
     */
    public static function make(Closure $message, Closure $removed, Closure $markedUnread): array
    {
        return [
            DraftActions::editMessage($message),
            ReplyAction::make($message),
            ReplyAction::make($message, all: true),
            ForwardAction::make($message),
            Action::make('toggleStar')
                ->label(fn (): string => $message()->is_flagged
                    ? __('filament-mailbox::mailbox.actions.unstar.label')
                    : __('filament-mailbox::mailbox.actions.star.label'))
                ->authorize(fn (): bool => Gate::allows('update', $message()))
                ->icon(fn (): Heroicon => $message()->is_flagged ? Heroicon::Star : Heroicon::OutlinedStar)
                ->color(fn (): string => $message()->is_flagged ? 'warning' : 'gray')
                ->action(function (MessageService $messages) use ($message): void {
                    $record = $message();

                    MessageActions::run(
                        fn () => $messages->setFlagged($record, ! $record->is_flagged),
                        $record->is_flagged
                            ? __('filament-mailbox::mailbox.actions.unstar.success')
                            : __('filament-mailbox::mailbox.actions.star.success'),
                    );
                }),
            LabelActions::edit()
                ->record($message),
            TagActions::edit()
                ->record($message),
            Action::make('markAsUnread')
                ->label(__('filament-mailbox::mailbox.actions.mark_unread.label'))
                ->authorize(fn (): bool => Gate::allows('update', $message()))
                ->visible(fn (): bool => $message()->mailbox->supports(ProviderCapability::Flags))
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('gray')
                ->action(function (MessageService $messages) use ($message, $markedUnread): void {
                    $record = $message();

                    if (MessageActions::run(fn () => $messages->markUnread($record), __('filament-mailbox::mailbox.actions.mark_unread.success'))) {
                        $markedUnread($record);
                    }
                }),
            Action::make('snooze')
                ->label(__('filament-mailbox::mailbox.snooze.actions.snooze'))
                ->authorize(fn (): bool => Gate::allows('update', $message()))
                ->visible(fn (): bool => MessageActions::canSnooze($message()))
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->modalHeading(__('filament-mailbox::mailbox.snooze.modal.heading'))
                ->modalSubmitActionLabel(__('filament-mailbox::mailbox.snooze.actions.snooze'))
                ->modalWidth('md')
                ->schema(fn (): array => MessageActions::snoozeSchema())
                ->action(function (array $data, SnoozeService $snooze) use ($message, $removed): void {
                    $record = $message();
                    $folderId = $record->folder_id;

                    // A snoozed message leaves the list until it returns.
                    if (MessageActions::snoozeAndNotify($snooze, $record, $data)) {
                        $removed($record, $folderId);
                    }
                }),
            Action::make('unsnooze')
                ->label(__('filament-mailbox::mailbox.snooze.actions.unsnooze'))
                ->authorize(fn (): bool => Gate::allows('update', $message()))
                ->visible(fn (): bool => SnoozeService::enabled() && $message()->isSnoozed())
                ->icon(Heroicon::OutlinedBellSlash)
                ->color('gray')
                ->action(fn (SnoozeService $snooze) => MessageActions::run(
                    fn () => $snooze->unsnooze($message()),
                    __('filament-mailbox::mailbox.snooze.unsnoozed'),
                )),
            Action::make('moveTo')
                ->label(__('filament-mailbox::mailbox.actions.move.label'))
                ->authorize(fn (): bool => Gate::allows('update', $message()))
                ->visible(fn (): bool => $message()->mailbox->supports(ProviderCapability::MoveMessages))
                ->icon(Heroicon::OutlinedArrowRightCircle)
                ->color('gray')
                ->modalWidth('md')
                ->schema(fn (): array => [MessageActions::targetFolderSelect($message()->mailbox, $message()->folder_id)])
                ->action(function (array $data, MessageService $messages) use ($message, $removed): void {
                    $record = $message();
                    $folderId = $record->folder_id;

                    if (MessageActions::moveToAndNotify($messages, $record, $data['folder'])) {
                        $removed($record, $folderId);
                    }
                }),
            Action::make('archive')
                ->label(__('filament-mailbox::mailbox.actions.archive.label'))
                ->authorize(fn (): bool => Gate::allows('update', $message()))
                ->visible(fn (): bool => MessageActions::archivableFolder($message()))
                ->disabled(fn (MessageService $messages): bool => ! $messages->canArchive($message()))
                ->tooltip(fn (MessageService $messages): ?string => MessageActions::archiveHint($message(), $messages))
                ->icon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->action(fn (MessageService $messages) => static::moveAway($message(), fn (MailboxMessage $record): array => $messages->archive($record), __('filament-mailbox::mailbox.actions.archive.success'), $removed)),
            Action::make('markAsSpam')
                ->label(__('filament-mailbox::mailbox.actions.spam.label'))
                ->authorize(fn (): bool => Gate::allows('update', $message()))
                ->visible(fn (MessageService $messages): bool => $messages->canMarkAsSpam($message()))
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('gray')
                ->action(fn (MessageService $messages) => static::moveAway($message(), fn (MailboxMessage $record): array => $messages->markAsSpam($record), __('filament-mailbox::mailbox.actions.spam.success'), $removed)),
            Action::make('markAsNotSpam')
                ->label(__('filament-mailbox::mailbox.actions.not_spam.label'))
                ->authorize(fn (): bool => Gate::allows('update', $message()))
                ->visible(fn (MessageService $messages): bool => $messages->canMarkAsNotSpam($message()))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('gray')
                ->action(fn (MessageService $messages) => static::moveAway($message(), fn (MailboxMessage $record): array => $messages->markAsNotSpam($record), __('filament-mailbox::mailbox.actions.not_spam.success'), $removed)),
            BlockSenderAction::make($message, $removed),
            Action::make('deleteMessage')
                ->label(fn (): string => MessageActions::deleteLabel($message()))
                ->authorize(fn (): bool => Gate::allows('delete', $message()))
                ->visible(fn (): bool => MessageActions::canDelete($message()))
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('filament-mailbox::mailbox.actions.delete.heading'))
                ->modalDescription(__('filament-mailbox::mailbox.actions.delete.description'))
                ->action(function (MessageService $messages) use ($message, $removed): void {
                    $record = $message();
                    $folderId = $record->folder_id;

                    if (MessageActions::run(fn () => $messages->delete($record), __('filament-mailbox::mailbox.actions.delete.success'))) {
                        $removed($record, $folderId);
                    }
                }),
        ];
    }

    /**
     * Move the message and offer to undo.
     *
     * @param  Closure(MailboxMessage): array<int, int>  $operation
     * @param  Closure(MailboxMessage, int): void  $removed
     */
    public static function moveAway(MailboxMessage $message, Closure $operation, string $successTitle, Closure $removed): void
    {
        $folderId = $message->folder_id;

        if (MessageActions::moveAndNotify(fn (): array => $operation($message), $successTitle)) {
            $removed($message, $folderId);
        }
    }
}
