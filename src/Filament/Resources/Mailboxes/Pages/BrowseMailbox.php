<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages;

use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Filament\Livewire\MessagePreview;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\DraftActions;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\ComposeMessageAction;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\MessageActions;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\SyncMailboxAction;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\Concerns\CancelsOutgoingMessages;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Tables\MessagesTable;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxLabel;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\DraftService;
use Cooolinho\FilamentMailbox\Services\FolderNavigation;
use Cooolinho\FilamentMailbox\Services\MessageService;
use Cooolinho\FilamentMailbox\Services\MessageViewer;
use Cooolinho\FilamentMailbox\Services\SnoozeService;
use Cooolinho\FilamentMailbox\Services\UserPreferences;
use Cooolinho\FilamentMailbox\Search\SearchManager;
use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Cooolinho\FilamentMailbox\Search\SearchScope;
use Cooolinho\FilamentMailbox\Search\SnippetBuilder;
use Illuminate\Support\HtmlString;
use Cooolinho\FilamentMailbox\Support\ShortcutRegistry;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * @property Mailbox $record
 */
class BrowseMailbox extends Page implements HasTable
{
    use CancelsOutgoingMessages;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = MailboxResource::class;

    #[Url]
    public ?int $folder = null;

    #[Url]
    public ?int $label = null;

    /** A virtual view across folders: "starred" or "snoozed". */
    #[Url(as: 'view')]
    public ?string $virtualView = null;

    public const VIEW_STARRED = 'starred';

    public const VIEW_SNOOZED = 'snoozed';

    public const LAYOUT_LIST = 'list';

    public const LAYOUT_SPLIT = 'split';

    /** Search the whole mailbox instead of the current folder. */
    #[Url(as: 'all')]
    public bool $searchAllFolders = false;

    /** The message shown in the reading pane of the split view. */
    #[Url]
    public ?int $message = null;

    /** Position of the previewed message in the list, to select the next one after removing it. */
    #[Locked]
    public ?int $messageIndex = null;

    public string $mailboxLayout = self::LAYOUT_LIST;

    public const UNDO_MOVE_EVENT = 'filament-mailbox-undo-move';

    protected ?MailboxFolder $activeFolder = null;

    protected ?MailboxLabel $activeLabel = null;

    protected ?SearchQuery $cachedSearchQuery = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $this->folder = $this->getActiveFolder()?->getKey();
        $this->label = $this->getActiveLabel()?->getKey();
        $this->virtualView = match (true) {
            $this->label !== null => null,
            $this->virtualView === self::VIEW_STARRED && static::starredNavigationEnabled() => self::VIEW_STARRED,
            $this->virtualView === self::VIEW_SNOOZED && SnoozeService::enabled() => self::VIEW_SNOOZED,
            default => null,
        };

        $this->mailboxLayout = static::splitEnabled()
            ? (string) app(UserPreferences::class)->get(Filament::auth()->user(), 'layout', config('filament-mailbox.ui.split_pane.default', self::LAYOUT_LIST))
            : self::LAYOUT_LIST;

        if (! in_array($this->mailboxLayout, [self::LAYOUT_LIST, self::LAYOUT_SPLIT], true)) {
            $this->mailboxLayout = self::LAYOUT_LIST;
        }

        // A deep link restores the selection; foreign or inaccessible ids are dropped.
        $this->message = $this->isSplit() && $this->message !== null
            ? app(MessageViewer::class)->open($this->getRecord(), $this->message)?->getKey()
            : null;
    }

    public function dragAndDropEnabled(): bool
    {
        return (bool) config('filament-mailbox.ui.drag_and_drop', true)
            && $this->getRecord()->supports(ProviderCapability::MoveMessages);
    }

    /**
     * Drop of messages on a folder of the navigation. The payload is controlled by the
     * client: folder and messages are resolved through the mailbox, the batch is limited
     * and every message is authorised.
     *
     * @param  array<int, mixed>  $messages
     */
    public function moveMessages(int|string $folder, array $messages, MessageService $service): void
    {
        if (! $this->dragAndDropEnabled()) {
            return;
        }

        $user = Filament::auth()->user();

        if (! RateLimiter::attempt('filament-mailbox-move:'.$user?->getAuthIdentifier(), 60, fn (): bool => true)) {
            Notification::make()->danger()->title(__('filament-mailbox::mailbox.drag_and_drop.too_many'))->send();

            return;
        }

        /** @var Mailbox $mailbox */
        $mailbox = $this->getRecord();

        if (! is_numeric($folder) || ! ($target = $mailbox->folders()->where('is_active', true)->find((int) $folder))) {
            return;
        }

        $ids = collect($messages)
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->take(max(1, (int) config('filament-mailbox.ui.max_move_batch', 500)))
            ->all();

        $records = $mailbox->messages()
            ->with(['mailbox', 'folder'])
            ->whereKey($ids)
            ->get()
            ->filter(fn (MailboxMessage $message): bool => $message->folder_id !== $target->getKey() && Gate::allows('update', $message))
            ->values();

        if ($records->isEmpty()) {
            return;
        }

        if (MessageActions::moveToAndNotify($service, $records, $target->getKey())) {
            if ($this->message !== null && $records->contains('id', $this->message)) {
                $this->message = null;
                $this->messageIndex = null;
            }

            $this->deselectAllTableRecords();
        }
    }

    /**
     * Row shortcuts of the list (archive, star, delete, read/unread). The id
     * comes from the browser: it is resolved through the table query and the
     * existing row actions are mounted, so visibility, authorisation and
     * confirmations apply as for a click.
     */
    public function shortcut(string $shortcut, int|string $message, MessageService $messages): void
    {
        $name = ['archive' => 'archive', 'delete' => 'deleteMessage', 'mark_read' => 'markAsRead', 'mark_unread' => 'markAsUnread', 'star' => 'toggleStar'][$shortcut] ?? null;

        if ($name === null || ! ShortcutRegistry::enabled() || ! is_numeric($message) || ! ($record = $this->getTableRecord((string) (int) $message))) {
            return;
        }

        /** @var MailboxMessage $record */
        if ($name === 'toggleStar') {
            // The star is a column action.
            if (Gate::allows('update', $record)) {
                MessageActions::run(
                    fn () => $messages->setFlagged($record, ! $record->is_flagged),
                    $record->is_flagged ? __('filament-mailbox::mailbox.actions.unstar.success') : __('filament-mailbox::mailbox.actions.star.success'),
                );
            }

            return;
        }

        $action = $this->getTable()->getAction($name);
        $action?->getRootGroup()?->record($record) ?? $action?->record($record);

        if (! $action || $action->isHidden() || $action->isDisabled()) {
            return;
        }

        $this->mountAction($name, context: ['table' => true, 'recordKey' => (string) $record->getKey()]);

        // A message that left the list is no longer shown in the reading pane.
        if ($this->message === $record->getKey() && ! in_array($record->getKey(), $this->currentRecordKeys(), true)) {
            $this->previewRemoved($record->getKey());
        }
    }

    public static function shortcutsHelpAction(): Action
    {
        return Action::make('shortcutsHelp')
            ->label(__('filament-mailbox::mailbox.shortcuts.help'))
            ->tooltip(__('filament-mailbox::mailbox.shortcuts.help').' (?)')
            ->hiddenLabel()
            ->icon(Heroicon::OutlinedCommandLine)
            ->color('gray')
            ->visible(fn (): bool => ShortcutRegistry::enabled())
            ->modalHeading(__('filament-mailbox::mailbox.shortcuts.help'))
            ->modalWidth('lg')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('filament-mailbox::mailbox.shortcuts.close'))
            ->modalContent(fn () => view('filament-mailbox::shortcuts.help', ['shortcuts' => ShortcutRegistry::all()]));
    }

    public static function splitEnabled(): bool
    {
        return (bool) config('filament-mailbox.ui.split_pane.enabled', true);
    }

    public function isSplit(): bool
    {
        return static::splitEnabled() && $this->mailboxLayout === self::LAYOUT_SPLIT;
    }

    public function setLayout(string $layout): void
    {
        if (! static::splitEnabled() || ! in_array($layout, [self::LAYOUT_LIST, self::LAYOUT_SPLIT], true)) {
            return;
        }

        if ($user = Filament::auth()->user()) {
            app(UserPreferences::class)->set($user, 'layout', $layout);
        }

        // The table columns depend on the layout.
        $this->redirect(static::getUrl(array_filter([
            'record' => $this->getRecord(),
            'folder' => $this->label === null && ! $this->isVirtualView() ? $this->folder : null,
            'label' => $this->label,
            'view' => $this->isVirtualView() ? $this->virtualView : null,
        ])), navigate: true);
    }

    /**
     * Show a message in the reading pane (row click in the split view).
     */
    public function preview(int|string $record): void
    {
        $message = app(MessageViewer::class)->find($this->getRecord(), $record);

        if (! $message || ! $this->isSplit()) {
            return;
        }

        // Drafts of the app open in the editor, as in the list view.
        if ($message->draft && Gate::allows('update', $message->draft)) {
            $this->redirect(DraftActions::url($message->draft));

            return;
        }

        app(MessageViewer::class)->markRead($message);

        $this->message = $message->getKey();
        $keys = $this->currentRecordKeys();
        $this->messageIndex = ($index = array_search($this->message, $keys, true)) === false ? null : $index;

        // Below the xl breakpoint the reading pane is hidden: open the message page instead.
        $url = MailboxResource::getUrl('message', ['record' => $this->getRecord(), 'message' => $message]);
        $this->js('if (! window.matchMedia("(min-width: 80rem)").matches) { window.location.href = '.json_encode($url).'; }');
    }

    #[On(MessagePreview::REMOVED_EVENT)]
    public function previewRemoved(int $id): void
    {
        if ($this->message !== $id) {
            return;
        }

        $keys = $this->currentRecordKeys();

        $this->message = $keys === [] ? null : $keys[min($this->messageIndex ?? 0, count($keys) - 1)];

        if ($this->message !== null && $next = app(MessageViewer::class)->open($this->getRecord(), $this->message)) {
            $this->message = $next->getKey();
            $this->messageIndex = array_search($this->message, $this->currentRecordKeys(), true) ?: 0;
        } else {
            $this->message = null;
            $this->messageIndex = null;
        }
    }

    #[On(MessagePreview::CLOSED_EVENT)]
    public function previewClosed(): void
    {
        $this->message = null;
        $this->messageIndex = null;
    }

    /**
     * Re-renders the list after actions in the reading pane (flags, labels, tags).
     */
    #[On(MessagePreview::UPDATED_EVENT)]
    public function previewUpdated(): void {}

    /**
     * Keys of the messages on the current table page, in display order.
     *
     * @return array<int, int>
     */
    protected function currentRecordKeys(): array
    {
        $this->flushCachedTableRecords();

        $records = $this->getTableRecords();
        $items = method_exists($records, 'items') ? $records->items() : $records->all();

        return array_values(array_map(fn (MailboxMessage $message): int => (int) $message->getKey(), $items));
    }

    /**
     * Move messages back to their original folders (undo of archive/spam).
     * Messages and folders are resolved through the mailbox and authorised again.
     *
     * @param  array<int|string, int|string>  $moves  Original folder ids keyed by message id
     */
    #[On(self::UNDO_MOVE_EVENT)]
    public function undoMove(array $moves, MessageService $messages): void
    {
        /** @var Mailbox $mailbox */
        $mailbox = $this->getRecord();

        $records = $mailbox->messages()
            ->with(['mailbox', 'folder'])
            ->whereKey(array_keys($moves))
            ->get()
            ->filter(fn (MailboxMessage $message): bool => Gate::allows('update', $message));

        MessageActions::run(function () use ($records, $moves, $mailbox, $messages): void {
            foreach ($records->groupBy(fn (MailboxMessage $message): int => (int) $moves[$message->getKey()]) as $folderId => $group) {
                if ($folder = $mailbox->folders()->where('is_active', true)->find($folderId)) {
                    $messages->move($group, $folder);
                }
            }
        }, __('filament-mailbox::mailbox.actions.undo.success'));

        $this->resetTable();
    }

    /**
     * Whether the active folder is the spam folder of the mailbox.
     */
    public function isSpamFolder(): bool
    {
        $folder = $this->getActiveFolder();

        return ! $this->spansFolders()
            && $folder !== null
            && ($folder->special_use === SpecialUse::Junk || $folder->getKey() === $this->getRecord()->spam_folder_id);
    }

    /**
     * Whether the active folder is the drafts folder of the mailbox.
     */
    public function isDraftsFolder(): bool
    {
        return ! $this->spansFolders() && $this->getActiveFolder()?->special_use === SpecialUse::Drafts;
    }

    public function isStarredView(): bool
    {
        return $this->virtualView === self::VIEW_STARRED && $this->label === null;
    }

    public function isSnoozedView(): bool
    {
        return $this->virtualView === self::VIEW_SNOOZED && $this->label === null;
    }

    public function isSearchingAllFolders(): bool
    {
        return $this->searchAllFolders && filled($this->getTableSearch()) && $this->label === null && ! $this->isVirtualView();
    }

    /**
     * Mailbox and folder the table search is limited to.
     */
    public function searchScope(): SearchScope
    {
        return new SearchScope(
            (int) $this->getRecord()->getKey(),
            $this->spansFolders() ? null : $this->getActiveFolder()?->getKey(),
        );
    }

    public function searchSnippet(MailboxMessage $message): ?HtmlString
    {
        $search = (string) $this->getTableSearch();

        return $search === '' ? null : app(SnippetBuilder::class)->for($message, $this->cachedSearchQuery ??= app(SearchManager::class)->parse($search));
    }

    public function updatedTableSearch(): void
    {
        $this->cachedSearchQuery = null;
    }

    public function isVirtualView(): bool
    {
        return $this->isStarredView() || $this->isSnoozedView();
    }

    /**
     * Whether messages of several folders are listed (label, starred or snoozed view).
     */
    public function spansFolders(): bool
    {
        return $this->getActiveLabel() !== null || $this->isVirtualView() || $this->isSearchingAllFolders();
    }

    public static function starredNavigationEnabled(): bool
    {
        return (bool) config('filament-mailbox.starred.navigation', true);
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Start;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->getActiveLabel()?->name
            ?? ($this->isStarredView() ? __('filament-mailbox::mailbox.starred.label') : null)
            ?? ($this->isSnoozedView() ? __('filament-mailbox::mailbox.snooze.label') : null)
            ?? $this->getActiveFolder()?->special_use?->getLabel()
            ?? $this->getActiveFolder()?->name;
    }

    public function getBreadcrumb(): ?string
    {
        return __('filament-mailbox::mailbox.pages.browse.breadcrumb');
    }

    /**
     * The folder is resolved through the mailbox relation, so a crafted
     * folder ID of another mailbox is ignored.
     */
    public function getActiveFolder(): ?MailboxFolder
    {
        if ($this->activeFolder?->getKey() === $this->folder && $this->activeFolder !== null) {
            return $this->activeFolder;
        }

        /** @var Mailbox $mailbox */
        $mailbox = $this->getRecord();

        return $this->activeFolder = ($this->folder ? $mailbox->folders()->find($this->folder) : null)
            ?? app(FolderNavigation::class)->defaultFolder($mailbox);
    }

    /**
     * Like folders, labels are resolved through the mailbox relation.
     */
    public function getActiveLabel(): ?MailboxLabel
    {
        if ($this->label === null) {
            return null;
        }

        if ($this->activeLabel?->getKey() === $this->label) {
            return $this->activeLabel;
        }

        /** @var Mailbox $mailbox */
        $mailbox = $this->getRecord();

        return $this->activeLabel = $mailbox->labels()->visible()->find($this->label);
    }

    public function getSubNavigation(): array
    {
        $navigation = app(FolderNavigation::class);
        $folders = $navigation->folders($this->getRecord());
        $items = [];

        foreach ($navigation->systemFolders($folders) as $index => $folder) {
            $items[] = $this->folderItem($folder, $folder->special_use->getLabel())
                ->icon($folder->special_use->getIcon())
                ->sort($index * 3)
                ->badge($folder->special_use === SpecialUse::Inbox ? $this->unreadBadge($folder) : null);

            // "Starred" and "Snoozed" follow the inbox.
            if ($folder->special_use === SpecialUse::Inbox && static::starredNavigationEnabled()) {
                $items[] = $this->starredItem()->sort($index * 3 + 1);
            }

            if ($folder->special_use === SpecialUse::Inbox && SnoozeService::enabled()) {
                $items[] = $this->snoozedItem()->sort($index * 3 + 2);
            }
        }

        if (! $folders->contains('special_use', SpecialUse::Inbox)) {
            if (SnoozeService::enabled()) {
                array_unshift($items, $this->snoozedItem()->sort(-1));
            }

            if (static::starredNavigationEnabled()) {
                array_unshift($items, $this->starredItem()->sort(-2));
            }
        }

        $customItems = [];

        foreach ($navigation->customFolders($folders) as $index => $custom) {
            $customItems[] = $this->folderItem($custom['folder'], $custom['label'])
                ->icon($custom['root'] ? null : Heroicon::OutlinedFolder)
                ->parentItem($custom['root'] ? $this->folderKey($custom['root']) : null)
                ->sort($index);
        }

        if ($customItems !== []) {
            $items[] = NavigationGroup::make(__('filament-mailbox::mailbox.pages.browse.other_folders'))
                ->items($customItems);
        }

        $labelItems = [];

        foreach ($this->getRecord()->supportsLabels() ? $this->getRecord()->labels()->visible()->orderBy('name')->get() : [] as $index => $label) {
            $labelItems[] = NavigationItem::make($label->name)
                ->key('label-'.$label->getKey())
                ->icon(Heroicon::OutlinedTag)
                ->url(static::getUrl(['record' => $this->getRecord(), 'label' => $label->getKey()]))
                ->isActiveWhen(fn (): bool => $this->label === $label->getKey())
                ->sort($index);
        }

        if ($labelItems !== []) {
            $items[] = NavigationGroup::make(__('filament-mailbox::mailbox.labels.plural_label'))
                ->items($labelItems);
        }

        return $items;
    }

    public function content(Schema $schema): Schema
    {
        $dragAndDrop = View::make('filament-mailbox::pages.browse-drag-and-drop')
            ->visible(fn (): bool => $this->dragAndDropEnabled());

        $shortcuts = View::make('filament-mailbox::shortcuts.script')
            ->viewData(fn (): array => [
                'page' => 'list',
                'bindings' => ShortcutRegistry::map(ShortcutRegistry::LIST_ACTIONS),
                'inboxUrl' => static::getUrl(['record' => $this->getRecord()]),
            ])
            ->visible(fn (): bool => ShortcutRegistry::enabled());

        // Rendered after the table, so the search has run.
        $truncated = Callout::make(__('filament-mailbox::mailbox.search.truncated', ['count' => app(SearchManager::class)->candidateLimit()]))
            ->warning()
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->visible(fn (): bool => app(SearchManager::class)->wasTruncated());

        if (! $this->isSplit()) {
            return $schema->components([
                $dragAndDrop,
                $shortcuts,
                EmbeddedTable::make(),
                $truncated,
            ]);
        }

        return $schema->components([
            $dragAndDrop,
            $shortcuts,
            View::make('filament-mailbox::pages.browse-split-styles'),
            Grid::make(['default' => 1, 'xl' => 12])
                ->schema([
                    Group::make([EmbeddedTable::make(), $truncated])
                        ->columnSpan(['default' => 1, 'xl' => 5]),
                    Livewire::make(MessagePreview::class, fn (): array => [
                        'mailboxId' => $this->getRecord()->getKey(),
                        'messageId' => $this->message,
                    ])
                        ->key('message-preview')
                        ->extraAttributes(['class' => 'fi-mailbox-split-preview'])
                        ->columnSpan(['default' => 1, 'xl' => 7]),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return MessagesTable::configure($table)
            ->query(function () {
                $query = MailboxMessage::query()->where('mailbox_id', $this->getRecord()->getKey());

                // A label shows its messages across all folders.
                if ($label = $this->getActiveLabel()) {
                    return $query->whereHas('labels', fn ($labels) => $labels->whereKey($label->getKey()))->notSnoozed();
                }

                if ($this->isStarredView()) {
                    return app(FolderNavigation::class)->starredQuery($this->getRecord());
                }

                if ($this->isSnoozedView()) {
                    return app(FolderNavigation::class)->snoozedQuery($this->getRecord());
                }

                if ($this->isSearchingAllFolders()) {
                    return $query->notSnoozed();
                }

                // Snoozed messages are hidden until they return.
                return $query->where('folder_id', $this->getActiveFolder()?->getKey() ?? 0)
                    ->notSnoozed()
                    ->when(config('filament-mailbox.read_receipts.hide_receipt_messages', false), fn ($query) => $query->where('is_receipt', false));
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            ComposeMessageAction::make(),
            Action::make('drafts')
                ->label(__('filament-mailbox::mailbox.drafts.plural_label'))
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->badge(fn (): ?string => ($count = ListDrafts::query($this->getRecord())->count()) > 0 ? (string) $count : null)
                ->visible(fn (): bool => DraftService::enabled() && Gate::allows('send', $this->getRecord()))
                ->url(fn (): string => MailboxResource::getUrl('drafts', ['record' => $this->getRecord()])),
            Action::make('outbox')
                ->label(__('filament-mailbox::mailbox.outbox.label'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->badge(fn (): ?string => ($count = ListOutgoingMessages::attentionCount($this->getRecord())) > 0 ? (string) $count : null)
                ->visible(fn (): bool => Gate::allows('send', $this->getRecord()) || ListOutgoingMessages::query($this->getRecord())->exists())
                ->url(fn (): string => MailboxResource::getUrl('outbox', ['record' => $this->getRecord()])),
            SyncMailboxAction::make(),
            static::manageFoldersAction($this->getRecord()),
            Action::make('layoutList')
                ->label(__('filament-mailbox::mailbox.split.list'))
                ->tooltip(__('filament-mailbox::mailbox.split.list'))
                ->hiddenLabel()
                ->icon(Heroicon::OutlinedQueueList)
                ->color(fn (): string => $this->isSplit() ? 'gray' : 'primary')
                ->outlined(fn (): bool => $this->isSplit())
                ->visible(fn (): bool => static::splitEnabled())
                ->action(fn () => $this->setLayout(self::LAYOUT_LIST)),
            Action::make('layoutSplit')
                ->label(__('filament-mailbox::mailbox.split.split'))
                ->tooltip(__('filament-mailbox::mailbox.split.split'))
                ->hiddenLabel()
                ->icon(Heroicon::OutlinedViewColumns)
                ->color(fn (): string => $this->isSplit() ? 'primary' : 'gray')
                ->outlined(fn (): bool => ! $this->isSplit())
                ->visible(fn (): bool => static::splitEnabled())
                ->action(fn () => $this->setLayout(self::LAYOUT_SPLIT)),
            static::shortcutsHelpAction(),
        ];
    }

    public static function manageFoldersAction(Mailbox $mailbox): Action
    {
        return Action::make('manageFolders')
            ->label(__('filament-mailbox::mailbox.folders.actions.manage'))
            ->icon(Heroicon::OutlinedFolder)
            ->color('gray')
            ->visible(fn (): bool => config('filament-mailbox.folders.management', true) && Gate::allows('manageFolders', $mailbox))
            ->url(fn (): string => MailboxResource::getUrl('folders', ['record' => $mailbox]));
    }

    protected function folderItem(MailboxFolder $folder, string $label): NavigationItem
    {
        return NavigationItem::make($label)
            ->key($this->folderKey($folder))
            // Drop target for dragged messages (integer id only).
            ->extraAttributes($this->dragAndDropEnabled() && $folder->is_active ? ['data-filament-mailbox-folder' => (int) $folder->getKey()] : [])
            ->url(static::getUrl(['record' => $this->getRecord(), 'folder' => $folder->getKey()]))
            ->isActiveWhen(fn (): bool => $this->label === null && ! $this->isVirtualView() && $this->folder === $folder->getKey());
    }

    protected function starredItem(): NavigationItem
    {
        $count = app(FolderNavigation::class)->starredCount($this->getRecord());

        return NavigationItem::make(__('filament-mailbox::mailbox.starred.label'))
            ->key('starred')
            ->icon(Heroicon::OutlinedStar)
            ->url(static::getUrl(['record' => $this->getRecord(), 'view' => self::VIEW_STARRED]))
            ->isActiveWhen(fn (): bool => $this->isStarredView())
            ->badge($count > 0 ? (string) $count : null);
    }

    protected function snoozedItem(): NavigationItem
    {
        $count = app(FolderNavigation::class)->snoozedCount($this->getRecord());

        return NavigationItem::make(__('filament-mailbox::mailbox.snooze.label'))
            ->key('snoozed')
            ->icon(Heroicon::OutlinedClock)
            ->url(static::getUrl(['record' => $this->getRecord(), 'view' => self::VIEW_SNOOZED]))
            ->isActiveWhen(fn (): bool => $this->isSnoozedView())
            ->badge($count > 0 ? (string) $count : null);
    }

    protected function folderKey(MailboxFolder $folder): string
    {
        return 'folder-'.$folder->getKey();
    }

    protected function unreadBadge(MailboxFolder $folder): ?string
    {
        $count = app(FolderNavigation::class)->unreadCount($folder);

        return $count > 0 ? (string) $count : null;
    }
}
