<?php

namespace Cooolinho\FilamentMailbox\Monitoring;

use Closure;
use Cooolinho\FilamentMailbox\Contracts\SyncObserver;
use Cooolinho\FilamentMailbox\Data\SyncResult;
use Cooolinho\FilamentMailbox\Enums\SyncRunStatus;
use Cooolinho\FilamentMailbox\Enums\SyncTrigger;
use Cooolinho\FilamentMailbox\Events\SyncRunFinished;
use Cooolinho\FilamentMailbox\Events\SyncRunStarted;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxSyncRun;
use Cooolinho\FilamentMailbox\Monitoring\OpenTelemetry\TracingSyncObserver;
use Cooolinho\FilamentMailbox\Support\CredentialRedactor;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Throwable;

/**
 * Records one synchronisation run: lifecycle, counts, per-folder statistics,
 * provider latencies and the classified error. Use one instance per run.
 */
class SyncRunRecorder implements SyncObserver
{
    protected ?MailboxSyncRun $run = null;

    protected ?Mailbox $mailbox = null;

    /** @var array<string, array{duration_ms: int, imported: int, updated: int, deleted: int, error_type: ?string, error: ?string}> */
    protected array $folders = [];

    /** @var array<string, float> */
    protected array $folderStarts = [];

    /** @var array<string, array{count: int, errors: int, total_ms: float, max_ms: float}> */
    protected array $providerStats = [];

    public function __construct(
        protected SyncErrorClassifier $classifier,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('filament-mailbox.monitoring.enabled', true);
    }

    /**
     * @template T
     *
     * @param  Closure(SyncObserver): T  $sync
     * @return T
     */
    public function record(Mailbox $mailbox, SyncTrigger $trigger, ?DateTimeInterface $queuedAt, Closure $sync): mixed
    {
        if (! static::enabled()) {
            return $sync(new NullSyncObserver);
        }

        $tracing = static::tracing();

        $this->start($mailbox, $trigger, $queuedAt);
        $tracing?->startRun($mailbox, $trigger);
        $started = hrtime(true);

        try {
            $result = $sync($tracing ? new CompositeSyncObserver($this, $tracing) : $this);
        } catch (Throwable $exception) {
            $this->finish($started, null, $exception);
            $tracing?->finishRun($this->run, $exception);

            throw $exception;
        }

        $this->finish($started, $result instanceof SyncResult ? $result : null);
        $tracing?->finishRun($this->run);

        return $result;
    }

    /**
     * OpenTelemetry spans, when enabled and the API package is installed.
     */
    public static function tracing(): ?TracingSyncObserver
    {
        if (! config('filament-mailbox.monitoring.opentelemetry', false) || ! interface_exists(TracerProviderInterface::class)) {
            return null;
        }

        return app(TracingSyncObserver::class);
    }

    public function run(): ?MailboxSyncRun
    {
        return $this->run;
    }

    public function folderStarted(string $folder): void
    {
        $this->folderStarts[$folder] = hrtime(true);
    }

    public function folderFinished(string $folder, int $imported, int $updated, int $deleted): void
    {
        $this->folders[$folder] = [
            'duration_ms' => $this->elapsed($this->folderStarts[$folder] ?? hrtime(true)),
            'imported' => $imported,
            'updated' => $updated,
            'deleted' => $deleted,
            'error_type' => null,
            'error' => null,
        ];
    }

    public function folderFailed(string $folder, Throwable $exception): void
    {
        $this->folders[$folder] = [
            'duration_ms' => $this->elapsed($this->folderStarts[$folder] ?? hrtime(true)),
            'imported' => 0,
            'updated' => 0,
            'deleted' => 0,
            'error_type' => $this->classifier->classify($exception)->value,
            'error' => $this->redact($exception->getMessage()),
        ];
    }

    public function providerCall(string $operation, float $milliseconds, ?Throwable $exception = null): void
    {
        $stats = $this->providerStats[$operation] ?? ['count' => 0, 'errors' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0];

        $this->providerStats[$operation] = [
            'count' => $stats['count'] + 1,
            'errors' => $stats['errors'] + ($exception ? 1 : 0),
            'total_ms' => round($stats['total_ms'] + $milliseconds, 2),
            'max_ms' => round(max($stats['max_ms'], $milliseconds), 2),
        ];
    }

    public function logContext(): array
    {
        return $this->run ? ['sync_run_id' => $this->run->uuid] : [];
    }

    protected function start(Mailbox $mailbox, SyncTrigger $trigger, ?DateTimeInterface $queuedAt): void
    {
        $this->mailbox = $mailbox;
        $now = now();

        $this->run = MailboxSyncRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'mailbox_id' => $mailbox->getKey(),
            'trigger' => $trigger,
            'status' => SyncRunStatus::Running,
            'queued_at' => $queuedAt,
            'started_at' => $now,
            'queue_wait_ms' => $queuedAt ? max(0, (int) round(Carbon::instance($queuedAt)->diffInMilliseconds($now))) : null,
        ]);

        SyncRunStarted::dispatch($this->run);
    }

    protected function finish(int|float $started, ?SyncResult $result, ?Throwable $exception = null): void
    {
        $folderErrors = array_filter($this->folders, fn (array $stats): bool => $stats['error_type'] !== null);
        $firstError = $folderErrors === [] ? null : reset($folderErrors);

        $status = match (true) {
            $exception !== null => SyncRunStatus::Failed,
            $folderErrors === [] && ($result === null || $result->successful()) => SyncRunStatus::Succeeded,
            count($folderErrors) < count($this->folders) => SyncRunStatus::Partial,
            default => SyncRunStatus::Failed,
        };

        // Errors of the result without folder statistics (e.g. the label catalogue).
        $resultError = $result && ! $result->successful() && $firstError === null ? (string) reset($result->errors) : null;

        $this->run->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'duration_ms' => $this->elapsed($started),
            'folders' => $result?->folders ?? count($this->folders),
            'imported' => $result?->imported ?? array_sum(array_column($this->folders, 'imported')),
            'updated' => array_sum(array_column($this->folders, 'updated')),
            'deleted' => array_sum(array_column($this->folders, 'deleted')),
            'error_type' => match (true) {
                $exception !== null => $this->classifier->classify($exception),
                $firstError !== null => $firstError['error_type'],
                $resultError !== null => $this->classifier->classifyMessage($resultError),
                default => null,
            },
            'error' => match (true) {
                $exception !== null => $this->redact($exception->getMessage()),
                $firstError !== null => $firstError['error'],
                default => $resultError,
            },
            'folder_stats' => $this->folders === [] ? null : $this->folders,
            'provider_stats' => $this->providerStats === [] ? null : $this->providerStats,
        ])->save();

        SyncRunFinished::dispatch($this->run);
    }

    protected function elapsed(int|float $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }

    protected function redact(string $message): string
    {
        // Monitoring data must not contain addresses (e.g. the OAuth account).
        $message = preg_replace('/[^\s@<>"\'(),;:]+@[^\s@<>"\'(),;:]+\.[a-z]{2,}/i', '[address]', CredentialRedactor::redact($message, $this->mailbox)) ?? '';

        return mb_substr($message, 0, 2000);
    }
}
