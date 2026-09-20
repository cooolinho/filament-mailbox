<?php

namespace Cooolinho\FilamentMailbox\Search\Engines;

use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Search\Contracts\ConstrainsQueries;
use Cooolinho\FilamentMailbox\Search\Contracts\MessageSearchEngine;
use Cooolinho\FilamentMailbox\Search\FilterConstraints;
use Cooolinho\FilamentMailbox\Search\Grammars\LikeGrammar;
use Cooolinho\FilamentMailbox\Search\Grammars\MySqlSearchGrammar;
use Cooolinho\FilamentMailbox\Search\Grammars\PostgresSearchGrammar;
use Cooolinho\FilamentMailbox\Search\Grammars\SearchGrammar;
use Cooolinho\FilamentMailbox\Search\Grammars\SqliteSearchGrammar;
use Cooolinho\FilamentMailbox\Search\SearchDocumentWriter;
use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Cooolinho\FilamentMailbox\Search\SearchResult;
use Cooolinho\FilamentMailbox\Search\SearchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Full-text search in the application database on mailbox_search_documents:
 * MySQL/MariaDB FULLTEXT, PostgreSQL tsvector, SQLite FTS5 (LIKE otherwise).
 */
class DatabaseSearchEngine implements ConstrainsQueries, MessageSearchEngine
{
    public function __construct(
        protected SearchDocumentWriter $writer,
        protected FilterConstraints $filters,
    ) {}

    public function name(): string
    {
        return 'database';
    }

    public function search(SearchQuery $query, SearchScope $scope, int $limit = 100, int $offset = 0): SearchResult
    {
        $documents = $this->documents($query, $scope);
        $total = (clone $documents)->count();

        $this->grammar()->orderByRelevance($documents, $query);

        $ids = $documents
            ->orderByDesc('d.received_at')
            ->offset($offset)
            ->limit($limit)
            ->pluck('d.message_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return new SearchResult($ids, $total);
    }

    public function constrain(Builder $messages, SearchQuery $query, SearchScope $scope): Builder
    {
        $table = $messages->getModel()->getTable();

        return $messages
            ->where("{$table}.mailbox_id", $scope->mailboxId)
            ->when($scope->folderId, fn (Builder $messages) => $messages->where("{$table}.folder_id", $scope->folderId))
            ->whereIn("{$table}.id", $this->documents($query, $scope)->select('d.message_id'));
    }

    public function index(MailboxMessage $message): void
    {
        $this->writer->write($message);
    }

    public function remove(array $messageIds): void
    {
        $this->writer->delete($messageIds);
    }

    protected function documents(SearchQuery $query, SearchScope $scope): QueryBuilder
    {
        $documents = DB::table('mailbox_search_documents', 'd')
            ->where('d.mailbox_id', $scope->mailboxId)
            ->when($scope->folderId, fn (QueryBuilder $documents) => $documents->where('d.folder_id', $scope->folderId));

        $this->grammar()->match($documents, $query);

        $this->filters->apply($documents, $query, $scope, [
            'from' => ["COALESCE(d.from_text, '')"],
            'to' => ["COALESCE(d.to_text, '')"],
            'cc' => ["COALESCE(d.cc_text, '')"],
            'subject' => ["COALESCE(d.subject, '')"],
            'filename' => "COALESCE(d.attachment_names, '')",
            'has_attachment' => 'd.has_attachments',
            'is_read' => 'd.is_read',
            'is_flagged' => 'd.is_flagged',
            'folder' => 'd.folder_id',
            'date' => 'd.received_at',
        ]);

        return $documents;
    }

    protected function grammar(): SearchGrammar
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => app(MySqlSearchGrammar::class),
            'pgsql' => app(PostgresSearchGrammar::class),
            'sqlite' => app(SqliteSearchGrammar::class),
            default => app(LikeGrammar::class),
        };
    }
}
