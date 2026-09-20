<?php

namespace Cooolinho\FilamentMailbox\Statistics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class BusinessHoursCalculator
{
    /**
     * Minutes between two points in time that lie within the business hours
     * (local wall-clock intervals, so daylight saving changes are respected).
     */
    public function minutes(CarbonInterface $from, CarbonInterface $to, BusinessHours $hours): int
    {
        $from = CarbonImmutable::instance($from)->setTimezone($hours->timezone);
        $to = CarbonImmutable::instance($to)->setTimezone($hours->timezone);

        if ($to->lte($from)) {
            return 0;
        }

        $seconds = 0;

        for ($day = $from->startOfDay(); $day->lte($to); $day = $day->addDay()) {
            foreach ($hours->intervalsOn($day) as [$start, $end]) {
                $open = $day->setTimeFromTimeString($start);
                $close = $day->setTimeFromTimeString($end);

                $overlapStart = $open->max($from);
                $overlapEnd = $close->min($to);

                if ($overlapEnd->gt($overlapStart)) {
                    $seconds += $overlapEnd->getTimestamp() - $overlapStart->getTimestamp();
                }
            }
        }

        return intdiv($seconds, 60);
    }
}
