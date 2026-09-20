<?php

namespace Cooolinho\FilamentMailbox\Filament\Widgets\Statistics;

use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

class HourlyChartWidget extends ChartWidget
{
    use InteractsWithStatistics;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string|Htmlable|null
    {
        return __('filament-mailbox::mailbox.statistics.charts.by_hour');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $hours = $this->statisticsQuery()->byHour();

        return [
            'datasets' => [
                ['label' => __('filament-mailbox::mailbox.statistics.kpis.inbound'), 'data' => $hours['inbound'], 'backgroundColor' => '#3b82f6'],
                ['label' => __('filament-mailbox::mailbox.statistics.kpis.outbound'), 'data' => $hours['outbound'], 'backgroundColor' => '#10b981'],
            ],
            'labels' => array_map(fn (int $hour): string => sprintf('%02d', $hour), range(0, 23)),
        ];
    }
}
