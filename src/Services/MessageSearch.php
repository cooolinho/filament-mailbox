<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Search\SearchManager;
use Cooolinho\FilamentMailbox\Search\SearchScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Search of the message tables through the configured engine (search.engine).
 * Supports the search syntax of Search\QueryParser.
 */
class MessageSearch
{
    public function __construct(
        protected SearchManager $search,
    ) {}

    /**
     * @param  ?SearchScope  $scope  Mailbox (and folder) to search; without, the mailbox of the query's constraints is not known and nothing is found
     */
    public function apply(Builder $query, string $search, ?SearchScope $scope = null, bool $orderByRelevance = false): Builder
    {
        if ($scope === null) {
            return $query->whereRaw('1 = 0');
        }

        return $this->search->apply($query, $search, $scope, $orderByRelevance);
    }
}
