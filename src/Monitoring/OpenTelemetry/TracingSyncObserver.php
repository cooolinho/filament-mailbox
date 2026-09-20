<?php

namespace Cooolinho\FilamentMailbox\Monitoring\OpenTelemetry;

use Cooolinho\FilamentMailbox\Contracts\SyncObserver;
use Cooolinho\FilamentMailbox\Enums\SyncTrigger;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxSyncRun;
use Cooolinho\FilamentMailbox\Monitoring\SyncErrorClassifier;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Throwable;

/**
 * OpenTelemetry spans for a sync run: "mailbox.sync" with a child span per
 * folder ("mailbox.sync.folder") and per provider call ("mailbox.provider.<operation>").
 *
 * Attributes never contain names, addresses or messages: mailboxes are
 * identified by id, folders by a short hash of their path.
 */
class TracingSyncObserver implements SyncObserver
{
    public const INSTRUMENTATION = 'cooolinho/filament-mailbox';

    protected TracerInterface $tracer;

    protected ?SpanInterface $runSpan = null;

    protected ?ContextInterface $runContext = null;

    protected ?SpanInterface $folderSpan = null;

    protected ?ContextInterface $folderContext = null;

    public function __construct(
        TracerProviderInterface $tracerProvider,
        protected SyncErrorClassifier $classifier,
    ) {
        $this->tracer = $tracerProvider->getTracer(self::INSTRUMENTATION);
    }

    public function startRun(Mailbox $mailbox, SyncTrigger $trigger): void
    {
        $this->runSpan = $this->tracer->spanBuilder('mailbox.sync')
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('mailbox.id', (int) $mailbox->getKey())
            ->setAttribute('mailbox.provider', $mailbox->provider->value)
            ->setAttribute('mailbox.sync.trigger', $trigger->value)
            ->startSpan();

        $this->runContext = $this->runSpan->storeInContext(Context::getCurrent());
    }

    public function finishRun(?MailboxSyncRun $run, ?Throwable $exception = null): void
    {
        if (! $this->runSpan) {
            return;
        }

        if ($run) {
            $this->runSpan->setAttributes(array_filter([
                'mailbox.sync.run_id' => $run->uuid,
                'mailbox.sync.status' => $run->status->value,
                'mailbox.sync.folders' => $run->folders,
                'mailbox.sync.imported' => $run->imported,
                'mailbox.sync.updated' => $run->updated,
                'mailbox.sync.deleted' => $run->deleted,
                'mailbox.sync.queue_wait_ms' => $run->queue_wait_ms,
                'mailbox.sync.error_type' => $run->error_type?->value,
            ], fn (mixed $value): bool => $value !== null));
        }

        if ($exception) {
            $this->fail($this->runSpan, $exception);
        } elseif ($run?->error_type) {
            $this->runSpan->setStatus(StatusCode::STATUS_ERROR, $run->error_type->value);
        } else {
            $this->runSpan->setStatus(StatusCode::STATUS_OK);
        }

        $this->runSpan->end();
        $this->runSpan = $this->runContext = null;
    }

    public function folderStarted(string $folder): void
    {
        $this->folderSpan = $this->tracer->spanBuilder('mailbox.sync.folder')
            ->setParent($this->runContext ?? false)
            ->setAttribute('mailbox.folder.hash', static::hash($folder))
            ->startSpan();

        $this->folderContext = $this->folderSpan->storeInContext($this->runContext ?? Context::getCurrent());
    }

    public function folderFinished(string $folder, int $imported, int $updated, int $deleted): void
    {
        $this->folderSpan?->setAttributes([
            'mailbox.sync.imported' => $imported,
            'mailbox.sync.updated' => $updated,
            'mailbox.sync.deleted' => $deleted,
        ])->setStatus(StatusCode::STATUS_OK);

        $this->endFolder();
    }

    public function folderFailed(string $folder, Throwable $exception): void
    {
        if ($this->folderSpan) {
            $this->fail($this->folderSpan, $exception);
        }

        $this->endFolder();
    }

    public function providerCall(string $operation, float $milliseconds, ?Throwable $exception = null): void
    {
        $end = (int) (microtime(true) * 1_000_000_000);

        // Reported after the call, so the span is created with its real start time.
        $span = $this->tracer->spanBuilder('mailbox.provider.'.$operation)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setParent($this->folderContext ?? $this->runContext ?? false)
            ->setStartTimestamp($end - (int) ($milliseconds * 1_000_000))
            ->setAttribute('mailbox.provider.operation', $operation)
            ->startSpan();

        $exception ? $this->fail($span, $exception) : $span->setStatus(StatusCode::STATUS_OK);

        $span->end($end);
    }

    public function logContext(): array
    {
        $context = $this->runSpan?->getContext();

        return $context && $context->isValid() ? ['trace_id' => $context->getTraceId()] : [];
    }

    public static function hash(string $value): string
    {
        return substr(sha1($value), 0, 12);
    }

    protected function fail(SpanInterface $span, Throwable $exception): void
    {
        // Only the classified type and the class: exception messages may contain server details.
        $span->setStatus(StatusCode::STATUS_ERROR, $this->classifier->classify($exception)->value)
            ->setAttribute('mailbox.sync.error_type', $this->classifier->classify($exception)->value)
            ->setAttribute('exception.type', $exception::class);
    }

    protected function endFolder(): void
    {
        $this->folderSpan?->end();
        $this->folderSpan = $this->folderContext = null;
    }
}
