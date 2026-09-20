<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature\OAuth;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Enums\AuthMode;
use Cooolinho\FilamentMailbox\Enums\Encryption;
use Cooolinho\FilamentMailbox\Enums\OAuthGrantType;
use Cooolinho\FilamentMailbox\Exceptions\ConnectionFailed;
use Cooolinho\FilamentMailbox\Exceptions\OAuthReconnectRequired;
use Cooolinho\FilamentMailbox\Exceptions\SyncFailed;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\CreateMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\EditMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\OAuthApplications\Pages\CreateOAuthApplication;
use Cooolinho\FilamentMailbox\Filament\Resources\OAuthApplications\Pages\ListOAuthApplications;
use Cooolinho\FilamentMailbox\Jobs\SyncMailboxJob;
use Cooolinho\FilamentMailbox\Mail\Transports\LaravelMailTransport;
use Cooolinho\FilamentMailbox\Mail\Transports\SmtpMailboxTransport;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Cooolinho\FilamentMailbox\OAuth\OAuthProviders;
use Cooolinho\FilamentMailbox\OAuth\OAuthTokenManager;
use Cooolinho\FilamentMailbox\OAuth\Providers\MicrosoftOAuthProvider;
use Cooolinho\FilamentMailbox\Providers\DefaultMailboxProviderFactory;
use Cooolinho\FilamentMailbox\Providers\Imap\ImapProvider;
use Cooolinho\FilamentMailbox\Services\MailSender;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Support\CredentialRedactor;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use ReflectionProperty;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

class OAuthMailboxTest extends TestCase
{
    public function test_imap_uses_xoauth2_with_the_access_token(): void
    {
        $mailbox = $this->oauthMailbox();

        $config = (fn () => $this->config())->call(new ImapProvider($mailbox));

        $this->assertSame('oauth', $config['authentication']);
        $this->assertSame('info@contoso.com', $config['username']);
        $this->assertSame('access-token-value', $config['password']);
    }

    public function test_revoked_connection_fails_the_sync_permanently(): void
    {
        $this->app->bind(MailboxProviderFactory::class, DefaultMailboxProviderFactory::class);
        $mailbox = $this->oauthMailbox(['status' => 'needs_reconnect']);

        try {
            $this->app->make(SyncService::class)->syncMailbox($mailbox);
            $this->fail('Expected SyncFailed.');
        } catch (SyncFailed $exception) {
            $this->assertTrue($exception->permanent);
        }

        $job = (new SyncMailboxJob($mailbox))->withFakeQueueInteractions();
        $job->handle($this->app->make(SyncService::class));
        $job->assertFailed();

        $this->expectException(OAuthReconnectRequired::class);
        (new ImapProvider($mailbox))->testConnection();
    }

    public function test_token_request_errors_become_connection_failures(): void
    {
        Http::fake(['*' => Http::response(['error' => 'temporarily_unavailable'], 503)]);
        $mailbox = $this->oauthMailbox(['access_token_expires_at' => now()->subMinute()]);

        $this->expectException(ConnectionFailed::class);
        (new ImapProvider($mailbox))->testConnection();
    }

    public function test_smtp_transport_uses_only_xoauth2(): void
    {
        $transport = $this->app->make(SmtpMailboxTransport::class)->transport($this->oauthMailbox());

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('smtp.office365.com', $transport->getStream()->getHost());
        $this->assertSame(587, $transport->getStream()->getPort());
        $this->assertSame('info@contoso.com', $transport->getUsername());
        $this->assertSame('access-token-value', $transport->getPassword());

        $authenticators = (new ReflectionProperty(EsmtpTransport::class, 'authenticators'))->getValue($transport);
        $this->assertCount(1, $authenticators);
        $this->assertInstanceOf(XOAuth2Authenticator::class, $authenticators[0]);
    }

    public function test_smtp_host_and_port_can_be_overridden_per_mailbox(): void
    {
        $mailbox = $this->oauthMailbox();
        $mailbox->forceFill(['smtp_host' => 'relay.contoso.com', 'smtp_port' => 465]);

        $transport = $this->app->make(SmtpMailboxTransport::class)->transport($mailbox);

        $this->assertSame('relay.contoso.com', $transport->getStream()->getHost());
        $this->assertSame(465, $transport->getStream()->getPort());
        $this->assertTrue($transport->getStream()->isTLS());
    }

    public function test_mail_sender_selects_the_transport(): void
    {
        $sender = $this->app->make(MailSender::class);

        $this->assertInstanceOf(LaravelMailTransport::class, $sender->transportFor(Mailbox::factory()->make()));
        $this->assertInstanceOf(SmtpMailboxTransport::class, $sender->transportFor(Mailbox::factory()->make(['smtp_host' => 'smtp.example.com'])));
        $this->assertInstanceOf(SmtpMailboxTransport::class, $sender->transportFor($this->oauthMailbox()));
    }

    public function test_messages_are_sent_through_the_mailbox_transport(): void
    {
        $array = new ArrayTransport;
        $this->app->instance(SmtpMailboxTransport::class, new class($array) extends SmtpMailboxTransport
        {
            public function __construct(protected ArrayTransport $array)
            {
                parent::__construct(app(OAuthTokenManager::class), app(OAuthProviders::class), app('view'), app('events'));
            }

            public function transport(Mailbox $mailbox): TransportInterface
            {
                return $this->array;
            }
        });

        $this->app->make(MailSender::class)->send($this->oauthMailbox(), new OutgoingMessageData(['jane@example.com'], 'Hello', 'Body', inReplyTo: 'parent@example.com'));

        $sent = $array->messages()->sole()->getOriginalMessage();
        $this->assertSame('Hello', $sent->getSubject());
        $this->assertSame('info@contoso.com', $sent->getFrom()[0]->getAddress());
        $this->assertSame('<parent@example.com>', $sent->getHeaders()->get('In-Reply-To')->getBodyAsString());
    }

    public function test_tokens_are_redacted(): void
    {
        $mailbox = $this->oauthMailbox();

        $redacted = CredentialRedactor::redact('AUTH failed for access-token-value / refresh-token-value, header Bearer eyJ0eXAi.abc-def_1 and user=x auth=Bearer abc', $mailbox);

        $this->assertStringNotContainsString('access-token-value', $redacted);
        $this->assertStringNotContainsString('refresh-token-value', $redacted);
        $this->assertStringNotContainsString('eyJ0eXAi', $redacted);
        $this->assertStringNotContainsString('Bearer abc', $redacted);
        $this->assertArrayNotHasKey('access_token', $mailbox->oauthConnection->toArray());
    }

    public function test_form_switches_between_password_and_oauth(): void
    {
        OAuthApplication::factory()->create();

        Livewire::test(CreateMailbox::class)
            ->assertFormFieldVisible('password')
            ->assertFormFieldHidden('oauth_application_id')
            ->fillForm(['auth_mode' => AuthMode::OAuth])
            ->assertFormFieldHidden('password')
            ->assertFormFieldVisible('oauth_application_id')
            ->fillForm([
                'name' => 'Info', 'email' => 'info@contoso.com', 'host' => 'outlook.office365.com', 'port' => 993,
                'encryption' => Encryption::Ssl, 'username' => 'info@contoso.com',
            ])
            ->call('create')
            ->assertHasFormErrors(['oauth_connection_id' => 'required']);

        $this->assertSame(0, Mailbox::count());
    }

    public function test_create_form_is_prefilled_after_the_callback(): void
    {
        $connection = OAuthConnection::factory()->create(['account_email' => 'info@contoso.com', 'created_by' => $this->user->id]);
        $foreign = OAuthConnection::factory()->create(['created_by' => null]);

        Livewire::withQueryParams(['oauth_connection' => $foreign->id])
            ->test(CreateMailbox::class)
            ->assertSchemaStateSet(['oauth_connection_id' => null]);

        Livewire::withQueryParams(['oauth_connection' => $connection->id])
            ->test(CreateMailbox::class)
            ->assertSchemaStateSet([
                'auth_mode' => AuthMode::OAuth,
                'oauth_connection_id' => $connection->id,
                'email' => 'info@contoso.com',
                'username' => 'info@contoso.com',
                'host' => 'outlook.office365.com',
                'port' => 993,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $mailbox = Mailbox::sole();
        $this->assertTrue($mailbox->usesOAuth());
        $this->assertNull($mailbox->password);
        $this->assertSame($connection->id, $mailbox->oauth_connection_id);
    }

    public function test_connect_action_redirects_to_the_provider(): void
    {
        $application = OAuthApplication::factory()->create();

        Livewire::test(CreateMailbox::class)
            ->fillForm(['auth_mode' => AuthMode::OAuth, 'oauth_application_id' => $application->id])
            ->callAction(TestAction::make('connectOAuth')->schemaComponent('oauthActions', schema: 'form'))
            ->assertRedirectContains('https://login.microsoftonline.com/contoso-tenant/oauth2/v2.0/authorize');
    }

    public function test_application_permissions_create_an_app_only_connection(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'app-token', 'expires_in' => 3599])]);
        $application = OAuthApplication::factory()->create();

        $component = Livewire::test(CreateMailbox::class)
            ->fillForm(['auth_mode' => AuthMode::OAuth, 'oauth_application_id' => $application->id, 'email' => 'shared@contoso.com'])
            ->callAction(TestAction::make('connectOAuthApplication')->schemaComponent('oauthActions', schema: 'form'));

        $connection = OAuthConnection::sole();
        $this->assertSame(OAuthGrantType::ClientCredentials, $connection->grant_type);
        $this->assertSame('shared@contoso.com', $connection->account_email);
        $this->assertSame('app-token', $connection->access_token);

        $component->assertSchemaStateSet(['oauth_connection_id' => $connection->id, 'host' => 'outlook.office365.com']);
    }

    public function test_disconnect_revokes_and_deletes_unused_google_connections(): void
    {
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response()]);
        $application = OAuthApplication::factory()->google()->create();
        $mailbox = $this->oauthMailbox(['application_id' => $application->id]);

        Livewire::test(EditMailbox::class, ['record' => $mailbox->getRouteKey()])
            ->callAction(TestAction::make('disconnectOAuth')->schemaComponent('oauthActions', schema: 'form'));

        Http::assertSent(fn ($request): bool => $request['token'] === 'refresh-token-value');
        $this->assertSame(0, OAuthConnection::count());
        $this->assertSame(AuthMode::Password, $mailbox->refresh()->auth_mode);
    }

    public function test_shared_connections_are_kept_on_disconnect(): void
    {
        Http::fake();
        $mailbox = $this->oauthMailbox();
        Mailbox::factory()->create(['auth_mode' => AuthMode::OAuth, 'oauth_connection_id' => $mailbox->oauth_connection_id]);

        Livewire::test(EditMailbox::class, ['record' => $mailbox->getRouteKey()])
            ->callAction(TestAction::make('disconnectOAuth')->schemaComponent('oauthActions', schema: 'form'));

        $this->assertSame(1, OAuthConnection::count());
        Http::assertNothingSent();
    }

    public function test_only_managers_manage_oauth_applications(): void
    {
        Livewire::test(CreateOAuthApplication::class)
            ->fillForm(['name' => 'Entra', 'provider' => 'microsoft', 'tenant' => 'contoso', 'client_id' => 'client', 'client_secret' => 'secret-value'])
            ->call('create')
            ->assertHasNoFormErrors();

        $application = OAuthApplication::sole();
        $this->assertSame('secret-value', $application->client_secret);
        $this->assertStringNotContainsString('secret-value', (string) $application->getRawOriginal('client_secret'));

        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE, fn (): bool => false);

        Livewire::test(ListOAuthApplications::class)->assertForbidden();
    }

    public function test_microsoft_client_assertion_is_signed(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'test'], $key), null, $key, 1);
        openssl_pkey_export($key, $privatePem);
        openssl_x509_export($certificate, $certificatePem);

        $application = OAuthApplication::factory()->make(['client_secret' => null, 'certificate' => $privatePem.$certificatePem]);
        $jwt = app(MicrosoftOAuthProvider::class)->clientAssertion($application, 'https://login.microsoftonline.com/t/oauth2/v2.0/token');

        [$header, $payload, $signature] = explode('.', $jwt);
        $this->assertSame(1, openssl_verify("{$header}.{$payload}", base64_decode(strtr($signature, '-_', '+/')), openssl_pkey_get_public($certificatePem), OPENSSL_ALGO_SHA256));
        $this->assertSame($application->client_id, json_decode(base64_decode(strtr($payload, '-_', '+/')), true)['iss']);
        $this->assertArrayHasKey('x5t#S256', json_decode(base64_decode(strtr($header, '-_', '+/')), true));
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    protected function oauthMailbox(array $connection = []): Mailbox
    {
        $connection = OAuthConnection::factory()->create(['account_email' => 'info@contoso.com', ...$connection]);

        return Mailbox::factory()->create([
            'email' => 'info@contoso.com',
            'username' => 'info@contoso.com',
            'password' => null,
            'host' => 'outlook.office365.com',
            'auth_mode' => AuthMode::OAuth,
            'oauth_connection_id' => $connection->id,
        ]);
    }
}
