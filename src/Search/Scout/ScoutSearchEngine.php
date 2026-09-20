<?php

namespace Cooolinho\FilamentMailbox\Search\Scout;

use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\SearchableMailboxMessage;
use Cooolinho\FilamentMailbox\Search\Contracts\DeclaresSearchCapabilities;
use Cooolinho\FilamentMailbox\Search\Contracts\MessageSearchEngine;
use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Cooolinho\FilamentMailbox\Search\SearchResult;
use Cooolinho\FilamentMailbox\Search\SearchScope;
use Cooolinho\FilamentMailbox\Search\SearchTerm;
use Laravel\Scout\EngineManager;
use RuntimeException;

/**
 * Bridge to any Laravel Scout engine (Typesense, Algolia, Meilisearch,
 * database, collection) of the application. What the engine cannot filter
 * itself is applied afterwards in the database (SearchManager).
 */
class ScoutSearchEngine implements DeclaresSearchCapabilities, MessageSearchEngine
{
    /** @var array<string, class-string<ScoutAdapter>> */
    public const ADAPTERS = [
        'typesense' => TypesenseScoutAdapter::class,
    ];

    /** Scout engines that send the contents to an external service. */
    public const EXTERNAL_DRIVERS = ['algolia', 'typesense', 'meilisearch'];

    public function __construct()
    {
        if (! class_exists(EngineManager::class)) {
            throw new RuntimeException('The "scout" search engine needs laravel/scout (composer require laravel/scout).');
        }

        $this->ensureExternalProcessingAcknowledged();
    }

    public function name(): string
    {
        return 'scout';
    }

    /**
     * @return array<int, \Cooolinho\FilamentMailbox\Search\SearchCapability>
     */
    public function capabilities(): array
    {
        return $this->adapter()->capabilities();
    }

    public function search(SearchQuery $query, SearchScope $scope, int $limit = 100, int $offset = 0): SearchResult
    {
        $builder = SearchableMailboxMessage::search($this->queryString($query));
        $this->adapter()->apply($builder, $query, $scope);

        $keys = $builder->take($limit + $offset)->keys()->slice($offset)->values();

        return new SearchResult($keys->map(fn (mixed $key): int => (int) $key)->all(), $keys->count() + $offset);
    }

    public function index(MailboxMessage $message): void
    {
        $searchable = $this->searchable($message);

        $searchable->shouldBeSearchable()
            ? $searchable->searchable()
            : $this->remove([(int) $message->getKey()]);
    }

    public function remove(array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        // Removing needs the key only, so messages that no longer exist leave the index as well.
        (new SearchableMailboxMessage)
            ->newCollection(array_map(fn (int|string $id): SearchableMailboxMessage => tap(new SearchableMailboxMessage, function (SearchableMailboxMessage $model) use ($id): void {
                $model->forceFill(['id' => (int) $id]);
                $model->exists = true;
            }), $messageIds))
            ->unsearchable();
    }

    protected function searchable(MailboxMessage $message): SearchableMailboxMessage
    {
        return tap(new SearchableMailboxMessage, function (SearchableMailboxMessage $model) use ($message): void {
            $model->setRawAttributes($message->getAttributes(), true);
            $model->exists = $message->exists;
        });
    }

    public function adapter(): ScoutAdapter
    {
        $name = (string) config('filament-mailbox.search.scout.adapter', '');
        $class = self::ADAPTERS[$name] ?? (class_exists($name) ? $name : DefaultScoutAdapter::class);

        return app($class);
    }

    /**
     * Phrases stay quoted; excluded terms and operators are applied by the database.
     */
    protected function queryString(SearchQuery $query): string
    {
        $parts = [];

        foreach ($query->clauses as $alternatives) {
            $parts[] = implode(' ', array_map(fn (SearchTerm $term): string => implode(' ', $term->tokens()), $alternatives));
        }

        return trim(implode(' ', array_filter($parts)));
    }

    /**
     * Mail contents leave the application with cloud engines: this needs an explicit decision.
     */
    protected function ensureExternalProcessingAcknowledged(): void
    {
        $driver = (string) config('scout.driver', 'null');

        if (in_array($driver, self::EXTERNAL_DRIVERS, true) && ! config('filament-mailbox.search.scout.external_processing_acknowledged', false)) {
            throw new RuntimeException("The Scout engine \"{$driver}\" sends message contents to an external service. Set filament-mailbox.search.scout.external_processing_acknowledged (MAILBOX_SEARCH_EXTERNAL_ACK) after clarifying data processing.");
        }
    }
}
