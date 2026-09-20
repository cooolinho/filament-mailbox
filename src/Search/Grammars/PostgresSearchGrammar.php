<?php

namespace Cooolinho\FilamentMailbox\Search\Grammars;

use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Cooolinho\FilamentMailbox\Search\SearchTerm;
use Illuminate\Database\Query\Builder;

/**
 * PostgreSQL tsvector (generated column search_vector, GIN index) with prefix
 * matching and ts_rank_cd ranking. Only letters and digits reach to_tsquery.
 */
class PostgresSearchGrammar implements SearchGrammar
{
    public function __construct(
        protected LikeGrammar $like,
    ) {}

    public function match(Builder $documents, SearchQuery $query): void
    {
        foreach ($query->clauses as $alternatives) {
            $documents->where(function (Builder $clause) use ($alternatives): void {
                foreach ($alternatives as $term) {
                    $term->tokens() === []
                        ? $clause->orWhere(fn (Builder $query) => $this->like->term($query, $term))
                        : $clause->orWhereRaw('d.search_vector @@ to_tsquery(?, ?)', [$this->language(), $this->term($term)]);
                }
            });
        }

        foreach ($query->excluded as $term) {
            $term->tokens() === []
                ? $documents->whereNot(fn (Builder $query) => $this->like->term($query, $term))
                : $documents->whereRaw('NOT (d.search_vector @@ to_tsquery(?, ?))', [$this->language(), $this->term($term)]);
        }
    }

    public function orderByRelevance(Builder $documents, SearchQuery $query): void
    {
        if (($expression = $this->expression($query)) !== null) {
            $documents->orderByRaw('ts_rank_cd(d.search_vector, to_tsquery(?, ?)) DESC', [$this->language(), $expression]);
        }
    }

    protected function expression(SearchQuery $query): ?string
    {
        $clauses = array_filter(array_map(function (array $alternatives): ?string {
            $terms = array_filter($alternatives, fn (SearchTerm $term): bool => $term->tokens() !== []);

            return $terms === [] ? null : '('.implode(' | ', array_map($this->term(...), $terms)).')';
        }, $query->clauses));

        return $clauses === [] ? null : implode(' & ', $clauses);
    }

    protected function term(SearchTerm $term): string
    {
        $tokens = $term->tokens();

        return count($tokens) === 1 && ! $term->phrase ? $tokens[0].':*' : '('.implode(' <-> ', $tokens).')';
    }

    protected function language(): string
    {
        return preg_replace('/[^a-z_]/', '', (string) config('filament-mailbox.search.postgres_language', 'german')) ?: 'simple';
    }
}
