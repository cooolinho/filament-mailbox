<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature\Graph;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Data\SyncResult;
use Cooolinho\FilamentMailbox\Enums\AuthMode;
use Cooolinho\FilamentMailbox\Enums\LabelColor;
use Cooolinho\FilamentMailbox\Enums\OAuthGrantType;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Exceptions\ConnectionFailed;
use Cooolinho\FilamentMailbox\Mail\Transports\ProviderTransport;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxLabel;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Cooolinho\FilamentMailbox\Providers\DefaultMailboxProviderFactory;
use Cooolinho\FilamentMailbox\Providers\Graph\GraphProvider;
use Cooolinho\FilamentMailbox\Services\LabelService;
use Cooolinho\FilamentMailbox\Services\MailSender;
use Cooolinho\FilamentMailbox\Services\MessageService;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeGraphServer;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

class GraphProviderTest extends TestCase
{
    protected FakeGraphServer $server;

    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Sleep::fake();

        $this->app->bind(MailboxProviderFactory::class, DefaultMailboxProviderFactory::class);
        $this->server = (new FakeGraphServer)->fake();
        $this->mailbox = $this->graphMailbox();
    }

    public function test_folders_are_mapped_with_roles_and_hierarchy(): void
    {
        $customers = $this->server->addFolder('Customers');
        $acme = $this->server->addFolder('Acme', parent: $customers);

        $folders = collect((new GraphProvider($this->mailbox))->folders())->keyBy('remoteId');

        $this->assertSame(SpecialUse::Inbox, $folders[$this->server->folderId('inbox')]->specialUse);
        $this->assertSame(SpecialUse::Trash, $folders[$this->server->folderId('deleteditems')]->specialUse);
        $this->assertNull($folders[$customers]->specialUse);
        $this->assertSame('Customers/Acme', $folders[$acme]->fullName);
        $this->assertSame($customers, $folders[$acme]->parentRemoteId);

        $this->assertContains('GET me/mailFolders/archive', $this->server->requests);
    }

    public function test_mailbox_is_synchronised_with_delta_queries(): void
    {
        config(['filament-mailbox.sync.chunk_size' => 2]);

        $first = $this->server->addMessage($this->server->folderId('inbox'), 'First', ['isRead' => true, 'categories' => ['Kunde']]);
        $second = $this->server->addMessage($this->server->folderId('inbox'), 'Second', ['flagStatus' => 'flagged']);
        $third = $this->server->addMessage($this->server->folderId('inbox'), 'Third', ['receivedDateTime' => '2026-09-16T10:00:00Z']);

        $result = $this->sync();

        $this->assertSame([], $result->errors);
        $this->assertSame(3, $result->imported);

        $inbox = MailboxFolder::where('remote_id', $this->server->folderId('inbox'))->sole();
        $this->assertStringContainsString('$deltatoken=', $inbox->sync_cursor['delta_link']);
        $this->assertTrue(collect($this->server->requests)->contains(fn (string $request): bool => str_contains($request, 'messages/delta')));

        $messages = MailboxMessage::get()->keyBy('remote_id');
        $this->assertSame('First', $messages[$first]->subject);
        $this->assertTrue($messages[$first]->is_read);
        $this->assertTrue($messages[$second]->is_flagged);
        $this->assertSame('2026-09-16 10:00:00', $messages[$third]->received_at->utc()->toDateTimeString());
        $this->assertSame(['Kunde'], $messages[$first]->labels()->pluck('remote_key')->all());

        // Incremental: flag change, removal and a new message; no MIME download for known messages.
        $this->server->update($first, ['isRead' => false]);
        $this->server->remove($second);
        $fourth = $this->server->addMessage($this->server->folderId('inbox'), 'Fourth');
        $this->server->requests = [];

        $this->assertSame(1, $this->sync()->imported);

        $this->assertFalse($messages[$first]->refresh()->is_read);
        $this->assertSoftDeleted($messages[$second]);
        $this->assertSame('Fourth', MailboxMessage::where('remote_id', $fourth)->value('subject'));
        $this->assertSame(["GET me/messages/{$fourth}/\$value"], array_values(array_filter($this->server->requests, fn (string $request): bool => str_ends_with($request, '$value'))));
    }

    public function test_expired_delta_token_triggers_a_resync_without_reimport(): void
    {
        $id = $this->server->addMessage($this->server->folderId('inbox'), 'Kept');
        $this->sync();

        $this->server->expiredBelow = PHP_INT_MAX;
        $this->server->requests = [];

        $this->assertSame(1, $this->sync()->imported);

        $message = MailboxMessage::where('remote_id', $id)->sole();
        $this->assertFalse($message->trashed());
        $this->assertSame(1, MailboxMessage::withTrashed()->count());
        $this->assertEmpty(array_filter($this->server->requests, fn (string $request): bool => str_ends_with($request, '$value')));
    }

    public function test_throttling_is_respected(): void
    {
        $this->server->throttle = 2;

        (new GraphProvider($this->mailbox))->testConnection();

        Sleep::assertSleptTimes(2);
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(2)->seconds()]);
    }

    public function test_a_rejected_token_is_renewed_once(): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'renewed-token', 'expires_in' => 3600])]);
        $this->server->unauthorized = 1;

        (new GraphProvider($this->mailbox))->testConnection();

        $this->assertSame(['Bearer access-token-value', 'Bearer renewed-token'], $this->server->tokens);

        $this->server->unauthorized = 5;

        try {
            (new GraphProvider($this->mailbox->refresh()))->testConnection();
            $this->fail('Expected ConnectionFailed.');
        } catch (ConnectionFailed $exception) {
            $this->assertStringNotContainsString('renewed-token', $exception->getMessage());
            $this->assertStringContainsString('Microsoft Graph', $exception->getMessage());
        }
    }

    public function test_application_access_uses_the_users_path(): void
    {
        $this->mailbox->oauthConnection->forceFill(['grant_type' => OAuthGrantType::ClientCredentials])->save();
        $this->mailbox->forceFill(['email' => 'shared@contoso.com'])->save();
        $this->server->mailboxPath = 'users/shared%40contoso.com';

        (new GraphProvider($this->mailbox->refresh()))->testConnection();

        $this->mailbox->forceFill(['remote_user' => 'other@contoso.com'])->save();
        $this->server->mailboxPath = 'users/other%40contoso.com';

        (new GraphProvider($this->mailbox->refresh()))->testConnection();

        $this->assertSame(['GET users/shared%40contoso.com/mailFolders/inbox', 'GET users/other%40contoso.com/mailFolders/inbox'], $this->server->requests);
    }

    public function test_message_actions_are_applied_through_graph(): void
    {
        $id = $this->server->addMessage($this->server->folderId('inbox'), 'Action');
        $this->sync();
        $message = MailboxMessage::where('remote_id', $id)->sole();
        $service = $this->app->make(MessageService::class);

        $service->markRead($message);
        $service->setFlagged($message, true);
        $this->assertTrue($this->server->messages[$id]['isRead']);
        $this->assertSame('flagged', $this->server->messages[$id]['flagStatus']);

        $archive = MailboxFolder::where('remote_id', $this->server->folderId('junkemail'))->sole();
        $service->move($message, $archive);

        $this->assertSame($this->server->folderId('junkemail'), $this->server->messages[$id]['folder']);
        $this->assertSame($archive->id, $message->refresh()->folder_id);
        // Immutable ids survive the move.
        $this->assertSame($id, $message->remote_id);

        $service->delete($message);
        $this->assertSame($this->server->folderId('deleteditems'), $this->server->messages[$id]['folder']);
    }

    public function test_categories_are_managed_as_labels(): void
    {
        $this->server->categories[] = ['id' => 'cat-1', 'displayName' => 'Kunde', 'color' => 'preset7'];
        $id = $this->server->addMessage($this->server->folderId('inbox'), 'Labelled');
        $this->sync();

        $kunde = MailboxLabel::where('remote_key', 'Kunde')->sole();
        $this->assertSame(LabelColor::Blue, $kunde->color);

        $labels = $this->app->make(LabelService::class);
        $new = $labels->create($this->mailbox, 'Rechnung', LabelColor::Red);
        $this->assertSame('preset0', collect($this->server->categories)->firstWhere('displayName', 'Rechnung')['color']);

        $message = MailboxMessage::where('remote_id', $id)->sole();
        $labels->attach($message, $kunde);
        $labels->attach($message, $new);
        $this->assertSame(['Kunde', 'Rechnung'], $this->server->messages[$id]['categories']);

        $labels->delete($new);
        $this->assertNull(collect($this->server->categories)->firstWhere('displayName', 'Rechnung'));
    }

    public function test_mail_is_sent_through_graph_with_threading_and_bcc(): void
    {
        $sender = $this->app->make(MailSender::class);

        $this->assertInstanceOf(ProviderTransport::class, $sender->transportFor($this->mailbox));

        $sender->send($this->mailbox, new OutgoingMessageData(
            to: ['jane@example.com'],
            subject: 'Re: Hello',
            body: 'Reply body',
            bcc: ['audit@example.com'],
            inReplyTo: 'parent@example.com',
            references: ['root@example.com', 'parent@example.com'],
        ));

        $raw = $this->server->sent[0];
        $this->assertStringContainsString('Subject: Re: Hello', $raw);
        $this->assertStringContainsString('Bcc: audit@example.com', $raw);
        $this->assertStringContainsString('In-Reply-To: <parent@example.com>', $raw);
        $this->assertStringContainsString('<root@example.com> <parent@example.com>', $raw);
        $this->assertStringContainsString('From: '.$this->mailbox->name.' <info@contoso.com>', $raw);
    }

    public function test_graph_capabilities(): void
    {
        $this->assertTrue($this->mailbox->supports(ProviderCapability::ServerSideSend));
        $this->assertFalse($this->mailbox->supports(ProviderCapability::AppendMessages));
        $this->assertInstanceOf(GraphProvider::class, $this->app->make(MailboxProviderFactory::class)->make($this->mailbox));
    }

    protected function sync(): SyncResult
    {
        return $this->app->make(SyncService::class)->syncMailbox($this->mailbox);
    }

    public function test_folders_are_managed_through_the_graph_api(): void
    {
        $customers = $this->server->addFolder('Customers');
        app(SyncService::class)->syncFolders($this->mailbox, new GraphProvider($this->mailbox));

        $manager = app(\Cooolinho\FilamentMailbox\Services\FolderManager::class);
        $parent = $this->mailbox->folders()->where('remote_id', $customers)->sole();

        $acme = $manager->create($this->mailbox, 'Acme', $parent);
        $child = $manager->create($this->mailbox, '2026', $acme);

        $this->assertSame('Customers/Acme', $acme->full_name);
        $this->assertSame($customers, $this->server->folders[$acme->remote_id]['parent']);

        $manager->rename($acme, 'Acme Corp');

        $this->assertSame('Acme Corp', $this->server->folders[$acme->remote_id]['displayName']);
        $this->assertSame('Customers/Acme Corp/2026', $child->refresh()->full_name);
        $this->assertSame($child->remote_id, array_key_last($this->server->folders));

        $manager->move($acme->refresh(), null);

        $this->assertNull($this->server->folders[$acme->remote_id]['parent']);
        $this->assertSame('Acme Corp/2026', $child->refresh()->full_name);

        $this->server->addMessage($child->remote_id, 'Invoice');
        $statistics = (new GraphProvider($this->mailbox))->folderStatistics($child->identifier());
        $this->assertSame(1, $statistics->messages);
        $this->assertSame(1, $statistics->unseen);

        $manager->delete($acme->refresh(), \Cooolinho\FilamentMailbox\Services\FolderManager::DELETE_TO_TRASH);

        $this->assertArrayNotHasKey($acme->remote_id, $this->server->folders);
        $this->assertFalse($this->mailbox->supports(ProviderCapability::FolderSubscriptions));

        // A full sync afterwards keeps the local tree consistent.
        app(SyncService::class)->syncFolders($this->mailbox, new GraphProvider($this->mailbox));

        $this->assertSame(0, $this->mailbox->folders()->where('is_active', true)->whereIn('remote_id', [$acme->remote_id, $child->remote_id])->count());
        $this->assertTrue($this->mailbox->folders()->where('remote_id', $customers)->value('is_active'));
    }

    protected function graphMailbox(): Mailbox
    {
        return Mailbox::factory()->create([
            'name' => 'Info',
            'email' => 'info@contoso.com',
            'provider' => ProviderType::Graph,
            'auth_mode' => AuthMode::OAuth,
            'oauth_connection_id' => OAuthConnection::factory()->create(['account_email' => 'info@contoso.com'])->id,
            'host' => null,
            'port' => null,
            'encryption' => null,
            'username' => null,
            'password' => null,
        ]);
    }
}
