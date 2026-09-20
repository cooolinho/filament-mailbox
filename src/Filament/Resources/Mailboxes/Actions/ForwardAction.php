<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions;

use Closure;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\ComposeMessageForm;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxDraft;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\ForwardBuilder;
use Cooolinho\FilamentMailbox\Support\ComposeContext;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Component;
use Throwable;

class ForwardAction
{
    /**
     * @param  Closure(): MailboxMessage  $message
     */
    public static function make(Closure $message): Action
    {
        return Action::make('forward')
            ->label(__('filament-mailbox::mailbox.actions.forward.label'))
            ->icon(Heroicon::OutlinedArrowUturnRight)
            ->color('gray')
            ->authorize(fn (): bool => Gate::allows('forward', $message()))
            ->modalHeading(__('filament-mailbox::mailbox.actions.forward.label'))
            ->modalSubmitActionLabel(__('filament-mailbox::mailbox.actions.compose.submit'))
            ->modalWidth('5xl')
            ->fillForm(fn (ForwardBuilder $forwards): array => [
                ...$forwards->formData($message()),
                ...ComposeMessageForm::defaults($message()->mailbox, ComposeContext::Forward),
            ])
            ->schema(fn (): array => static::components($message))
            ->extraModalFooterActions(fn (Action $action): array => [DraftActions::saveFromModal($action)])
            ->action(function (array $data, array $arguments, ForwardBuilder $forwards, Action $action, Component $livewire) use ($message): void {
                $original = $message();

                if (DraftActions::isSavingDraft($arguments)) {
                    DraftActions::saveAndEdit($original->mailbox, $data, MailboxDraft::MODE_FORWARD, $original, $livewire);

                    return;
                }
                $asAttachment = (bool) ($data['as_attachment'] ?? false);

                try {
                    $attachments = $asAttachment
                        ? [$forwards->messageAttachment($original)]
                        : $forwards->originalAttachments($original, array_values($data['original_attachments'] ?? []));
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->danger()
                        ->title(__('filament-mailbox::mailbox.actions.compose.failure'))
                        ->send();

                    $action->halt();
                }

                $outgoing = ComposeMessageForm::toData(
                    $data,
                    additionalAttachments: $attachments,
                    forwardedMessageId: $original->message_id ? trim($original->message_id, '<>') : null,
                    mailbox: $original->mailbox,
                    context: ComposeContext::Forward,
                );

                if ($forwards->exceedsSizeLimit($outgoing->attachments)) {
                    Notification::make()
                        ->danger()
                        ->title(__('filament-mailbox::mailbox.forward.too_large', [
                            'size' => Number::fileSize((int) config('filament-mailbox.mail.max_attachment_size', 10240) * 1024),
                        ]))
                        ->send();

                    $action->halt();
                }

                // The original is marked as forwarded when the message is really sent.
                ComposeMessageAction::send($original->mailbox, $outgoing, $action, $data, ['forward_of' => $original, 'as_attachment' => $asAttachment]);

                ComposeMessageForm::afterSent($data, $original->mailbox, ComposeContext::Forward);
            });
    }

    /**
     * The compose form with the forward mode toggle and the original attachments.
     *
     * @param  Closure(): MailboxMessage  $message
     * @param  array<int, string>  $imageDirectories
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    public static function components(Closure $message, array $imageDirectories = []): array
    {
        return [
            Toggle::make('as_attachment')
                ->label(__('filament-mailbox::mailbox.forward.as_attachment'))
                ->helperText(__('filament-mailbox::mailbox.forward.as_attachment_help'))
                ->live()
                // The quoted original is replaced by the attached message and vice versa.
                ->afterStateUpdated(function (bool $state, Get $get, Set $set, ForwardBuilder $forwards) use ($message): void {
                    foreach ($forwards->quoted($message(), $get('format'), $state) as $field => $value) {
                        $set($field, $value);
                    }
                }),
            ...ComposeMessageForm::components(bodyRequired: false, quote: fn (Get $get): bool => ! $get('as_attachment'), mailbox: fn (): Mailbox => $message()->mailbox, context: ComposeContext::Forward, original: $message, imageDirectories: $imageDirectories),
            CheckboxList::make('original_attachments')
                ->label(__('filament-mailbox::mailbox.forward.original_attachments'))
                ->options(fn (): array => $message()->attachments
                    ->mapWithKeys(fn (MailboxAttachment $attachment): array => [
                        $attachment->getKey() => $attachment->filename.' ('.Number::fileSize($attachment->size).')',
                    ])
                    ->all())
                ->columns(2)
                ->visible(fn (Get $get): bool => ! $get('as_attachment') && $message()->attachments->isNotEmpty()),
        ];
    }
}
