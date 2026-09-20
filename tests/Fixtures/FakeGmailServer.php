<?php

namespace Cooolinho\FilamentMailbox\Tests\Fixtures;

use Cooolinho\FilamentMailbox\OAuth\Providers\AbstractOAuthProvider;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Stateful in-memory Gmail API for Http::fake(): profile, labels, message
 * listing, minimal/raw messages, modify/trash/untrash, send, history,
 * watch and batch requests.
 */
class FakeGmailServer
{
    public const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me/';

    /** @var array<string, array{raw: string, labelIds: array<int, string>, threadId: string, internalDate: int}> */
    public array $messages = [];

    /** @var array<string, array{id: string, name: string, type: string}> */
    public array $labels = [];

    /** @var array<int, array<string, mixed>> */
    public array $history = [];

    /** @var array<int, array<string, mixed>> Payloads of messages.send */
    public array $sent = [];

    /** @var array<int, string> */
    public array $requests = [];

    public int $historyId = 1000;

    /** History ids below this value are expired (404). */
    public int $oldestHistoryId = 0;

    public int $throttle = 0;

    public int $rateLimited = 0;

    public ?array $watch = null;

    public function __construct()
    {
        foreach (['INBOX', 'SENT', 'DRAFT', 'SPAM', 'TRASH', 'UNREAD', 'STARRED', 'IMPORTANT', 'CATEGORY_PROMOTIONS'] as $id) {
            $this->labels[$id] = ['id' => $id, 'name' => $id, 'type' => 'system'];
        }
    }

    public function fake(): static
    {
        Http::fake([
            'https://gmail.googleapis.com/batch/gmail/v1' => fn (Request $request) => $this->batch($request),
            self::BASE.'*' => fn (Request $request) => $this->handle($request->method(), $request->url(), $request->data(), true),
        ]);

        return $this;
    }

    public function addLabel(string $name): string
    {
        $id = 'Label_'.(count($this->labels) + 1);
        $this->labels[$id] = ['id' => $id, 'name' => $name, 'type' => 'user'];

        return $id;
    }

    /**
     * @param  array<int, string>  $labelIds
     */
    public function addMessage(string $subject = 'Hello', array $labelIds = ['INBOX', 'UNREAD'], ?string $raw = null, ?string $threadId = null, ?int $daysAgo = 1): string
    {
        $id = Str::lower(Str::random(16));
        $this->messages[$id] = [
            'raw' => $raw ?? "From: John <john@example.com>\r\nTo: me@example.com\r\nSubject: {$subject}\r\nMessage-ID: <{$id}@example.com>\r\nDate: Thu, 17 Sep 2026 08:00:00 +0000\r\n\r\nBody of {$subject}\r\n",
            'labelIds' => $labelIds,
            'threadId' => $threadId ?? 'thread-'.$id,
            'internalDate' => now()->subDays($daysAgo)->getTimestampMs(),
        ];

        $this->record('messagesAdded', $id);

        return $id;
    }

    /**
     * @param  array<int, string>  $add
     * @param  array<int, string>  $remove
     */
    public function modify(string $id, array $add = [], array $remove = []): void
    {
        $labels = $this->messages[$id]['labelIds'];
        $this->messages[$id]['labelIds'] = array_values(array_diff(array_unique([...$labels, ...$add]), $remove));

        if ($add !== []) {
            $this->record('labelsAdded', $id);
        }

        if ($remove !== []) {
            $this->record('labelsRemoved', $id);
        }
    }

    public function deleteForever(string $id): void
    {
        $this->record('messagesDeleted', $id);
        unset($this->messages[$id]);
    }

    protected function record(string $type, string $id): void
    {
        $this->history[] = [
            'id' => (string) ++$this->historyId,
            $type => [['message' => ['id' => $id, 'threadId' => $this->messages[$id]['threadId'] ?? null]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handle(string $method, string $url, array $data, bool $top): PromiseInterface
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $path = substr($parts['path'], strlen('/gmail/v1/users/me/'));
        $this->requests[] = "{$method} {$path}";

        if ($top && $this->throttle > 0) {
            $this->throttle--;

            return Http::response(['error' => ['code' => 429, 'message' => 'Too many']], 429, ['Retry-After' => '1']);
        }

        if ($top && $this->rateLimited > 0) {
            $this->rateLimited--;

            return Http::response(['error' => ['code' => 403, 'errors' => [['reason' => 'userRateLimitExceeded']]]], 403);
        }

        $segments = explode('/', $path);

        return match (true) {
            $path === 'profile' => Http::response(['emailAddress' => 'me@example.com', 'historyId' => (string) $this->historyId]),
            $path === 'watch' => Http::response($this->watch = ['historyId' => (string) $this->historyId, 'expiration' => (string) now()->addDays(7)->getTimestampMs(), 'request' => $data]),
            $segments[0] === 'labels' => $this->labelsEndpoint($method, $segments[1] ?? null, $data),
            $path === 'messages' => $this->list($query),
            $path === 'messages/send' => $this->send($data),
            $segments[0] === 'messages' => $this->messageEndpoint($method, $segments[1], $segments[2] ?? null, $query, $data),
            $path === 'history' => $this->historyEndpoint($parts['query'] ?? ''),
            default => Http::response(['error' => ['code' => 404]], 404),
        };
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function list(array $query): PromiseInterface
    {
        $ids = array_keys(array_filter($this->messages, function (array $message) use ($query): bool {
            preg_match('/newer_than:(\d+)d/', (string) ($query['q'] ?? ''), $matches);

            return ! isset($matches[1]) || $message['internalDate'] >= now()->subDays((int) $matches[1])->getTimestampMs();
        }));

        $offset = (int) ($query['pageToken'] ?? 0);
        $size = (int) ($query['maxResults'] ?? 100);
        $page = array_slice($ids, $offset, $size);

        return Http::response(array_filter([
            'messages' => array_map(fn (string $id): array => ['id' => $id, 'threadId' => $this->messages[$id]['threadId']], $page),
            'nextPageToken' => count($ids) > $offset + $size ? (string) ($offset + $size) : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $data
     */
    protected function messageEndpoint(string $method, string $id, ?string $action, array $query, array $data): PromiseInterface
    {
        if (! isset($this->messages[$id])) {
            return Http::response(['error' => ['code' => 404, 'message' => 'Requested entity was not found.']], 404);
        }

        $message = $this->messages[$id];

        return match ([$method, $action]) {
            ['GET', null] => Http::response([
                'id' => $id,
                'threadId' => $message['threadId'],
                'labelIds' => $message['labelIds'],
                'internalDate' => (string) $message['internalDate'],
                'historyId' => (string) $this->historyId,
            ] + (($query['format'] ?? null) === 'raw' ? ['raw' => AbstractOAuthProvider::base64UrlEncode($message['raw'])] : [])),
            ['POST', 'modify'] => tap(Http::response(['id' => $id]), fn () => $this->modify($id, $data['addLabelIds'] ?? [], $data['removeLabelIds'] ?? [])),
            ['POST', 'trash'] => tap(Http::response(['id' => $id]), fn () => $this->modify($id, ['TRASH'])),
            ['POST', 'untrash'] => tap(Http::response(['id' => $id]), fn () => $this->modify($id, [], ['TRASH'])),
            default => Http::response([], 405),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function send(array $data): PromiseInterface
    {
        $this->sent[] = $data;
        $id = Str::lower(Str::random(16));

        $this->messages[$id] = [
            'raw' => AbstractOAuthProvider::base64UrlDecode((string) $data['raw']),
            'labelIds' => ['SENT'],
            'threadId' => $data['threadId'] ?? 'thread-'.$id,
            'internalDate' => now()->getTimestampMs(),
        ];
        $this->record('messagesAdded', $id);

        return Http::response(['id' => $id, 'threadId' => $this->messages[$id]['threadId'], 'labelIds' => ['SENT']]);
    }

    protected function historyEndpoint(string $rawQuery): PromiseInterface
    {
        parse_str($rawQuery, $query);
        $start = (int) ($query['startHistoryId'] ?? 0);

        if ($start < $this->oldestHistoryId) {
            return Http::response(['error' => ['code' => 404, 'message' => 'Requested entity was not found.']], 404);
        }

        // Repeated historyTypes parameters must be sent without brackets.
        abort_unless(substr_count($rawQuery, 'historyTypes=') === 4, 500, 'historyTypes not repeated');

        $records = array_values(array_filter($this->history, fn (array $record): bool => (int) $record['id'] > $start));
        $offset = (int) ($query['pageToken'] ?? 0);
        $size = (int) ($query['maxResults'] ?? 100);

        return Http::response(array_filter([
            'history' => array_slice($records, $offset, $size),
            'nextPageToken' => count($records) > $offset + $size ? (string) ($offset + $size) : null,
            'historyId' => (string) $this->historyId,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function labelsEndpoint(string $method, ?string $id, array $data): PromiseInterface
    {
        if ($method === 'GET') {
            return Http::response(['labels' => array_values($this->labels)]);
        }

        if ($method === 'POST') {
            $id = $this->addLabel((string) $data['name']);

            return Http::response($this->labels[$id]);
        }

        if (! isset($this->labels[$id])) {
            return Http::response(['error' => ['code' => 404]], 404);
        }

        if ($method === 'DELETE') {
            unset($this->labels[$id]);

            return Http::response(null, 204);
        }

        $this->labels[$id]['name'] = (string) $data['name'];

        return Http::response($this->labels[$id]);
    }

    protected function batch(Request $request): PromiseInterface
    {
        preg_match('/boundary=([^;]+)/', $request->header('Content-Type')[0], $matches);
        $this->requests[] = 'POST batch';

        $responses = [];

        foreach (explode('--'.$matches[1], $request->body()) as $part) {
            if (! preg_match('/Content-ID: <item-(\d+)>.*?\r\n\r\n(GET) (\S+)/s', $part, $item)) {
                continue;
            }

            $response = $this->handle($item[2], 'https://gmail.googleapis.com'.$item[3], [], false)->wait();

            $responses[] = "--response_boundary\r\nContent-Type: application/http\r\nContent-ID: <response-item-{$item[1]}>\r\n\r\n"
                ."HTTP/1.1 {$response->getStatusCode()} OK\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
                .$response->getBody()."\r\n";
        }

        return Http::response(implode('', $responses)."--response_boundary--\r\n", 200, [
            'Content-Type' => 'multipart/mixed; boundary=response_boundary',
        ]);
    }
}
