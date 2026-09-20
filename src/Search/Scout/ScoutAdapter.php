<?php

namespace Cooolinho\FilamentMailbox\Search\Scout;

use Cooolinho\FilamentMailbox\Search\SearchCapability;
use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Cooolinho\FilamentMailbox\Search\SearchScope;
use Laravel\Scout\Builder;

/**
 * Scout engines differ in their filter support: an adapter adds engine-specific
 * options and says what it can do. Everything else is filtered in the database.
 */
interface ScoutAdapter
{
    /**
     * @return array<int, SearchCapability>
     */
    public function capabilities(): array;

    public function apply(Builder $builder, SearchQuery $query, SearchScope $scope): Builder;
}
