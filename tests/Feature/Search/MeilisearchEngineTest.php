<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature\Search;

use Carbon\CarbonImmutable;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxSearchTask;
use Cooolinho\FilamentMailbox\Health\Checks\SearchEngineCheck;
use Cooolinho\FilamentMailbox\Enums\HealthCheckStatus;
use Cooolinho\FilamentMailbox\Search\SearchDocumentMapper;
use Cooolinho\FilamentMailbox\Search\Meilisearch\MeilisearchFilterBuilder;
use Cooolinho\FilamentMailbox\Search\Meilisearch\MeilisearchSearchEngine;
use Cooolinho\FilamentMailbox\Search\QueryParser;
use Cooolinho\FilamentMailbox\Search\SearchManager;
use Cooolinho\FilamentMailbox\Search\SearchScope;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The Meilisearch engine against a faked HTTP API; the real instance is
 * covered by tests/Integration/MeilisearchIntegrationTest.
 */
class MeilisearchEngineTest extends TestCase
{
    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filament-mailbox.search.engine', 'meilisearch');
        $app['config']->set('filament-mailbox.search.meilisearch.host', 'http://meili.test:7700');
        $app['config']->set('filament-mailbox.search.meilisearch.key', 'search-key');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-22 12:00:00', 'UTC'));
        $this->mailbox = Mailbox::factory()->create();
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
    }

    public function test_searching_sends_query_and_filters_and_returns_ids(): void
    {
        Http::fake(['http://meili.test:7700/*' => Http::response(['hits' => [['message_id' => 7], ['message_id' => 3]], 'estimatedTotalHits' => 9])]);

        $result = app(MeilisearchSearchEngine::class)->search(
            app(QueryParser::class)->parse('rechnung "letzte mahnung" -newsletter from:jane@example.com is:unread after:2026-09-01', 'UTC'),
            new SearchScope($this->mailbox->id, $this->inbox->id),
            25,
        );

        $this->assertSame([7, 3], $result->ids);
        $this->assertSame(9, $result->total);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('Bearer search-key', $request->header('Authorization')[0]);
            $this->assertSame('http://meili.test:7700/indexes/filament_mailbox_messages/search', $request->url());
            $this->assertSame('rechnung "letzte mahnung" -newsletter', $request['q']);
            $this->assertSame([
                'mailbox_id = '.$this->mailbox->id,
                'folder_id = '.$this->inbox->id,
                "(from_tokens = 'jane' AND from_tokens = 'example' AND from_tokens = 'com')",
                'is_read = false',
                'received_at_ts >= '.CarbonImmutable::parse('2026-09-01', 'UTC')->getTimestamp(),
            ], $request['filter']);
            $this->assertSame(25, $request['limit']);

            return true;
        });
    }

    public function test_filter_values_are_escaped(): void
    {
        $filters = app(MeilisearchFilterBuilder::class)->build(
            app(QueryParser::class)->parse('from:"o\'brien" -in:'.$this->inbox->name),
            new SearchScope($this->mailbox->id),
        );

        $this->assertSame([
            'mailbox_id = '.$this->mailbox->id,
            "(from_tokens = 'o' AND from_tokens = 'brien')",
            'NOT (folder_id IN ['.$this->inbox->id.'])',
        ], $filters);

        $this->assertSame("'o\\\\ \\'; x'", MeilisearchFilterBuilder::escape("o\\ '; x"));
    }

    public function test_documents_carry_tokens_flags_and_timestamps(): void
    {
        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create([
            'from_name' => 'Jane Doe', 'from_address' => 'billing@acme.test', 'subject' => 'Rechnung',
            'to' => [['address' => 'office@example.com', 'name' => 'Office']], 'cc' => [],
            'text_body' => null, 'html_body' => '<p>Bitte <b>zahlen</b></p>', 'is_read' => true, 'received_at' => now(),
        ]);

        $document = app(SearchDocumentMapper::class)->map($message);

        $this->assertSame('Jane Doe billing@acme.test', $document['from']);
        $this->assertSame(['jane', 'doe', 'billing', 'acme', 'test'], $document['from_tokens']);
        $this->assertSame(['office', 'example', 'com'], $document['to_tokens']);
        $this->assertSame('acme.test', $document['from_domain']);
        $this->assertStringContainsString('zahlen', $document['body']);
        $this->assertTrue($document['is_read']);
        $this->assertSame(now()->getTimestamp(), $document['received_at_ts']);
    }

    public function test_indexing_runs_through_jobs_and_records_tasks(): void
    {
        Http::fake(['http://meili.test:7700/*' => Http::response(['taskUid' => 42])]);

        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['subject' => 'Indexed']);

        $task = MailboxSearchTask::query()->sole();
        $this->assertSame(42, $task->task_uid);
        $this->assertSame('documentAdditionOrUpdate', $task->type);
        $this->assertSame([$message->id], $task->message_ids);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'http://meili.test:7700/indexes/filament_mailbox_messages/documents'
            && $request->data()[0]['message_id'] === $message->id);

        $message->delete();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/documents/delete-batch') && $request->data() === [$message->id]);
    }

    public function test_failed_tasks_are_repeated_up_to_three_times(): void
    {
        $task = MailboxSearchTask::query()->create(['task_uid' => 7, 'type' => 'documentAdditionOrUpdate', 'message_ids' => [1], 'status' => 'enqueued', 'attempts' => 0]);
        Http::fake([
            'http://meili.test:7700/tasks/7' => Http::response(['status' => 'failed', 'error' => ['message' => 'index not found']]),
            'http://meili.test:7700/*' => Http::response(['taskUid' => 8]),
        ]);

        $this->assertSame(['checked' => 1, 'failed' => 1], app(MeilisearchSearchEngine::class)->checkTasks());

        $task->refresh();
        $this->assertSame('failed', $task->status);
        $this->assertSame('index not found', $task->error);
        $this->assertSame(1, $task->attempts);

        $this->artisan('mailbox:search-tasks-check')->assertSuccessful();
    }

    public function test_an_unreachable_instance_falls_back_and_is_reported_as_failing(): void
    {
        Http::fake(['http://meili.test:7700/*' => Http::response('gone', 503)]);
        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['subject' => 'Fallback works']);

        $result = app(SearchManager::class)->engine()->search(app(QueryParser::class)->parse('fallback'), new SearchScope($this->mailbox->id));

        $this->assertSame([$message->id], $result->ids);
        $this->assertSame(HealthCheckStatus::Failed, app(SearchEngineCheck::class)->run()->status);
    }

    public function test_reindex_swaps_a_fresh_index(): void
    {
        // The temporary index does not exist yet.
        Http::fake(fn (Request $request) => $request->method() === 'GET' && str_ends_with($request->url(), '_tmp')
            ? Http::response([], 404)
            : Http::response(['taskUid' => 1]));
        MailboxMessage::factory()->count(2)->for($this->inbox, 'folder')->create();

        $this->artisan('mailbox:search-reindex', ['--now' => true, '--swap' => true])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/indexes') && ($request['uid'] ?? null) === 'filament_mailbox_messages_tmp');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/indexes/filament_mailbox_messages_tmp/documents'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/swap-indexes') && $request->data() === [['indexes' => ['filament_mailbox_messages', 'filament_mailbox_messages_tmp']]]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/indexes/filament_mailbox_messages_tmp'));

        $this->artisan('mailbox:search-reindex', ['--swap' => true])->assertFailed();
    }
}
