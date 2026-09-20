<?php

namespace Cooolinho\FilamentMailbox\Providers\Graph;

use Closure;
use Cooolinho\FilamentMailbox\Contracts\ManagesFolders;
use Cooolinho\FilamentMailbox\Contracts\SupportsLabels;
use Cooolinho\FilamentMailbox\Contracts\SupportsSending;
use Cooolinho\FilamentMailbox\Data\FolderChanges;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Data\FolderStatistics;
use Cooolinho\FilamentMailbox\Data\LabelData;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Data\SyncCursor;
use Cooolinho\FilamentMailbox\Enums\LabelColor;
use Cooolinho\FilamentMailbox\Enums\LabelSource;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Exceptions\ConnectionFailed;
use Cooolinho\FilamentMailbox\Exceptions\ProviderOperationFailed;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\OAuth\OAuthTokenManager;
use Cooolinho\FilamentMailbox\Providers\AbstractMailboxProvider;
use Cooolinho\FilamentMailbox\Providers\MimeMessageMapper;
use Cooolinho\FilamentMailbox\Support\FolderPath;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Microsoft 365 / Exchange Online through the Microsoft Graph Mail API.
 *
 * Folders and messages are addressed by their (immutable) Graph ids. Each
 * folder is synchronised with delta queries; new messages are downloaded as
 * MIME and parsed like IMAP messages.
 */
class GraphProvider extends AbstractMailboxProvider implements ManagesFolders, SupportsLabels, SupportsSending
{
    protected const DELTA_SELECT = 'id,isRead,isDraft,flag,categories,receivedDateTime';

    protected GraphClient $client;

    /** @var array<string, string> receivedDateTime of messages seen in delta pages, keyed by id */
    protected array $receivedAt = [];

    public function __construct(
        protected Mailbox $mailbox,
        ?GraphClient $client = null,
    ) {
        $this->client = $client ?? new GraphClient($mailbox, app(OAuthTokenManager::class));
    }

    public function capabilities(): array
    {
        return ProviderType::Graph->capabilities();
    }

    public function testConnection(): void
    {
        $this->attempt('test connection', fn () => $this->client->get('mailFolders/inbox', ['$select' => 'id']), connection: true);
    }

    public function folders(): iterable
    {
        return $this->attempt('folders', function (): array {
            $roles = $this->wellKnownFolders();
            $folders = [];

            $this->collectFolders('mailFolders', null, $roles, $folders);

            return $folders;
        }, connection: true);
    }

    public function changes(FolderIdentifier $folder, SyncCursor $cursor, int $limit): FolderChanges
    {
        return $this->attempt('fetch changes', function () use ($folder, $cursor, $limit): FolderChanges {
            $headers = ['Prefer' => 'IdType="ImmutableId", odata.maxpagesize='.max(1, $limit)];
            $link = $cursor->get('next_link') ?? $cursor->get('delta_link');
            $reset = false;

            try {
                $page = $link ? $this->client->getUrl((string) $link, $headers) : $this->initialDelta($folder, $headers);
            } catch (GraphRequestFailed $exception) {
                if (! $link || ! $exception->isSyncStateExpired()) {
                    throw $exception;
                }

                // The delta token expired: start over and let the sync reconcile the folder.
                $page = $this->initialDelta($folder, $headers);
                $reset = true;
            }

            $updated = $deleted = [];

            foreach ($page['value'] ?? [] as $item) {
                $id = (string) $item['id'];

                if (isset($item['@removed'])) {
                    $deleted[] = $id;

                    continue;
                }

                $updated[$id] = static::flags($item);

                if (isset($item['receivedDateTime'])) {
                    $this->receivedAt[$id] = (string) $item['receivedDateTime'];
                }
            }

            $next = $page['@odata.nextLink'] ?? null;

            return new FolderChanges(
                created: [],
                updated: $updated,
                deleted: $deleted,
                cursor: new SyncCursor($next
                    ? ['next_link' => $next, 'delta_link' => $cursor->get('delta_link')]
                    : ['delta_link' => $page['@odata.deltaLink'] ?? $cursor->get('delta_link')]),
                hasMore: $next !== null,
                reset: $reset,
                includesNew: true,
            );
        });
    }

    public function fetchMessages(FolderIdentifier $folder, array $remoteIds): array
    {
        return $this->attempt('fetch messages', function () use ($remoteIds): array {
            $messages = [];

            foreach ($remoteIds as $id) {
                try {
                    $raw = $this->client->raw('messages/'.rawurlencode($id).'/$value');
                } catch (GraphRequestFailed $exception) {
                    // Deleted or moved in the meantime.
                    if ($exception->isNotFound()) {
                        continue;
                    }

                    throw $exception;
                }

                $messages[] = MimeMessageMapper::fromRaw(
                    $raw,
                    $id,
                    receivedAt: isset($this->receivedAt[$id]) ? Carbon::parse($this->receivedAt[$id]) : null,
                );
            }

            return $messages;
        });
    }

    public function setFlags(MessageIdentifier $message, MessageFlags $add, MessageFlags $remove): void
    {
        $this->attempt('set flags', function () use ($message, $add, $remove): void {
            $path = 'messages/'.rawurlencode($message->remoteId);
            $body = [];

            if ($add->seen || $remove->seen) {
                $body['isRead'] = $add->seen;
            }

            if ($add->flagged || $remove->flagged) {
                $body['flag'] = ['flagStatus' => $add->flagged ? 'flagged' : 'notFlagged'];
            }

            if ($add->keywords !== [] || $remove->keywords !== []) {
                $current = $this->client->get($path, ['$select' => 'categories'])['categories'] ?? [];
                $body['categories'] = array_values(array_diff(array_unique([...$current, ...$add->keywords]), $remove->keywords));
            }

            if ($body !== []) {
                $this->client->patch($path, $body);
            }
        });
    }

    public function moveMessage(MessageIdentifier $message, FolderIdentifier $target): ?MessageIdentifier
    {
        return $this->attempt('move message', function () use ($message, $target): ?MessageIdentifier {
            $moved = $this->client->post('messages/'.rawurlencode($message->remoteId).'/move', ['destinationId' => $target->remoteId]);

            return isset($moved['id']) ? new MessageIdentifier($target, (string) $moved['id']) : null;
        });
    }

    public function identifiesFoldersByPath(): bool
    {
        return false;
    }

    public function createFolder(string $name, ?FolderIdentifier $parent): FolderData
    {
        return $this->attempt('create folder', function () use ($name, $parent): FolderData {
            $path = $parent ? 'mailFolders/'.rawurlencode($parent->remoteId).'/childFolders' : 'mailFolders';

            return $this->mapFolder($this->client->post($path, ['displayName' => FolderPath::assertValidName($name, null)]), $parent);
        });
    }

    public function renameFolder(FolderIdentifier $folder, string $newName, ?FolderIdentifier $newParent): FolderData
    {
        return $this->attempt('rename folder', function () use ($folder, $newName, $newParent): FolderData {
            $id = rawurlencode($folder->remoteId);
            $item = $this->client->get("mailFolders/{$id}", ['$select' => 'id,displayName,parentFolderId']);
            $name = FolderPath::assertValidName($newName, null);

            if (($item['displayName'] ?? null) !== $name) {
                $item = $this->client->patch("mailFolders/{$id}", ['displayName' => $name]);
            }

            $destination = $newParent?->remoteId ?? (string) ($this->client->get('mailFolders/msgfolderroot', ['$select' => 'id'])['id'] ?? 'msgfolderroot');

            if (($item['parentFolderId'] ?? null) !== $destination) {
                // Moving may return the folder with a new id.
                $item = $this->client->post("mailFolders/{$id}/move", ['destinationId' => $destination]);
            }

            return $this->mapFolder($item, $newParent);
        });
    }

    public function deleteFolder(FolderIdentifier $folder): void
    {
        // Exchange moves deleted folders to "Deleted Items".
        $this->attempt('delete folder', fn () => $this->client->delete('mailFolders/'.rawurlencode($folder->remoteId)));
    }

    public function subscribeFolder(FolderIdentifier $folder, bool $subscribed): void
    {
        throw UnsupportedOperation::for('subscribe folder');
    }

    public function subscribedFolders(): ?array
    {
        return null;
    }

    public function folderStatistics(FolderIdentifier $folder): FolderStatistics
    {
        return $this->attempt('folder statistics', function () use ($folder): FolderStatistics {
            $item = $this->client->get('mailFolders/'.rawurlencode($folder->remoteId), ['$select' => 'totalItemCount,unreadItemCount']);

            return new FolderStatistics(
                isset($item['totalItemCount']) ? (int) $item['totalItemCount'] : null,
                isset($item['unreadItemCount']) ? (int) $item['unreadItemCount'] : null,
            );
        });
    }

    public function rawMessage(MessageIdentifier $message): string
    {
        return $this->attempt('raw message', fn (): string => $this->client->raw('messages/'.rawurlencode($message->remoteId).'/$value'));
    }

    public function send(string $rawMime, OutgoingMessageData $data): void
    {
        // Graph stores the message in "Sent Items".
        $this->attempt('send', fn () => $this->client->postRaw('sendMail', base64_encode($rawMime), 'text/plain'));
    }

    public function labelSource(): LabelSource
    {
        return LabelSource::GraphCategory;
    }

    public function labels(): array
    {
        return $this->attempt('labels', fn (): array => array_map(
            fn (array $category): LabelData => new LabelData(LabelSource::GraphCategory, (string) $category['displayName'], (string) $category['displayName'], GraphCategoryColor::toLabel($category['color'] ?? null)),
            $this->client->get('outlook/masterCategories')['value'] ?? [],
        ));
    }

    public function createLabel(string $name, ?LabelColor $color = null): LabelData
    {
        return $this->attempt('create category', function () use ($name, $color): LabelData {
            try {
                $this->client->post('outlook/masterCategories', ['displayName' => $name, 'color' => GraphCategoryColor::toPreset($color)]);
            } catch (GraphRequestFailed $exception) {
                // Already exists.
                if ($exception->status !== 409) {
                    throw $exception;
                }
            }

            return new LabelData(LabelSource::GraphCategory, $name, $name, $color);
        });
    }

    /**
     * Category names cannot be changed in Graph; only the colour is updated
     * and the new name is kept locally.
     */
    public function renameLabel(LabelData $label, string $name, ?LabelColor $color = null): LabelData
    {
        return $this->attempt('update category', function () use ($label, $name, $color): LabelData {
            if ($id = $this->categoryId($label->remoteKey)) {
                $this->client->patch('outlook/masterCategories/'.rawurlencode($id), ['color' => GraphCategoryColor::toPreset($color)]);
            }

            return new LabelData($label->source, $label->remoteKey, $name, $color);
        });
    }

    public function deleteLabel(LabelData $label): void
    {
        $this->attempt('delete category', function () use ($label): void {
            if ($id = $this->categoryId($label->remoteKey)) {
                $this->client->delete('outlook/masterCategories/'.rawurlencode($id));
            }
        });
    }

    public function changeLabels(MessageIdentifier $message, array $add, array $remove): void
    {
        $this->setFlags($message, new MessageFlags(keywords: $add), new MessageFlags(keywords: $remove));
    }

    /**
     * @param  array<string, mixed>  $item  message resource from a delta page
     */
    public static function flags(array $item): MessageFlags
    {
        return new MessageFlags(
            seen: (bool) ($item['isRead'] ?? false),
            flagged: ($item['flag']['flagStatus'] ?? null) === 'flagged',
            draft: (bool) ($item['isDraft'] ?? false),
            keywords: array_values(array_map('strval', $item['categories'] ?? [])),
        );
    }

    public function client(): GraphClient
    {
        return $this->client;
    }

    protected function deletePermanently(MessageIdentifier $message): void
    {
        $this->attempt('delete', fn () => $this->client->delete('messages/'.rawurlencode($message->remoteId)));
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    protected function initialDelta(FolderIdentifier $folder, array $headers): array
    {
        return $this->client->get('mailFolders/'.rawurlencode($folder->remoteId).'/messages/delta', ['$select' => static::DELTA_SELECT], $headers);
    }

    /**
     * @return array<string, SpecialUse> Roles keyed by folder id
     */
    protected function wellKnownFolders(): array
    {
        $roles = [];

        foreach (GraphFolderMapper::WELL_KNOWN as $name => $role) {
            try {
                $roles[(string) $this->client->get("mailFolders/{$name}", ['$select' => 'id'])['id']] = $role;
            } catch (GraphRequestFailed $exception) {
                // E.g. no archive folder.
                if (! $exception->isNotFound()) {
                    throw $exception;
                }
            }
        }

        return $roles;
    }

    /**
     * @param  array<string, SpecialUse>  $roles
     * @param  array<int, FolderData>  $folders
     */
    protected function collectFolders(string $path, ?FolderData $parent, array $roles, array &$folders): void
    {
        $page = $this->client->get($path, [
            '$top' => 100,
            '$select' => 'id,displayName,parentFolderId,childFolderCount',
            'includeHiddenFolders' => 'false',
        ]);

        while (true) {
            foreach ($page['value'] ?? [] as $item) {
                $folders[] = $folder = GraphFolderMapper::map($item, $parent, $roles);

                if (($item['childFolderCount'] ?? 0) > 0) {
                    $this->collectFolders('mailFolders/'.rawurlencode((string) $item['id']).'/childFolders', $folder, $roles, $folders);
                }
            }

            if (! isset($page['@odata.nextLink'])) {
                break;
            }

            $page = $this->client->getUrl((string) $page['@odata.nextLink']);
        }
    }

    /**
     * The full name is only the display name here; FolderManager builds the local path.
     *
     * @param  array<string, mixed>  $item
     */
    protected function mapFolder(array $item, ?FolderIdentifier $parent): FolderData
    {
        return new FolderData(
            name: (string) ($item['displayName'] ?? $item['id']),
            fullName: (string) ($item['displayName'] ?? $item['id']),
            delimiter: '/',
            parentRemoteId: $parent?->remoteId,
            remoteId: (string) $item['id'],
        );
    }

    protected function categoryId(string $name): ?string
    {
        foreach ($this->client->get('outlook/masterCategories')['value'] ?? [] as $category) {
            if (strcasecmp((string) $category['displayName'], $name) === 0) {
                return (string) $category['id'];
            }
        }

        return null;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function attempt(string $operation, Closure $callback, bool $connection = false): mixed
    {
        try {
            return $callback();
        } catch (ConnectionFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $connection
                ? ConnectionFailed::for($this->mailbox, $exception)
                : ProviderOperationFailed::for($this->mailbox, $operation, $exception);
        }
    }
}
