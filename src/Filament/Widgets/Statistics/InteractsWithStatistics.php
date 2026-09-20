<?php

namespace Cooolinho\FilamentMailbox\Filament\Widgets\Statistics;

use Cooolinho\FilamentMailbox\Filament\Pages\MailboxStatistics;
use Cooolinho\FilamentMailbox\Statistics\StatisticsQuery;
use Cooolinho\FilamentMailbox\Statistics\StatisticsSettings;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Statistics widgets read aggregates only; the page filters are validated
 * against the mailboxes the user may see.
 */
trait InteractsWithStatistics
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        return MailboxStatistics::canAccess();
    }

    protected function statisticsQuery(): StatisticsQuery
    {
        return StatisticsQuery::fromFilters($this->pageFilters, StatisticsSettings::allowedMailboxIds(Filament::auth()->user()));
    }
}
