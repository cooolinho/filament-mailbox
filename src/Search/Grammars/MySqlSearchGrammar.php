<?php

namespace Cooolinho\FilamentMailbox\Search\Grammars;

use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Cooolinho\FilamentMailbox\Search\SearchTerm;
use Illuminate\Database\Query\Builder;

/**
 * MySQL/MariaDB FULLTEXT in boolean mode. Tokens shorter than the minimum
 * token size are not indexed: such terms fall back to LIKE.
 */
class MySqlSearchGrammar implements SearchGrammar
{
    public const MATCH = 'MATCH(d.subject, d.from_text, d.to_text, d.cc_text, d.body_text, d.attachment_names, d.attachment_text)';

    public function __construct(
        protected LikeGrammar $like,
    ) {}

    public function match(Builder $documents, SearchQuery $query): void
    {
        foreach ($query->clauses as $alternatives) {
            $documents->where(function (Builder $clause) use ($alternatives): void {
                foreach ($alternatives as $term) {
                    $this->indexable($term)
                        ? $clause->orWhereRaw(self::MATCH.' AGAINST (? IN BOOLEAN MODE)', ['+'.$this->term($term)])
                        : $clause->orWhere(fn (Builder $query) => $this->like->term($query, $term));
                }
            });
        }

        foreach ($query->excluded as $term) {
            $this->indexable($term)
                ? $documents->whereRaw('NOT '.self::MATCH.' AGAINST (? IN BOOLEAN MODE)', ['+'.$this->term($term)])
                : $documents->whereNot(fn (Builder $query) => $this->like->term($query, $term));
        }
    }

    public function orderByRelevance(Builder $documents, SearchQuery $query): void
    {
        $terms = array_filter($query->terms(), $this->indexable(...));

        if ($terms !== []) {
            $documents->orderByRaw(self::MATCH.' AGAINST (? IN BOOLEAN MODE) DESC', [implode(' ', array_map($this->term(...), $terms))]);
        }
    }

    protected function indexable(SearchTerm $term): bool
    {
        $min = max(1, (int) config('filament-mailbox.search.mysql_min_token_size', 3));
        $tokens = $term->tokens();

        return $tokens !== [] && min(array_map('mb_strlen', $tokens)) >= $min;
    }

    /**
     * Only letters and digits reach the boolean syntax.
     */
    protected function term(SearchTerm $term): string
    {
        $tokens = $term->tokens();

        return count($tokens) === 1 && ! $term->phrase ? $tokens[0].'*' : '"'.implode(' ', $tokens).'"';
    }
}
