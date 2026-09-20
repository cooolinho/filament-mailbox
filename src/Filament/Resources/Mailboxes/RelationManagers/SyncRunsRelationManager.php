<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\RelationManagers;

use Cooolinho\FilamentMailbox\Enums\SyncRunStatus;
use Cooolinho\FilamentMailbox\Enums\SyncTrigger;
use Cooolinho\FilamentMailbox\Models\MailboxSyncRun;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only synchronisation history of a mailbox.
 */
class SyncRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'syncRuns';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return config('filament-mailbox.monitoring.enabled', true) && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('filament-mailbox::mailbox.monitoring.runs.plural_label');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextEntry::make('status')->label(__('filament-mailbox::mailbox.messages.fields.status'))->badge(),
            TextEntry::make('trigger')->label(__('filament-mailbox::mailbox.monitoring.fields.trigger')),
            TextEntry::make('uuid')->label(__('filament-mailbox::mailbox.monitoring.fields.uuid'))->copyable(),
            TextEntry::make('started_at')->label(__('filament-mailbox::mailbox.monitoring.fields.started_at'))->dateTime(),
            TextEntry::make('duration_ms')->label(__('filament-mailbox::mailbox.monitoring.fields.duration'))->suffix(' ms')->placeholder('—'),
            TextEntry::make('queue_wait_ms')->label(__('filament-mailbox::mailbox.monitoring.fields.queue_wait'))->suffix(' ms')->placeholder('—'),
            TextEntry::make('error_type')->label(__('filament-mailbox::mailbox.monitoring.fields.error_type'))->badge()->color('danger')->placeholder('—'),
            TextEntry::make('error')->label(__('filament-mailbox::mailbox.monitoring.fields.error'))->placeholder('—')->columnSpan(2),
            Section::make(__('filament-mailbox::mailbox.monitoring.fields.folders'))
                ->columnSpanFull()
                ->visible(fn (MailboxSyncRun $record): bool => filled($record->folder_stats))
                ->schema([
                    RepeatableEntry::make('folder_stats')
                        ->hiddenLabel()
                        ->state(fn (MailboxSyncRun $record): array => collect($record->folder_stats ?? [])
                            ->map(fn (array $stats, string $folder): array => ['folder' => $folder, ...$stats])
                            ->values()
                            ->all())
                        ->columns(5)
                        ->schema([
                            TextEntry::make('folder')->label(__('filament-mailbox::mailbox.labels.fields.folder')),
                            TextEntry::make('duration_ms')->label(__('filament-mailbox::mailbox.monitoring.fields.duration'))->suffix(' ms'),
                            TextEntry::make('imported')->label(__('filament-mailbox::mailbox.monitoring.fields.imported')),
                            TextEntry::make('updated')->label(__('filament-mailbox::mailbox.monitoring.fields.updated')),
                            TextEntry::make('error')->label(__('filament-mailbox::mailbox.monitoring.fields.error'))->placeholder('—'),
                        ]),
                ]),
            Section::make(__('filament-mailbox::mailbox.monitoring.fields.provider_calls'))
                ->columnSpanFull()
                ->visible(fn (MailboxSyncRun $record): bool => filled($record->provider_stats))
                ->schema([
                    RepeatableEntry::make('provider_stats')
                        ->hiddenLabel()
                        ->state(fn (MailboxSyncRun $record): array => collect($record->provider_stats ?? [])
                            ->map(fn (array $stats, string $operation): array => ['operation' => $operation, 'avg_ms' => round($stats['total_ms'] / max(1, $stats['count']), 1), ...$stats])
                            ->values()
                            ->all())
                        ->columns(5)
                        ->schema([
                            TextEntry::make('operation')->label(__('filament-mailbox::mailbox.monitoring.fields.operation')),
                            TextEntry::make('count')->label(__('filament-mailbox::mailbox.monitoring.fields.calls')),
                            TextEntry::make('avg_ms')->label('Ø ms'),
                            TextEntry::make('max_ms')->label('max ms'),
                            TextEntry::make('errors')->label(__('filament-mailbox::mailbox.monitoring.fields.errors')),
                        ]),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')
                    ->label(__('filament-mailbox::mailbox.monitoring.fields.started_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('filament-mailbox::mailbox.messages.fields.status'))
                    ->badge(),
                TextColumn::make('trigger')
                    ->label(__('filament-mailbox::mailbox.monitoring.fields.trigger')),
                TextColumn::make('duration_ms')
                    ->label(__('filament-mailbox::mailbox.monitoring.fields.duration'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : number_format($state / 1000, 1).' s')
                    ->sortable(),
                TextColumn::make('imported')
                    ->label(__('filament-mailbox::mailbox.monitoring.fields.imported'))
                    ->numeric(),
                TextColumn::make('updated')
                    ->label(__('filament-mailbox::mailbox.monitoring.fields.updated'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted')
                    ->label(__('filament-mailbox::mailbox.monitoring.fields.deleted'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('error_type')
                    ->label(__('filament-mailbox::mailbox.monitoring.fields.error_type'))
                    ->badge()
                    ->color('danger')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('filament-mailbox::mailbox.messages.fields.status'))
                    ->options(SyncRunStatus::class),
                SelectFilter::make('trigger')
                    ->label(__('filament-mailbox::mailbox.monitoring.fields.trigger'))
                    ->options(SyncTrigger::class),
            ])
            ->recordActions([
                ViewAction::make()->modalWidth('5xl'),
            ]);
    }
}
