<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Tables;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\DraftActions;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\LabelActions;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\MessageActions;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\TagActions;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\MessageSearch;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

class MessagesTable
{
    public static function configure(Table $table): Table
    {
        $weight = fn (MailboxMessage $record): ?FontWeight => $record->is_read ? null : FontWeight::Bold;

        $columns = [
            IconColumn::make('is_flagged')
                ->label(__('filament-mailbox::mailbox.starred.column'))
                ->icon(fn (bool $state): Heroicon => $state ? Heroicon::Star : Heroicon::OutlinedStar)
                ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                ->tooltip(fn (bool $state): string => $state
                    ? __('filament-mailbox::mailbox.actions.unstar.label')
                    : __('filament-mailbox::mailbox.actions.star.label'))
                // No ToggleColumn: the provider must be called and the policy checked.
                ->action(MessageActions::toggleStar())
                ->grow(false),
            IconColumn::make('is_read')
                ->label(__('filament-mailbox::mailbox.messages.fields.status'))
                ->icon(fn (bool $state): Heroicon => $state ? Heroicon::OutlinedEnvelopeOpen : Heroicon::Envelope)
                ->color(fn (bool $state): string => $state ? 'gray' : 'primary')
                ->tooltip(fn (bool $state): string => $state
                    ? __('filament-mailbox::mailbox.messages.read')
                    : __('filament-mailbox::mailbox.messages.unread'))
                ->grow(false),
            TextColumn::make('from_name')
                ->label(__('filament-mailbox::mailbox.messages.fields.from'))
                ->state(fn (MailboxMessage $record): string => $record->fromLabel())
                ->weight($weight)
                ->limit(40)
                ->visible(fn (BrowseMailbox $livewire): bool => ! $livewire->isDraftsFolder()),
            // Drafts show their recipients instead of the sender.
            TextColumn::make('to')
                ->label(__('filament-mailbox::mailbox.messages.fields.to'))
                ->state(fn (MailboxMessage $record): string => implode(', ', array_map(fn (array $address): string => $address['name'] ?: $address['address'], $record->to ?? [])))
                ->placeholder('—')
                ->limit(40)
                ->visible(fn (BrowseMailbox $livewire): bool => $livewire->isDraftsFolder()),
            TextColumn::make('subject')
                ->label(__('filament-mailbox::mailbox.messages.fields.subject'))
                ->icon(fn (MailboxMessage $record): ?Heroicon => static::subjectIcon($record))
                ->placeholder(__('filament-mailbox::mailbox.messages.no_subject'))
                ->weight($weight)
                ->limit(80)
                ->wrap(),
            TextColumn::make('labels')
                ->label(__('filament-mailbox::mailbox.labels.plural_label'))
                ->state(fn (MailboxMessage $record): array => $record->labels->where('is_hidden', false)->pluck('name')->all())
                ->badge()
                ->color(fn (string $state, MailboxMessage $record): array|string => LabelActions::color($record->labels->firstWhere('name', $state)))
                ->visible(fn (BrowseMailbox $livewire): bool => $livewire->getRecord()->supportsLabels()),
            TextColumn::make('tags')
                ->label(__('filament-mailbox::mailbox.tags.plural_label'))
                ->state(fn (MailboxMessage $record): array => $record->tags->pluck('name')->all())
                ->badge()
                ->color(fn (string $state, MailboxMessage $record): array|string => TagActions::color($record->tags->firstWhere('name', $state)))
                ->visible(fn (BrowseMailbox $livewire): bool => TagActions::options($livewire->getRecord()) !== []),
            TextColumn::make('folder.name')
                ->label(__('filament-mailbox::mailbox.labels.fields.folder'))
                ->state(fn (MailboxMessage $record): ?string => $record->folder?->special_use?->getLabel() ?? $record->folder?->name)
                ->visible(fn (BrowseMailbox $livewire): bool => $livewire->spansFolders()),
            TextColumn::make('snoozed_until')
                ->label(__('filament-mailbox::mailbox.snooze.column'))
                ->dateTime()
                ->description(fn (MailboxMessage $record): ?string => $record->snoozed_until?->diffForHumans())
                ->icon(Heroicon::OutlinedClock)
                ->sortable()
                ->visible(fn (BrowseMailbox $livewire): bool => $livewire->isSnoozedView()),
            IconColumn::make('has_attachments')
                ->label(__('filament-mailbox::mailbox.messages.fields.attachments'))
                ->icon(fn (bool $state): ?Heroicon => $state ? Heroicon::OutlinedPaperClip : null)
                ->color('gray')
                ->grow(false),
            TextColumn::make('search_snippet')
                ->label(__('filament-mailbox::mailbox.search.snippet'))
                ->state(fn (MailboxMessage $record, BrowseMailbox $livewire): ?HtmlString => $livewire->searchSnippet($record))
                ->wrap()
                ->color('gray')
                ->visible(fn (BrowseMailbox $livewire): bool => (bool) config('filament-mailbox.search.snippets', true) && filled($livewire->getTableSearch())),
            TextColumn::make('received_at')
                ->label(__('filament-mailbox::mailbox.messages.fields.date'))
                ->dateTime()
                ->weight($weight)
                ->sortable(),
        ];

        $livewire = $table->getLivewire();
        // Switching the layout reloads the page, so the columns can be chosen once.
        $split = fn (): bool => $livewire instanceof BrowseMailbox && $livewire->isSplit();

        return $table
            ->columns($split() ? static::compactColumns($weight) : $columns)
            ->recordAction(fn (): ?string => $split() ? 'preview' : null)
            ->recordClasses(fn (MailboxMessage $record): array => [
                'fi-mailbox-selected' => $split() && $livewire->message === $record->getKey(),
                // Drag source for moving to a folder; the id is an integer.
                'fi-mailbox-draggable fi-mailbox-message-'.(int) $record->getKey() => $livewire instanceof BrowseMailbox && $livewire->dragAndDropEnabled(),
                // Keyboard shortcuts find rows by these classes.
                'fi-mailbox-row' => true,
                'fi-mailbox-message-'.(int) $record->getKey() => ! ($livewire instanceof BrowseMailbox && $livewire->dragAndDropEnabled()),
            ])
            // Drafts of the app open in the editor, the split view opens messages in the reading pane.
            ->recordUrl(fn (MailboxMessage $record): ?string => match (true) {
                $record->draft && Gate::allows('update', $record->draft) => DraftActions::url($record->draft),
                $split() => null,
                default => MailboxResource::getUrl('message', [
                    'record' => $record->mailbox_id,
                    'message' => $record,
                ]),
            })
            ->recordActions([
                ActionGroup::make([
                    LabelActions::edit(),
                    TagActions::edit(),
                    MessageActions::markAsRead(),
                    MessageActions::markAsUnread(),
                    MessageActions::snooze(),
                    MessageActions::unsnooze(),
                    MessageActions::moveTo(),
                    MessageActions::archive(),
                    MessageActions::spam(),
                    MessageActions::notSpam(),
                    MessageActions::delete(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    MessageActions::bulkMarkAsRead(),
                    MessageActions::bulkMarkAsUnread(),
                    MessageActions::bulkStar(),
                    MessageActions::bulkUnstar(),
                    LabelActions::bulkAttach(),
                    LabelActions::bulkDetach(),
                    TagActions::bulkAttach(),
                    TagActions::bulkDetach(),
                    MessageActions::bulkSnooze(),
                    MessageActions::bulkUnsnooze(),
                    MessageActions::bulkMoveTo(),
                    MessageActions::bulkArchive(),
                    MessageActions::bulkSpam(),
                    MessageActions::bulkNotSpam(),
                    MessageActions::bulkDelete(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['labels', 'folder', 'draft', 'tags' => fn ($tags) => $tags->ordered()]))
            ->filters([
                TernaryFilter::make('is_flagged')
                    ->label(__('filament-mailbox::mailbox.starred.filter'))
                    ->visible(fn (BrowseMailbox $livewire): bool => ! $livewire->isStarredView()),
                TernaryFilter::make('is_receipt')
                    ->label(__('filament-mailbox::mailbox.read_receipts.filter'))
                    ->trueLabel(__('filament-mailbox::mailbox.read_receipts.filter_only'))
                    ->falseLabel(__('filament-mailbox::mailbox.read_receipts.filter_hide')),
                SelectFilter::make('labels')
                    ->label(__('filament-mailbox::mailbox.labels.plural_label'))
                    ->multiple()
                    ->options(fn (BrowseMailbox $livewire): array => LabelActions::options($livewire->getRecord()))
                    ->visible(fn (BrowseMailbox $livewire): bool => $livewire->getRecord()->supportsLabels())
                    ->query(fn (Builder $query, array $data, BrowseMailbox $livewire): Builder => blank($data['values'] ?? null)
                        ? $query
                        : $query->whereHas('labels', fn (Builder $labels) => $labels
                            ->where('mailbox_labels.mailbox_id', $livewire->getRecord()->getKey())
                            ->whereIn('mailbox_labels.id', $data['values']))),
                SelectFilter::make('tags')
                    ->label(__('filament-mailbox::mailbox.tags.plural_label'))
                    ->multiple()
                    ->options(fn (BrowseMailbox $livewire): array => TagActions::options($livewire->getRecord()))
                    ->visible(fn (BrowseMailbox $livewire): bool => TagActions::options($livewire->getRecord()) !== [])
                    ->query(fn (Builder $query, array $data, BrowseMailbox $livewire): Builder => blank($data['values'] ?? null)
                        ? $query
                        : $query->whereHas('tags', fn (Builder $tags) => $tags
                            ->visibleFor($livewire->getRecord())
                            ->whereIn('mailbox_tags.id', $data['values']))),
            ])
            ->searchable()
            ->searchPlaceholder(__('filament-mailbox::mailbox.messages.search_placeholder'))
            ->searchUsing(fn (Builder $query, string $search, MessageSearch $messageSearch) => $livewire instanceof BrowseMailbox
                ? $messageSearch->apply($query, $search, $livewire->searchScope())
                : $query)
            ->headerActions([
                Action::make('searchAllFolders')
                    ->label(fn (): string => $livewire instanceof BrowseMailbox && $livewire->searchAllFolders
                        ? __('filament-mailbox::mailbox.search.current_folder')
                        : __('filament-mailbox::mailbox.search.all_folders'))
                    ->icon(Heroicon::OutlinedFolderOpen)
                    ->color(fn (): string => $livewire instanceof BrowseMailbox && $livewire->searchAllFolders ? 'primary' : 'gray')
                    ->size('sm')
                    ->visible(fn (): bool => $livewire instanceof BrowseMailbox && $livewire->getActiveLabel() === null && ! $livewire->isVirtualView() && filled($livewire->getTableSearch()))
                    ->action(function () use ($livewire): void {
                        $livewire->searchAllFolders = ! $livewire->searchAllFolders;
                        $livewire->resetPage();
                    }),
                Action::make('searchHelp')
                    ->label(__('filament-mailbox::mailbox.search.help'))
                    ->icon(Heroicon::OutlinedQuestionMarkCircle)
                    ->color('gray')
                    ->size('sm')
                    ->modalHeading(__('filament-mailbox::mailbox.search.help'))
                    ->modalWidth('lg')
                    ->modalSubmitAction(false)
                    ->modalContent(fn () => view('filament-mailbox::search.help')),
            ])
            // Returned snoozed messages are on top (sort_at); the snoozed view lists the next returning first.
            ->defaultSort(
                fn (): string => $livewire instanceof BrowseMailbox && $livewire->isSnoozedView() ? 'snoozed_until' : 'sort_at',
                fn (): string => $livewire instanceof BrowseMailbox && $livewire->isSnoozedView() ? 'asc' : 'desc',
            )
            ->emptyStateIcon(Heroicon::OutlinedInbox)
            ->emptyStateHeading(__('filament-mailbox::mailbox.messages.empty.heading'))
            ->emptyStateDescription(__('filament-mailbox::mailbox.messages.empty.description'));
    }

    /**
     * Drafts of the app and receipts (read receipts, delivery reports) are marked.
     */
    public static function subjectIcon(MailboxMessage $record): ?Heroicon
    {
        return match (true) {
            DraftActions::isDraftMessage($record) => Heroicon::OutlinedPencilSquare,
            $record->is_receipt => Heroicon::OutlinedCheckBadge,
            default => null,
        };
    }

    /**
     * Narrow list for the split view: sender and subject stacked, date on the right.
     *
     * @param  \Closure(MailboxMessage): ?FontWeight  $weight
     * @return array<int, Split>
     */
    public static function compactColumns(\Closure $weight): array
    {
        return [
            Split::make([
                IconColumn::make('is_flagged')
                    ->icon(fn (bool $state): Heroicon => $state ? Heroicon::Star : Heroicon::OutlinedStar)
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                    ->action(MessageActions::toggleStar())
                    ->grow(false),
                Stack::make([
                    TextColumn::make('from_name')
                        ->state(fn (MailboxMessage $record, BrowseMailbox $livewire): string => $livewire->isDraftsFolder()
                            ? implode(', ', array_map(fn (array $address): string => $address['name'] ?: $address['address'], $record->to ?? []))
                            : $record->fromLabel())
                        ->weight($weight)
                        ->limit(40),
                    TextColumn::make('subject')
                        ->icon(fn (MailboxMessage $record): ?Heroicon => static::subjectIcon($record))
                        ->placeholder(__('filament-mailbox::mailbox.messages.no_subject'))
                        ->weight($weight)
                        ->color('gray')
                        ->limit(70),
                    TextColumn::make('folder.name')
                        ->state(fn (MailboxMessage $record): ?string => $record->folder?->special_use?->getLabel() ?? $record->folder?->name)
                        ->size('xs')
                        ->color('gray')
                        ->visible(fn (BrowseMailbox $livewire): bool => $livewire->spansFolders()),
                ])->space(1),
                Stack::make([
                    TextColumn::make('received_at')
                        ->dateTime('d.m. H:i')
                        ->size('xs')
                        ->weight($weight)
                        ->alignEnd()
                        ->visible(fn (BrowseMailbox $livewire): bool => ! $livewire->isSnoozedView()),
                    TextColumn::make('snoozed_until')
                        ->dateTime('d.m. H:i')
                        ->icon(Heroicon::OutlinedClock)
                        ->size('xs')
                        ->alignEnd()
                        ->visible(fn (BrowseMailbox $livewire): bool => $livewire->isSnoozedView()),
                    IconColumn::make('has_attachments')
                        ->icon(fn (bool $state): ?Heroicon => $state ? Heroicon::OutlinedPaperClip : null)
                        ->color('gray')
                        ->alignEnd(),
                ])->grow(false)->space(1),
            ]),
        ];
    }
}
