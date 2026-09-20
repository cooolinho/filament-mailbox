<?php

namespace Cooolinho\FilamentMailbox\Filament\Widgets;

use Cooolinho\FilamentMailbox\Enums\MailboxHealth;
use Cooolinho\FilamentMailbox\Filament\Pages\MailboxHealth as MailboxHealthPage;
use Cooolinho\FilamentMailbox\Health\HealthStatistics;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MailboxHealthStatsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return MailboxHealthPage::canAccess();
    }

    protected function getPollingInterval(): ?string
    {
        return config('filament-mailbox.health.polling', '30s');
    }

    protected function getColumns(): int|array|null
    {
        return ['@xl' => 4, '!@lg' => 4];
    }

    protected function getStats(): array
    {
        $statistics = app(HealthStatistics::class)->get();

        $healthy = HealthStatistics::count($statistics, [MailboxHealth::Healthy]);
        $warnings = HealthStatistics::count($statistics, [MailboxHealth::Degraded, MailboxHealth::Stale]);
        $errors = HealthStatistics::count($statistics, [MailboxHealth::Failing, MailboxHealth::Reconnect]);

        return [
            Stat::make(__('filament-mailbox::mailbox.health.kpis.active'), $statistics['active'])
                ->icon(Heroicon::OutlinedInbox),
            Stat::make(__('filament-mailbox::mailbox.health.kpis.healthy'), $healthy)
                ->color('success')
                ->icon(MailboxHealth::Healthy->getIcon()),
            Stat::make(__('filament-mailbox::mailbox.health.kpis.warnings'), $warnings)
                ->color($warnings > 0 ? 'warning' : 'gray')
                ->icon(MailboxHealth::Degraded->getIcon()),
            Stat::make(__('filament-mailbox::mailbox.health.kpis.errors'), $errors)
                ->color($errors > 0 ? 'danger' : 'gray')
                ->icon(MailboxHealth::Failing->getIcon()),
            Stat::make(__('filament-mailbox::mailbox.health.kpis.open_alerts'), $statistics['open_alerts'])
                ->color($statistics['open_alerts'] > 0 ? 'danger' : 'gray')
                ->icon(Heroicon::OutlinedBellAlert),
            Stat::make(__('filament-mailbox::mailbox.health.kpis.success_rate'), $statistics['success_rate'] === null ? '—' : $statistics['success_rate'].' %')
                ->description(__('filament-mailbox::mailbox.health.kpis.last_24_hours'))
                ->chart($statistics['hourly_success'])
                ->color(match (true) {
                    $statistics['success_rate'] === null => 'gray',
                    $statistics['success_rate'] >= 95 => 'success',
                    $statistics['success_rate'] >= 80 => 'warning',
                    default => 'danger',
                }),
            Stat::make(__('filament-mailbox::mailbox.health.kpis.p95_duration'), $statistics['p95_ms'] === null ? '—' : $this->formatDuration($statistics['p95_ms']))
                ->description(__('filament-mailbox::mailbox.health.kpis.last_24_hours'))
                ->icon(Heroicon::OutlinedClock),
            Stat::make(__('filament-mailbox::mailbox.health.kpis.queue_size'), $statistics['queue_size'] ?? '—')
                ->description(config('filament-mailbox.sync.queue'))
                ->icon(Heroicon::OutlinedQueueList),
        ];
    }

    protected function formatDuration(int $milliseconds): string
    {
        return $milliseconds < 1000 ? $milliseconds.' ms' : round($milliseconds / 1000, 1).' s';
    }
}
