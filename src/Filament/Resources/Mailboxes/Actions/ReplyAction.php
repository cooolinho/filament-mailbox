<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions;

use Closure;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\ComposeMessageForm;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxDraft;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\ReplyBuilder;
use Cooolinho\FilamentMailbox\Support\ComposeContext;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Gate;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

class ReplyAction
{
    /**
     * @param  Closure(): MailboxMessage  $message
     */
    public static function make(Closure $message, bool $all = false): Action
    {
        $key = $all ? 'reply_all' : 'reply';

        return Action::make($all ? 'replyAll' : 'reply')
            ->label(__("filament-mailbox::mailbox.actions.{$key}.label"))
            ->icon($all ? Heroicon::OutlinedChatBubbleLeftRight : Heroicon::OutlinedArrowUturnLeft)
            ->color($all ? 'gray' : 'primary')
            ->authorize(fn (): bool => Gate::allows('reply', $message()))
            ->modalHeading(__("filament-mailbox::mailbox.actions.{$key}.label"))
            ->modalSubmitActionLabel(__('filament-mailbox::mailbox.actions.compose.submit'))
            ->modalWidth('5xl')
            ->fillForm(fn (ReplyBuilder $replies): array => [
                ...$replies->formData($message(), $all),
                ...ComposeMessageForm::defaults($message()->mailbox, ComposeContext::Reply),
            ])
            ->schema(ComposeMessageForm::components(quote: true, mailbox: fn (): Mailbox => $message()->mailbox, context: ComposeContext::Reply, original: $message))
            ->extraModalFooterActions(fn (Action $action): array => [DraftActions::saveFromModal($action)])
            ->action(function (array $data, array $arguments, ReplyBuilder $replies, Action $action, Component $livewire) use ($message, $all): void {
                $original = $message();

                if (DraftActions::isSavingDraft($arguments)) {
                    DraftActions::saveAndEdit($original->mailbox, $data, $all ? MailboxDraft::MODE_REPLY_ALL : MailboxDraft::MODE_REPLY, $original, $livewire);

                    return;
                }

                ComposeMessageAction::send(
                    $original->mailbox,
                    ComposeMessageForm::toData(
                        $data,
                        inReplyTo: $original->message_id ? trim($original->message_id, '<>') : null,
                        references: $replies->references($original),
                        providerThreadId: $original->thread_id,
                        mailbox: $original->mailbox,
                        context: ComposeContext::Reply,
                    ),
                    $action,
                    $data,
                    ['reply_to' => $original],
                );

                ComposeMessageForm::afterSent($data, $original->mailbox, ComposeContext::Reply);
            });
    }
}
