<?php

namespace Cooolinho\FilamentMailbox\Search\Contracts;

use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Cooolinho\FilamentMailbox\Search\SearchResult;
use Cooolinho\FilamentMailbox\Search\SearchScope;

/**
 * A search engine for messages. The scope (mailbox, optionally folder) must always be enforced.
 */
interface MessageSearchEngine
{
    public function name(): string;

    /**
     * Message ids of the hits, most relevant first.
     */
    public function search(SearchQuery $query, SearchScope $scope, int $limit = 100, int $offset = 0): SearchResult;

    /**
     * Add or update the message in the index (no-op for engines without an index).
     */
    public function index(MailboxMessage $message): void;

    /**
     * @param  array<int, int>  $messageIds
     */
    public function remove(array $messageIds): void;
}
