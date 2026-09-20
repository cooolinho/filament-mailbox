<?php

namespace Cooolinho\FilamentMailbox\Monitoring;

use Cooolinho\FilamentMailbox\Contracts\SyncObserver;
use Throwable;

/**
 * Forwards every measurement to several observers.
 */
class CompositeSyncObserver implements SyncObserver
{
    /** @var array<int, SyncObserver> */
    protected array $observers;

    public function __construct(SyncObserver ...$observers)
    {
        $this->observers = $observers;
    }

    public function folderStarted(string $folder): void
    {
        foreach ($this->observers as $observer) {
            $observer->folderStarted($folder);
        }
    }

    public function folderFinished(string $folder, int $imported, int $updated, int $deleted): void
    {
        foreach ($this->observers as $observer) {
            $observer->folderFinished($folder, $imported, $updated, $deleted);
        }
    }

    public function folderFailed(string $folder, Throwable $exception): void
    {
        foreach ($this->observers as $observer) {
            $observer->folderFailed($folder, $exception);
        }
    }

    public function providerCall(string $operation, float $milliseconds, ?Throwable $exception = null): void
    {
        foreach ($this->observers as $observer) {
            $observer->providerCall($operation, $milliseconds, $exception);
        }
    }

    public function logContext(): array
    {
        return array_merge(...array_map(fn (SyncObserver $observer): array => $observer->logContext(), $this->observers));
    }
}
