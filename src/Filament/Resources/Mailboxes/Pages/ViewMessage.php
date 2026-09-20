<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\MessageViewActions;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\Concerns\CancelsOutgoingMessages;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\MessageInfolist;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\MessageViewer;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Cooolinho\FilamentMailbox\Support\ShortcutRegistry;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

/**
 * @property Mailbox $record
 */
class ViewMessage extends Page
{
    use CancelsOutgoingMessages;
    use InteractsWithRecord;

    protected static string $resource = MailboxResource::class;

    #[Locked]
    public int $messageId;

    protected ?MailboxMessage $cachedMessage = null;

    public function mount(int|string $record, int|string $message): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $this->messageId = (int) $message;

        abort_unless(Gate::allows('view', $this->getMessage()), 403);

        app(MessageViewer::class)->markRead($this->getMessage());
    }

    /**
     * Messages are always resolved through the mailbox, so a message ID of
     * another mailbox results in a 404.
     */
    public function getMessage(): MailboxMessage
    {
        if ($this->cachedMessage?->getKey() === $this->messageId) {
            return $this->cachedMessage;
        }

        /** @var Mailbox $mailbox */
        $mailbox = $this->getRecord();

        return $this->cachedMessage = $mailbox->messages()->with('folder')->findOrFail($this->messageId);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getMessage()->subject ?: __('filament-mailbox::mailbox.messages.no_subject');
    }

    public function getBreadcrumbs(): array
    {
        $message = $this->getMessage();

        return [
            MailboxResource::getUrl() => MailboxResource::getBreadcrumb(),
            MailboxResource::getUrl('browse', ['record' => $this->getRecord(), 'folder' => $message->folder_id]) => $this->getRecord()->name,
            __('filament-mailbox::mailbox.pages.view.breadcrumb'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return MessageInfolist::configure($schema->record($this->getMessage()));
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament-mailbox::shortcuts.script')
                ->viewData(fn (): array => [
                    'page' => 'message',
                    'bindings' => ShortcutRegistry::map(ShortcutRegistry::MESSAGE_ACTIONS),
                    'inboxUrl' => MailboxResource::getUrl('browse', ['record' => $this->getRecord()]),
                    'backUrl' => $this->folderUrl($this->getMessage()->folder_id),
                ])
                ->visible(fn (): bool => ShortcutRegistry::enabled()),
            EmbeddedSchema::make('infolist'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $actions = MessageViewActions::make(
            message: fn (): MailboxMessage => $this->getMessage(),
            removed: fn (MailboxMessage $message, int $folderId) => $this->redirect($this->folderUrl($folderId)),
            markedUnread: fn (MailboxMessage $message) => $this->redirect($this->folderUrl($message->folder_id)),
        );

        // "Back" follows the flag, label and tag actions.
        $index = array_search('markAsUnread', array_map(fn (Action $action): string => $action->getName(), $actions), true);

        array_splice($actions, $index, 0, [
            Action::make('back')
                ->label(__('filament-mailbox::mailbox.actions.back.label'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => $this->folderUrl($this->getMessage()->folder_id)),
        ]);

        $actions[] = BrowseMailbox::shortcutsHelpAction();

        return $actions;
    }

    protected function folderUrl(int $folderId): string
    {
        return MailboxResource::getUrl('browse', ['record' => $this->getRecord(), 'folder' => $folderId]);
    }
}
