<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\MailboxAlerts;

use BackedEnum;
use Cooolinho\FilamentMailbox\Enums\AlertRule;
use Cooolinho\FilamentMailbox\FilamentMailboxPlugin;
use Cooolinho\FilamentMailbox\Filament\Resources\MailboxAlerts\Pages\ListMailboxAlerts;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\MailboxAlert;
use Cooolinho\FilamentMailbox\Monitoring\AlertEvaluator;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Monitoring alerts (repeated failures, stale synchronisation, authentication).
 */
class MailboxAlertResource extends Resource
{
    protected static ?string $model = MailboxAlert::class;

    protected static ?string $slug = 'mailbox-alerts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    public static function getModelLabel(): string
    {
        return __('filament-mailbox::mailbox.monitoring.alerts.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-mailbox::mailbox.monitoring.alerts.plural_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return FilamentMailboxPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $sort = FilamentMailboxPlugin::get()->getNavigationSort();

        return $sort === null ? null : $sort + 3;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return config('filament-mailbox.monitoring.enabled', true) && parent::shouldRegisterNavigation();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = MailboxAlert::query()->open()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('mailbox'))
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('opened_at')
                    ->label(__('filament-mailbox::mailbox.monitoring.fields.opened_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('mailbox.name')
                    ->label(__('filament-mailbox::mailbox.resource.label'))
                    ->url(fn (MailboxAlert $record): ?string => $record->mailbox && MailboxResource::canEdit($record->mailbox)
                        ? MailboxResource::getUrl('edit', ['record' => $record->mailbox])
                        : null)
                    ->placeholder('—'),
                TextColumn::make('rule')
                    ->label(__('filament-mailbox::mailbox.monitoring.fields.rule'))
                    ->badge(),
                TextColumn::make('severity')
                    ->label(__('filament-mailbox::mailbox.monitoring.fields.severity'))
                    ->badge(),
                TextColumn::make('message')
                    ->label(__('filament-mailbox::mailbox.monitoring.fields.message'))
                    ->wrap()
                    ->limit(200),
                TextColumn::make('resolved_at')
                    ->label(__('filament-mailbox::mailbox.monitoring.fields.resolved_at'))
                    ->dateTime()
                    ->placeholder(__('filament-mailbox::mailbox.monitoring.alerts.open')),
            ])
            ->filters([
                TernaryFilter::make('open')
                    ->label(__('filament-mailbox::mailbox.monitoring.alerts.open'))
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('resolved_at'),
                        false: fn (Builder $query) => $query->whereNotNull('resolved_at'),
                    ),
                SelectFilter::make('rule')
                    ->label(__('filament-mailbox::mailbox.monitoring.fields.rule'))
                    ->options(AlertRule::class),
            ])
            ->recordActions([
                Action::make('acknowledge')
                    ->label(__('filament-mailbox::mailbox.monitoring.alerts.acknowledge'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->authorize('update')
                    ->visible(fn (MailboxAlert $record): bool => $record->isOpen())
                    ->requiresConfirmation()
                    ->action(function (MailboxAlert $record, AlertEvaluator $alerts): void {
                        $alerts->close($record, acknowledgedBy: auth()->id());

                        Notification::make()
                            ->success()
                            ->title(__('filament-mailbox::mailbox.monitoring.alerts.acknowledged'))
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMailboxAlerts::route('/'),
        ];
    }
}
