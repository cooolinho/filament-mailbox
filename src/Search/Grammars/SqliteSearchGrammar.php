<?php

namespace Cooolinho\FilamentMailbox\Search\Grammars;

use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Cooolinho\FilamentMailbox\Search\SearchTerm;
use Illuminate\Database\Query\Builder;

/**
 * SQLite FTS5 (tests and small installations): prefix matches, phrases for multi-token terms, bm25 ranking.
 */
class SqliteSearchGrammar implements SearchGrammar
{
    public function __construct(
        protected LikeGrammar $like,
    ) {}

    public function match(Builder $documents, SearchQuery $query): void
    {
        foreach ($query->clauses as $alternatives) {
            $documents->where(function (Builder $clause) use ($alternatives): void {
                foreach ($alternatives as $term) {
                    // Terms without letters or digits (e.g. "%") are matched as substrings.
                    $term->tokens() === []
                        ? $clause->orWhere(fn (Builder $query) => $this->like->term($query, $term))
                        : $clause->orWhereIn('d.message_id', fn (Builder $fts) => $fts->select('rowid')->from('mailbox_search_fts')->whereRaw('mailbox_search_fts MATCH ?', [$this->term($term)]));
                }
            });
        }

        foreach ($query->excluded as $term) {
            $term->tokens() === []
                ? $documents->whereNot(fn (Builder $query) => $this->like->term($query, $term))
                : $documents->whereNotIn('d.message_id', fn (Builder $fts) => $fts->select('rowid')->from('mailbox_search_fts')->whereRaw('mailbox_search_fts MATCH ?', [$this->term($term)]));
        }
    }

    public function orderByRelevance(Builder $documents, SearchQuery $query): void
    {
        if (($expression = $this->expression($query)) !== null) {
            $documents->orderByRaw('(SELECT bm25(mailbox_search_fts) FROM mailbox_search_fts WHERE mailbox_search_fts MATCH ? AND rowid = d.message_id)', [$expression]);
        }
    }

    protected function expression(SearchQuery $query): ?string
    {
        $clauses = array_filter(array_map(function (array $alternatives): ?string {
            $terms = array_filter($alternatives, fn (SearchTerm $term): bool => $term->tokens() !== []);

            return $terms === [] ? null : '('.implode(' OR ', array_map($this->term(...), $terms)).')';
        }, $query->clauses));

        return $clauses === [] ? null : implode(' AND ', $clauses);
    }

    /**
     * Only letters and digits reach the MATCH syntax.
     */
    protected function term(SearchTerm $term): string
    {
        $tokens = $term->tokens();

        return count($tokens) === 1 && ! $term->phrase
            ? '"'.$tokens[0].'"*'
            : '"'.implode(' ', $tokens).'"';
    }
}
