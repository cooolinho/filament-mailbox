<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use ArrayObject;
use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Data\FolderChanges;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Data\SyncCursor;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Jobs\SyncMailboxJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxSyncRun;
use Cooolinho\FilamentMailbox\Monitoring\OpenTelemetry\TracingSyncObserver;
use Cooolinho\FilamentMailbox\Monitoring\SyncErrorClassifier;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProviderFactory;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use RuntimeException;

class OpenTelemetryTest extends TestCase
{
    protected ArrayObject $spans;

    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spans = new ArrayObject;
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter($this->spans)));
        $this->app->instance(TracingSyncObserver::class, new TracingSyncObserver($tracerProvider, new SyncErrorClassifier));

        config(['filament-mailbox.monitoring.opentelemetry' => true]);

        $this->mailbox = Mailbox::factory()->create(['name' => 'Support']);
    }

    public function test_sync_runs_create_nested_spans_without_personal_data(): void
    {
        $failing = new class extends FakeMailboxProvider
        {
            public function changes(FolderIdentifier $folder, SyncCursor $cursor, int $limit): FolderChanges
            {
                if ($folder->remoteId === 'Kunden/Acme') {
                    throw new RuntimeException('Connection timed out for anna@example.com');
                }

                return parent::changes($folder, $cursor, $limit);
            }
        };
        $failing
            ->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox))
            ->addFolder(new FolderData('Acme', 'Kunden/Acme', '/'))
            ->addMessage('INBOX', 1);
        $this->app->instance(MailboxProviderFactory::class, new FakeMailboxProviderFactory($failing));

        app()->call([new SyncMailboxJob($this->mailbox), 'handle']);

        /** @var array<string, array<int, ImmutableSpan>> $byName */
        $byName = collect($this->spans->getArrayCopy())->groupBy(fn (ImmutableSpan $span): string => $span->getName())->all();

        $run = $byName['mailbox.sync'][0];
        $this->assertSame((int) $this->mailbox->id, $run->getAttributes()->get('mailbox.id'));
        $this->assertSame('schedule', $run->getAttributes()->get('mailbox.sync.trigger'));
        $this->assertSame('partial', $run->getAttributes()->get('mailbox.sync.status'));
        $this->assertSame(MailboxSyncRun::sole()->uuid, $run->getAttributes()->get('mailbox.sync.run_id'));
        $this->assertSame(1, $run->getAttributes()->get('mailbox.sync.imported'));

        $folders = $byName['mailbox.sync.folder'];
        $this->assertCount(2, $folders);
        $this->assertTrue(collect($folders)->every(fn (ImmutableSpan $span): bool => $span->getParentSpanId() === $run->getSpanId()));
        $this->assertSame(TracingSyncObserver::hash('Kunden/Acme'), $folders[1]->getAttributes()->get('mailbox.folder.hash'));
        $this->assertSame(StatusCode::STATUS_ERROR, $folders[1]->getStatus()->getCode());
        $this->assertSame('timeout', $folders[1]->getAttributes()->get('mailbox.sync.error_type'));

        $changes = $byName['mailbox.provider.changes'];
        $this->assertCount(2, $changes);
        $this->assertSame($folders[0]->getSpanId(), $changes[0]->getParentSpanId());
        $this->assertSame($run->getSpanId(), $byName['mailbox.provider.folders'][0]->getParentSpanId());

        $serialized = json_encode(array_map(fn (ImmutableSpan $span): array => [$span->getName(), $span->getAttributes()->toArray(), $span->getStatus()->getDescription()], $this->spans->getArrayCopy()));
        $this->assertStringNotContainsString('Support', $serialized);
        $this->assertStringNotContainsString('Kunden', $serialized);
        $this->assertStringNotContainsString('anna@example.com', $serialized);
    }

    public function test_failed_runs_mark_the_run_span_as_error(): void
    {
        $provider = $this->fakeProvider();
        $provider->connectionException = new RuntimeException('LOGIN failed');

        rescue(fn () => app()->call([new SyncMailboxJob($this->mailbox), 'handle']), report: false);

        $run = collect($this->spans->getArrayCopy())->first(fn (ImmutableSpan $span): bool => $span->getName() === 'mailbox.sync');

        $this->assertSame(StatusCode::STATUS_ERROR, $run->getStatus()->getCode());
        $this->assertSame('authentication', $run->getAttributes()->get('mailbox.sync.error_type'));
    }

    public function test_tracing_is_off_by_default(): void
    {
        config(['filament-mailbox.monitoring.opentelemetry' => false]);
        $this->fakeProvider()->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox));

        app()->call([new SyncMailboxJob($this->mailbox), 'handle']);

        $this->assertCount(0, $this->spans);
        $this->assertSame(1, MailboxSyncRun::count());
    }
}
