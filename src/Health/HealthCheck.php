<?php

namespace Cooolinho\FilamentMailbox\Health;

/**
 * A system check of the health dashboard. Register own checks in
 * "filament-mailbox.health.checks".
 */
interface HealthCheck
{
    /**
     * Unique key, e.g. "queue".
     */
    public function key(): string;

    public function label(): string;

    public function run(): HealthCheckResult;

    /**
     * Cheap checks (cache reads) run on every render; all others are run and
     * cached by "mailbox:monitor".
     */
    public function isLive(): bool;
}
