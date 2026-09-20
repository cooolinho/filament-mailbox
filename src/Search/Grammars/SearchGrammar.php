<?php

namespace Cooolinho\FilamentMailbox\Search\Grammars;

use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Illuminate\Database\Query\Builder;

/**
 * Database-specific full-text part of the database engine.
 */
interface SearchGrammar
{
    /**
     * Constrain a query of mailbox_search_documents (alias "d") to the free-text part.
     */
    public function match(Builder $documents, SearchQuery $query): void;

    /**
     * Order by relevance (best first); may do nothing.
     */
    public function orderByRelevance(Builder $documents, SearchQuery $query): void;
}
