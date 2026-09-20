<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages;

use Closure;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Exceptions\InvalidFolderOperation;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\MessageActions;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Services\FolderManager;
use Cooolinho\FilamentMailbox\Support\FolderPath;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Throwable;

/**
 * Folder tree of a mailbox with create, rename, move, subscribe and delete.
 *
 * @property Mailbox $record
 */
class ManageFolders extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = MailboxResource::class;

    /** @var array<int, int>|null */
    protected ?array $depths = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(Gate::allows('manageFolders', $this->getRecord()), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return __('filament-mailbox::mailbox.folders.title', ['mailbox' => $this->getRecord()->name]);
    }

    public function getBreadcrumb(): ?string
    {
        return __('filament-mailbox::mailbox.folders.breadcrumb');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->getRecord()->folders()->where('is_active', true)->withCount('messages')->getQuery())
            ->defaultSort('full_name')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament-mailbox::mailbox.fields.name'))
                    // Plain text, escaped by Filament.
                    ->formatStateUsing(fn (string $state, MailboxFolder $record): string => str_repeat('— ', $this->depth($record)).$state)
                    ->description(fn (MailboxFolder $record): ?string => $record->full_name !== $record->name ? FolderPath::decode($record->full_name) : null),
                TextColumn::make('special_use')
                    ->label(__('filament-mailbox::mailbox.folders.fields.role'))
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('message_count')
                    ->label(__('filament-mailbox::mailbox.labels.fields.messages'))
                    ->state(fn (MailboxFolder $record): int => $record->message_count ?? $record->messages_count)
                    ->numeric(),
                TextColumn::make('unseen_count')
                    ->label(__('filament-mailbox::mailbox.messages.unread'))
                    ->numeric()
                    ->placeholder('—'),
                TextColumn::make('size_bytes')
                    ->label(__('filament-mailbox::mailbox.attachments.fields.size'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : Number::fileSize($state))
                    ->placeholder('—'),
                IconColumn::make('is_subscribed')
                    ->label(__('filament-mailbox::mailbox.folders.fields.subscribed'))
                    ->boolean()
                    ->visible(fn (): bool => $this->getRecord()->supports(ProviderCapability::FolderSubscriptions)),
            ])
            ->headerActions([
                $this->createAction(),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->renameAction(),
                    $this->moveAction(),
                    $this->subscribeAction(),
                    $this->deleteAction(),
                ]),
            ])
            ->emptyStateHeading(__('filament-mailbox::mailbox.folders.empty'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('browse')
                ->label(__('filament-mailbox::mailbox.pages.browse.breadcrumb'))
                ->icon(Heroicon::OutlinedInbox)
                ->color('gray')
                ->visible(fn (): bool => MailboxResource::canView($this->getRecord()))
                ->url(fn (): string => MailboxResource::getUrl('browse', ['record' => $this->getRecord()])),
        ];
    }

    protected function createAction(): Action
    {
        return Action::make('createFolder')
            ->label(__('filament-mailbox::mailbox.folders.actions.create'))
            ->icon(Heroicon::OutlinedFolderPlus)
            ->visible(fn (): bool => $this->managesFolders())
            ->modalWidth('md')
            ->schema([
                $this->nameInput(),
                Select::make('parent')
                    ->label(__('filament-mailbox::mailbox.folders.fields.parent'))
                    ->placeholder(__('filament-mailbox::mailbox.folders.top_level'))
                    ->options(fn (): array => $this->folderOptions($this->getRecord()->folders()->where('is_active', true)->orderBy('full_name')->get()))
                    ->searchable(),
            ])
            ->action(fn (array $data, FolderManager $folders, Action $action) => $this->attempt(
                fn () => $folders->create($this->getRecord(), $data['name'], $this->folder($data['parent'] ?? null)),
                __('filament-mailbox::mailbox.folders.actions.created'),
                $action,
            ));
    }

    protected function renameAction(): Action
    {
        return $this->recordAction('renameFolder', 'rename', Heroicon::OutlinedPencil)
            ->fillForm(fn (MailboxFolder $record): array => ['name' => $record->name])
            ->schema([$this->nameInput()])
            ->action(fn (MailboxFolder $record, array $data, FolderManager $folders, Action $action) => $this->attempt(
                fn () => $folders->rename($record, $data['name']),
                __('filament-mailbox::mailbox.folders.actions.renamed'),
                $action,
            ));
    }

    protected function moveAction(): Action
    {
        return $this->recordAction('moveFolder', 'move', Heroicon::OutlinedArrowsRightLeft)
            ->fillForm(fn (MailboxFolder $record): array => ['parent' => $record->parent_id])
            ->schema(fn (MailboxFolder $record, FolderManager $folders): array => [
                Select::make('parent')
                    ->label(__('filament-mailbox::mailbox.folders.fields.parent'))
                    ->placeholder(__('filament-mailbox::mailbox.folders.top_level'))
                    // The folder itself and its descendants are no valid parents.
                    ->options($this->folderOptions($folders->possibleParents($record)))
                    ->searchable(),
            ])
            ->action(fn (MailboxFolder $record, array $data, FolderManager $folders, Action $action) => $this->attempt(
                fn () => $folders->move($record, $this->folder($data['parent'] ?? null)),
                __('filament-mailbox::mailbox.folders.actions.moved'),
                $action,
            ));
    }

    protected function subscribeAction(): Action
    {
        return Action::make('toggleSubscription')
            ->label(fn (MailboxFolder $record): string => $record->is_subscribed
                ? __('filament-mailbox::mailbox.folders.actions.unsubscribe')
                : __('filament-mailbox::mailbox.folders.actions.subscribe'))
            ->icon(fn (MailboxFolder $record): Heroicon => $record->is_subscribed ? Heroicon::OutlinedBellSlash : Heroicon::OutlinedBell)
            ->visible(fn (): bool => $this->managesFolders() && $this->getRecord()->supports(ProviderCapability::FolderSubscriptions))
            ->action(fn (MailboxFolder $record, FolderManager $folders) => $this->attempt(
                fn () => $folders->subscribe($record, ! $record->is_subscribed),
                __('filament-mailbox::mailbox.folders.actions.saved'),
            ));
    }

    protected function deleteAction(): Action
    {
        return $this->recordAction('deleteFolder', 'delete', Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('filament-mailbox::mailbox.folders.delete_description'))
            ->schema(fn (MailboxFolder $record): array => [
                Radio::make('mode')
                    ->label(__('filament-mailbox::mailbox.folders.fields.contents'))
                    ->options(array_filter([
                        FolderManager::DELETE_TO_TRASH => __('filament-mailbox::mailbox.folders.delete_modes.trash'),
                        FolderManager::DELETE_PERMANENTLY => config('filament-mailbox.folders.allow_permanent_delete', false)
                            ? __('filament-mailbox::mailbox.folders.delete_modes.delete')
                            : null,
                    ]))
                    ->default(FolderManager::DELETE_TO_TRASH)
                    ->required(),
                TextInput::make('confirmation')
                    ->label(__('filament-mailbox::mailbox.folders.fields.confirmation', ['name' => $record->name]))
                    ->required()
                    ->in([$record->name])
                    ->validationMessages(['in' => __('filament-mailbox::mailbox.folders.errors.confirmation')]),
            ])
            ->action(fn (MailboxFolder $record, array $data, FolderManager $folders, Action $action) => $this->attempt(
                fn () => $folders->delete($record, $data['mode'] ?? FolderManager::DELETE_TO_TRASH),
                __('filament-mailbox::mailbox.folders.actions.deleted'),
                $action,
            ));
    }

    /**
     * Rename, move and delete: disabled for protected system folders.
     */
    protected function recordAction(string $name, string $key, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label(__("filament-mailbox::mailbox.folders.actions.{$key}"))
            ->icon($icon)
            ->modalWidth('md')
            ->visible(fn (): bool => $this->managesFolders())
            ->disabled(fn (MailboxFolder $record, FolderManager $folders): bool => ! $folders->isModifiable($record))
            ->tooltip(fn (MailboxFolder $record, FolderManager $folders): ?string => $folders->isModifiable($record)
                ? null
                : __('filament-mailbox::mailbox.folders.errors.protected'));
    }

    protected function nameInput(): TextInput
    {
        return TextInput::make('name')
            ->label(__('filament-mailbox::mailbox.fields.name'))
            ->required()
            ->maxLength(FolderPath::MAX_LENGTH)
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                $delimiter = $this->getRecord()->folders()->whereNotNull('delimiter')->value('delimiter');

                if ($error = FolderPath::nameError((string) $value, $delimiter)) {
                    $fail(__("filament-mailbox::mailbox.folders.errors.{$error}", ['delimiter' => (string) $delimiter]));
                }
            });
    }

    protected function managesFolders(): bool
    {
        return app(FolderManager::class)->supports($this->getRecord());
    }

    /**
     * Folder ids from forms are resolved through the mailbox.
     */
    protected function folder(mixed $id): ?MailboxFolder
    {
        return blank($id) ? null : $this->getRecord()->folders()->where('is_active', true)->findOrFail($id);
    }

    /**
     * @param  iterable<MailboxFolder>  $folders
     * @return array<int, string>
     */
    protected function folderOptions(iterable $folders): array
    {
        $options = [];

        foreach ($folders as $folder) {
            $options[$folder->getKey()] = $folder->special_use?->getLabel() ?? FolderPath::decode($folder->full_name);
        }

        return $options;
    }

    protected function depth(MailboxFolder $folder): int
    {
        $this->depths ??= $this->getRecord()->folders()->where('is_active', true)->pluck('parent_id', 'id')->all();

        $depth = 0;
        $parent = $this->depths[$folder->getKey()] ?? null;

        while ($parent !== null && $depth < 50) {
            $depth++;
            $parent = $this->depths[$parent] ?? null;
        }

        return $depth;
    }

    protected function attempt(Closure $operation, string $success, ?Action $action = null): void
    {
        $this->depths = null;

        try {
            $operation();
        } catch (InvalidFolderOperation $exception) {
            // Rejected before reaching the server; the message is meant for the user.
            Notification::make()
                ->danger()
                ->title($exception->getMessage())
                ->send();

            $action?->halt();

            return;
        } catch (Throwable $exception) {
            MessageActions::run(fn () => throw $exception, $success);

            $action?->halt();

            return;
        }

        Notification::make()
            ->success()
            ->title($success)
            ->send();
    }
}
