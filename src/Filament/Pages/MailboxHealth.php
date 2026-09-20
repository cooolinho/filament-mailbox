<?php

namespace Cooolinho\FilamentMailbox\Filament\Pages;

use BackedEnum;
use Cooolinho\FilamentMailbox\Enums\MailboxHealth as MailboxHealthStatus;
use Cooolinho\FilamentMailbox\Enums\SyncRunStatus;
use Cooolinho\FilamentMailbox\Enums\SyncTrigger;
use Cooolinho\FilamentMailbox\FilamentMailboxPlugin;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\TestConnectionAction;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\RelationManagers\SyncRunsRelationManager;
use Cooolinho\FilamentMailbox\Filament\Widgets\MailboxHealthStatsWidget;
use Cooolinho\FilamentMailbox\Filament\Widgets\SystemChecksWidget;
use Cooolinho\FilamentMailbox\Health\MailboxHealthEvaluator;
use Cooolinho\FilamentMailbox\Jobs\SyncMailboxJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Health of all mailboxes and the infrastructure they depend on (managers only).
 */
class MailboxHealth extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'mailbox-health';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    public static function enabled(): bool
    {
        return (bool) config('filament-mailbox.health.enabled', true) && (bool) config('filament-mailbox.monitoring.enabled', true);
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return static::enabled() && $user !== null && app(MailboxAuthorization::class)->canManage($user);
    }

    public function getTitle(): string|Htmlable
    {
        return __('filament-mailbox::mailbox.health.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-mailbox::mailbox.health.title');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return FilamentMailboxPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $sort = FilamentMailboxPlugin::get()->getNavigationSort();

        return $sort === null ? null : $sort + 4;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $count = Mailbox::query()->whereIn('health', [MailboxHealthStatus::Failing, MailboxHealthStatus::Reconnect])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MailboxHealthStatsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Livewire::make(SystemChecksWidget::class)->key('system-checks'),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        $evaluator = app(MailboxHealthEvaluator::class);

        return $table
            ->heading(__('filament-mailbox::mailbox.resource.plural_label'))
            ->query(fn (): Builder => Mailbox::query()->with('oauthConnection'))
            ->poll(config('filament-mailbox.health.polling', '30s'))
            // Worst status first.
            ->defaultSort(fn (Builder $query): Builder => static::orderBySeverity($query)->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament-mailbox::mailbox.fields.name'))
                    ->description(fn (Mailbox $record): string => $record->email)
                    ->searchable(['name', 'email'])
                    ->sortable(),
                TextColumn::make('health')
                    ->label(__('filament-mailbox::mailbox.health.fields.status'))
                    ->state(fn (Mailbox $record): MailboxHealthStatus => $evaluator->current($record))
                    ->badge()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => static::orderBySeverity($query, $direction)),
                TextColumn::make('last_success_at')
                    ->label(__('filament-mailbox::mailbox.health.fields.last_success_at'))
                    ->state(fn (Mailbox $record) => $evaluator->lastSuccess($record))
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder(__('filament-mailbox::mailbox.health.never'))
                    ->sortable(),
                TextColumn::make('last_run_status')
                    ->label(__('filament-mailbox::mailbox.health.fields.last_run'))
                    ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : SyncRunStatus::tryFrom($state)?->getLabel())
                    ->color(fn (?string $state): string => $state === null ? 'gray' : (SyncRunStatus::tryFrom($state)?->getColor() ?? 'gray'))
                    ->badge()
                    ->description(fn (Mailbox $record): ?string => $record->last_run_duration_ms === null ? null : number_format($record->last_run_duration_ms).' ms')
                    ->placeholder('—'),
                TextColumn::make('last_sync_error')
                    ->label(__('filament-mailbox::mailbox.health.fields.last_error'))
                    ->description(fn (Mailbox $record): ?string => $record->last_error_type ? __('filament-mailbox::mailbox.monitoring.error_types.'.$record->last_error_type) : null, position: 'above')
                    ->limit(120)
                    ->tooltip(fn (Mailbox $record): ?string => $record->last_sync_error)
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('consecutive_failures')
                    ->label(__('filament-mailbox::mailbox.health.fields.consecutive_failures'))
                    ->numeric()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),
                TextColumn::make('failing_folders')
                    ->label(__('filament-mailbox::mailbox.health.fields.failing_folders'))
                    ->numeric()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('health')
                    ->label(__('filament-mailbox::mailbox.health.fields.status'))
                    ->options(MailboxHealthStatus::class)
                    ->multiple(),
                TernaryFilter::make('is_active')
                    ->label(__('filament-mailbox::mailbox.fields.is_active')),
            ])
            ->recordUrl(null)
            ->recordActions([
                ActionGroup::make([
                    TestConnectionAction::make(),
                    Action::make('sync')
                        ->label(__('filament-mailbox::mailbox.actions.sync.label'))
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->authorize('update')
                        ->disabled(fn (Mailbox $record): bool => ! $record->is_active)
                        ->action(function (Mailbox $record): void {
                            SyncMailboxJob::dispatch($record, SyncTrigger::Manual);

                            Notification::make()
                                ->success()
                                ->title(__('filament-mailbox::mailbox.actions.sync.started'))
                                ->send();
                        }),
                    Action::make('history')
                        ->label(__('filament-mailbox::mailbox.monitoring.runs.plural_label'))
                        ->icon(Heroicon::OutlinedClock)
                        ->authorize('update')
                        ->url(fn (Mailbox $record): string => static::historyUrl($record)),
                    Action::make('edit')
                        ->label(__('filament-actions::edit.single.label'))
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->authorize('update')
                        ->url(fn (Mailbox $record): string => MailboxResource::getUrl('edit', ['record' => $record])),
                ]),
            ])
            ->toolbarActions([
                BulkAction::make('sync')
                    ->label(__('filament-mailbox::mailbox.actions.sync.label'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->authorize(fn (): bool => static::canAccess())
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $count = 0;

                        // Unique jobs: already queued mailboxes are skipped by the queue.
                        $records->filter(fn (Mailbox $mailbox): bool => $mailbox->is_active)->each(function (Mailbox $mailbox) use (&$count): void {
                            SyncMailboxJob::dispatch($mailbox, SyncTrigger::Manual);
                            $count++;
                        });

                        Notification::make()
                            ->success()
                            ->title(trans_choice('filament-mailbox::mailbox.health.sync_started', $count, ['count' => $count]))
                            ->send();
                    }),
            ]);
    }

    public static function historyUrl(Mailbox $mailbox): string
    {
        $index = array_search(SyncRunsRelationManager::class, MailboxResource::getRelations(), true);

        return MailboxResource::getUrl('edit', ['record' => $mailbox, 'relation' => $index === false ? null : $index]);
    }

    public static function orderBySeverity(Builder $query, string $direction = 'asc'): Builder
    {
        $cases = collect(MailboxHealthStatus::cases())
            ->map(fn (MailboxHealthStatus $health): string => "when '{$health->value}' then {$health->rank()}")
            ->implode(' ');

        return $query->orderByRaw("case health {$cases} else 99 end ".($direction === 'desc' ? 'desc' : 'asc'));
    }
}
