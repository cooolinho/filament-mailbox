<?php

namespace Cooolinho\FilamentMailbox\Jobs;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Contracts\SyncObserver;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\SyncTrigger;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Monitoring\SyncRunRecorder;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Synchronises a single folder, e.g. after a change notification.
 */
class SyncMailboxFolderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $uniqueFor = 300;

    /** For the queue wait time of the sync run. */
    public string $dispatchedAt;

    public function __construct(
        public MailboxFolder $folder,
        public SyncTrigger $trigger = SyncTrigger::Webhook,
    ) {
        $this->dispatchedAt = now()->toIso8601ZuluString('millisecond');
        $this->onConnection(config('filament-mailbox.sync.queue_connection'));
        $this->onQueue(config('filament-mailbox.sync.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->folder->getKey();
    }

    public function handle(SyncService $sync, MailboxProviderFactory $providers, ?SyncRunRecorder $recorder = null): void
    {
        $recorder ??= app(SyncRunRecorder::class);

        $mailbox = $this->folder->mailbox;

        if (! $mailbox?->is_active || ! $this->folder->is_active) {
            return;
        }

        $trigger = $this->attempts() > 1 ? SyncTrigger::Retry : $this->trigger;

        $recorder->record($mailbox, $trigger, Carbon::parse($this->dispatchedAt), function (SyncObserver $observer) use ($sync, $providers, $mailbox) {
            if ($mailbox->supports(ProviderCapability::MailboxWideSync)) {
                return $sync->syncMailbox($mailbox, $observer);
            }

            $provider = $providers->make($mailbox);

            try {
                return $sync->syncFolder($this->folder, $provider, $observer);
            } finally {
                $provider->disconnect();
            }
        });
    }
}
