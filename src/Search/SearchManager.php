<?php

namespace Cooolinho\FilamentMailbox\Search;

use Cooolinho\FilamentMailbox\Search\Contracts\ConstrainsQueries;
use Cooolinho\FilamentMailbox\Search\Contracts\DeclaresSearchCapabilities;
use Cooolinho\FilamentMailbox\Search\Contracts\MessageSearchEngine;
use Cooolinho\FilamentMailbox\Search\Engines\LikeSearchEngine;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Builder;

/**
 * Connects the configured engine ("search.engine") to message queries.
 * Parts an engine cannot do itself (operators, phrases, exclusion) are
 * applied afterwards in the database on its hits, so every engine supports
 * the full syntax and the scope is always enforced.
 */
class SearchManager
{
    protected ?MessageSearchEngine $engine = null;

    /** Whether the last search hit the candidate limit of an external engine. */
    protected bool $truncated = false;

    public function __construct(
        protected QueryParser $parser,
        protected SearchEngineRegistry $registry,
        protected LikeSearchEngine $postFilter,
    ) {}

    public function engine(): MessageSearchEngine
    {
        return $this->engine ??= $this->registry->resolve();
    }

    public function resolve(string $name): MessageSearchEngine
    {
        return $this->registry->resolve($name);
    }

    /**
     * Whether the engine keeps its own index that has to be written.
     */
    public function indexes(): bool
    {
        return ! $this->engine() instanceof LikeSearchEngine;
    }

    public function parse(string $search): SearchQuery
    {
        return $this->parser->parse($search, FilamentTimezone::get());
    }

    /**
     * @return array<int, SearchCapability>
     */
    public function capabilities(?MessageSearchEngine $engine = null): array
    {
        $engine ??= $this->engine();

        return match (true) {
            $engine instanceof DeclaresSearchCapabilities => $engine->capabilities(),
            $engine instanceof ConstrainsQueries => SearchCapability::all(),
            default => SearchCapability::freeTextOnly(),
        };
    }

    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    public function candidateLimit(): int
    {
        return max(1, (int) config('filament-mailbox.search.candidate_limit', 2000));
    }

    /**
     * Constrain a message query (e.g. of a table) to the hits.
     *
     * @param  Builder<\Cooolinho\FilamentMailbox\Models\MailboxMessage>  $messages
     * @return Builder<\Cooolinho\FilamentMailbox\Models\MailboxMessage>
     */
    public function apply(Builder $messages, string $search, SearchScope $scope, bool $orderByRelevance = false): Builder
    {
        $query = $this->parse($search);
        $this->truncated = false;

        if ($query->isEmpty()) {
            return $messages;
        }

        $engine = $this->engine();
        $capabilities = $this->capabilities($engine);
        [$supported, $remaining] = $this->split($query, $capabilities);

        if ($engine instanceof ConstrainsQueries && $remaining === null) {
            return $engine->constrain($messages, $query, $scope);
        }

        $ids = $engine->search($supported, $scope, $this->candidateLimit())->ids;
        $this->truncated = count($ids) >= $this->candidateLimit();
        $table = $messages->getModel()->getTable();

        // The scope is enforced again: ids of an engine are never trusted.
        $messages->where("{$table}.mailbox_id", $scope->mailboxId)
            ->when($scope->folderId, fn (Builder $messages) => $messages->where("{$table}.folder_id", $scope->folderId))
            ->whereIn("{$table}.id", $ids === [] ? [0] : $ids);

        if ($remaining !== null) {
            $this->postFilter->constrain($messages, $remaining, $scope);
        }

        if ($orderByRelevance && $ids !== [] && in_array(SearchCapability::RelevanceSort, $capabilities, true)) {
            $messages->orderByRaw('CASE '.$table.'.id '.implode(' ', array_fill(0, count($ids), 'WHEN ? THEN ?')).' END', array_merge(...array_map(fn (int $id, int $position): array => [$id, $position], $ids, array_keys($ids))));
        }

        return $messages;
    }

    /**
     * Splits a query into the part the engine handles and the rest for the database.
     *
     * @param  array<int, SearchCapability>  $capabilities
     * @return array{0: SearchQuery, 1: ?SearchQuery}
     */
    public function split(SearchQuery $query, array $capabilities): array
    {
        $can = fn (SearchCapability $capability): bool => in_array($capability, $capabilities, true);

        $engineFilters = [];
        $remainingFilters = [];

        foreach ($query->filters as $filter) {
            $capability = match ($filter->name) {
                SearchFilter::FROM, SearchFilter::TO, SearchFilter::CC, SearchFilter::SUBJECT, SearchFilter::FILENAME => SearchCapability::FieldOperators,
                SearchFilter::HAS_ATTACHMENT, SearchFilter::IS_READ, SearchFilter::IS_FLAGGED => SearchCapability::FlagFilters,
                SearchFilter::IN_FOLDER => SearchCapability::FolderFilter,
                default => SearchCapability::DateRange,
            };

            $can($capability) && (! $filter->negated || $can(SearchCapability::Negation))
                ? $engineFilters[] = $filter
                : $remainingFilters[] = $filter;
        }

        $phrases = $can(SearchCapability::Phrases);
        $negation = $can(SearchCapability::Negation);

        // Phrases stay in the engine query as words when it cannot do phrases; the database checks them exactly.
        $remainingClauses = $phrases ? [] : array_values(array_filter($query->clauses, fn (array $alternatives): bool => (bool) array_filter($alternatives, fn (SearchTerm $term): bool => $term->phrase)));
        $excludedForEngine = $negation ? $query->excluded : [];
        $remainingExcluded = $negation ? [] : $query->excluded;

        $remaining = ($remainingFilters === [] && $remainingClauses === [] && $remainingExcluded === [])
            ? null
            : new SearchQuery($remainingClauses, $remainingExcluded, $remainingFilters, $query->raw);

        return [new SearchQuery($query->clauses, $excludedForEngine, $engineFilters, $query->raw), $remaining];
    }
}
