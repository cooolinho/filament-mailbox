<?php

namespace Cooolinho\FilamentMailbox\Search\Engines;

use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Search\Contracts\ConstrainsQueries;
use Cooolinho\FilamentMailbox\Search\Contracts\MessageSearchEngine;
use Cooolinho\FilamentMailbox\Search\FilterConstraints;
use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Cooolinho\FilamentMailbox\Search\SearchResult;
use Cooolinho\FilamentMailbox\Search\SearchScope;
use Cooolinho\FilamentMailbox\Search\SearchTerm;
use Illuminate\Database\Eloquent\Builder;

/**
 * Case-insensitive substring search on the messages table (no index, works
 * everywhere). Every clause must match at least one searchable field.
 */
class LikeSearchEngine implements ConstrainsQueries, MessageSearchEngine
{
    public function __construct(
        protected FilterConstraints $filters,
    ) {}

    public function name(): string
    {
        return 'like';
    }

    public function search(SearchQuery $query, SearchScope $scope, int $limit = 100, int $offset = 0): SearchResult
    {
        $messages = $this->constrain(MailboxMessage::query(), $query, $scope);

        return new SearchResult(
            (clone $messages)->latest('received_at')->offset($offset)->limit($limit)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            (clone $messages)->count(),
        );
    }

    public function constrain(Builder $messages, SearchQuery $query, SearchScope $scope): Builder
    {
        $table = $messages->getModel()->getTable();
        $messages->where("{$table}.mailbox_id", $scope->mailboxId)
            ->when($scope->folderId, fn (Builder $messages) => $messages->where("{$table}.folder_id", $scope->folderId));

        $fields = $this->columns($messages);

        foreach ($query->clauses as $alternatives) {
            $messages->where(function (Builder $clause) use ($alternatives, $fields): void {
                foreach ($alternatives as $term) {
                    $clause->orWhere(fn (Builder $query) => $this->filters->like($query, $fields, $term->text, false));
                }
            });
        }

        foreach ($query->excluded as $term) {
            $this->filters->like($messages, $fields, $term->text, true);
        }

        $grammar = $messages->getQuery()->getGrammar();
        $wrap = fn (string $column): string => 'LOWER('.$grammar->wrap($column).')';

        $this->filters->apply($messages, $query, $scope, [
            'from' => [$wrap('from_name'), $wrap('from_address')],
            'to' => [$this->json($messages, 'to')],
            'cc' => [$this->json($messages, 'cc')],
            'subject' => [$wrap('subject')],
            'filename' => null,
            'has_attachment' => "{$table}.has_attachments",
            'is_read' => "{$table}.is_read",
            'is_flagged' => "{$table}.is_flagged",
            'folder' => "{$table}.folder_id",
            'date' => "{$table}.received_at",
        ]);

        return $messages;
    }

    public function index(MailboxMessage $message): void {}

    public function remove(array $messageIds): void {}

    /**
     * @return array<int, string>
     */
    protected function columns(Builder $query): array
    {
        $grammar = $query->getQuery()->getGrammar();
        $columns = ['from_name', 'from_address', 'subject'];

        if (config('filament-mailbox.search.include_body', false)) {
            $columns[] = 'text_body';
        }

        return [
            ...array_map(fn (string $column): string => 'LOWER('.$grammar->wrap($column).')', $columns),
            $this->json($query, 'to'),
            $this->json($query, 'cc'),
        ];
    }

    /**
     * Recipients are stored as JSON; their textual representation is searched.
     */
    protected function json(Builder $query, string $column): string
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        return match ($query->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => "LOWER(CAST({$wrapped} AS CHAR))",
            'pgsql' => "LOWER(CAST({$wrapped} AS TEXT))",
            default => "LOWER({$wrapped})",
        };
    }
}
