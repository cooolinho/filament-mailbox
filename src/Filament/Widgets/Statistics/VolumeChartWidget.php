<?php

namespace Cooolinho\FilamentMailbox\Filament\Widgets\Statistics;

use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

class VolumeChartWidget extends ChartWidget
{
    use InteractsWithStatistics;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    public function getHeading(): string|Htmlable|null
    {
        return __('filament-mailbox::mailbox.statistics.charts.volume');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = $this->statisticsQuery()->perDay();

        return [
            'datasets' => [
                [
                    'label' => __('filament-mailbox::mailbox.statistics.kpis.inbound'),
                    'data' => array_column($days, 'inbound'),
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.15)',
                    'fill' => true,
                ],
                [
                    'label' => __('filament-mailbox::mailbox.statistics.kpis.outbound'),
                    'data' => array_column($days, 'outbound'),
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.15)',
                    'fill' => true,
                ],
            ],
            'labels' => array_map(fn (string $date): string => Carbon::parse($date)->isoFormat('l'), array_keys($days)),
        ];
    }
}
