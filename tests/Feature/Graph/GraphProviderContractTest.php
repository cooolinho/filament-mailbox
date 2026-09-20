<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature\Graph;

use Cooolinho\FilamentMailbox\Contracts\MailboxProvider;
use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Enums\AuthMode;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Cooolinho\FilamentMailbox\Providers\Graph\GraphProvider;
use Cooolinho\FilamentMailbox\Tests\Contracts\MailboxProviderContractTest;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeGraphServer;
use Illuminate\Support\Str;

class GraphProviderContractTest extends MailboxProviderContractTest
{
    protected FakeGraphServer $server;

    protected string $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = (new FakeGraphServer)->fake();
        $this->archive = $this->server->addFolder('Archive');
    }

    protected function provider(): MailboxProvider
    {
        return new GraphProvider(Mailbox::factory()->create([
            'provider' => 'graph',
            'auth_mode' => AuthMode::OAuth,
            'oauth_connection_id' => OAuthConnection::factory()->create()->id,
            'host' => null,
            'port' => null,
            'encryption' => null,
            'username' => null,
            'password' => null,
        ]));
    }

    protected function inbox(): FolderIdentifier
    {
        return new FolderIdentifier($this->server->folderId('inbox'));
    }

    protected function otherFolder(): FolderIdentifier
    {
        return new FolderIdentifier($this->archive);
    }

    protected function seedMessage(FolderIdentifier $folder, string $rawMime): void
    {
        preg_match('/^Subject: (.*)$/m', $rawMime, $matches);

        $this->server->addMessage($folder->remoteId, trim($matches[1] ?? Str::random()), ['raw' => $rawMime]);
    }
}
