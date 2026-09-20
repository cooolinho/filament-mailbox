<?php

namespace Cooolinho\FilamentMailbox\Search\Meilisearch;

/**
 * Index and its settings: searchable attributes (in weight order), filters,
 * sorting and typo tolerance (never for addresses).
 */
class MeilisearchIndexManager
{
    public function __construct(
        protected MeilisearchClient $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'searchableAttributes' => ['subject', 'from', 'recipients', 'attachment_names', 'body', 'attachment_text'],
            'filterableAttributes' => ['mailbox_id', 'folder_id', 'is_read', 'is_flagged', 'has_attachments', 'received_at_ts', 'labels', 'tags', 'from_domain', 'from_tokens', 'to_tokens', 'cc_tokens', 'subject_tokens', 'attachment_name_tokens'],
            'sortableAttributes' => ['received_at_ts'],
            'displayedAttributes' => ['message_id', 'received_at_ts'],
            'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness', 'received_at_ts:desc'],
            'typoTolerance' => [
                'enabled' => true,
                // Addresses and file names are matched exactly.
                'disableOnAttributes' => ['from', 'recipients', 'attachment_names'],
                'minWordSizeForTypos' => ['oneTypo' => 5, 'twoTypos' => 9],
            ],
            'separatorTokens' => ['@', '.'],
            'pagination' => ['maxTotalHits' => max(100, (int) config('filament-mailbox.search.max_results', 1000))],
        ];
    }

    /**
     * Create the index with its settings when it does not exist yet.
     */
    public function ensure(?string $index = null): void
    {
        $index ??= $this->client->indexName();

        if (! $this->client->indexExists($index)) {
            $this->client->createIndex($index);
        }

        $this->client->updateSettings($index, $this->settings());
    }

    /**
     * Fresh index for a reindex without downtime.
     */
    public function beginReindex(): string
    {
        $temporary = $this->client->indexName().'_tmp';

        if ($this->client->indexExists($temporary)) {
            $this->client->deleteIndex($temporary);
        }

        $this->client->createIndex($temporary);
        $this->client->updateSettings($temporary, $this->settings());

        return $temporary;
    }

    public function finishReindex(string $temporary): void
    {
        $index = $this->client->indexName();

        $this->ensure($index);
        $this->client->swapIndexes([['indexes' => [$index, $temporary]]]);
        $this->client->deleteIndex($temporary);
    }
}
