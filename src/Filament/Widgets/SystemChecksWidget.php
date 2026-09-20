<?php

namespace Cooolinho\FilamentMailbox\Filament\Widgets;

use Cooolinho\FilamentMailbox\Filament\Pages\MailboxHealth as MailboxHealthPage;
use Cooolinho\FilamentMailbox\Health\HealthCheckResult;
use Cooolinho\FilamentMailbox\Health\HealthCheckRunner;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class SystemChecksWidget extends TableWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return MailboxHealthPage::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('filament-mailbox::mailbox.health.checks.heading'))
            ->records(fn (HealthCheckRunner $runner): array => collect($runner->results())
                ->map(fn (HealthCheckResult $result, string $key): array => [
                    'key' => $key,
                    'label' => $runner->checks()[$key]->label(),
                    'status' => $result->status,
                    'message' => $result->message,
                    'checked_at' => $result->checkedAt,
                ])
                ->all())
            ->poll(config('filament-mailbox.health.polling', '30s'))
            ->paginated(false)
            ->columns([
                TextColumn::make('label')
                    ->label(__('filament-mailbox::mailbox.health.checks.fields.check'))
                    ->weight('medium'),
                TextColumn::make('status')
                    ->label(__('filament-mailbox::mailbox.health.fields.status'))
                    ->badge(),
                TextColumn::make('message')
                    ->label(__('filament-mailbox::mailbox.monitoring.fields.message'))
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('checked_at')
                    ->label(__('filament-mailbox::mailbox.health.checks.fields.checked_at'))
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->headerActions([
                Action::make('runChecks')
                    ->label(__('filament-mailbox::mailbox.health.checks.run'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->authorize(fn (): bool => MailboxHealthPage::canAccess())
                    ->action(function (HealthCheckRunner $runner): void {
                        $runner->run();

                        Notification::make()
                            ->success()
                            ->title(__('filament-mailbox::mailbox.health.checks.finished'))
                            ->send();
                    }),
            ]);
    }
}
