<?php

namespace Cooolinho\FilamentMailbox\Statistics;

use Carbon\CarbonImmutable;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calculates the daily aggregates of a mailbox. Idempotent: a day is always
 * recalculated completely from the messages.
 */
class DailyStatsAggregator
{
    /**
     * @param  string  $date  Y-m-d in the time zone of the mailbox
     * @return array<string, mixed> the stored row
     */
    public function aggregate(Mailbox $mailbox, string $date): array
    {
        $timezone = BusinessHours::timezoneFor($mailbox);
        $start = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $end = $start->addDay();
        // Timestamps are stored in the application time zone.
        $from = $start->setTimezone(config('app.timezone', 'UTC'));
        $until = $end->setTimezone(config('app.timezone', 'UTC'));

        $inbound = $this->unique($this->messages($mailbox, inbound: true)
            ->where('m.received_at', '>=', $from)
            ->where('m.received_at', '<', $until)
            ->get());

        $outbound = $this->unique($this->messages($mailbox, inbound: false)
            ->whereRaw('coalesce(m.sent_at, m.received_at) >= ?', [$from])
            ->whereRaw('coalesce(m.sent_at, m.received_at) < ?', [$until])
            ->get());

        $human = $inbound->reject(fn (object $message): bool => (bool) $message->is_auto_generated);
        $replies = $this->firstReplies($human->pluck('id')->all());
        $sla = StatisticsSettings::slaMinutes($mailbox);
        $domains = $human
            ->map(fn (object $message): ?string => $this->domain($message->from_address))
            ->filter()
            ->countBy()
            ->sortDesc();

        $wallClock = $replies->pluck('reply_minutes')->sort()->values();
        $business = $replies->pluck('reply_business_minutes')->filter(fn (mixed $minutes): bool => $minutes !== null)->sort()->values();

        $row = [
            'mailbox_id' => $mailbox->getKey(),
            'date' => $start->format('Y-m-d'),
            'inbound_count' => $inbound->count(),
            'outbound_count' => $outbound->count(),
            'auto_generated_count' => $inbound->count() - $human->count(),
            'inbound_by_hour' => json_encode($this->byHour($inbound, 'received_at', $timezone)),
            'outbound_by_hour' => json_encode($this->byHour($outbound, 'sent_at', $timezone)),
            'replied_count' => $replies->count(),
            'reply_minutes_p50' => $this->percentile($wallClock, 50),
            'reply_minutes_p90' => $this->percentile($wallClock, 90),
            'reply_business_minutes_p50' => $this->percentile($business, 50),
            'reply_business_minutes_p90' => $this->percentile($business, 90),
            // Within business hours when configured, otherwise wall-clock time.
            'sla_met_count' => $replies->filter(fn (object $reply): bool => ($reply->reply_business_minutes ?? $reply->reply_minutes) <= $sla)->count(),
            'unique_sender_domains' => $domains->count(),
            'attachment_bytes_in' => $this->attachmentBytes($inbound->pluck('id')->all()),
            'attachment_bytes_out' => $this->attachmentBytes($outbound->pluck('id')->all()),
            'updated_at' => now(),
        ];

        DB::transaction(function () use ($mailbox, $start, $row, $domains): void {
            DB::table('mailbox_stats_daily')->upsert(
                [...$row, 'created_at' => now()],
                ['mailbox_id', 'date'],
                array_keys(array_diff_key($row, ['mailbox_id' => true, 'date' => true])),
            );

            DB::table('mailbox_stats_domains_daily')->where('mailbox_id', $mailbox->getKey())->where('date', $start->format('Y-m-d'))->delete();

            $top = $domains->take(max(1, (int) config('filament-mailbox.statistics.top_domains', 10)));

            if ($top->isNotEmpty()) {
                DB::table('mailbox_stats_domains_daily')->insert($top->map(fn (int $count, string $domain): array => [
                    'mailbox_id' => $mailbox->getKey(),
                    'date' => $start->format('Y-m-d'),
                    'domain' => mb_substr($domain, 0, 255),
                    'count' => $count,
                ])->values()->all());
            }

            DB::table('mailbox_stats_dirty_days')->where('mailbox_id', $mailbox->getKey())->where('date', $start->format('Y-m-d'))->delete();
        });

        return $row;
    }

    /**
     * Today, yesterday and the days marked by late imports (per mailbox time zone).
     *
     * @return array<int, string>
     */
    public function pendingDates(Mailbox $mailbox): array
    {
        $today = now(BusinessHours::timezoneFor($mailbox));

        $dirty = DB::table('mailbox_stats_dirty_days')
            ->where('mailbox_id', $mailbox->getKey())
            ->pluck('date')
            ->map(fn (mixed $date): string => substr((string) $date, 0, 10));

        return $dirty
            ->push($today->copy()->subDay()->format('Y-m-d'), $today->format('Y-m-d'))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    protected function messages(Mailbox $mailbox, bool $inbound): \Illuminate\Database\Query\Builder
    {
        // Soft-deleted messages were received or sent as well.
        return DB::table('mailbox_messages as m')
            ->join('mailbox_folders as f', 'f.id', '=', 'm.folder_id')
            ->where('m.mailbox_id', $mailbox->getKey())
            ->when(
                $inbound,
                fn ($query) => $query->where(fn ($query) => $query->whereNull('f.special_use')->orWhereNotIn('f.special_use', [SpecialUse::Sent->value, SpecialUse::Drafts->value, SpecialUse::Junk->value])),
                fn ($query) => $query->where('f.special_use', SpecialUse::Sent->value),
            )
            ->select(['m.id', 'm.message_id', 'm.from_address', 'm.is_auto_generated', 'm.received_at', 'm.sent_at']);
    }

    /**
     * The same message in several folders (Gmail labels, copies) counts once.
     *
     * @param  Collection<int, object>  $messages
     * @return Collection<int, object>
     */
    protected function unique(Collection $messages): Collection
    {
        return $messages->unique(fn (object $message): string => $message->message_id ?: 'id:'.$message->id)->values();
    }

    /**
     * The earliest reply per inbound message.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, object>
     */
    protected function firstReplies(array $ids): Collection
    {
        return collect(array_chunk($ids, 500))
            ->flatMap(fn (array $chunk) => DB::table('mailbox_reply_links')
                ->whereIn('inbound_message_id', $chunk)
                ->selectRaw('inbound_message_id, min(reply_minutes) as reply_minutes, min(reply_business_minutes) as reply_business_minutes')
                ->groupBy('inbound_message_id')
                ->get())
            ->map(fn (object $row): object => (object) [
                'reply_minutes' => (int) $row->reply_minutes,
                'reply_business_minutes' => $row->reply_business_minutes === null ? null : (int) $row->reply_business_minutes,
            ]);
    }

    /**
     * @param  Collection<int, object>  $messages
     * @return array<int, int>
     */
    protected function byHour(Collection $messages, string $column, string $timezone): array
    {
        $hours = array_fill(0, 24, 0);

        foreach ($messages as $message) {
            $at = $message->{$column} ?? $message->received_at;

            if ($at !== null) {
                $hours[(int) CarbonImmutable::parse($at, config('app.timezone', 'UTC'))->setTimezone($timezone)->format('G')]++;
            }
        }

        return $hours;
    }

    /**
     * Nearest-rank percentile of sorted values.
     *
     * @param  Collection<int, int>  $sorted
     */
    public function percentile(Collection $sorted, int $percentile): ?int
    {
        if ($sorted->isEmpty()) {
            return null;
        }

        return (int) $sorted->get(max(0, (int) ceil($percentile / 100 * $sorted->count()) - 1));
    }

    /**
     * @param  array<int, int>  $ids
     */
    protected function attachmentBytes(array $ids): int
    {
        return (int) collect(array_chunk($ids, 500))
            ->sum(fn (array $chunk): int => (int) DB::table('mailbox_attachments')->whereIn('message_id', $chunk)->sum('size'));
    }

    protected function domain(?string $address): ?string
    {
        $domain = strtolower(trim((string) substr((string) strrchr((string) $address, '@'), 1)));

        return $domain === '' ? null : $domain;
    }
}
