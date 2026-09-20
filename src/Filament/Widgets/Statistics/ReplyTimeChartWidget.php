<?php

namespace Cooolinho\FilamentMailbox\Filament\Widgets\Statistics;

use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

class ReplyTimeChartWidget extends ChartWidget
{
    use InteractsWithStatistics;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string|Htmlable|null
    {
        return __('filament-mailbox::mailbox.statistics.charts.reply_times');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $buckets = $this->statisticsQuery()->replyTimeDistribution();

        return [
            'datasets' => [
                ['label' => __('filament-mailbox::mailbox.statistics.charts.first_replies'), 'data' => array_values($buckets), 'backgroundColor' => '#8b5cf6'],
            ],
            'labels' => array_keys($buckets),
        ];
    }
}
