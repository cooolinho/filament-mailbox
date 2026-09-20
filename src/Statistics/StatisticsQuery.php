<?php

namespace Cooolinho\FilamentMailbox\Statistics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the daily aggregates for a period and a set of (allowed) mailboxes.
 */
class StatisticsQuery
{
    /**
     * @param  array<int, int>  $mailboxIds
     */
    public function __construct(
        public readonly array $mailboxIds,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $until,
    ) {}

    /**
     * Resolve page filters against the mailboxes the user may see; foreign ids are dropped.
     *
     * @param  ?array<string, mixed>  $filters
     * @param  ?array<int, int>  $allowed
     */
    public static function fromFilters(?array $filters, ?array $allowed): self
    {
        $allowed ??= [];
        $selected = array_map('intval', array_filter((array) ($filters['mailboxes'] ?? []), 'is_numeric'));
        $mailboxIds = $selected === [] ? $allowed : array_values(array_intersect($selected, $allowed));

        $today = CarbonImmutable::today();
        $period = (string) ($filters['period'] ?? '30');

        if ($period === 'custom') {
            $from = self::date($filters['from'] ?? null) ?? $today->subDays(29);
            $until = self::date($filters['until'] ?? null) ?? $today;
        } else {
            $days = in_array($period, ['7', '30', '90'], true) ? (int) $period : 30;
            $from = $today->subDays($days - 1);
            $until = $today;
        }

        if ($until->lt($from)) {
            [$from, $until] = [$until, $from];
        }

        // At most two years, the default retention.
        if ($from->lt($until->subDays(730))) {
            $from = $until->subDays(730);
        }

        return new self($mailboxIds, $from, $until);
    }

    public function previous(): self
    {
        $days = $this->days();

        return new self($this->mailboxIds, $this->from->subDays($days), $this->from->subDay());
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->until) + 1;
    }

    public function daily(): Builder
    {
        return DB::table('mailbox_stats_daily')
            ->whereIn('mailbox_id', $this->mailboxIds === [] ? [0] : $this->mailboxIds)
            ->whereBetween('date', [$this->from->format('Y-m-d'), $this->until->format('Y-m-d')]);
    }

    /**
     * @return array{inbound: int, outbound: int, auto_generated: int, replied: int, sla_met: int, attachment_bytes_in: int, attachment_bytes_out: int, reply_minutes_p50: ?int, reply_minutes_p90: ?int, reply_business_minutes_p50: ?int, reply_business_minutes_p90: ?int}
     */
    public function totals(): array
    {
        $row = $this->daily()
            ->selectRaw('coalesce(sum(inbound_count), 0) as inbound, coalesce(sum(outbound_count), 0) as outbound, coalesce(sum(auto_generated_count), 0) as auto_generated, coalesce(sum(replied_count), 0) as replied, coalesce(sum(sla_met_count), 0) as sla_met, coalesce(sum(attachment_bytes_in), 0) as attachment_bytes_in, coalesce(sum(attachment_bytes_out), 0) as attachment_bytes_out')
            ->first();

        $minutes = $this->replyMinutes();

        return [
            'inbound' => (int) $row->inbound,
            'outbound' => (int) $row->outbound,
            'auto_generated' => (int) $row->auto_generated,
            'replied' => (int) $row->replied,
            'sla_met' => (int) $row->sla_met,
            'attachment_bytes_in' => (int) $row->attachment_bytes_in,
            'attachment_bytes_out' => (int) $row->attachment_bytes_out,
            'reply_minutes_p50' => $this->percentile($minutes['wall_clock'], 50),
            'reply_minutes_p90' => $this->percentile($minutes['wall_clock'], 90),
            'reply_business_minutes_p50' => $this->percentile($minutes['business'], 50),
            'reply_business_minutes_p90' => $this->percentile($minutes['business'], 90),
        ];
    }

    /**
     * Inbound and outbound per day, days without data as zero.
     *
     * @return array<string, array{inbound: int, outbound: int}>
     */
    public function perDay(): array
    {
        $rows = $this->daily()
            ->selectRaw('date, sum(inbound_count) as inbound, sum(outbound_count) as outbound')
            ->groupBy('date')
            ->get()
            ->keyBy(fn (object $row): string => substr((string) $row->date, 0, 10));

        $days = [];

        for ($day = $this->from; $day->lte($this->until); $day = $day->addDay()) {
            $row = $rows->get($day->format('Y-m-d'));
            $days[$day->format('Y-m-d')] = ['inbound' => (int) ($row->inbound ?? 0), 'outbound' => (int) ($row->outbound ?? 0)];
        }

        return $days;
    }

    /**
     * @return array{inbound: array<int, int>, outbound: array<int, int>}
     */
    public function byHour(): array
    {
        $totals = ['inbound' => array_fill(0, 24, 0), 'outbound' => array_fill(0, 24, 0)];

        foreach ($this->daily()->get(['inbound_by_hour', 'outbound_by_hour']) as $row) {
            foreach (['inbound', 'outbound'] as $direction) {
                foreach ((array) json_decode((string) $row->{$direction.'_by_hour'}, true) as $hour => $count) {
                    if (isset($totals[$direction][$hour])) {
                        $totals[$direction][$hour] += (int) $count;
                    }
                }
            }
        }

        return $totals;
    }

    /**
     * Monday first.
     *
     * @return array{inbound: array<int, int>, outbound: array<int, int>}
     */
    public function byWeekday(): array
    {
        $totals = ['inbound' => array_fill(0, 7, 0), 'outbound' => array_fill(0, 7, 0)];

        foreach ($this->daily()->get(['date', 'inbound_count', 'outbound_count']) as $row) {
            $weekday = CarbonImmutable::parse(substr((string) $row->date, 0, 10))->dayOfWeekIso - 1;
            $totals['inbound'][$weekday] += (int) $row->inbound_count;
            $totals['outbound'][$weekday] += (int) $row->outbound_count;
        }

        return $totals;
    }

    /**
     * @return Collection<int, object{domain: string, count: int}>
     */
    public function topDomains(int $limit): Collection
    {
        return DB::table('mailbox_stats_domains_daily')
            ->whereIn('mailbox_id', $this->mailboxIds === [] ? [0] : $this->mailboxIds)
            ->whereBetween('date', [$this->from->format('Y-m-d'), $this->until->format('Y-m-d')])
            ->selectRaw('domain, sum(count) as total')
            ->groupBy('domain')
            ->orderByDesc('total')
            ->orderBy('domain')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): object => (object) ['domain' => $row->domain, 'count' => (int) $row->total]);
    }

    /**
     * First-reply times of inbound messages received in the period, in buckets.
     *
     * @return array<string, int>
     */
    public function replyTimeDistribution(): array
    {
        $buckets = ['< 1 h' => 0, '1–4 h' => 0, '4–8 h' => 0, '8–24 h' => 0, '1–3 d' => 0, '> 3 d' => 0];

        foreach ($this->replyMinutes()['wall_clock'] as $minutes) {
            $key = match (true) {
                $minutes < 60 => '< 1 h',
                $minutes < 240 => '1–4 h',
                $minutes < 480 => '4–8 h',
                $minutes < 1440 => '8–24 h',
                $minutes < 4320 => '1–3 d',
                default => '> 3 d',
            };

            $buckets[$key]++;
        }

        return $buckets;
    }

    /**
     * @return array{wall_clock: Collection<int, int>, business: Collection<int, int>}
     */
    protected function replyMinutes(): array
    {
        $timezone = config('app.timezone', 'UTC');

        // Period boundaries in the application time zone (mailboxes may use others).
        $rows = DB::table('mailbox_reply_links as l')
            ->join('mailbox_messages as m', 'm.id', '=', 'l.inbound_message_id')
            ->whereIn('m.mailbox_id', $this->mailboxIds === [] ? [0] : $this->mailboxIds)
            ->where('m.is_auto_generated', false)
            ->where('m.received_at', '>=', $this->from->startOfDay()->setTimezone($timezone))
            ->where('m.received_at', '<', $this->until->addDay()->startOfDay()->setTimezone($timezone))
            ->selectRaw('l.inbound_message_id, min(l.reply_minutes) as reply_minutes, min(l.reply_business_minutes) as reply_business_minutes')
            ->groupBy('l.inbound_message_id')
            ->get();

        return [
            'wall_clock' => $rows->pluck('reply_minutes')->map(fn (mixed $value): int => (int) $value)->sort()->values(),
            'business' => $rows->pluck('reply_business_minutes')->filter(fn (mixed $value): bool => $value !== null)->map(fn (mixed $value): int => (int) $value)->sort()->values(),
        ];
    }

    /**
     * @param  Collection<int, int>  $sorted
     */
    protected function percentile(Collection $sorted, int $percentile): ?int
    {
        return app(DailyStatsAggregator::class)->percentile($sorted, $percentile);
    }

    protected static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::parse(substr($value, 0, 10))->startOfDay();
    }
}
