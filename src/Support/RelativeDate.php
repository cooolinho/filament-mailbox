<?php

namespace Cooolinho\FilamentMailbox\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Relative date expressions of presets ("+3 hours", "tomorrow 08:00"), calculated in a time zone.
 */
class RelativeDate
{
    public static function resolve(string $expression, ?string $timezone = null): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::now($timezone ?? config('app.timezone'))->modify($expression) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
