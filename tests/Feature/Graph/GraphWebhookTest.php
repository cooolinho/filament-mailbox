<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature\Graph;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Enums\AuthMode;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\CreateMailbox;
use Cooolinho\FilamentMailbox\Jobs\SyncMailboxFolderJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxGraphSubscription;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Cooolinho\FilamentMailbox\OAuth\OAuthFlow;
use Cooolinho\FilamentMailbox\Providers\DefaultMailboxProviderFactory;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeGraphServer;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class GraphWebhookTest extends TestCase
{
    protected FakeGraphServer $server;

    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'filament-mailbox.graph.webhooks.enabled' => true,
            'filament-mailbox.graph.webhooks.notification_url' => 'https://mail.example.com/filament-mailbox/webhooks/graph',
        ]);

        $this->app->bind(MailboxProviderFactory::class, DefaultMailboxProviderFactory::class);
        $this->server = (new FakeGraphServer)->fake();
        $this->mailbox = Mailbox::factory()->create([
            'provider' => ProviderType::Graph,
            'auth_mode' => AuthMode::OAuth,
            'oauth_connection_id' => OAuthConnection::factory()->create()->id,
            'host' => null, 'port' => null, 'encryption' => null, 'username' => null, 'password' => null,
        ]);
    }

    public function test_subscriptions_are_created_and_renewed(): void
    {
        $this->artisan('mailbox:sync', ['mailbox' => [$this->mailbox->id], '--now' => true])->assertSuccessful();
        $this->artisan('mailbox:graph-subscriptions')->assertSuccessful();

        $subscriptions = MailboxGraphSubscription::all();
        $this->assertCount(5, $subscriptions);

        $inbox = MailboxFolder::where('remote_id', $this->server->folderId('inbox'))->sole();
        $subscription = $subscriptions->firstWhere('folder_id', $inbox->id);
        $remote = $this->server->subscriptions[$subscription->subscription_id];

        $this->assertSame("me/mailFolders('{$inbox->remote_id}')/messages", $remote['resource']);
        $this->assertSame('created,updated,deleted', $remote['changeType']);
        $this->assertSame($subscription->client_state, $remote['clientState']);
        $this->assertNotSame($remote['clientState'], $subscription->getRawOriginal('client_state'));

        // Close to expiry: renewed; unknown on the Graph side: recreated.
        $subscription->forceFill(['expires_at' => now()->addHour()])->save();
        $other = $subscriptions->firstWhere('folder_id', '!=', $inbox->id);
        $other->forceFill(['expires_at' => now()->addHour(), 'subscription_id' => 'gone'])->save();

        $this->artisan('mailbox:graph-subscriptions')->assertSuccessful();

        $this->assertTrue($subscription->refresh()->expires_at->isAfter(now()->addDays(2)));
        $this->assertModelMissing($other);
        $this->assertSame(5, MailboxGraphSubscription::count());
    }

    public function test_validation_handshake(): void
    {
        $this->post('/filament-mailbox/webhooks/graph?validationToken=abc%20123')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertContent('abc 123');
    }

    public function test_notifications_dispatch_a_folder_sync(): void
    {
        Queue::fake();
        $subscription = $this->subscription();

        $this->postJson('/filament-mailbox/webhooks/graph', ['value' => [
            ['subscriptionId' => $subscription->subscription_id, 'clientState' => 'secret-state', 'resource' => 'ignored'],
            ['subscriptionId' => $subscription->subscription_id, 'clientState' => 'secret-state'],
        ]])->assertStatus(202);

        Queue::assertPushed(SyncMailboxFolderJob::class, 1);
        Queue::assertPushed(SyncMailboxFolderJob::class, fn (SyncMailboxFolderJob $job): bool => $job->folder->is($subscription->folder));
    }

    public function test_wrong_client_state_is_rejected(): void
    {
        Queue::fake();
        $subscription = $this->subscription();

        $this->postJson('/filament-mailbox/webhooks/graph', ['value' => [['subscriptionId' => $subscription->subscription_id, 'clientState' => 'wrong']]])->assertForbidden();
        $this->postJson('/filament-mailbox/webhooks/graph', ['value' => [['subscriptionId' => 'unknown', 'clientState' => 'secret-state']]])->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_webhook_is_disabled_by_default(): void
    {
        config(['filament-mailbox.graph.webhooks.enabled' => false]);

        $this->post('/filament-mailbox/webhooks/graph?validationToken=x')->assertNotFound();
    }

    public function test_folder_job_synchronises_one_folder(): void
    {
        $inbox = MailboxFolder::factory()->for($this->mailbox)->create(['remote_id' => $this->server->folderId('inbox'), 'full_name' => 'Inbox', 'sync_cursor' => null]);
        $this->server->addMessage($inbox->remote_id, 'Pushed');

        SyncMailboxFolderJob::dispatchSync($inbox);

        $this->assertSame('Pushed', MailboxMessage::sole()->subject);
    }

    public function test_graph_mailbox_is_created_from_the_oauth_callback(): void
    {
        $connection = OAuthConnection::factory()->create(['account_email' => 'info@contoso.com', 'created_by' => $this->user->id]);

        Livewire::withQueryParams(['oauth_connection' => $connection->id, 'provider' => 'graph'])
            ->test(CreateMailbox::class)
            ->assertSchemaStateSet(['provider' => ProviderType::Graph, 'auth_mode' => AuthMode::OAuth, 'host' => null])
            ->assertFormFieldHidden('host')
            ->assertFormFieldVisible('remote_user')
            ->fillForm(['remote_user' => 'shared@contoso.com'])
            ->call('create')
            ->assertHasNoFormErrors();

        $mailbox = Mailbox::latest('id')->first();
        $this->assertSame(ProviderType::Graph, $mailbox->provider);
        $this->assertNull($mailbox->host);
        $this->assertSame('shared@contoso.com', $mailbox->remote_user);
    }

    public function test_graph_oauth_requests_graph_scopes(): void
    {
        $url = $this->app->make(OAuthFlow::class)->begin(OAuthApplication::factory()->create(), url('/admin'), provider: ProviderType::Graph);

        $this->assertStringContainsString(rawurlencode('https://graph.microsoft.com/Mail.ReadWrite'), $url);
        $this->assertStringNotContainsString('IMAP.AccessAsUser.All', $url);
    }

    protected function subscription(): MailboxGraphSubscription
    {
        return MailboxGraphSubscription::query()->create([
            'folder_id' => MailboxFolder::factory()->for($this->mailbox)->create()->id,
            'subscription_id' => 'sub-1',
            'client_state' => 'secret-state',
            'expires_at' => now()->addDay(),
        ]);
    }
}
