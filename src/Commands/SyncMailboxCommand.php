<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Cooolinho\FilamentMailbox\Contracts\SyncObserver;
use Cooolinho\FilamentMailbox\Data\SyncResult;
use Cooolinho\FilamentMailbox\Enums\SyncTrigger;
use Cooolinho\FilamentMailbox\Exceptions\SyncFailed;
use Cooolinho\FilamentMailbox\Monitoring\SyncRunRecorder;
use Cooolinho\FilamentMailbox\Jobs\SyncMailboxJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Illuminate\Console\Command;

class SyncMailboxCommand extends Command
{
    protected $signature = 'mailbox:sync
        {mailbox?* : IDs of the mailboxes to synchronise (default: all active mailboxes)}
        {--now : Synchronise immediately instead of dispatching queue jobs}';

    protected $description = 'Synchronise mailboxes with their remote servers';

    public function handle(SyncService $sync): int
    {
        $ids = $this->argument('mailbox');

        $mailboxes = Mailbox::query()
            ->active()
            ->when($ids !== [], fn ($query) => $query->whereKey($ids))
            ->get();

        if ($mailboxes->isEmpty()) {
            $this->components->warn('No active mailboxes found.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($mailboxes as $mailbox) {
            if (! $this->option('now')) {
                SyncMailboxJob::dispatch($mailbox);
                $this->components->info("Queued synchronisation for mailbox [{$mailbox->name}].");

                continue;
            }

            try {
                $result = app(SyncRunRecorder::class)->record($mailbox, SyncTrigger::Command, null, fn (SyncObserver $observer): SyncResult => $sync->syncMailbox($mailbox, $observer));
            } catch (SyncFailed $exception) {
                $failed = true;
                $this->components->error("Mailbox [{$mailbox->name}]: {$exception->getMessage()}");

                continue;
            }

            $this->components->info("Mailbox [{$mailbox->name}]: {$result->folders} folders, {$result->imported} new messages.");

            foreach ($result->errors as $folder => $error) {
                $failed = true;
                $this->components->error("Folder [{$folder}]: {$error}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
