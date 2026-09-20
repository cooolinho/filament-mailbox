<?php

namespace Cooolinho\FilamentMailbox\Contracts;

use Throwable;

/**
 * Receives measurements while SyncService runs, e.g. to record sync runs.
 */
interface SyncObserver
{
    public function folderStarted(string $folder): void;

    public function folderFinished(string $folder, int $imported, int $updated, int $deleted): void;

    public function folderFailed(string $folder, Throwable $exception): void;

    /**
     * A provider operation finished (or failed with the given exception).
     */
    public function providerCall(string $operation, float $milliseconds, ?Throwable $exception = null): void;

    /**
     * Context added to every log entry of the run, e.g. a correlation id.
     *
     * @return array<string, scalar>
     */
    public function logContext(): array;
}
