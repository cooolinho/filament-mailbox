<?php

namespace Cooolinho\FilamentMailbox\Filament\Widgets\Statistics;

use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

class WeekdayChartWidget extends ChartWidget
{
    use InteractsWithStatistics;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string|Htmlable|null
    {
        return __('filament-mailbox::mailbox.statistics.charts.by_weekday');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $weekdays = $this->statisticsQuery()->byWeekday();
        $monday = CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY);

        return [
            'datasets' => [
                ['label' => __('filament-mailbox::mailbox.statistics.kpis.inbound'), 'data' => $weekdays['inbound'], 'backgroundColor' => '#3b82f6'],
                ['label' => __('filament-mailbox::mailbox.statistics.kpis.outbound'), 'data' => $weekdays['outbound'], 'backgroundColor' => '#10b981'],
            ],
            'labels' => array_map(fn (int $offset): string => $monday->addDays($offset)->isoFormat('dd'), range(0, 6)),
        ];
    }
}
