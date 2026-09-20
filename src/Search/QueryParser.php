<?php

namespace Cooolinho\FilamentMailbox\Search;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Gmail-like search syntax: words, "phrases", -exclusion, OR and operators
 * (from:, to:, cc:, subject:, has:attachment, filename:, is:read|unread|starred|unstarred,
 * in:, before:, after:, older_than:, newer_than:). Unknown operators and invalid
 * values are searched as text. Dates are read in the given time zone.
 */
class QueryParser
{
    public const MAX_LENGTH = 500;

    public const MAX_TOKENS = 20;

    public function parse(string $input, ?string $timezone = null): SearchQuery
    {
        $input = mb_substr(trim($input), 0, self::MAX_LENGTH);
        $timezone ??= config('app.timezone');

        preg_match_all('/(-?)(?:([a-z_]+):)?(?:"([^"]*)"?|(\S+))/iu', $input, $matches, PREG_SET_ORDER);

        $clauses = [];
        $excluded = [];
        $filters = [];
        $or = false;

        foreach (array_slice($matches, 0, self::MAX_TOKENS) as $match) {
            $negated = $match[1] === '-';
            $operator = mb_strtolower($match[2] ?? '');
            // The value part starts with a quote: "exact phrase" or operator:"exact phrase".
            $phrase = str_starts_with(substr($match[0], strlen($match[1]) + ($operator === '' ? 0 : strlen($operator) + 1)), '"');
            $value = $phrase ? ($match[3] ?? '') : ($match[4] ?? '');

            if ($operator === '' && ! $negated && ! $phrase && $value === 'OR') {
                $or = $clauses !== [];

                continue;
            }

            if ($operator !== '' && ($filter = $this->filter($operator, $value, $negated, $timezone))) {
                $filters[] = $filter;
                $or = false;

                continue;
            }

            $text = $operator !== '' ? $operator.':'.$value : $value;

            if (trim($text) === '') {
                continue;
            }

            $term = new SearchTerm($text, $phrase);

            if ($negated) {
                $excluded[] = $term;
            } elseif ($or) {
                $clauses[array_key_last($clauses)][] = $term;
            } else {
                $clauses[] = [$term];
            }

            $or = false;
        }

        return new SearchQuery(array_values($clauses), $excluded, $filters, $input);
    }

    protected function filter(string $operator, string $value, bool $negated, string $timezone): ?SearchFilter
    {
        $lower = mb_strtolower($value);

        return match ($operator) {
            'from', 'to', 'cc', 'subject', 'filename' => $value === '' ? null : new SearchFilter($operator, mb_substr($value, 0, 200), $negated),
            'has' => in_array($lower, ['attachment', 'attachments', 'anhang'], true) ? new SearchFilter(SearchFilter::HAS_ATTACHMENT, ! $negated) : null,
            'is' => match ($lower) {
                'read' => new SearchFilter(SearchFilter::IS_READ, ! $negated),
                'unread' => new SearchFilter(SearchFilter::IS_READ, $negated),
                'starred', 'flagged' => new SearchFilter(SearchFilter::IS_FLAGGED, ! $negated),
                'unstarred', 'unflagged' => new SearchFilter(SearchFilter::IS_FLAGGED, $negated),
                default => null,
            },
            'in' => $value === '' ? null : new SearchFilter(SearchFilter::IN_FOLDER, mb_substr($value, 0, 200), $negated),
            'before', 'after' => ($date = $this->date($value, $timezone)) ? new SearchFilter($operator, $date) : null,
            'older_than' => ($date = $this->relative($value)) ? new SearchFilter(SearchFilter::BEFORE, $date) : null,
            'newer_than' => ($date = $this->relative($value)) ? new SearchFilter(SearchFilter::AFTER, $date) : null,
            default => null,
        };
    }

    /**
     * Start of the day in the user's time zone, as application time.
     */
    protected function date(string $value, string $timezone): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}[-\/]\d{1,2}[-\/]\d{1,2}$|^\d{1,2}\.\d{1,2}\.\d{4}$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse(str_replace('/', '-', $value), $timezone)->startOfDay()->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * "30d", "2w", "6m", "1y" before now.
     */
    protected function relative(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^(\d{1,4})([dwmy])$/i', $value, $matches)) {
            return null;
        }

        $amount = (int) $matches[1];

        return match (strtolower($matches[2])) {
            'd' => CarbonImmutable::now()->subDays($amount),
            'w' => CarbonImmutable::now()->subWeeks($amount),
            'm' => CarbonImmutable::now()->subMonths($amount),
            'y' => CarbonImmutable::now()->subYears($amount),
        };
    }
}
