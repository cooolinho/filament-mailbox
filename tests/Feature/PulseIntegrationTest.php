<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Carbon\CarbonInterval;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Jobs\SyncMailboxJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Monitoring\Pulse\Cards\MailboxSyncFailures;
use Cooolinho\FilamentMailbox\Monitoring\Pulse\Cards\MailboxSyncs;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\PulseServiceProvider;
use Livewire\Livewire;
use RuntimeException;

class PulseIntegrationTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->fakeProvider();
        $this->provider->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox))->addMessage('INBOX', 1);

        $this->mailbox = Mailbox::factory()->create(['name' => 'Support']);
    }

    public function test_sync_runs_are_recorded_and_shown_in_pulse_cards(): void
    {
        app()->call([new SyncMailboxJob($this->mailbox), 'handle']);

        $this->provider->connectionException = new RuntimeException('Stream timed out');
        rescue(fn () => app()->call([new SyncMailboxJob($this->mailbox), 'handle']), report: false);

        Pulse::ingest();

        $syncs = Pulse::aggregate('mailbox_sync', ['avg', 'max', 'count'], CarbonInterval::hour());
        $this->assertSame([(string) $this->mailbox->id], $syncs->pluck('key')->all());
        $this->assertEquals(2, $syncs->first()->count);

        $failures = Pulse::aggregate('mailbox_sync_failure', ['count'], CarbonInterval::hour());
        $this->assertSame(['timeout'], $failures->pluck('key')->all());

        Gate::define('viewPulse', fn () => true);

        Livewire::withoutLazyLoading()->test(MailboxSyncs::class)
            ->assertSee('Support')
            ->assertDontSee('Stream timed out');

        Livewire::withoutLazyLoading()->test(MailboxSyncFailures::class)
            ->assertSee(__('filament-mailbox::mailbox.monitoring.error_types.timeout'));
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PulseServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pulse.enabled', true);
        $app['config']->set('pulse.recorders', []);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/laravel/pulse/database/migrations');
    }
}
