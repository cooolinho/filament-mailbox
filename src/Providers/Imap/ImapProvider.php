<?php

namespace Cooolinho\FilamentMailbox\Providers\Imap;

use Closure;
use Cooolinho\FilamentMailbox\Contracts\ManagesFolders;
use Cooolinho\FilamentMailbox\Contracts\SupportsLabels;
use Cooolinho\FilamentMailbox\Data\FolderChanges;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Data\FolderStatistics;
use Cooolinho\FilamentMailbox\Data\LabelData;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Data\SyncCursor;
use Cooolinho\FilamentMailbox\Enums\Encryption;
use Cooolinho\FilamentMailbox\Enums\LabelColor;
use Cooolinho\FilamentMailbox\Enums\LabelSource;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Exceptions\ConnectionFailed;
use Cooolinho\FilamentMailbox\Exceptions\ProviderOperationFailed;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;
use Cooolinho\FilamentMailbox\Models\Mailbox as MailboxModel;
use Cooolinho\FilamentMailbox\OAuth\OAuthTokenManager;
use Cooolinho\FilamentMailbox\Providers\AbstractMailboxProvider;
use Cooolinho\FilamentMailbox\Providers\MimeMessageMapper;
use Cooolinho\FilamentMailbox\Support\FolderPath;
use Cooolinho\FilamentMailbox\Support\ImapKeyword;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Streams\ImapStream;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\Message;
use DirectoryTree\ImapEngine\MessageInterface;
use RuntimeException;
use Throwable;

/**
 * IMAP provider. Message remote ids have the form "<uidvalidity>:<uid>", so
 * identities stay unique across UIDVALIDITY changes.
 */
class ImapProvider extends AbstractMailboxProvider implements ManagesFolders, SupportsLabels
{
    protected ?MailboxInterface $connection = null;

    public function __construct(
        protected MailboxModel $mailbox,
        protected ?MailboxInterface $client = null,
    ) {}

    public function capabilities(): array
    {
        return ProviderType::Imap->capabilities();
    }

    public function testConnection(): void
    {
        $this->connection();
    }

    public function folders(): iterable
    {
        return $this->attempt('folders', function (): array {
            return $this->connection()
                ->folders()
                ->get()
                ->map(fn (FolderInterface $folder): FolderData => ImapFolderMapper::map($folder))
                ->all();
        });
    }

    /**
     * New messages are fetched by UID in chunks. Once the folder is caught up,
     * the flags of all messages are returned as a snapshot, which also reveals
     * messages deleted on the server.
     */
    public function changes(FolderIdentifier $folder, SyncCursor $cursor, int $limit): FolderChanges
    {
        return $this->attempt('fetch changes', function () use ($folder, $cursor, $limit): FolderChanges {
            $imapFolder = $this->folder($folder);
            $uidValidity = $this->uidValidity($imapFolder);

            $known = $cursor->get('uid_validity');
            $reset = $known !== null && (int) $known !== $uidValidity;
            $lastUid = $reset ? 0 : (int) $cursor->get('last_uid', 0);

            $messages = $imapFolder->messages()
                ->uid($lastUid + 1, INF)
                ->oldest()
                ->leaveUnread()
                ->withHeaders()
                ->withBody()
                ->withFlags()
                ->limit($limit)
                ->get()
                // "UID n:*" always matches the highest UID, even when it is below n.
                ->filter(fn (MessageInterface $message): bool => $message->uid() > $lastUid)
                ->values();

            $created = $messages
                ->map(fn (Message $message) => MimeMessageMapper::map(
                    $message,
                    static::remoteId($uidValidity, $message->uid()),
                    MessageFlags::fromImap($message->flags()),
                ))
                ->all();

            $lastUid = max($lastUid, (int) $messages->max(fn (MessageInterface $message): int => $message->uid()));
            $hasMore = $messages->count() >= $limit;

            return new FolderChanges(
                created: $created,
                updated: $hasMore ? [] : $this->flagSnapshot($imapFolder, $uidValidity),
                deleted: [],
                cursor: $cursor->with(['uid_validity' => $uidValidity, 'last_uid' => $lastUid]),
                hasMore: $hasMore,
                reset: $reset,
                snapshot: ! $hasMore,
            );
        });
    }

    public function isStale(MessageIdentifier $message, SyncCursor $cursor): bool
    {
        [$uidValidity] = static::parseRemoteId($message->remoteId);

        return $cursor->get('uid_validity') !== null && (int) $cursor->get('uid_validity') !== $uidValidity;
    }

    public function setFlags(MessageIdentifier $message, MessageFlags $add, MessageFlags $remove): void
    {
        $this->attempt('set flags', function () use ($message, $add, $remove): void {
            $uid = $this->uid($message);
            $connection = $this->connection()->connection();

            foreach (['+' => $add, '-' => $remove] as $mode => $flags) {
                if (! $flags->isEmpty()) {
                    $connection->store($flags->toImap(), $uid, mode: $mode);
                }
            }
        });
    }

    public function moveMessage(MessageIdentifier $message, FolderIdentifier $target): ?MessageIdentifier
    {
        return $this->attempt('move message', function () use ($message, $target): ?MessageIdentifier {
            $source = $this->folder($message->folder);
            $uid = $this->uid($message, $source);

            try {
                $newUid = $this->find($source, $uid)->move($target->remoteId, expunge: true);
            } catch (ImapCapabilityException) {
                // Neither MOVE nor UIDPLUS: copy, flag as deleted and expunge. The new UID is unknown.
                $this->connection()->connection()->copy($target->remoteId, $uid);
                $source->messages()->destroy($uid, expunge: true);

                return null;
            }

            return $newUid
                ? new MessageIdentifier($target, static::remoteId($this->uidValidity($this->folder($target)), $newUid))
                : null;
        });
    }

    public function appendMessage(FolderIdentifier $folder, string $rawMime, MessageFlags $flags = new MessageFlags): ?MessageIdentifier
    {
        return $this->attempt('append message', function () use ($folder, $rawMime, $flags): ?MessageIdentifier {
            $imapFolder = $this->folder($folder);
            $uid = rescue(fn () => $imapFolder->messages()->append($rawMime, $flags->toImap() ?: null), null, report: false);

            // Servers without UIDPLUS do not report the new UID.
            return $uid ? new MessageIdentifier($folder, static::remoteId($this->uidValidity($imapFolder), $uid)) : null;
        });
    }

    public function rawMessage(MessageIdentifier $message): string
    {
        return $this->attempt('raw message', function () use ($message): string {
            $folder = $this->folder($message->folder);

            return (string) $this->find($folder, $this->uid($message, $folder), withContent: true);
        });
    }

    public function identifiesFoldersByPath(): bool
    {
        return true;
    }

    public function createFolder(string $name, ?FolderIdentifier $parent): FolderData
    {
        return $this->attempt('create folder', function () use ($name, $parent): FolderData {
            [$delimiter, $prefix] = $this->hierarchy();
            $path = FolderPath::imap($name, $parent?->remoteId, $delimiter, $prefix);

            $this->connection()->connection()->create($path);

            // Servers do not always subscribe new folders; other clients may only show subscribed ones.
            rescue(fn () => $this->connection()->connection()->subscribe($path), report: false);

            return $this->folderData($path);
        });
    }

    public function renameFolder(FolderIdentifier $folder, string $newName, ?FolderIdentifier $newParent): FolderData
    {
        return $this->attempt('rename folder', function () use ($folder, $newName, $newParent): FolderData {
            [$delimiter, $prefix] = $this->hierarchy();
            $path = FolderPath::imap($newName, $newParent?->remoteId, $delimiter, $prefix);

            if (strcasecmp($folder->remoteId, 'INBOX') === 0) {
                throw new RuntimeException('The INBOX cannot be renamed.');
            }

            if ($path !== $folder->remoteId) {
                // RENAME moves the descendants as well.
                $this->connection()->connection()->rename($folder->remoteId, $path);

                rescue(function () use ($folder, $path): void {
                    $this->connection()->connection()->unsubscribe($folder->remoteId);
                    $this->connection()->connection()->subscribe($path);
                }, report: false);
            }

            return $this->folderData($path);
        });
    }

    public function deleteFolder(FolderIdentifier $folder): void
    {
        $this->attempt('delete folder', function () use ($folder): void {
            if (strcasecmp($folder->remoteId, 'INBOX') === 0) {
                throw new RuntimeException('The INBOX cannot be deleted.');
            }

            // A selected folder cannot be deleted on every server.
            rescue(fn () => $this->connection()->connection()->unsubscribe($folder->remoteId), report: false);
            $this->connection()->connection()->delete($folder->remoteId);
        });
    }

    public function subscribeFolder(FolderIdentifier $folder, bool $subscribed): void
    {
        $this->attempt('subscribe folder', function () use ($folder, $subscribed): void {
            $subscribed
                ? $this->connection()->connection()->subscribe($folder->remoteId)
                : $this->connection()->connection()->unsubscribe($folder->remoteId);
        });
    }

    public function subscribedFolders(): ?array
    {
        return $this->attempt('subscribed folders', function (): ?array {
            $connection = $this->connection()->connection();

            if (! $connection instanceof ImapClientConnection) {
                return null;
            }

            return $connection->lsub()
                ->map(fn (UntaggedResponse $response): string => (string) $response->tokenAt(4)?->value)
                ->filter()
                ->values()
                ->all();
        });
    }

    public function folderStatistics(FolderIdentifier $folder): FolderStatistics
    {
        return $this->attempt('folder statistics', function () use ($folder): FolderStatistics {
            $mailbox = $this->connection();
            $arguments = ['MESSAGES', 'UNSEEN'];

            // RFC 8438
            if (in_array('STATUS=SIZE', $mailbox->capabilities(), true)) {
                $arguments[] = 'SIZE';
            }

            $tokens = $mailbox->connection()->status($folder->remoteId, $arguments)->tokenAt(3)?->tokens() ?? [];
            $values = [];

            for ($i = 0; $i + 1 < count($tokens); $i += 2) {
                $values[strtoupper((string) $tokens[$i]->value)] = (int) $tokens[$i + 1]->value;
            }

            return new FolderStatistics($values['MESSAGES'] ?? null, $values['UNSEEN'] ?? null, $values['SIZE'] ?? null);
        });
    }

    public function labelSource(): LabelSource
    {
        return LabelSource::ImapKeyword;
    }

    /**
     * Keywords defined in the INBOX (FLAGS and PERMANENTFLAGS of SELECT/EXAMINE).
     */
    public function labels(): array
    {
        return $this->attempt('labels', function (): array {
            [$flags, $permanent] = $this->inboxFlags();

            return collect([...$flags, ...$permanent])
                ->reject(fn (string $flag): bool => ImapKeyword::isHidden($flag))
                ->unique(fn (string $flag): string => strtolower($flag))
                ->map(fn (string $keyword): LabelData => new LabelData(LabelSource::ImapKeyword, $keyword, ImapKeyword::toName($keyword)))
                ->values()
                ->all();
        });
    }

    public function createLabel(string $name, ?LabelColor $color = null): LabelData
    {
        $keyword = ImapKeyword::fromName($name);

        $allowed = $this->attempt('labels', function (): bool {
            [, $permanent] = $this->inboxFlags();

            return in_array('\*', $permanent, true);
        });

        // Keywords only exist on messages; the server must allow new ones.
        if (! $allowed) {
            throw UnsupportedOperation::for('create keyword', ProviderCapability::Keywords);
        }

        return new LabelData(LabelSource::ImapKeyword, $keyword, $name, $color);
    }

    public function renameLabel(LabelData $label, string $name, ?LabelColor $color = null): LabelData
    {
        // The keyword stays; the display name is kept locally.
        return new LabelData($label->source, $label->remoteKey, $name, $color);
    }

    public function deleteLabel(LabelData $label): void
    {
        //
    }

    public function changeLabels(MessageIdentifier $message, array $add, array $remove): void
    {
        foreach ([...$add, ...$remove] as $keyword) {
            if (! ImapKeyword::isValid($keyword)) {
                throw new RuntimeException('Invalid IMAP keyword.');
            }
        }

        $this->setFlags($message, new MessageFlags(keywords: $add), new MessageFlags(keywords: $remove));
    }

    public function disconnect(): void
    {
        $this->connection?->disconnect();
        $this->connection = null;
    }

    public static function remoteId(int $uidValidity, int $uid): string
    {
        return $uidValidity.':'.$uid;
    }

    /**
     * @return array{int, int} UIDVALIDITY and UID
     */
    public static function parseRemoteId(string $remoteId): array
    {
        if (! preg_match('/^(\d+):(\d+)$/', $remoteId, $matches)) {
            throw new RuntimeException("Invalid IMAP message id [{$remoteId}].");
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    protected function deletePermanently(MessageIdentifier $message): void
    {
        $this->attempt('delete', function () use ($message): void {
            $folder = $this->folder($message->folder);

            $folder->messages()->destroy($this->uid($message, $folder), expunge: true);
        });
    }

    protected function connection(): MailboxInterface
    {
        if ($this->connection) {
            return $this->connection;
        }

        try {
            $config = $this->config();
        } catch (ConnectionFailed $exception) {
            // E.g. a revoked OAuth connection that must be reconnected.
            throw $exception;
        } catch (Throwable $exception) {
            throw ConnectionFailed::for($this->mailbox, $exception);
        }

        try {
            $connection = $this->client ?? new Mailbox($config);
            // Injected clients (tests) bring their own connection.
            $connection->connect($this->client ? null : new ImapClientConnection(new ImapStream));
        } catch (Throwable $exception) {
            throw ConnectionFailed::for($this->mailbox, $exception);
        }

        return $this->connection = $connection;
    }

    /**
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        $base = [
            'host' => $this->mailbox->host,
            'port' => $this->mailbox->port,
            'encryption' => $this->mailbox->encryption === Encryption::None ? null : $this->mailbox->encryption->value,
            'validate_cert' => $this->mailbox->validate_cert,
            'username' => $this->mailbox->username,
            'password' => $this->mailbox->password,
            'timeout' => (int) config('filament-mailbox.imap.timeout', 30),
        ];

        if (! $this->mailbox->usesOAuth()) {
            return $base;
        }

        if (! $this->mailbox->oauthConnection) {
            throw new ConnectionFailed('The mailbox has no OAuth connection. Connect an account first.');
        }

        return [
            ...$base,
            'authentication' => 'oauth',
            'username' => $this->mailbox->username ?: $this->mailbox->email,
            'password' => app(OAuthTokenManager::class)->accessToken($this->mailbox->oauthConnection),
        ];
    }

    /**
     * Folder by its raw (already UTF-7 encoded) path. FolderRepository::find()
     * would encode the path a second time.
     */
    protected function folder(FolderIdentifier $folder): FolderInterface
    {
        $mailbox = $this->connection();

        $response = $mailbox->connection()->list('', $folder->remoteId)
            ->first(fn (UntaggedResponse $response): bool => $response->tokenAt(4)?->value === $folder->remoteId);

        if (! $response) {
            throw new RuntimeException("Folder [{$folder->remoteId}] not found.");
        }

        return new Folder(
            mailbox: $mailbox,
            path: $response->tokenAt(4)->value,
            flags: $response->tokenAt(2)->values(),
            delimiter: (string) $response->tokenAt(3)->value,
        );
    }

    protected function uidValidity(FolderInterface $folder): int
    {
        return (int) ($folder->status()['UIDVALIDITY'] ?? 0);
    }

    /**
     * Resolve the UID of a message and select its folder.
     */
    protected function uid(MessageIdentifier $message, ?FolderInterface $folder = null): int
    {
        [$uidValidity, $uid] = static::parseRemoteId($message->remoteId);

        $folder ??= $this->folder($message->folder);

        if ($uidValidity !== $this->uidValidity($folder)) {
            throw new RuntimeException('The message identifier is outdated (UIDVALIDITY changed).');
        }

        $folder->select(force: true);

        return $uid;
    }

    protected function find(FolderInterface $folder, int $uid, bool $withContent = false): Message
    {
        $query = $folder->messages()->leaveUnread();

        if ($withContent) {
            $query->withHeaders()->withBody();
        }

        $message = $query->find($uid);

        if (! $message instanceof Message) {
            throw new RuntimeException("Message with UID {$uid} not found.");
        }

        return $message;
    }

    /**
     * Hierarchy delimiter and personal namespace prefix of the server.
     *
     * @return array{string, string}
     */
    protected function hierarchy(): array
    {
        $folders = $this->connection()->connection()->list('', '*');

        $delimiter = (string) ($folders->map(fn (UntaggedResponse $response) => $response->tokenAt(3)?->value)->filter()->first() ?? '/');
        $paths = $folders->map(fn (UntaggedResponse $response): string => (string) $response->tokenAt(4)?->value)->filter()->values()->all();

        return [$delimiter, FolderPath::namespacePrefix($paths, $delimiter)];
    }

    protected function folderData(string $path): FolderData
    {
        return ImapFolderMapper::map($this->folder(new FolderIdentifier($path)));
    }

    /**
     * @return array{array<int, string>, array<int, string>} FLAGS and PERMANENTFLAGS of the INBOX
     */
    protected function inboxFlags(): array
    {
        $flags = $permanent = [];

        foreach ($this->connection()->inbox()->examine() as $response) {
            if (($response[1] ?? null) === 'FLAGS' && is_array($response[2] ?? null)) {
                $flags = $response[2];
            }

            if (($response[1] ?? null) === 'OK' && is_array($response[2] ?? null) && ($response[2][0] ?? null) === 'PERMANENTFLAGS') {
                $permanent = (array) ($response[2][1] ?? []);
            }
        }

        // EXAMINE deselects the folder; operations select their folder again.
        return [array_map('strval', $flags), array_map('strval', $permanent)];
    }

    /**
     * @return array<string, MessageFlags>
     */
    protected function flagSnapshot(FolderInterface $folder, int $uidValidity): array
    {
        return $folder->messages()
            ->uid(1, INF)
            ->withFlags()
            ->get()
            ->mapWithKeys(fn (MessageInterface $message): array => [
                static::remoteId($uidValidity, $message->uid()) => MessageFlags::fromImap($message->flags()),
            ])
            ->all();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function attempt(string $operation, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ConnectionFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ProviderOperationFailed::for($this->mailbox, $operation, $exception);
        }
    }
}
