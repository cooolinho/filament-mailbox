<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Jobs\SyncMailboxJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

class SyncCommandTest extends TestCase
{
    public function test_it_dispatches_jobs_for_active_mailboxes(): void
    {
        Queue::fake();
        config(['filament-mailbox.sync.queue' => 'mail']);

        $active = Mailbox::factory()->create();
        $inactive = Mailbox::factory()->inactive()->create();

        $this->artisan('mailbox:sync')->assertSuccessful();

        Queue::assertPushedOn('mail', SyncMailboxJob::class, fn (SyncMailboxJob $job) => $job->mailbox->is($active));
        Queue::assertNotPushed(SyncMailboxJob::class, fn (SyncMailboxJob $job) => $job->mailbox->is($inactive));
    }

    public function test_it_can_limit_to_given_mailboxes(): void
    {
        Queue::fake();

        $first = Mailbox::factory()->create();
        Mailbox::factory()->create();

        $this->artisan('mailbox:sync', ['mailbox' => [$first->id]])->assertSuccessful();

        Queue::assertPushed(SyncMailboxJob::class, 1);
    }

    public function test_now_option_synchronises_immediately(): void
    {
        $provider = $this->fakeProvider();
        $provider->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox));
        $mailbox = Mailbox::factory()->create();

        $this->artisan('mailbox:sync', ['--now' => true])->assertSuccessful();

        $this->assertNotNull($mailbox->refresh()->last_synced_at);
        $this->assertSame(1, $mailbox->folders()->count());
    }

    public function test_now_option_reports_failures(): void
    {
        $provider = $this->fakeProvider();
        $provider->connectionException = new RuntimeException('unreachable');
        Mailbox::factory()->create();

        $this->artisan('mailbox:sync', ['--now' => true])->assertFailed();
    }

    public function test_job_runs_the_synchronisation(): void
    {
        $provider = $this->fakeProvider();
        $provider->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox));
        $mailbox = Mailbox::factory()->create();

        SyncMailboxJob::dispatchSync($mailbox);

        $this->assertNotNull($mailbox->refresh()->last_synced_at);
        $this->assertSame((string) $mailbox->id, (new SyncMailboxJob($mailbox))->uniqueId());
    }
}
