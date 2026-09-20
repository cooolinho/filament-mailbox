<?php

namespace Cooolinho\FilamentMailbox\Search\Scout;

use Cooolinho\FilamentMailbox\Search\SearchCapability;
use Cooolinho\FilamentMailbox\Search\SearchFilter;
use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Cooolinho\FilamentMailbox\Search\SearchScope;
use Laravel\Scout\Builder;

/**
 * Typesense: scope, flags, folders and dates as "filter_by"; field operators
 * and phrases stay with the database post-filter.
 */
class TypesenseScoutAdapter implements ScoutAdapter
{
    public function capabilities(): array
    {
        return [SearchCapability::FlagFilters, SearchCapability::FolderFilter, SearchCapability::DateRange];
    }

    public function apply(Builder $builder, SearchQuery $query, SearchScope $scope): Builder
    {
        $filters = ['mailbox_id:='.$scope->mailboxId];

        if ($scope->folderId !== null) {
            $filters[] = 'folder_id:='.$scope->folderId;
        }

        foreach ($query->filters as $filter) {
            $filters[] = match ($filter->name) {
                SearchFilter::HAS_ATTACHMENT => 'has_attachments:='.($filter->value ? 'true' : 'false'),
                SearchFilter::IS_READ => 'is_read:='.($filter->value ? 'true' : 'false'),
                SearchFilter::IS_FLAGGED => 'is_flagged:='.($filter->value ? 'true' : 'false'),
                SearchFilter::BEFORE => 'received_at_ts:<'.$filter->value->getTimestamp(),
                SearchFilter::AFTER => 'received_at_ts:>='.$filter->value->getTimestamp(),
                default => null,
            };
        }

        return $builder->options(['filter_by' => implode(' && ', array_filter($filters))]);
    }
}
