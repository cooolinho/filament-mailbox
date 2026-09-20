<?php

namespace Cooolinho\FilamentMailbox\Search\Meilisearch;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Meilisearch client on Laravel's HTTP client (no extra dependency).
 */
class MeilisearchClient
{
    public function host(): string
    {
        return rtrim((string) config('filament-mailbox.search.meilisearch.host', 'http://localhost:7700'), '/');
    }

    public function indexName(): string
    {
        $name = (string) config('filament-mailbox.search.meilisearch.index', 'filament_mailbox_messages');

        return preg_replace('/[^A-Za-z0-9_-]/', '', $name) ?: 'filament_mailbox_messages';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function search(string $index, array $payload): array
    {
        return $this->request('post', "/indexes/{$index}/search", $payload);
    }

    /**
     * @param  array<int, array<string, mixed>>  $documents
     * @return array<string, mixed>
     */
    public function addDocuments(string $index, array $documents): array
    {
        return $this->request('put', "/indexes/{$index}/documents", $documents);
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<string, mixed>
     */
    public function deleteDocuments(string $index, array $ids): array
    {
        return $this->request('post', "/indexes/{$index}/documents/delete-batch", array_values($ids));
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteByFilter(string $index, string $filter): array
    {
        return $this->request('post', "/indexes/{$index}/documents/delete", ['filter' => $filter]);
    }

    /**
     * @return array<string, mixed>
     */
    public function createIndex(string $index, string $primaryKey = 'message_id'): array
    {
        return $this->request('post', '/indexes', ['uid' => $index, 'primaryKey' => $primaryKey]);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteIndex(string $index): array
    {
        return $this->request('delete', "/indexes/{$index}");
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function updateSettings(string $index, array $settings): array
    {
        return $this->request('patch', "/indexes/{$index}/settings", $settings);
    }

    /**
     * @param  array<int, array{indexes: array<int, string>}>  $swaps
     * @return array<string, mixed>
     */
    public function swapIndexes(array $swaps): array
    {
        return $this->request('post', '/swap-indexes', $swaps);
    }

    /**
     * @return array<string, mixed>
     */
    public function task(int $uid): array
    {
        return $this->request('get', "/tasks/{$uid}");
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        return $this->request('get', '/health');
    }

    public function indexExists(string $index): bool
    {
        return ! empty($this->request('get', "/indexes/{$index}", allowMissing: true));
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, array $payload = [], bool $allowMissing = false): array
    {
        $response = $this->pending()->{$method}($this->host().$path, $payload === [] && $method !== 'post' ? null : $payload);

        if ($allowMissing && $response->status() === 404) {
            return [];
        }

        if ($response->failed()) {
            throw new RuntimeException('Meilisearch request failed ('.$response->status().'): '.mb_substr((string) $response->body(), 0, 300));
        }

        return $response->json() ?? [];
    }

    protected function pending(): PendingRequest
    {
        $key = (string) config('filament-mailbox.search.meilisearch.key');

        return Http::asJson()
            ->acceptJson()
            ->when(filled($key), fn (PendingRequest $request) => $request->withToken($key))
            ->timeout(max(1, (int) config('filament-mailbox.search.meilisearch.timeout', 10)));
    }
}
