<?php

namespace Cooolinho\FilamentMailbox\Filament\Widgets\Statistics;

use Cooolinho\FilamentMailbox\Statistics\LiveStatsProvider;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class StatisticsOverviewWidget extends StatsOverviewWidget
{
    use InteractsWithStatistics;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array|null
    {
        return ['@xl' => 4, '!@lg' => 4];
    }

    protected function getStats(): array
    {
        $query = $this->statisticsQuery();
        $current = $query->totals();
        $previous = $query->previous()->totals();
        $hours = $query->byHour();
        $live = app(LiveStatsProvider::class)->get($query->mailboxIds);
        $peak = max($hours['inbound']) > 0 ? array_search(max($hours['inbound']), $hours['inbound'], true) : null;
        $unanswered = array_sum($live['unanswered']);

        return [
            $this->compared(Stat::make(__('filament-mailbox::mailbox.statistics.kpis.inbound'), Number::format($current['inbound'])), $current['inbound'], $previous['inbound'])
                ->icon(Heroicon::OutlinedInboxArrowDown),
            $this->compared(Stat::make(__('filament-mailbox::mailbox.statistics.kpis.outbound'), Number::format($current['outbound'])), $current['outbound'], $previous['outbound'])
                ->icon(Heroicon::OutlinedPaperAirplane),
            Stat::make(__('filament-mailbox::mailbox.statistics.kpis.per_day'), Number::format($current['inbound'] / max(1, $query->days()), 1))
                ->description($peak === null ? null : __('filament-mailbox::mailbox.statistics.kpis.peak_hour', ['hour' => sprintf('%02d:00', $peak)]))
                ->icon(Heroicon::OutlinedCalendarDays),
            Stat::make(__('filament-mailbox::mailbox.statistics.kpis.first_reply'), $this->duration($current['reply_business_minutes_p50'] ?? $current['reply_minutes_p50']))
                ->description(__('filament-mailbox::mailbox.statistics.kpis.first_reply_detail', [
                    'p90' => $this->duration($current['reply_business_minutes_p90'] ?? $current['reply_minutes_p90']),
                    'wall' => $this->duration($current['reply_minutes_p50']),
                ]))
                ->icon(Heroicon::OutlinedClock),
            Stat::make(__('filament-mailbox::mailbox.statistics.kpis.sla'), $current['replied'] > 0 ? Number::percentage($current['sla_met'] / $current['replied'] * 100, 1) : '—')
                ->description(trans_choice('filament-mailbox::mailbox.statistics.kpis.replied', $current['replied'], ['count' => Number::format($current['replied'])]))
                ->color($current['replied'] === 0 ? 'gray' : ($current['sla_met'] / $current['replied'] >= 0.9 ? 'success' : 'warning'))
                ->icon(Heroicon::OutlinedCheckBadge),
            Stat::make(__('filament-mailbox::mailbox.statistics.kpis.unanswered'), Number::format($unanswered))
                ->description(__('filament-mailbox::mailbox.statistics.kpis.unanswered_old', ['count' => Number::format($live['unanswered']['over_3d'])]))
                ->color($live['unanswered']['over_3d'] > 0 ? 'danger' : ($unanswered > 0 ? 'warning' : 'success'))
                ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis),
            Stat::make(__('filament-mailbox::mailbox.statistics.kpis.unread'), Number::format($live['unread']))
                ->description(__('filament-mailbox::mailbox.statistics.kpis.auto_generated', ['count' => Number::format($current['auto_generated'])]))
                ->icon(Heroicon::OutlinedEnvelope),
            Stat::make(__('filament-mailbox::mailbox.statistics.kpis.storage'), Number::fileSize($live['storage_bytes'], 1))
                ->description(__('filament-mailbox::mailbox.statistics.kpis.attachments', [
                    'in' => Number::fileSize($current['attachment_bytes_in'], 1),
                    'out' => Number::fileSize($current['attachment_bytes_out'], 1),
                ]))
                ->icon(Heroicon::OutlinedCircleStack),
        ];
    }

    protected function compared(Stat $stat, int $current, int $previous): Stat
    {
        if ($previous === 0) {
            return $stat->description(__('filament-mailbox::mailbox.statistics.kpis.no_previous'));
        }

        $change = ($current - $previous) / $previous * 100;

        return $stat
            ->description(__('filament-mailbox::mailbox.statistics.kpis.vs_previous', ['change' => ($change >= 0 ? '+' : '').Number::format($change, 1).' %']))
            ->descriptionIcon($change >= 0 ? Heroicon::ArrowTrendingUp : Heroicon::ArrowTrendingDown);
    }

    public static function duration(?int $minutes): string
    {
        return match (true) {
            $minutes === null => '—',
            $minutes < 60 => $minutes.' min',
            $minutes < 1440 => Number::format($minutes / 60, 1).' h',
            default => Number::format($minutes / 1440, 1).' d',
        };
    }
}
