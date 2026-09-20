<?php

namespace Cooolinho\FilamentMailbox\Search;

final readonly class SearchResult
{
    /**
     * @param  array<int, int>  $ids  Message ids, most relevant first
     * @param  int  $total  Number of all hits (may be estimated by external engines)
     */
    public function __construct(
        public array $ids,
        public int $total,
    ) {}
}
