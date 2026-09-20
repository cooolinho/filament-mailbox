<?php

namespace Cooolinho\FilamentMailbox\Tests\Contracts;

use Cooolinho\FilamentMailbox\Contracts\MailboxProvider;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Data\SyncCursor;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Support\Str;

/**
 * Behaviour every mailbox provider must fulfil. Concrete tests provide the
 * provider and two existing, empty folders.
 */
abstract class MailboxProviderContractTest extends TestCase
{
    /** @var array<string, array<string, bool>> Remote ids already seen per folder */
    protected array $known = [];

    abstract protected function provider(): MailboxProvider;

    abstract protected function inbox(): FolderIdentifier;

    abstract protected function otherFolder(): FolderIdentifier;

    /**
     * Put a message on the server without going through the provider.
     */
    abstract protected function seedMessage(FolderIdentifier $folder, string $rawMime): void;

    public function test_capabilities_are_consistent(): void
    {
        $provider = $this->provider();

        foreach (ProviderCapability::cases() as $capability) {
            $this->assertSame(in_array($capability, $provider->capabilities(), true), $provider->supports($capability));
        }
    }

    public function test_it_connects_and_lists_folders(): void
    {
        $provider = $this->provider();
        $provider->testConnection();

        $remoteIds = collect($provider->folders())->map(fn (FolderData $folder): string => $folder->remoteId);

        $this->assertContains($this->inbox()->remoteId, $remoteIds);
        $this->assertContains($this->otherFolder()->remoteId, $remoteIds);
    }

    public function test_changes_are_incremental(): void
    {
        $provider = $this->provider();
        [, , , $cursor] = $this->drain($provider, $this->inbox(), new SyncCursor);

        $this->seedMessage($this->inbox(), $this->mime($subject = 'Contract '.Str::random(6)));

        [$created, , , $cursor] = $this->drain($provider, $this->inbox(), $cursor);

        $this->assertSame([$subject], array_map(fn (MessageData $message): ?string => $message->subject, $created));
        $this->assertFalse($created[0]->flags->seen);

        [$created] = $this->drain($provider, $this->inbox(), $cursor);

        $this->assertSame([], $created);
    }

    public function test_flags_are_set_and_reported(): void
    {
        $provider = $this->provider();
        [$message, $cursor] = $this->seedAndFetch($provider, $this->inbox());

        $provider->markRead($message);
        $provider->setFlags($message, new MessageFlags(flagged: true), new MessageFlags);

        [, $updated] = $this->drain($provider, $this->inbox(), $cursor);

        $this->assertTrue($updated[$message->remoteId]->seen);
        $this->assertTrue($updated[$message->remoteId]->flagged);

        $provider->markUnread($message);
        $provider->setFlags($message, new MessageFlags, new MessageFlags(flagged: true));

        [, $updated] = $this->drain($provider, $this->inbox(), $cursor);

        $this->assertFalse($updated[$message->remoteId]->seen);
        $this->assertFalse($updated[$message->remoteId]->flagged);
    }

    public function test_messages_can_be_moved(): void
    {
        $provider = $this->provider();

        if (! $provider->supports(ProviderCapability::MoveMessages)) {
            $this->markTestSkipped('Provider does not move messages.');
        }

        [, , , $targetCursor] = $this->drain($provider, $this->otherFolder(), new SyncCursor);
        [$message, $cursor] = $this->seedAndFetch($provider, $this->inbox(), $subject = 'Move '.Str::random(6));

        $moved = $provider->moveMessage($message, $this->otherFolder());

        [$created] = $this->drain($provider, $this->otherFolder(), $targetCursor);

        $this->assertSame([$subject], array_map(fn (MessageData $data): ?string => $data->subject, $created));

        if ($moved) {
            $this->assertTrue($moved->folder->is($this->otherFolder()));
            $this->assertSame($moved->remoteId, $created[0]->remoteId);
        }

        [, $updated, $deleted, , $snapshot] = $this->drain($provider, $this->inbox(), $cursor);

        $this->assertTrue(in_array($message->remoteId, $deleted, true) || ($snapshot && ! isset($updated[$message->remoteId])));
    }

    public function test_messages_can_be_deleted_permanently(): void
    {
        $provider = $this->provider();

        if (! $provider->supports(ProviderCapability::PermanentDelete)) {
            $this->markTestSkipped('Provider does not delete permanently.');
        }
        [$message, $cursor] = $this->seedAndFetch($provider, $this->inbox());

        $provider->delete($message);

        [, $updated, $deleted, , $snapshot] = $this->drain($provider, $this->inbox(), $cursor);

        $this->assertTrue(in_array($message->remoteId, $deleted, true) || ($snapshot && ! isset($updated[$message->remoteId])));
    }

    public function test_messages_can_be_appended_and_read_raw(): void
    {
        $provider = $this->provider();

        if (! $provider->supports(ProviderCapability::AppendMessages)) {
            $this->markTestSkipped('Provider does not append messages.');
        }

        [, , , $cursor] = $this->drain($provider, $this->otherFolder(), new SyncCursor);

        $identifier = $provider->appendMessage($this->otherFolder(), $this->mime($subject = 'Append '.Str::random(6)), new MessageFlags(seen: true));

        [$created] = $this->drain($provider, $this->otherFolder(), $cursor);

        $this->assertSame([$subject], array_map(fn (MessageData $data): ?string => $data->subject, $created));
        $this->assertTrue($created[0]->flags->seen);

        $identifier ??= new MessageIdentifier($this->otherFolder(), $created[0]->remoteId);

        $this->assertSame($created[0]->remoteId, $identifier->remoteId);
        $this->assertStringContainsString("Subject: {$subject}", $provider->rawMessage($identifier));
    }

    /**
     * @return array{MessageIdentifier, SyncCursor}
     */
    protected function seedAndFetch(MailboxProvider $provider, FolderIdentifier $folder, ?string $subject = null): array
    {
        [, , , $cursor] = $this->drain($provider, $folder, new SyncCursor);

        $this->seedMessage($folder, $this->mime($subject ?? 'Contract '.Str::random(6)));

        [$created, , , $cursor] = $this->drain($provider, $folder, $cursor);

        $this->assertCount(1, $created);

        return [new MessageIdentifier($folder, $created[0]->remoteId), $cursor];
    }

    /**
     * Fetch changes until the provider has no more.
     *
     * @return array{array<int, MessageData>, array<string, MessageFlags>, array<int, string>, SyncCursor, bool}
     */
    protected function drain(MailboxProvider $provider, FolderIdentifier $folder, SyncCursor $cursor): array
    {
        $created = $updated = $deleted = [];
        $snapshot = false;

        do {
            $changes = $provider->changes($folder, $cursor, 10);
            $created = [...$created, ...$changes->created];

            // Delta providers report new messages among the updated ones (like SyncService handles it).
            if ($changes->includesNew) {
                $new = array_values(array_filter(array_map('strval', array_keys($changes->updated)), fn (string $id): bool => ! isset($this->known[$folder->remoteId][$id])));
                $created = [...$created, ...array_map(
                    fn (MessageData $data): MessageData => $data->with(['flags' => $changes->updated[$data->remoteId]]),
                    $new === [] ? [] : $provider->fetchMessages($folder, $new),
                )];
            }

            foreach ($created as $message) {
                $this->known[$folder->remoteId][$message->remoteId] = true;
            }

            $updated = [...$updated, ...$changes->updated];
            $deleted = [...$deleted, ...$changes->deleted];
            $snapshot = $changes->snapshot;
            $cursor = $changes->cursor;
        } while ($changes->hasMore);

        return [$created, $updated, $deleted, $cursor, $snapshot];
    }

    protected function mime(string $subject): string
    {
        return implode("\r\n", [
            'From: John <john@example.com>',
            'To: jane@example.com',
            "Subject: {$subject}",
            'Message-ID: <'.Str::uuid().'@example.com>',
            'Date: '.now()->toRfc2822String(),
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Hello',
            '',
        ]);
    }
}
