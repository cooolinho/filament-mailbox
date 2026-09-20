<?php

namespace Cooolinho\FilamentMailbox\Tests\Integration;

use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Data\MessageIdentifier;
use Cooolinho\FilamentMailbox\Data\SyncCursor;
use Cooolinho\FilamentMailbox\Enums\Encryption;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Exceptions\ConnectionFailed;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Providers\Imap\ImapProvider;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use DirectoryTree\ImapEngine\Mailbox as ImapMailbox;
use Illuminate\Support\Str;

/**
 * Runs against a real IMAP server (GreenMail from the sandbox docker-compose).
 * Skipped when the server is not reachable.
 */
class ImapProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = @fsockopen($this->imapHost(), $this->imapPort(), $errno, $errstr, 1);

        if (! $connection) {
            $this->markTestSkipped("IMAP test server {$this->imapHost()}:{$this->imapPort()} not reachable.");
        }

        fclose($connection);
    }

    public function test_it_connects_and_lists_folders(): void
    {
        $provider = new ImapProvider($this->mailbox());

        $provider->testConnection();

        $folders = collect($provider->folders());
        $inbox = $folders->firstWhere('remoteId', 'INBOX');

        $this->assertNotNull($inbox);
        $this->assertSame(SpecialUse::Inbox, $inbox->specialUse);

        $provider->disconnect();
    }

    public function test_it_fetches_messages_flags_and_deletes(): void
    {
        $inbox = new FolderIdentifier('INBOX');
        $provider = new ImapProvider($this->mailbox());
        $cursor = $this->catchUp($provider, $inbox);

        $uid = $this->appendFixture();

        $changes = $provider->changes($inbox, $cursor, 10);
        $remoteId = ImapProvider::remoteId((int) $changes->cursor->get('uid_validity'), $uid);

        $this->assertCount(1, $changes->created);
        $this->assertSame($remoteId, $changes->created[0]->remoteId);
        $this->assertSame('Projekt Update ✓', $changes->created[0]->subject);
        $this->assertFalse($changes->created[0]->flags->seen);
        $this->assertCount(1, $changes->created[0]->attachments);
        $this->assertSame($uid, $changes->cursor->get('last_uid'));

        // Nothing newer than the last UID: "UID n:*" must not return the last message again.
        $this->assertSame([], $provider->changes($inbox, $changes->cursor, 10)->created);

        $identifier = new MessageIdentifier($inbox, $remoteId);

        $provider->markRead($identifier);
        $this->assertTrue($provider->changes($inbox, $changes->cursor, 10)->updated[$remoteId]->seen);

        $provider->markUnread($identifier);
        $this->assertFalse($provider->changes($inbox, $changes->cursor, 10)->updated[$remoteId]->seen);

        $provider->delete($identifier);
        $this->assertArrayNotHasKey($remoteId, $provider->changes($inbox, $changes->cursor, 10)->updated);

        $provider->disconnect();
    }

    public function test_keywords_are_set_read_and_listed(): void
    {
        $inbox = new FolderIdentifier('INBOX');
        $provider = new ImapProvider($this->mailbox());
        $cursor = $this->catchUp($provider, $inbox);

        $this->appendFixture();
        $changes = $provider->changes($inbox, $cursor, 10);
        $identifier = new MessageIdentifier($inbox, $changes->created[0]->remoteId);

        $keyword = 'Kunde_'.Str::random(6);
        $this->assertSame($keyword, $provider->createLabel(str_replace('_', ' ', $keyword))->remoteKey);

        $provider->changeLabels($identifier, [$keyword, '$label1'], []);

        $updated = $provider->changes($inbox, $changes->cursor, 10)->updated[$identifier->remoteId];
        $this->assertEqualsCanonicalizing([$keyword, '$label1'], $updated->keywords);
        $this->assertContains($keyword, array_map(fn ($label) => $label->remoteKey, $provider->labels()));

        $provider->changeLabels($identifier, [], [$keyword]);
        $this->assertSame(['$label1'], $provider->changes($inbox, $changes->cursor, 10)->updated[$identifier->remoteId]->keywords);

        $provider->delete($identifier);
        $provider->disconnect();
    }

    public function test_stale_identifiers_are_detected(): void
    {
        $provider = new ImapProvider($this->mailbox());
        $identifier = new MessageIdentifier(new FolderIdentifier('INBOX'), '5:12');

        $this->assertFalse($provider->isStale($identifier, new SyncCursor(['uid_validity' => 5])));
        $this->assertTrue($provider->isStale($identifier, new SyncCursor(['uid_validity' => 6])));
        $this->assertFalse($provider->isStale($identifier, new SyncCursor));
    }

    public function test_wrong_password_throws_redacted_connection_failure(): void
    {
        $provider = new ImapProvider($this->mailbox(['password' => 'definitely-wrong']));

        try {
            $provider->testConnection();
            $this->fail('Expected ConnectionFailed.');
        } catch (ConnectionFailed $exception) {
            $this->assertStringNotContainsString('definitely-wrong', $exception->getMessage());
        }
    }

    protected function catchUp(ImapProvider $provider, FolderIdentifier $folder): SyncCursor
    {
        $cursor = new SyncCursor;

        do {
            $changes = $provider->changes($folder, $cursor, 100);
            $cursor = $changes->cursor;
        } while ($changes->hasMore);

        return $cursor;
    }

    protected function appendFixture(): int
    {
        $client = new ImapMailbox([
            'host' => $this->imapHost(),
            'port' => $this->imapPort(),
            'encryption' => null,
            'username' => env('MAILBOX_TEST_IMAP_USERNAME', 'test@example.com'),
            'password' => env('MAILBOX_TEST_IMAP_PASSWORD', 'secret'),
        ]);

        $uid = $client->inbox()->messages()->append(file_get_contents(__DIR__.'/../Fixtures/mails/multipart.eml'));

        $client->disconnect();

        return $uid;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function mailbox(array $attributes = []): Mailbox
    {
        return Mailbox::factory()->make([
            'host' => $this->imapHost(),
            'port' => $this->imapPort(),
            'encryption' => Encryption::None,
            'validate_cert' => false,
            'username' => env('MAILBOX_TEST_IMAP_USERNAME', 'test@example.com'),
            'password' => env('MAILBOX_TEST_IMAP_PASSWORD', 'secret'),
            ...$attributes,
        ]);
    }

    protected function imapHost(): string
    {
        return (string) env('MAILBOX_TEST_IMAP_HOST', 'sandbox-greenmail');
    }

    protected function imapPort(): int
    {
        return (int) env('MAILBOX_TEST_IMAP_PORT', 3143);
    }
}
