<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions;

use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Enums\OutgoingStatus;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\ComposeMessageForm;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxDraft;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxOutgoingMessage;
use Cooolinho\FilamentMailbox\Services\OutboxService;
use Cooolinho\FilamentMailbox\Support\ComposeContext;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Livewire\Component;
use Throwable;

class ComposeMessageAction
{
    public static function make(string $name = 'compose'): Action
    {
        return Action::make($name)
            ->label(__('filament-mailbox::mailbox.actions.compose.label'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->authorize('send')
            ->modalHeading(__('filament-mailbox::mailbox.actions.compose.heading'))
            ->modalSubmitActionLabel(__('filament-mailbox::mailbox.actions.compose.submit'))
            ->modalWidth('5xl')
            ->fillForm(fn (Mailbox $record): array => ComposeMessageForm::defaults($record, ComposeContext::New))
            ->schema(fn (Mailbox $record): array => ComposeMessageForm::components(mailbox: fn (): Mailbox => $record, context: ComposeContext::New))
            ->extraModalFooterActions(fn (Action $action): array => [DraftActions::saveFromModal($action)])
            ->action(function (Mailbox $record, array $data, array $arguments, Action $action, Component $livewire): void {
                if (DraftActions::isSavingDraft($arguments)) {
                    DraftActions::saveAndEdit($record, $data, MailboxDraft::MODE_NEW, null, $livewire);

                    return;
                }

                static::send($record, ComposeMessageForm::toData($data, mailbox: $record, context: ComposeContext::New), $action, $data);

                ComposeMessageForm::afterSent($data, $record, ComposeContext::New);
            });
    }

    /**
     * Send through the outbox: right away, after the undo window or at the chosen time.
     * Halts the action (the modal stays open) when the time is invalid or sending fails.
     *
     * @param  array<string, mixed>  $formData
     * @param  array{reply_to?: ?MailboxMessage, forward_of?: ?MailboxMessage, as_attachment?: bool}  $context
     */
    public static function send(Mailbox $mailbox, OutgoingMessageData $data, Action $action, array $formData = [], array $context = []): MailboxOutgoingMessage
    {
        $outgoing = static::queue($mailbox, $data, $formData, $context);

        if (! $outgoing) {
            $action->halt();
        }

        return $outgoing;
    }

    /**
     * @param  array<string, mixed>  $formData
     * @param  array{reply_to?: ?MailboxMessage, forward_of?: ?MailboxMessage, as_attachment?: bool}  $context
     */
    public static function queue(Mailbox $mailbox, OutgoingMessageData $data, array $formData = [], array $context = []): ?MailboxOutgoingMessage
    {
        try {
            $sendAt = ComposeMessageForm::sendAt($formData);
        } catch (InvalidArgumentException) {
            Notification::make()
                ->danger()
                ->title(__('filament-mailbox::mailbox.outbox.schedule.invalid'))
                ->send();

            return null;
        }

        try {
            $outgoing = app(OutboxService::class)->send($mailbox, $data, Filament::auth()->user(), $sendAt, $context);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title(__('filament-mailbox::mailbox.actions.compose.failure'))
                ->send();

            return null;
        }

        static::notifyQueued($outgoing, scheduled: $sendAt !== null);

        return $outgoing;
    }

    /**
     * "Sent", "Sending…" (undo window) or "Scheduled for …", with undo while it is not sent.
     */
    public static function notifyQueued(MailboxOutgoingMessage $outgoing, bool $scheduled): void
    {
        if ($outgoing->status === OutgoingStatus::Sent) {
            Notification::make()
                ->success()
                ->title(__('filament-mailbox::mailbox.actions.compose.success'))
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title($scheduled
                ? __('filament-mailbox::mailbox.outbox.scheduled', ['time' => $outgoing->send_at->copy()->setTimezone(FilamentTimezone::get())->translatedFormat('D, j. M Y, H:i')])
                : __('filament-mailbox::mailbox.outbox.sending'))
            ->duration($scheduled ? 8000 : max(3, OutboxService::undoSeconds()) * 1000)
            ->actions([
                Action::make('undo')
                    ->label(__('filament-mailbox::mailbox.actions.undo.label'))
                    ->link()
                    ->dispatch(OutboxService::CANCEL_EVENT, ['id' => $outgoing->getKey()])
                    ->close(),
                Action::make('outbox')
                    ->label(__('filament-mailbox::mailbox.outbox.label'))
                    ->link()
                    ->url(MailboxResource::getUrl('outbox', ['record' => $outgoing->mailbox_id])),
            ])
            ->send();
    }
}
