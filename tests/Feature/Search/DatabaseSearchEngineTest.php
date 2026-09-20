<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature\Search;

use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Search\Contracts\MessageSearchEngine;
use Cooolinho\FilamentMailbox\Search\Engines\DatabaseSearchEngine;
use Cooolinho\FilamentMailbox\Search\QueryParser;
use Cooolinho\FilamentMailbox\Search\SearchScope;
use Cooolinho\FilamentMailbox\Tests\Contracts\MessageSearchEngineContractTest;
use Illuminate\Support\Facades\DB;

/**
 * SQLite FTS5 in CI; runs against MySQL/PostgreSQL with DB_CONNECTION (see docs/testing.md).
 */
class DatabaseSearchEngineTest extends MessageSearchEngineContractTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filament-mailbox.search.engine', 'database');
    }

    /**
     * InnoDB updates FULLTEXT indexes on commit only: on MySQL the tests run without
     * transactions on a freshly migrated database.
     *
     * @return array<int, ?string>
     */
    protected function connectionsToTransact(): array
    {
        return in_array(config('database.default'), ['mysql', 'mariadb'], true) ? [] : parent::connectionsToTransact();
    }

    protected function refreshTestDatabase(): void
    {
        if (in_array(config('database.default'), ['mysql', 'mariadb'], true)) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            $this->app[\Illuminate\Contracts\Console\Kernel::class]->setArtisan(null);

            return;
        }

        parent::refreshTestDatabase();
    }

    protected function engine(): MessageSearchEngine
    {
        return app(DatabaseSearchEngine::class);
    }

    public function test_umlauts_and_diacritics_are_found_without_them(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Diacritic folding depends on the database collation.');
        }

        $this->assertHits('uberweisen', [$this->invoice]);
    }

    public function test_relevance_orders_subject_hits_first(): void
    {
        $body = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['subject' => 'Hallo', 'text_body' => 'Es geht um eine Kündigung', 'received_at' => now()]);
        $subject = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['subject' => 'Kündigung Kündigung', 'text_body' => 'Kündigung', 'received_at' => now()->subDays(5)]);

        $ids = $this->engine()->search(app(QueryParser::class)->parse('kündigung'), new SearchScope($this->mailbox->id))->ids;

        $this->assertSame([$subject->id, $body->id], $ids);
    }

    public function test_documents_are_normalised(): void
    {
        $document = DB::table('mailbox_search_documents')->where('message_id', $this->newsletter->id)->first();

        $this->assertSame('the weekly product update for developers', $document->body_text);
        $this->assertSame('office@example.com', $document->to_text);
        $this->assertSame('news news@letter.test', $document->from_text);
        $this->assertSame('rechnung-4711.pdf', DB::table('mailbox_search_documents')->where('message_id', $this->invoice->id)->value('attachment_names'));
    }

    public function test_reindex_command_rebuilds_the_index(): void
    {
        DB::table('mailbox_search_documents')->delete();

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('mailbox_search_fts')->delete();
        }
        $this->assertHits('bewerbung', []);

        $this->artisan('mailbox:search-reindex', ['--now' => true])->assertSuccessful();

        $this->assertHits('bewerbung', [$this->application, $this->reply]);
    }
}
