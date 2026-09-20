<?php

namespace Cooolinho\FilamentMailbox\Search\Contracts;

use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Cooolinho\FilamentMailbox\Search\SearchScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Engines in the application database constrain the message query directly,
 * so tables can paginate and sort all hits without an id list.
 */
interface ConstrainsQueries
{
    /**
     * @param  Builder<\Cooolinho\FilamentMailbox\Models\MailboxMessage>  $messages
     * @return Builder<\Cooolinho\FilamentMailbox\Models\MailboxMessage>
     */
    public function constrain(Builder $messages, SearchQuery $query, SearchScope $scope): Builder;
}
