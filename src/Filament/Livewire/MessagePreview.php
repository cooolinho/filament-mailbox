<?php

namespace Cooolinho\FilamentMailbox\Filament\Livewire;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\MessageViewActions;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\MessageInfolist;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\MessageViewer;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Reading pane of the split mailbox view. Receives ids only; the message is
 * resolved through the mailbox and authorised like the message page.
 */
class MessagePreview extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public const REMOVED_EVENT = 'filament-mailbox-preview-removed';

    public const CLOSED_EVENT = 'filament-mailbox-preview-closed';

    public const UPDATED_EVENT = 'filament-mailbox-preview-updated';

    #[Locked]
    public int $mailboxId;

    #[Reactive]
    public ?int $messageId = null;

    protected ?Mailbox $cachedMailbox = null;

    protected ?MailboxMessage $cachedMessage = null;

    public function booted(): void
    {
        $this->cachePreviewActions();
    }

    /**
     * Actions resolve the message lazily, so they stay valid when the selection changes.
     */
    protected function cachePreviewActions(): void
    {
        if ($this->cachedActions !== [] || ! $this->getMessage()) {
            return;
        }

        foreach ($this->actions() as $action) {
            $this->cacheAction($action);
        }
    }

    public function getMailbox(): ?Mailbox
    {
        $this->cachedMailbox ??= Mailbox::query()->find($this->mailboxId);

        return $this->cachedMailbox && MailboxResource::canView($this->cachedMailbox) ? $this->cachedMailbox : null;
    }

    public function getMessage(): ?MailboxMessage
    {
        if ($this->messageId === null || ! ($mailbox = $this->getMailbox())) {
            return null;
        }

        if ($this->cachedMessage?->getKey() !== $this->messageId) {
            $this->cachedMessage = app(MessageViewer::class)->find($mailbox, $this->messageId);
        }

        return $this->cachedMessage;
    }

    protected function message(): MailboxMessage
    {
        return $this->getMessage() ?? abort(404);
    }

    public function infolist(Schema $schema): Schema
    {
        $message = $this->getMessage();

        return $message ? MessageInfolist::configure($schema->record($message)) : $schema;
    }

    /**
     * @return array<int, Action>
     */
    public function actions(): array
    {
        return [
            ...MessageViewActions::make(
                message: fn (): MailboxMessage => $this->message(),
                removed: fn (MailboxMessage $message) => $this->dispatch(self::REMOVED_EVENT, id: $message->getKey()),
                markedUnread: fn () => $this->dispatch(self::CLOSED_EVENT),
            ),
            Action::make('openFullView')
                ->label(__('filament-mailbox::mailbox.split.open_full'))
                ->icon(Heroicon::OutlinedArrowsPointingOut)
                ->color('gray')
                ->url(fn (): string => MailboxResource::getUrl('message', ['record' => $this->mailboxId, 'message' => $this->message()])),
        ];
    }

    /**
     * Frequent actions as buttons, the others in a menu.
     *
     * @return array<int, Action|ActionGroup>
     */
    public function getToolbarActions(): array
    {
        $this->cachePreviewActions();

        $primary = ['editDraft', 'reply', 'replyAll', 'forward', 'toggleStar', 'archive', 'deleteMessage'];
        $actions = collect($this->cachedActions)->filter(fn (Action $action): bool => $action->isVisible());

        return [
            ...$actions->only($primary)->values()->all(),
            ActionGroup::make($actions->except($primary)->values()->all())
                ->icon(Heroicon::EllipsisVertical)
                ->color('gray'),
        ];
    }

    protected function afterActionCalled(Action $action): void
    {
        // Flags, labels and tags are shown in the list as well.
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function render(): View
    {
        return view('filament-mailbox::livewire.message-preview', [
            'message' => $this->getMessage(),
        ]);
    }
}
