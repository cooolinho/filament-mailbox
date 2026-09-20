<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature\Search;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\FilamentMailboxPlugin;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Search\Contracts\MessageSearchEngine;
use Cooolinho\FilamentMailbox\Search\Engines\LikeSearchEngine;
use Cooolinho\FilamentMailbox\Search\SearchCapability;
use Cooolinho\FilamentMailbox\Search\SearchEngineRegistry;
use Cooolinho\FilamentMailbox\Search\SearchManager;
use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Cooolinho\FilamentMailbox\Search\SearchResult;
use Cooolinho\FilamentMailbox\Search\SearchScope;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use InvalidArgumentException;
use Livewire\Livewire;

/**
 * Engines of other packages: registry, capabilities with database post-filter
 * and the guaranteed scope.
 */
class ExternalEnginesTest extends TestCase
{
    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected MailboxFolder $sent;

    protected MailboxMessage $invoice;

    protected MailboxMessage $reply;

    protected MailboxMessage $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailbox = Mailbox::factory()->create();
        $this->mailbox->users()->attach($this->user);
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
        $this->sent = MailboxFolder::factory()->for($this->mailbox)->specialUse(SpecialUse::Sent)->create(['name' => 'Sent', 'full_name' => 'Sent']);

        $this->invoice = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['subject' => 'Rechnung September', 'from_name' => 'Jane Doe', 'from_address' => 'billing@acme.test', 'is_read' => false, 'text_body' => 'Bitte zahlen']);
        $this->reply = MailboxMessage::factory()->for($this->sent, 'folder')->create(['subject' => 'Re: Rechnung September', 'from_name' => 'Office', 'from_address' => 'office@example.com', 'is_read' => true, 'text_body' => 'Ist bezahlt']);
        $this->foreign = MailboxMessage::factory()->create(['subject' => 'Rechnung September']);
    }

    public function test_engines_are_registered_by_the_plugin_or_the_configuration(): void
    {
        $registry = app(SearchEngineRegistry::class);
        $engine = new FakeSearchEngine([]);

        FilamentMailboxPlugin::make()->searchEngine('acme', fn (): MessageSearchEngine => $engine);
        config(['filament-mailbox.search.engines.custom' => LikeSearchEngine::class]);

        $this->assertSame($engine, $registry->resolve('acme'));
        $this->assertInstanceOf(LikeSearchEngine::class, $registry->resolve('custom'));
        $this->assertTrue($registry->has('meilisearch'));
        $this->assertContains('scout', $registry->names());

        $this->expectException(InvalidArgumentException::class);
        $registry->resolve('does-not-exist');
    }

    public function test_free_text_engines_get_the_words_and_the_database_applies_the_rest(): void
    {
        $engine = $this->fakeEngine([$this->invoice->id, $this->reply->id, $this->foreign->id]);

        $ids = $this->search('rechnung is:unread in:inbox -bezahlt "rechnung september"');

        $this->assertSame([$this->invoice->id], $ids);
        // Only the free-text part reached the engine.
        $this->assertSame('rechnung "rechnung september"', $this->queryWords($engine->lastQuery));
        $this->assertSame([], $engine->lastQuery->filters);
        $this->assertSame([], $engine->lastQuery->excluded);
    }

    public function test_engines_that_can_filter_themselves_keep_the_operators(): void
    {
        $engine = $this->fakeEngine([$this->invoice->id], [SearchCapability::FlagFilters, SearchCapability::FolderFilter, SearchCapability::Negation, SearchCapability::Phrases, SearchCapability::FieldOperators, SearchCapability::DateRange]);

        $this->search('rechnung is:unread -bezahlt');

        $this->assertCount(1, $engine->lastQuery->filters);
        $this->assertCount(1, $engine->lastQuery->excluded);
    }

    public function test_foreign_ids_of_an_engine_are_never_returned(): void
    {
        $this->fakeEngine([$this->foreign->id, $this->invoice->id]);

        $this->assertSame([$this->invoice->id], $this->search('rechnung'));
        $this->assertSame([], $this->search('rechnung', new SearchScope($this->mailbox->id, $this->sent->id)));
    }

    public function test_hitting_the_candidate_limit_is_reported(): void
    {
        config(['filament-mailbox.search.candidate_limit' => 2]);
        $this->fakeEngine([$this->invoice->id, $this->reply->id]);
        $search = app(SearchManager::class);

        $search->apply(MailboxMessage::query(), 'rechnung', new SearchScope($this->mailbox->id))->get();

        $this->assertTrue($search->wasTruncated());
        $this->assertSame(2, $search->candidateLimit());

        Livewire::test(BrowseMailbox::class, ['record' => $this->mailbox->id])
            ->searchTable('rechnung')
            ->assertSee(__('filament-mailbox::mailbox.search.truncated', ['count' => 2]));
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, SearchCapability>  $capabilities
     */
    protected function fakeEngine(array $ids, array $capabilities = []): FakeSearchEngine
    {
        $engine = new FakeSearchEngine($ids, $capabilities);

        app(SearchEngineRegistry::class)->register('fake', fn (): MessageSearchEngine => $engine);
        config(['filament-mailbox.search.engine' => 'fake']);
        // The manager caches its engine; the fixtures were indexed with the previous one.
        app()->forgetInstance(SearchManager::class);

        return $engine;
    }

    /**
     * @return array<int, int>
     */
    protected function search(string $search, ?SearchScope $scope = null): array
    {
        return app(SearchManager::class)
            ->apply(MailboxMessage::query(), $search, $scope ?? new SearchScope($this->mailbox->id))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    protected function queryWords(SearchQuery $query): string
    {
        return collect($query->clauses)
            ->map(fn (array $alternatives): string => collect($alternatives)->map(fn ($term): string => $term->phrase ? '"'.$term->text.'"' : $term->text)->implode(' OR '))
            ->implode(' ');
    }
}

/**
 * An engine of another package: free text only, returns fixed ids.
 */
class FakeSearchEngine implements MessageSearchEngine, \Cooolinho\FilamentMailbox\Search\Contracts\DeclaresSearchCapabilities
{
    public ?SearchQuery $lastQuery = null;

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, SearchCapability>  $capabilities
     */
    public function __construct(
        protected array $ids,
        protected array $capabilities = [],
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function search(SearchQuery $query, SearchScope $scope, int $limit = 100, int $offset = 0): SearchResult
    {
        $this->lastQuery = $query;

        return new SearchResult(array_slice($this->ids, $offset, $limit), count($this->ids));
    }

    public function index(\Cooolinho\FilamentMailbox\Models\MailboxMessage $message): void {}

    public function remove(array $messageIds): void {}
}
