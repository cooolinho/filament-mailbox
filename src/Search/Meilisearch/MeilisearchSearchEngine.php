<?php

namespace Cooolinho\FilamentMailbox\Search\Meilisearch;

use Cooolinho\FilamentMailbox\Jobs\SyncMeilisearchDocuments;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxSearchTask;
use Cooolinho\FilamentMailbox\Search\Contracts\DeclaresSearchCapabilities;
use Cooolinho\FilamentMailbox\Search\Contracts\MessageSearchEngine;
use Cooolinho\FilamentMailbox\Search\SearchCapability;
use Cooolinho\FilamentMailbox\Search\Contracts\SupportsReindex;
use Cooolinho\FilamentMailbox\Search\SearchManager;
use Cooolinho\FilamentMailbox\Search\SearchDocumentMapper;
use Cooolinho\FilamentMailbox\Search\SearchQuery;
use Cooolinho\FilamentMailbox\Search\SearchResult;
use Cooolinho\FilamentMailbox\Search\SearchScope;
use Cooolinho\FilamentMailbox\Search\SearchTerm;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Meilisearch: typo-tolerant prefix search with relevance ranking. Indexing
 * runs through queue jobs and Meilisearch tasks; a failing search falls back
 * to "search.meilisearch.fallback_engine".
 */
class MeilisearchSearchEngine implements DeclaresSearchCapabilities, MessageSearchEngine, SupportsReindex
{
    protected ?string $reindexTarget = null;

    public function __construct(
        protected MeilisearchClient $client,
        protected MeilisearchIndexManager $indexes,
        protected SearchDocumentMapper $mapper,
        protected MeilisearchFilterBuilder $filters,
    ) {}

    public function name(): string
    {
        return 'meilisearch';
    }

    /**
     * Filters, phrases, negation and ranking are handled by Meilisearch itself.
     *
     * @return array<int, SearchCapability>
     */
    public function capabilities(): array
    {
        return SearchCapability::all();
    }

    public function search(SearchQuery $query, SearchScope $scope, int $limit = 100, int $offset = 0): SearchResult
    {
        try {
            $response = $this->client->search($this->client->indexName(), [
                'q' => $this->queryString($query),
                'filter' => $this->filters->build($query, $scope),
                'limit' => $limit,
                'offset' => $offset,
                'attributesToRetrieve' => ['message_id'],
                'matchingStrategy' => 'all',
            ]);
        } catch (Throwable $exception) {
            return $this->fallback($exception, $query, $scope, $limit, $offset);
        }

        $ids = array_values(array_map(fn (array $hit): int => (int) ($hit['message_id'] ?? 0), $response['hits'] ?? []));

        return new SearchResult(array_values(array_filter($ids)), (int) ($response['estimatedTotalHits'] ?? count($ids)));
    }

    public function index(MailboxMessage $message): void
    {
        // The fallback engine stays usable while the instance is unreachable.
        $this->fallbackEngine()?->index($message);

        SyncMeilisearchDocuments::dispatch([(int) $message->getKey()], index: $this->reindexTarget)->afterCommit();
    }

    public function remove(array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        $this->fallbackEngine()?->remove($messageIds);

        SyncMeilisearchDocuments::dispatch(array_map('intval', $messageIds), delete: true, index: $this->reindexTarget)->afterCommit();
    }

    /**
     * The engine used when Meilisearch cannot be reached; its index is kept up to date.
     */
    public function fallbackEngine(): ?MessageSearchEngine
    {
        $fallback = (string) config('filament-mailbox.search.meilisearch.fallback_engine', 'database');

        if ($fallback === '' || $fallback === $this->name()) {
            return null;
        }

        $engine = app(SearchManager::class)->resolve($fallback);

        return $engine instanceof self ? null : $engine;
    }

    /**
     * @param  array<int, int>  $messageIds
     */
    public function sendDocuments(array $messageIds, ?string $index = null): void
    {
        $messages = MailboxMessage::query()->with(['attachments'])->whereKey($messageIds)->get();
        $documents = $messages->map(fn (MailboxMessage $message): array => $this->mapper->map($message))->all();
        $missing = array_values(array_diff($messageIds, $messages->modelKeys()));

        if ($documents !== []) {
            $this->recordTask($this->client->addDocuments($index ?? $this->client->indexName(), $documents), 'documentAdditionOrUpdate', $messages->modelKeys());
        }

        if ($missing !== []) {
            $this->sendDeletions($missing, $index);
        }
    }

    /**
     * @param  array<int, int>  $messageIds
     */
    public function sendDeletions(array $messageIds, ?string $index = null): void
    {
        if ($messageIds !== []) {
            $this->recordTask($this->client->deleteDocuments($index ?? $this->client->indexName(), $messageIds), 'documentDeletion', $messageIds);
        }
    }

    public function beginReindex(): void
    {
        $this->reindexTarget = $this->indexes->beginReindex();
    }

    public function finishReindex(): void
    {
        if ($this->reindexTarget !== null) {
            $this->indexes->finishReindex($this->reindexTarget);
            $this->reindexTarget = null;
        }
    }

    /**
     * Raises when the instance cannot be reached (health check).
     */
    public function ping(): void
    {
        $this->client->health();
    }

    public function ensureIndex(): void
    {
        $this->indexes->ensure();
    }

    /**
     * Checks open tasks and repeats failed document batches.
     *
     * @return array{checked: int, failed: int}
     */
    public function checkTasks(): array
    {
        $checked = 0;
        $failed = 0;

        MailboxSearchTask::query()
            ->where('status', MailboxSearchTask::ENQUEUED)
            ->where('created_at', '>', now()->subDay())
            ->orderBy('id')
            ->chunkById(100, function ($tasks) use (&$checked, &$failed): void {
                foreach ($tasks as $task) {
                    $checked++;
                    $status = $this->client->task($task->task_uid);
                    $state = (string) ($status['status'] ?? MailboxSearchTask::ENQUEUED);

                    if (in_array($state, ['enqueued', 'processing'], true)) {
                        continue;
                    }

                    $task->forceFill([
                        'status' => $state === 'succeeded' ? MailboxSearchTask::SUCCEEDED : MailboxSearchTask::FAILED,
                        'error' => mb_substr((string) ($status['error']['message'] ?? ''), 0, 1000) ?: null,
                    ])->save();

                    if ($state === 'succeeded' || $task->attempts >= 3 || $task->message_ids === null) {
                        $failed += $state === 'succeeded' ? 0 : 1;

                        continue;
                    }

                    $failed++;
                    $task->increment('attempts');

                    $task->type === 'documentDeletion'
                        ? SyncMeilisearchDocuments::dispatch($task->message_ids, delete: true)
                        : SyncMeilisearchDocuments::dispatch($task->message_ids);
                }
            });

        return ['checked' => $checked, 'failed' => $failed];
    }

    /**
     * Phrases stay quoted, excluded terms use "-" (Meilisearch 1.x).
     */
    protected function queryString(SearchQuery $query): string
    {
        $parts = [];

        foreach ($query->clauses as $alternatives) {
            $parts[] = implode(' ', array_map(fn (SearchTerm $term): string => $term->phrase || count($term->tokens()) > 1
                ? '"'.implode(' ', $term->tokens()).'"'
                : implode('', $term->tokens()), $alternatives));
        }

        foreach ($query->excluded as $term) {
            $tokens = $term->tokens();

            if ($tokens !== []) {
                $parts[] = '-'.(count($tokens) > 1 ? '"'.implode(' ', $tokens).'"' : $tokens[0]);
            }
        }

        return trim(implode(' ', array_filter($parts)));
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<int, int>  $messageIds
     */
    protected function recordTask(array $response, string $type, array $messageIds): void
    {
        if (! isset($response['taskUid'])) {
            return;
        }

        MailboxSearchTask::query()->updateOrCreate(['task_uid' => (int) $response['taskUid']], [
            'type' => $type,
            'message_ids' => array_map('intval', $messageIds),
            'status' => MailboxSearchTask::ENQUEUED,
        ]);
    }

    /**
     * A failing search must not make the mailbox unusable.
     */
    protected function fallback(Throwable $exception, SearchQuery $query, SearchScope $scope, int $limit, int $offset): SearchResult
    {
        Log::channel(config('filament-mailbox.sync.log_channel'))->warning('Meilisearch search failed.', ['error' => mb_substr($exception->getMessage(), 0, 300)]);

        $fallback = $this->fallbackEngine();

        if (! $fallback) {
            throw $exception;
        }

        return $fallback->search($query, $scope, $limit, $offset);
    }
}
