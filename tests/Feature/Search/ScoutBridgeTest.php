<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature\Search;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\SearchableMailboxMessage;
use Cooolinho\FilamentMailbox\Search\SearchCapability;
use Cooolinho\FilamentMailbox\Search\SearchManager;
use Cooolinho\FilamentMailbox\Search\SearchScope;
use Cooolinho\FilamentMailbox\Search\Scout\ScoutSearchEngine;
use Cooolinho\FilamentMailbox\Search\Scout\TypesenseScoutAdapter;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Laravel\Scout\ScoutServiceProvider;
use RuntimeException;

/**
 * The bridge to Laravel Scout, with the "collection" driver of Scout.
 */
class ScoutBridgeTest extends TestCase
{
    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ScoutServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('scout.driver', 'collection');
        $app['config']->set('filament-mailbox.search.engine', 'scout');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailbox = Mailbox::factory()->create();
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
    }

    public function test_searching_through_scout_with_database_post_filter(): void
    {
        $invoice = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['subject' => 'Rechnung September', 'from_name' => 'Jane Doe', 'is_read' => false]);
        $other = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['subject' => 'Rechnung August', 'from_name' => 'Max', 'is_read' => true]);
        $foreign = MailboxMessage::factory()->create(['subject' => 'Rechnung September']);

        $search = app(SearchManager::class);

        $this->assertInstanceOf(ScoutSearchEngine::class, $search->engine());
        $this->assertSame(SearchCapability::freeTextOnly(), $search->capabilities());

        $ids = fn (string $term): array => $search->apply(MailboxMessage::query(), $term, new SearchScope($this->mailbox->id))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([$invoice->id, $other->id], $ids('rechnung'));
        // Operators are applied by the database on the hits of the engine.
        $this->assertSame([$invoice->id], $ids('rechnung is:unread'));
        $this->assertSame([$invoice->id], $ids('rechnung from:jane'));
        $this->assertSame([], $ids('rechnung in:sent'));
        $this->assertNotContains($foreign->id, $ids('rechnung'));
    }

    public function test_documents_can_be_limited_to_configured_fields(): void
    {
        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['subject' => 'Rechnung', 'text_body' => 'Vertraulich']);
        $searchable = SearchableMailboxMessage::query()->find($message->id);

        $this->assertArrayHasKey('body', $searchable->toSearchableArray());
        $this->assertSame('filament_mailbox_messages', $searchable->searchableAs());

        config(['filament-mailbox.search.scout.fields' => ['subject', 'from']]);
        $document = $searchable->toSearchableArray();

        $this->assertSame(['message_id', 'mailbox_id', 'folder_id', 'subject', 'from'], array_keys($document));
        $this->assertArrayNotHasKey('body', $document);
    }

    public function test_the_typesense_adapter_declares_filters(): void
    {
        config(['filament-mailbox.search.scout.adapter' => 'typesense']);

        $adapter = app(ScoutSearchEngine::class)->adapter();

        $this->assertInstanceOf(TypesenseScoutAdapter::class, $adapter);
        $this->assertContains(SearchCapability::FlagFilters, $adapter->capabilities());
        $this->assertNotContains(SearchCapability::FieldOperators, $adapter->capabilities());
    }

    public function test_cloud_engines_need_an_explicit_acknowledgement(): void
    {
        config(['scout.driver' => 'algolia']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('external service');

        app(SearchManager::class)->resolve('scout');
    }
}
