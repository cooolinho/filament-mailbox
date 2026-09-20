<?php

namespace Cooolinho\FilamentMailbox\Tests\Integration;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxSearchTask;
use Cooolinho\FilamentMailbox\Search\Meilisearch\MeilisearchClient;
use Cooolinho\FilamentMailbox\Search\Meilisearch\MeilisearchSearchEngine;
use Cooolinho\FilamentMailbox\Search\QueryParser;
use Cooolinho\FilamentMailbox\Search\SearchScope;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Against a real Meilisearch (docker compose --profile search up -d sandbox-meilisearch);
 * skipped when it is not reachable. Each run uses its own index.
 */
class MeilisearchIntegrationTest extends TestCase
{
    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected MailboxFolder $sent;

    protected string $index;

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = 'fm_test_'.Str::lower(Str::random(8));

        config([
            'filament-mailbox.search.engine' => 'meilisearch',
            'filament-mailbox.search.meilisearch.host' => $this->host(),
            'filament-mailbox.search.meilisearch.key' => (string) (getenv('MAILBOX_TEST_MEILISEARCH_KEY') ?: 'masterKey'),
            'filament-mailbox.search.meilisearch.index' => $this->index,
            'filament-mailbox.search.meilisearch.fallback_engine' => '',
        ]);

        $reachable = rescue(fn (): bool => Http::timeout(2)->get($this->host().'/health')->successful(), false, report: false);

        if (! $reachable) {
            $this->markTestSkipped("Meilisearch {$this->host()} not reachable.");
        }

        app(MeilisearchSearchEngine::class)->ensureIndex();

        $this->mailbox = Mailbox::factory()->create();
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
        $this->sent = MailboxFolder::factory()->for($this->mailbox)->specialUse(SpecialUse::Sent)->create(['name' => 'Sent', 'full_name' => 'Sent']);
    }

    protected function tearDown(): void
    {
        rescue(fn () => app(MeilisearchClient::class)->deleteIndex($this->index), report: false);

        parent::tearDown();
    }

    public function test_typo_tolerant_search_with_filters_and_reindex(): void
    {
        $invoice = MailboxMessage::factory()->for($this->inbox, 'folder')->create([
            'subject' => 'Rechnung September', 'from_name' => 'Jane Doe', 'from_address' => 'billing@acme.test',
            'to' => [['address' => 'office@example.com', 'name' => 'Office']], 'cc' => [],
            'text_body' => 'Bitte überweisen Sie den Betrag.', 'is_read' => false, 'received_at' => now()->subDays(2),
        ]);
        $reply = MailboxMessage::factory()->for($this->sent, 'folder')->create([
            'subject' => 'Re: Rechnung September', 'from_name' => 'Office', 'from_address' => 'office@example.com',
            'to' => [['address' => 'billing@acme.test', 'name' => null]], 'cc' => [],
            'text_body' => 'Ist bezahlt.', 'is_read' => true, 'received_at' => now(),
        ]);

        $this->awaitTasks();

        // Typo tolerance for words, exact matching for addresses.
        $this->assertHits('rechung', [$invoice, $reply]);
        $this->assertHits('billing@acme.test', [$invoice, $reply]);
        $this->assertHits('billingg@acme.test', []);
        $this->assertHits('"rechnung september"', [$invoice, $reply]);
        $this->assertHits('rechnung -re', [$invoice]);
        $this->assertHits('rechnung is:unread', [$invoice]);
        $this->assertHits('rechnung in:sent', [$reply]);
        $this->assertHits('rechnung from:jane', [$invoice]);
        $this->assertHits('rechnung', [$invoice], new SearchScope($this->mailbox->id, $this->inbox->id));
        $this->assertHits('rechnung', [], new SearchScope($this->mailbox->id + 1000));

        // Deletions leave the index.
        $reply->delete();
        $this->awaitTasks();
        $this->assertHits('rechnung', [$invoice]);

        // Reindex into a fresh index and swap.
        app(MeilisearchClient::class)->deleteDocuments($this->index, [$invoice->id]);
        $this->awaitTasks();
        $this->assertHits('rechnung', []);

        $this->artisan('mailbox:search-reindex', ['--now' => true, '--swap' => true])->assertSuccessful();
        $this->awaitTasks();
        $this->assertHits('rechnung', [$invoice]);
    }

    /**
     * @param  array<int, MailboxMessage>  $expected
     */
    protected function assertHits(string $search, array $expected, ?SearchScope $scope = null): void
    {
        $ids = app(MeilisearchSearchEngine::class)
            ->search(app(QueryParser::class)->parse($search, 'UTC'), $scope ?? new SearchScope($this->mailbox->id))
            ->ids;

        sort($ids);
        $expectedIds = array_map(fn (MailboxMessage $message): int => $message->id, $expected);
        sort($expectedIds);

        $this->assertSame($expectedIds, $ids, "search for [{$search}]");
    }

    /**
     * Meilisearch indexes asynchronously.
     */
    protected function awaitTasks(): void
    {
        $client = app(MeilisearchClient::class);

        foreach (MailboxSearchTask::query()->pluck('task_uid') as $uid) {
            for ($attempt = 0; $attempt < 50; $attempt++) {
                if (in_array($client->task((int) $uid)['status'] ?? '', ['succeeded', 'failed', 'canceled'], true)) {
                    break;
                }

                usleep(100_000);
            }
        }

        MailboxSearchTask::query()->delete();
        usleep(200_000);
    }

    protected function host(): string
    {
        return rtrim((string) (getenv('MAILBOX_TEST_MEILISEARCH_HOST') ?: 'http://sandbox-meilisearch:7700'), '/');
    }
}
