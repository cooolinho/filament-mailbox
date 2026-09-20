<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature\Gmail;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Enums\AuthMode;
use Cooolinho\FilamentMailbox\Enums\OAuthGrantType;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\CreateMailbox;
use Cooolinho\FilamentMailbox\Jobs\SyncMailboxJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Cooolinho\FilamentMailbox\OAuth\OAuthConnectionManager;
use Cooolinho\FilamentMailbox\OAuth\OAuthTokenManager;
use Cooolinho\FilamentMailbox\OAuth\Providers\AbstractOAuthProvider;
use Cooolinho\FilamentMailbox\Providers\DefaultMailboxProviderFactory;
use Cooolinho\FilamentMailbox\Support\GoogleIdTokenVerifier;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeGmailServer;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use OpenSSLAsymmetricKey;

class GmailPushTest extends TestCase
{
    protected OpenSSLAsymmetricKey $key;

    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filament-mailbox.gmail.push.enabled' => true,
            'filament-mailbox.gmail.push.topic' => 'projects/p/topics/gmail',
            'filament-mailbox.gmail.push.audience' => 'https://mail.example.com/filament-mailbox/webhooks/gmail',
            'filament-mailbox.gmail.push.service_account' => 'push@p.iam.gserviceaccount.com',
        ]);

        $this->key = openssl_pkey_new(['private_key_bits' => 2048]);
        $details = openssl_pkey_get_details($this->key);

        Http::fake([
            GoogleIdTokenVerifier::JWKS_URL => Http::response(['keys' => [[
                'kid' => 'key-1', 'kty' => 'RSA', 'alg' => 'RS256',
                'n' => AbstractOAuthProvider::base64UrlEncode($details['rsa']['n']),
                'e' => AbstractOAuthProvider::base64UrlEncode($details['rsa']['e']),
            ]]]),
        ]);

        $this->app->bind(MailboxProviderFactory::class, DefaultMailboxProviderFactory::class);
        $this->mailbox = Mailbox::factory()->create([
            'email' => 'me@example.com',
            'provider' => ProviderType::Gmail,
            'auth_mode' => AuthMode::OAuth,
            'oauth_connection_id' => OAuthConnection::factory()->for(OAuthApplication::factory()->google(), 'application')->create()->id,
            'host' => null, 'port' => null, 'encryption' => null, 'username' => null, 'password' => null,
        ]);
    }

    public function test_valid_push_dispatches_a_mailbox_sync(): void
    {
        Queue::fake();

        $this->push($this->token())->assertNoContent();

        Queue::assertPushed(SyncMailboxJob::class, fn (SyncMailboxJob $job): bool => $job->mailbox->is($this->mailbox));
    }

    public function test_invalid_tokens_are_rejected(): void
    {
        Queue::fake();

        $this->push($this->token(['aud' => 'https://other.example.com']))->assertForbidden();
        $this->push($this->token(['exp' => now()->subHour()->getTimestamp()]))->assertForbidden();
        $this->push($this->token(['iss' => 'https://evil.example.com']))->assertForbidden();
        $this->push($this->token(['email' => 'other@p.iam.gserviceaccount.com']))->assertForbidden();
        $this->push($this->token(key: openssl_pkey_new(['private_key_bits' => 2048])))->assertForbidden();
        $this->push('not-a-jwt')->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_push_is_disabled_by_default(): void
    {
        config(['filament-mailbox.gmail.push.enabled' => false]);

        $this->push($this->token())->assertNotFound();
    }

    public function test_watch_command_starts_and_renews_watches(): void
    {
        $server = (new FakeGmailServer)->fake();

        $this->artisan('mailbox:gmail-watch')->assertSuccessful();

        $this->assertSame('projects/p/topics/gmail', $server->watch['request']['topicName']);
        $this->assertSame(['INBOX'], $server->watch['request']['labelIds']);
        $this->assertTrue($this->mailbox->refresh()->watch_expires_at->isAfter(now()->addDays(6)));

        $server->watch = null;
        $this->artisan('mailbox:gmail-watch')->assertSuccessful();
        $this->assertNull($server->watch);
    }

    public function test_service_account_tokens_use_domain_wide_delegation(): void
    {
        openssl_pkey_export($this->key, $pem);
        $application = OAuthApplication::factory()->google()->create([
            'client_secret' => null,
            'certificate' => json_encode(['type' => 'service_account', 'client_email' => 'mailer@p.iam.gserviceaccount.com', 'private_key' => $pem, 'private_key_id' => 'k1']),
        ]);

        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'sa-token', 'expires_in' => 3600])]);

        $connection = $this->app->make(OAuthConnectionManager::class)->connectApplication($application, 'shared@example.com', ProviderType::Gmail);

        $this->assertSame(OAuthGrantType::ServiceAccount, $connection->grant_type);
        $this->assertSame(['https://www.googleapis.com/auth/gmail.modify', 'https://www.googleapis.com/auth/gmail.send'], $connection->scopes);

        Http::assertSent(function (Request $request) use ($pem): bool {
            if ($request->url() !== 'https://oauth2.googleapis.com/token') {
                return false;
            }

            [$header, $payload, $signature] = explode('.', $request['assertion']);
            $claims = json_decode(AbstractOAuthProvider::base64UrlDecode($payload), true);

            return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && $claims['sub'] === 'shared@example.com'
                && $claims['iss'] === 'mailer@p.iam.gserviceaccount.com'
                && $claims['scope'] === 'https://www.googleapis.com/auth/gmail.modify https://www.googleapis.com/auth/gmail.send'
                && openssl_verify("{$header}.{$payload}", AbstractOAuthProvider::base64UrlDecode($signature), openssl_pkey_get_public(openssl_pkey_get_details($this->key)['key']), OPENSSL_ALGO_SHA256) === 1;
        });

        // Renewal requests a new assertion instead of a refresh token.
        $connection->forceFill(['access_token_expires_at' => now()->subMinute()])->save();
        $this->assertSame('sa-token', $this->app->make(OAuthTokenManager::class)->accessToken($connection));
    }

    public function test_gmail_mailbox_form(): void
    {
        $google = OAuthApplication::factory()->google()->create(['name' => 'Google Workspace']);
        OAuthApplication::factory()->create(['name' => 'Entra']);
        $connection = OAuthConnection::factory()->for($google, 'application')->create(['account_email' => 'me@example.com', 'created_by' => $this->user->id]);

        Livewire::withQueryParams(['oauth_connection' => $connection->id, 'provider' => 'gmail'])
            ->test(CreateMailbox::class)
            ->assertSchemaStateSet(['provider' => ProviderType::Gmail, 'host' => null])
            ->assertFormFieldVisible('initial_sync_days')
            ->assertFormFieldExists('oauth_application_id', fn ($field): bool => in_array('Google Workspace', $field->getOptions(), true) && ! in_array('Entra', $field->getOptions(), true))
            ->fillForm(['initial_sync_days' => 14])
            ->call('create')
            ->assertHasNoFormErrors();

        $mailbox = Mailbox::latest('id')->first();
        $this->assertSame(ProviderType::Gmail, $mailbox->provider);
        $this->assertSame(14, $mailbox->initial_sync_days);
    }

    protected function push(string $token): TestResponse
    {
        return $this->withToken($token)->postJson('/filament-mailbox/webhooks/gmail', [
            'message' => ['data' => base64_encode(json_encode(['emailAddress' => 'me@example.com', 'historyId' => '1234'])), 'messageId' => '1'],
            'subscription' => 'projects/p/subscriptions/gmail-push',
        ]);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function token(array $claims = [], ?OpenSSLAsymmetricKey $key = null): string
    {
        $input = AbstractOAuthProvider::base64UrlEncode(json_encode(['alg' => 'RS256', 'kid' => 'key-1', 'typ' => 'JWT']))
            .'.'.AbstractOAuthProvider::base64UrlEncode(json_encode([
                'iss' => 'https://accounts.google.com',
                'aud' => 'https://mail.example.com/filament-mailbox/webhooks/gmail',
                'iat' => now()->getTimestamp(),
                'exp' => now()->addHour()->getTimestamp(),
                'email' => 'push@p.iam.gserviceaccount.com',
                'email_verified' => true,
                ...$claims,
            ]));

        openssl_sign($input, $signature, $key ?? $this->key, OPENSSL_ALGO_SHA256);

        return $input.'.'.AbstractOAuthProvider::base64UrlEncode($signature);
    }
}
