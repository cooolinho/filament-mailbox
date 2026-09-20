<?php

namespace Cooolinho\FilamentMailbox\Search;

/**
 * Engine-neutral search: every clause must match (a clause is a list of
 * alternatives joined with OR), no excluded term may match, and all filters apply.
 */
final readonly class SearchQuery
{
    /**
     * @param  array<int, array<int, SearchTerm>>  $clauses
     * @param  array<int, SearchTerm>  $excluded
     * @param  array<int, SearchFilter>  $filters
     */
    public function __construct(
        public array $clauses = [],
        public array $excluded = [],
        public array $filters = [],
        public string $raw = '',
    ) {}

    public function isEmpty(): bool
    {
        return $this->clauses === [] && $this->excluded === [] && $this->filters === [];
    }

    /**
     * Positive terms, e.g. for highlighting.
     *
     * @return array<int, SearchTerm>
     */
    public function terms(): array
    {
        return array_merge(...array_values($this->clauses) ?: [[]]);
    }

    /**
     * @return array<int, SearchFilter>
     */
    public function filters(string $name): array
    {
        return array_values(array_filter($this->filters, fn (SearchFilter $filter): bool => $filter->name === $name));
    }
}
