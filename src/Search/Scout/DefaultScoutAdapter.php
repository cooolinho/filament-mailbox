<?php

namespace Cooolinho\FilamentMailbox\Search\Scout;

use Cooolinho\FilamentMailbox\Search\SearchCapability;
use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Cooolinho\FilamentMailbox\Search\SearchScope;
use Laravel\Scout\Builder;

/**
 * Free text with the simple "where" of Scout (supported by most engines) for
 * the mailbox. The rest is filtered in the database.
 */
class DefaultScoutAdapter implements ScoutAdapter
{
    public function capabilities(): array
    {
        return SearchCapability::freeTextOnly();
    }

    public function apply(Builder $builder, SearchQuery $query, SearchScope $scope): Builder
    {
        $builder->where('mailbox_id', $scope->mailboxId);

        if ($scope->folderId !== null) {
            $builder->where('folder_id', $scope->folderId);
        }

        return $builder;
    }
}
