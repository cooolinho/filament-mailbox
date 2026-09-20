<?php

namespace Cooolinho\FilamentMailbox\Monitoring;

use Cooolinho\FilamentMailbox\Contracts\SyncObserver;
use Throwable;

class NullSyncObserver implements SyncObserver
{
    public function folderStarted(string $folder): void
    {
        //
    }

    public function folderFinished(string $folder, int $imported, int $updated, int $deleted): void
    {
        //
    }

    public function folderFailed(string $folder, Throwable $exception): void
    {
        //
    }

    public function providerCall(string $operation, float $milliseconds, ?Throwable $exception = null): void
    {
        //
    }

    public function logContext(): array
    {
        return [];
    }
}
