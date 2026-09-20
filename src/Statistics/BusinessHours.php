<?php

namespace Cooolinho\FilamentMailbox\Statistics;

use Carbon\CarbonInterface;
use Cooolinho\FilamentMailbox\Models\Mailbox;

/**
 * Business hours of a mailbox: time zone, opening intervals per weekday and holidays.
 */
final readonly class BusinessHours
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @param  array<string, array<int, array{0: string, 1: string}>>  $intervals  per weekday key ("mon"), "HH:MM" pairs
     * @param  array<int, string>  $holidays  dates (Y-m-d)
     */
    public function __construct(
        public string $timezone,
        public array $intervals = [],
        public array $holidays = [],
    ) {}

    public static function timezoneFor(Mailbox $mailbox): string
    {
        $timezone = $mailbox->business_hours['timezone'] ?? null;

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : (string) (config('filament-mailbox.statistics.timezone') ?: config('app.timezone', 'UTC'));
    }

    /**
     * Null when the mailbox has no opening intervals.
     */
    public static function forMailbox(Mailbox $mailbox): ?self
    {
        return self::fromArray($mailbox->business_hours, self::timezoneFor($mailbox));
    }

    /**
     * @param  ?array<string, mixed>  $data
     */
    public static function fromArray(?array $data, string $timezone): ?self
    {
        $intervals = [];

        foreach ((array) ($data['hours'] ?? []) as $row) {
            $day = $row['day'] ?? null;
            $start = self::time($row['start'] ?? null);
            $end = self::time($row['end'] ?? null);

            if (in_array($day, self::DAYS, true) && $start !== null && $end !== null && $start < $end) {
                $intervals[$day][] = [$start, $end];
            }
        }

        if ($intervals === []) {
            return null;
        }

        $holidays = array_values(array_filter(
            (array) ($data['holidays'] ?? []),
            fn (mixed $date): bool => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1,
        ));

        return new self($timezone, $intervals, $holidays);
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public function intervalsOn(CarbonInterface $day): array
    {
        if (in_array($day->format('Y-m-d'), $this->holidays, true)) {
            return [];
        }

        return $this->intervals[strtolower($day->locale('en')->isoFormat('ddd'))] ?? [];
    }

    protected static function time(mixed $value): ?string
    {
        // Time pickers may add seconds.
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $value, $match) === 1 ? substr($value, 0, 5) : null;
    }
}
