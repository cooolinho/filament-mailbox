<?php

namespace Cooolinho\FilamentMailbox\Tests\Fixtures;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Stateful in-memory Microsoft Graph Mail API for Http::fake().
 *
 * Supports folders (incl. well-known names and child folders), delta
 * queries with paging and removals, MIME download, PATCH/move/delete,
 * sendMail, master categories and subscriptions.
 */
class FakeGraphServer
{
    public const BASE = 'https://graph.microsoft.com/v1.0';

    /** @var array<string, array{displayName: string, parent: ?string, wellKnown: ?string}> */
    public array $folders = [];

    /** @var array<string, array{folder: string, raw: string, isRead: bool, flagStatus: string, categories: array<int, string>, isDraft: bool, receivedDateTime: string, seq: int}> */
    public array $messages = [];

    /** @var array<int, array{seq: int, folder: string, id: string}> */
    public array $removed = [];

    /** @var array<int, array{id: string, displayName: string, color: string}> */
    public array $categories = [];

    /** @var array<int, string> Decoded MIME of sent messages */
    public array $sent = [];

    /** @var array<string, array<string, mixed>> */
    public array $subscriptions = [];

    /** @var array<int, string> "METHOD path" of every request */
    public array $requests = [];

    /** @var array<int, string> Authorization headers */
    public array $tokens = [];

    public int $seq = 0;

    /** Respond with 429 to the next n requests. */
    public int $throttle = 0;

    /** Respond with 401 to the next n requests. */
    public int $unauthorized = 0;

    /** Delta tokens not above this value are expired (410). */
    public int $expiredBelow = 0;

    public string $mailboxPath = 'me';

    public function __construct()
    {
        foreach (['inbox' => 'Inbox', 'sentitems' => 'Sent Items', 'drafts' => 'Drafts', 'deleteditems' => 'Deleted Items', 'junkemail' => 'Junk Email'] as $wellKnown => $name) {
            $this->addFolder($name, wellKnown: $wellKnown, id: 'folder-'.$wellKnown);
        }
    }

    public function fake(): static
    {
        Http::fake([self::BASE.'/*' => fn (Request $request) => $this->handle($request)]);

        return $this;
    }

    public function addFolder(string $name, ?string $parent = null, ?string $wellKnown = null, ?string $id = null): string
    {
        $id ??= 'folder-'.Str::lower(Str::random(8));
        $this->folders[$id] = ['displayName' => $name, 'parent' => $parent, 'wellKnown' => $wellKnown];

        return $id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addMessage(string $folder, string $subject = 'Hello', array $attributes = []): string
    {
        $id = $attributes['id'] ?? 'AAMk-'.Str::random(16);

        $this->messages[$id] = [
            'folder' => $folder,
            'raw' => $attributes['raw'] ?? "From: John <john@example.com>\r\nTo: jane@example.com\r\nSubject: {$subject}\r\nMessage-ID: <{$id}@example.com>\r\nDate: Thu, 17 Sep 2026 08:00:00 +0000\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody of {$subject}\r\n",
            'isRead' => $attributes['isRead'] ?? false,
            'flagStatus' => $attributes['flagStatus'] ?? 'notFlagged',
            'categories' => $attributes['categories'] ?? [],
            'isDraft' => false,
            'receivedDateTime' => $attributes['receivedDateTime'] ?? '2026-09-17T09:30:00Z',
            'seq' => ++$this->seq,
        ];

        return $id;
    }

    public function update(string $id, array $changes): void
    {
        $this->messages[$id] = [...$this->messages[$id], ...$changes, 'seq' => ++$this->seq];
    }

    public function remove(string $id): void
    {
        $this->removed[] = ['seq' => ++$this->seq, 'folder' => $this->messages[$id]['folder'], 'id' => $id];
        unset($this->messages[$id]);
    }

    public function folderId(string $wellKnown): string
    {
        return 'folder-'.$wellKnown;
    }

    protected function handle(Request $request): mixed
    {
        $url = parse_url($request->url());
        parse_str($url['query'] ?? '', $query);
        $path = substr($url['path'], strlen('/v1.0/'));
        $method = $request->method();

        $this->requests[] = "{$method} {$path}";
        $this->tokens[] = $request->header('Authorization')[0] ?? '';

        if ($this->throttle > 0) {
            $this->throttle--;

            return Http::response(['error' => ['code' => 'TooManyRequests']], 429, ['Retry-After' => '2']);
        }

        if ($this->unauthorized > 0) {
            $this->unauthorized--;

            return Http::response(['error' => ['code' => 'InvalidAuthenticationToken']], 401);
        }

        if (str_starts_with($path, 'subscriptions')) {
            return $this->subscriptionsEndpoint($method, $path, $request);
        }

        if (! str_starts_with($path, $this->mailboxPath.'/')) {
            return Http::response(['error' => ['code' => 'ErrorInvalidUser']], 404);
        }

        $path = substr($path, strlen($this->mailboxPath) + 1);
        $segments = array_map('rawurldecode', explode('/', $path));

        return match (true) {
            $segments[0] === 'mailFolders' => $this->foldersEndpoint($segments, $query, $request),
            $segments[0] === 'messages' => $this->messagesEndpoint($method, $segments, $query, $request),
            $segments[0] === 'sendMail' => $this->sendMail($request),
            $segments[0] === 'outlook' => $this->categoriesEndpoint($method, $segments, $request),
            default => Http::response(['error' => ['code' => 'NotFound']], 404),
        };
    }

    /**
     * @param  array<int, string>  $segments
     * @param  array<string, mixed>  $query
     */
    protected function foldersEndpoint(array $segments, array $query, Request $request): mixed
    {
        $method = $request->method();

        if (count($segments) === 1) {
            return $method === 'POST'
                ? Http::response($this->folderResource($this->addFolder((string) $request->data()['displayName'])), 201)
                : Http::response(['value' => $this->folderList(null)]);
        }

        if ($segments[1] === 'msgfolderroot') {
            return Http::response(['id' => 'folder-root', 'displayName' => 'Top of Information Store']);
        }

        $id = $this->resolveFolder($segments[1]);

        if (! $id) {
            return Http::response(['error' => ['code' => 'ErrorItemNotFound']], 404);
        }

        return match (true) {
            $method === 'DELETE' && ! isset($segments[2]) => $this->deleteFolder($id),
            $method === 'PATCH' && ! isset($segments[2]) => $this->patchFolder($id, $request->data()),
            $method === 'POST' && ($segments[2] ?? null) === 'childFolders' => Http::response($this->folderResource($this->addFolder((string) $request->data()['displayName'], $id)), 201),
            $method === 'POST' && ($segments[2] ?? null) === 'move' => $this->moveFolder($id, (string) $request->data()['destinationId']),
            ! isset($segments[2]) => Http::response($this->folderResource($id)),
            default => $this->folderSubresource($id, $segments, $query, $request),
        };
    }

    /**
     * @param  array<int, string>  $segments
     * @param  array<string, mixed>  $query
     */
    protected function folderSubresource(string $id, array $segments, array $query, Request $request): mixed
    {
        return match ($segments[2] ?? null) {
            'childFolders' => Http::response(['value' => $this->folderList($id)]),
            'messages' => $this->delta($id, $query, $request),
            default => Http::response([], 404),
        };
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function delta(string $folder, array $query, Request $request): mixed
    {
        preg_match('/odata\.maxpagesize=(\d+)/', implode(',', $request->header('Prefer')), $matches);
        $pageSize = (int) ($matches[1] ?? 100);

        [$since, $offset] = isset($query['$skiptoken'])
            ? array_map('intval', explode(':', $query['$skiptoken']))
            : [(int) ($query['$deltatoken'] ?? 0), 0];

        if (isset($query['$deltatoken']) && $since <= $this->expiredBelow) {
            return Http::response(['error' => ['code' => 'SyncStateNotFound']], 410);
        }

        $items = [];

        foreach ($this->messages as $id => $message) {
            if ($message['folder'] === $folder && $message['seq'] > $since) {
                $items[] = ['id' => $id, 'isRead' => $message['isRead'], 'isDraft' => $message['isDraft'], 'flag' => ['flagStatus' => $message['flagStatus']], 'categories' => $message['categories'], 'receivedDateTime' => $message['receivedDateTime']];
            }
        }

        if ($since > 0) {
            foreach ($this->removed as $removed) {
                if ($removed['folder'] === $folder && $removed['seq'] > $since) {
                    $items[] = ['id' => $removed['id'], '@removed' => ['reason' => 'deleted']];
                }
            }
        }

        $page = array_slice($items, $offset, $pageSize);
        $link = self::BASE."/{$this->mailboxPath}/mailFolders/".rawurlencode($folder).'/messages/delta';

        return Http::response(['value' => $page] + (count($items) > $offset + $pageSize
            ? ['@odata.nextLink' => $link.'?$skiptoken='.$since.':'.($offset + $pageSize)]
            : ['@odata.deltaLink' => $link.'?$deltatoken='.$this->seq]));
    }

    /**
     * @param  array<int, string>  $segments
     * @param  array<string, mixed>  $query
     */
    protected function messagesEndpoint(string $method, array $segments, array $query, Request $request): mixed
    {
        $id = $segments[1] ?? '';

        if (! isset($this->messages[$id])) {
            return Http::response(['error' => ['code' => 'ErrorItemNotFound']], 404);
        }

        return match ([$method, $segments[2] ?? null]) {
            ['GET', '$value'] => Http::response($this->messages[$id]['raw'], 200, ['Content-Type' => 'message/rfc822']),
            ['GET', null] => Http::response(['id' => $id, 'categories' => $this->messages[$id]['categories']]),
            ['PATCH', null] => $this->patchMessage($id, $request->data()),
            ['DELETE', null] => tap(Http::response(null, 204), fn () => $this->remove($id)),
            ['POST', 'move'] => $this->move($id, (string) $request['destinationId']),
            default => Http::response([], 405),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function patchMessage(string $id, array $data): mixed
    {
        $changes = [];

        if (array_key_exists('isRead', $data)) {
            $changes['isRead'] = (bool) $data['isRead'];
        }

        if (isset($data['flag']['flagStatus'])) {
            $changes['flagStatus'] = $data['flag']['flagStatus'];
        }

        if (array_key_exists('categories', $data)) {
            $changes['categories'] = $data['categories'];
        }

        $this->update($id, $changes);

        return Http::response(['id' => $id]);
    }

    protected function move(string $id, string $destination): mixed
    {
        $target = $this->resolveFolder($destination);

        if (! $target) {
            return Http::response(['error' => ['code' => 'ErrorItemNotFound']], 404);
        }

        $message = $this->messages[$id];
        $this->remove($id);
        $this->messages[$id] = [...$message, 'folder' => $target, 'seq' => ++$this->seq];

        return Http::response(['id' => $id]);
    }

    protected function sendMail(Request $request): mixed
    {
        $this->sent[] = (string) base64_decode($request->body(), true);

        return Http::response(null, 202);
    }

    /**
     * @param  array<int, string>  $segments
     */
    protected function categoriesEndpoint(string $method, array $segments, Request $request): mixed
    {
        $id = $segments[2] ?? null;

        if ($method === 'GET') {
            return Http::response(['value' => $this->categories]);
        }

        if ($method === 'POST') {
            foreach ($this->categories as $category) {
                if (strcasecmp($category['displayName'], $request['displayName']) === 0) {
                    return Http::response(['error' => ['code' => 'ErrorDuplicate']], 409);
                }
            }

            $this->categories[] = $category = ['id' => Str::uuid()->toString(), 'displayName' => $request['displayName'], 'color' => $request['color']];

            return Http::response($category, 201);
        }

        foreach ($this->categories as $index => $category) {
            if ($category['id'] === $id) {
                if ($method === 'DELETE') {
                    array_splice($this->categories, $index, 1);

                    return Http::response(null, 204);
                }

                $this->categories[$index]['color'] = $request['color'];

                return Http::response($this->categories[$index]);
            }
        }

        return Http::response([], 404);
    }

    protected function subscriptionsEndpoint(string $method, string $path, Request $request): mixed
    {
        $id = explode('/', $path)[1] ?? null;

        if ($method === 'POST') {
            $id = Str::uuid()->toString();
            $this->subscriptions[$id] = $request->data();

            return Http::response(['id' => $id] + $request->data(), 201);
        }

        if (! $id || ! isset($this->subscriptions[$id])) {
            return Http::response(['error' => ['code' => 'ResourceNotFound']], 404);
        }

        if ($method === 'DELETE') {
            unset($this->subscriptions[$id]);

            return Http::response(null, 204);
        }

        $this->subscriptions[$id] = [...$this->subscriptions[$id], ...$request->data()];

        return Http::response(['id' => $id] + $this->subscriptions[$id]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function folderResource(string $id): array
    {
        return [
            'id' => $id,
            'displayName' => $this->folders[$id]['displayName'],
            'parentFolderId' => $this->folders[$id]['parent'] ?? 'folder-root',
            'totalItemCount' => count(array_filter($this->messages, fn (array $message): bool => $message['folder'] === $id)),
            'unreadItemCount' => count(array_filter($this->messages, fn (array $message): bool => $message['folder'] === $id && ! $message['isRead'])),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function patchFolder(string $id, array $data): mixed
    {
        $this->folders[$id]['displayName'] = (string) ($data['displayName'] ?? $this->folders[$id]['displayName']);

        return Http::response($this->folderResource($id));
    }

    protected function moveFolder(string $id, string $destination): mixed
    {
        $this->folders[$id]['parent'] = $destination === 'folder-root' ? null : $this->resolveFolder($destination);

        return Http::response($this->folderResource($id), 201);
    }

    protected function deleteFolder(string $id): mixed
    {
        foreach (array_keys(array_filter($this->folders, fn (array $folder): bool => $folder['parent'] === $id)) as $child) {
            $this->deleteFolder($child);
        }

        foreach (array_keys(array_filter($this->messages, fn (array $message): bool => $message['folder'] === $id)) as $message) {
            $this->remove($message);
        }

        unset($this->folders[$id]);

        return Http::response(null, 204);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function folderList(?string $parent): array
    {
        $list = [];

        foreach ($this->folders as $id => $folder) {
            if ($folder['parent'] === $parent) {
                $list[] = [
                    'id' => $id,
                    'displayName' => $folder['displayName'],
                    'parentFolderId' => $parent,
                    'childFolderCount' => count(array_filter($this->folders, fn (array $child): bool => $child['parent'] === $id)),
                ];
            }
        }

        return $list;
    }

    protected function resolveFolder(string $idOrWellKnown): ?string
    {
        if (isset($this->folders[$idOrWellKnown])) {
            return $idOrWellKnown;
        }

        foreach ($this->folders as $id => $folder) {
            if ($folder['wellKnown'] === strtolower($idOrWellKnown)) {
                return $id;
            }
        }

        return null;
    }
}
