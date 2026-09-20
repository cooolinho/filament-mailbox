<?php

namespace Cooolinho\FilamentMailbox\Search\Grammars;

use Cooolinho\FilamentMailbox\Search\FilterConstraints;
use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Cooolinho\FilamentMailbox\Search\SearchTerm;
use Illuminate\Database\Query\Builder;

/**
 * Substring matching on the normalised document columns: databases without full-text support, short tokens.
 */
class LikeGrammar implements SearchGrammar
{
    public const COLUMNS = ['d.subject', 'd.from_text', 'd.to_text', 'd.cc_text', 'd.body_text', 'd.attachment_names', 'd.attachment_text'];

    public function __construct(
        protected FilterConstraints $filters,
    ) {}

    public function match(Builder $documents, SearchQuery $query): void
    {
        foreach ($query->clauses as $alternatives) {
            $documents->where(function (Builder $clause) use ($alternatives): void {
                foreach ($alternatives as $term) {
                    $clause->orWhere(fn (Builder $query) => $this->term($query, $term));
                }
            });
        }

        foreach ($query->excluded as $term) {
            $documents->whereNot(fn (Builder $query) => $this->term($query, $term));
        }
    }

    public function term(Builder $query, SearchTerm $term): void
    {
        $this->filters->like($query, array_map(fn (string $column): string => "COALESCE({$column}, '')", self::COLUMNS), $term->text, false);
    }

    public function orderByRelevance(Builder $documents, SearchQuery $query): void {}
}
