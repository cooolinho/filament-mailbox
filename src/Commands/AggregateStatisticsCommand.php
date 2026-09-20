<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Carbon\CarbonImmutable;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Statistics\DailyStatsAggregator;
use Cooolinho\FilamentMailbox\Statistics\StatisticsSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AggregateStatisticsCommand extends Command
{
    protected $signature = 'mailbox:stats-aggregate
        {--mailbox=* : IDs of the mailboxes (default: all)}
        {--date= : Aggregate this day (Y-m-d)}
        {--from= : First day of a range (Y-m-d)}
        {--to= : Last day of a range (Y-m-d, default: today)}';

    protected $description = 'Calculate the daily mailbox statistics (default: today, yesterday and days with late imports)';

    public function handle(DailyStatsAggregator $aggregator): int
    {
        if (! StatisticsSettings::enabled()) {
            $this->components->warn('Mailbox statistics are disabled.');

            return self::SUCCESS;
        }

        $explicit = $this->explicitDates();

        if ($explicit === false) {
            $this->components->error('Dates must use the format Y-m-d.');

            return self::FAILURE;
        }

        $days = 0;

        $this->mailboxes()->each(function (Mailbox $mailbox) use ($aggregator, $explicit, &$days): void {
            foreach ($explicit ?? $aggregator->pendingDates($mailbox) as $date) {
                $aggregator->aggregate($mailbox, $date);
                $days++;
            }
        });

        $pruned = $this->prune();

        $this->components->info("Aggregated {$days} mailbox days, removed {$pruned} expired rows.");

        return self::SUCCESS;
    }

    protected function mailboxes()
    {
        $ids = array_filter((array) $this->option('mailbox'), 'is_numeric');

        return Mailbox::query()->when($ids !== [], fn ($query) => $query->whereKey($ids))->orderBy('id')->lazy();
    }

    /**
     * @return array<int, string>|false|null null = pending dates per mailbox
     */
    protected function explicitDates(): array|false|null
    {
        $pattern = '/^\d{4}-\d{2}-\d{2}$/';

        if ($date = $this->option('date')) {
            return preg_match($pattern, $date) === 1 ? [$date] : false;
        }

        if (! $this->option('from')) {
            return null;
        }

        $to = $this->option('to') ?: CarbonImmutable::today()->format('Y-m-d');

        if (preg_match($pattern, $this->option('from')) !== 1 || preg_match($pattern, $to) !== 1) {
            return false;
        }

        $dates = [];

        for ($day = CarbonImmutable::parse($this->option('from')); $day->lte(CarbonImmutable::parse($to)); $day = $day->addDay()) {
            $dates[] = $day->format('Y-m-d');
        }

        return $dates;
    }

    protected function prune(): int
    {
        $days = (int) config('filament-mailbox.statistics.retention_days', 730);

        if ($days <= 0) {
            return 0;
        }

        $before = CarbonImmutable::today()->subDays($days)->format('Y-m-d');

        return DB::table('mailbox_stats_daily')->where('date', '<', $before)->delete()
            + DB::table('mailbox_stats_domains_daily')->where('date', '<', $before)->delete();
    }
}
