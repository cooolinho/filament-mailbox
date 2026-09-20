<?php

namespace Cooolinho\FilamentMailbox\Jobs;

use Cooolinho\FilamentMailbox\Contracts\SyncObserver;
use Cooolinho\FilamentMailbox\Enums\SyncTrigger;
use Cooolinho\FilamentMailbox\Exceptions\SyncFailed;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Monitoring\SyncRunRecorder;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class SyncMailboxJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $uniqueFor = 900;

    /** For the queue wait time of the sync run. */
    public string $dispatchedAt;

    public function __construct(
        public Mailbox $mailbox,
        public SyncTrigger $trigger = SyncTrigger::Schedule,
    ) {
        $this->dispatchedAt = now()->toIso8601ZuluString('millisecond');
        $this->onConnection(config('filament-mailbox.sync.queue_connection'));
        $this->onQueue(config('filament-mailbox.sync.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->mailbox->getKey();
    }

    public function handle(SyncService $sync, ?SyncRunRecorder $recorder = null): void
    {
        $recorder ??= app(SyncRunRecorder::class);

        if (! $this->mailbox->is_active) {
            return;
        }

        try {
            $recorder->record(
                $this->mailbox,
                $this->attempts() > 1 ? SyncTrigger::Retry : $this->trigger,
                Carbon::parse($this->dispatchedAt),
                fn (SyncObserver $observer) => $sync->syncMailbox($this->mailbox, $observer),
            );
        } catch (SyncFailed $exception) {
            if (! $exception->permanent) {
                throw $exception;
            }

            // E.g. a revoked OAuth connection: fail without retries.
            $this->fail($exception);
        }
    }
}
