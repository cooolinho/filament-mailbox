<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature\OAuth;

use Cooolinho\FilamentMailbox\Enums\AuthMode;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Cooolinho\FilamentMailbox\OAuth\OAuthFlow;
use Cooolinho\FilamentMailbox\OAuth\Providers\AbstractOAuthProvider;
use Cooolinho\FilamentMailbox\Tests\Fixtures\User;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class OAuthCallbackTest extends TestCase
{
    protected OAuthApplication $application;

    protected function setUp(): void
    {
        parent::setUp();

        $this->application = OAuthApplication::factory()->create();
    }

    public function test_authorization_url_uses_pkce_and_state(): void
    {
        $url = $this->flow()->begin($this->application, MailboxResource::getUrl('create'));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://login.microsoftonline.com/contoso-tenant/oauth2/v2.0/authorize?', $url);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertStringContainsString('https://outlook.office.com/IMAP.AccessAsUser.All', $query['scope']);
        $this->assertStringContainsString('offline_access', $query['scope']);
        $this->assertSame(url('/admin/filament-mailbox/oauth/microsoft/callback'), $query['redirect_uri']);

        $context = session(OAuthFlow::SESSION_KEY.'.'.$query['state']);
        $this->assertSame(AbstractOAuthProvider::base64UrlEncode(hash('sha256', $context['code_verifier'], true)), $query['code_challenge']);
        $this->assertSame($this->user->id, $context['user_id']);
    }

    public function test_callback_creates_the_connection_and_returns_to_the_form(): void
    {
        Http::fake(['*' => Http::response([
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires_in' => 3600,
            'scope' => 'https://outlook.office.com/IMAP.AccessAsUser.All offline_access',
            'id_token' => $this->idToken(['sub' => 'subject-1', 'email' => 'info@contoso.com']),
        ])]);

        [$state, $session] = $this->begin(MailboxResource::getUrl('create'));

        $this->withSession($session)
            ->get("/admin/filament-mailbox/oauth/microsoft/callback?state={$state}&code=auth-code")
            ->assertRedirect(MailboxResource::getUrl('create').'?oauth_connection='.OAuthConnection::sole()->id.'&provider=imap');

        Http::assertSent(fn (Request $request): bool => $request['code'] === 'auth-code'
            && $request['code_verifier'] === $session[OAuthFlow::SESSION_KEY][$state]['code_verifier']
            && $request['redirect_uri'] === url('/admin/filament-mailbox/oauth/microsoft/callback'));

        $connection = OAuthConnection::sole();
        $this->assertSame('info@contoso.com', $connection->account_email);
        $this->assertSame('subject-1', $connection->account_subject);
        $this->assertSame('refresh', $connection->refresh_token);
        $this->assertSame($this->user->id, $connection->created_by);
    }

    public function test_reconnecting_updates_the_existing_connection_and_mailbox(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'access', 'refresh_token' => 'new', 'id_token' => $this->idToken(['sub' => 'subject-1', 'email' => 'info@contoso.com'])])]);

        $existing = OAuthConnection::factory()->for($this->application, 'application')->create(['account_subject' => 'subject-1', 'status' => 'needs_reconnect']);
        $mailbox = Mailbox::factory()->create();

        [$state, $session] = $this->begin(MailboxResource::getUrl('edit', ['record' => $mailbox]), $mailbox);

        $this->withSession($session)
            ->get("/admin/filament-mailbox/oauth/microsoft/callback?state={$state}&code=auth-code")
            ->assertRedirect();

        $this->assertSame(1, OAuthConnection::count());
        $this->assertTrue($existing->refresh()->isActive());
        $this->assertSame('new', $existing->refresh_token);

        $mailbox->refresh();
        $this->assertSame(AuthMode::OAuth, $mailbox->auth_mode);
        $this->assertSame($existing->id, $mailbox->oauth_connection_id);
    }

    public function test_invalid_states_are_rejected(): void
    {
        Http::fake();
        [$state, $session] = $this->begin(MailboxResource::getUrl('create'));

        $this->withSession($session)->get('/admin/filament-mailbox/oauth/microsoft/callback?state=unknown&code=x')->assertForbidden();
        $this->withSession($session)->get("/admin/filament-mailbox/oauth/google/callback?state={$state}&code=x")->assertForbidden();

        // Expired.
        $expired = $session;
        $expired[OAuthFlow::SESSION_KEY][$state]['expires_at'] = now()->subSecond()->getTimestamp();
        $this->withSession($expired)->get("/admin/filament-mailbox/oauth/microsoft/callback?state={$state}&code=x")->assertForbidden();

        // Started by another user.
        $this->actingAs(User::make('Other'));
        $this->withSession($session)->get("/admin/filament-mailbox/oauth/microsoft/callback?state={$state}&code=x")->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, OAuthConnection::count());
    }

    public function test_a_state_can_only_be_used_once(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'a', 'refresh_token' => 'r'])]);
        [$state, $session] = $this->begin(MailboxResource::getUrl('create'));

        $this->withSession($session)->get("/admin/filament-mailbox/oauth/microsoft/callback?state={$state}&code=x")->assertRedirect();
        $this->get("/admin/filament-mailbox/oauth/microsoft/callback?state={$state}&code=x")->assertForbidden();
    }

    public function test_denied_consent_returns_without_connection(): void
    {
        Http::fake();
        [$state, $session] = $this->begin(MailboxResource::getUrl('create'));

        $this->withSession($session)
            ->get("/admin/filament-mailbox/oauth/microsoft/callback?state={$state}&error=access_denied")
            ->assertRedirect(MailboxResource::getUrl('create'));

        $this->assertSame(0, OAuthConnection::count());
    }

    public function test_external_return_urls_are_not_used(): void
    {
        [, $session] = $this->begin('https://evil.example.com/steal');

        $this->assertStringStartsWith(url('/'), collect($session[OAuthFlow::SESSION_KEY])->first()['return_url']);
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    protected function begin(string $returnUrl, ?Mailbox $mailbox = null): array
    {
        $url = $this->flow()->begin($this->application, $returnUrl, $mailbox);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return [$query['state'], [OAuthFlow::SESSION_KEY => session(OAuthFlow::SESSION_KEY)]];
    }

    /**
     * @param  array<string, string>  $claims
     */
    protected function idToken(array $claims): string
    {
        return implode('.', [
            AbstractOAuthProvider::base64UrlEncode('{"alg":"none"}'),
            AbstractOAuthProvider::base64UrlEncode((string) json_encode($claims)),
            'signature',
        ]);
    }

    protected function flow(): OAuthFlow
    {
        return $this->app->make(OAuthFlow::class);
    }
}
