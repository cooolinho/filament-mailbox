<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature\Gmail;

use Cooolinho\FilamentMailbox\Contracts\MailboxProvider;
use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Enums\AuthMode;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Cooolinho\FilamentMailbox\Providers\Gmail\GmailLabelMapper;
use Cooolinho\FilamentMailbox\Providers\Gmail\GmailProvider;
use Cooolinho\FilamentMailbox\Tests\Contracts\MailboxProviderContractTest;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeGmailServer;
use Illuminate\Support\Sleep;

class GmailProviderContractTest extends MailboxProviderContractTest
{
    protected FakeGmailServer $server;

    protected ?GmailProvider $provider = null;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->server = (new FakeGmailServer)->fake();
    }

    protected function provider(): MailboxProvider
    {
        return $this->provider ??= new GmailProvider(Mailbox::factory()->create([
            'provider' => 'gmail',
            'auth_mode' => AuthMode::OAuth,
            'oauth_connection_id' => OAuthConnection::factory()->for(OAuthApplication::factory()->google(), 'application')->create()->id,
            'host' => null, 'port' => null, 'encryption' => null, 'username' => null, 'password' => null,
        ]));
    }

    protected function inbox(): FolderIdentifier
    {
        return new FolderIdentifier('INBOX');
    }

    protected function otherFolder(): FolderIdentifier
    {
        return new FolderIdentifier(GmailLabelMapper::ARCHIVE);
    }

    protected function seedMessage(FolderIdentifier $folder, string $rawMime): void
    {
        $this->server->addMessage(raw: $rawMime, labelIds: $folder->remoteId === GmailLabelMapper::ARCHIVE ? ['UNREAD'] : [$folder->remoteId, 'UNREAD']);
    }
}
