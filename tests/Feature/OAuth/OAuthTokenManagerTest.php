<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature\OAuth;

use Cooolinho\FilamentMailbox\Enums\OAuthConnectionStatus;
use Cooolinho\FilamentMailbox\Enums\OAuthGrantType;
use Cooolinho\FilamentMailbox\Events\OAuthConnectionRevoked;
use Cooolinho\FilamentMailbox\Exceptions\OAuthReconnectRequired;
use Cooolinho\FilamentMailbox\Exceptions\OAuthRequestFailed;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Cooolinho\FilamentMailbox\OAuth\OAuthTokenManager;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

class OAuthTokenManagerTest extends TestCase
{
    public function test_fresh_tokens_are_returned_without_a_request(): void
    {
        Http::fake();
        $connection = OAuthConnection::factory()->create(['access_token' => 'cached', 'access_token_expires_at' => now()->addHour()]);

        $this->assertSame('cached', $this->manager()->accessToken($connection));

        Http::assertNothingSent();
    }

    public function test_tokens_are_refreshed_before_they_expire_and_rotated(): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::response([
            'access_token' => 'new-access', 'refresh_token' => 'rotated-refresh', 'expires_in' => 3600,
        ])]);

        // Expires within the refresh margin.
        $connection = OAuthConnection::factory()->create(['access_token_expires_at' => now()->addMinutes(2)]);

        $this->assertSame('new-access', $this->manager()->accessToken($connection));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/contoso-tenant/oauth2/v2.0/token')
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-token-value'
            && $request['client_secret'] === 'app-secret-value');

        $connection->refresh();
        $this->assertSame('rotated-refresh', $connection->refresh_token);
        $this->assertTrue($connection->access_token_expires_at->isAfter(now()->addMinutes(50)));
        // Encrypted at rest.
        $this->assertStringNotContainsString('rotated-refresh', (string) $connection->getRawOriginal('refresh_token'));
    }

    public function test_the_refresh_token_is_kept_when_none_is_returned(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'new-access', 'expires_in' => 3600])]);
        $connection = OAuthConnection::factory()->expired()->create();

        $this->manager()->accessToken($connection);

        $this->assertSame('refresh-token-value', $connection->refresh()->refresh_token);
    }

    public function test_a_token_refreshed_by_another_process_is_reused(): void
    {
        Http::fake();
        $connection = OAuthConnection::factory()->expired()->create();

        // Another worker refreshed the token while this one waited for the lock.
        OAuthConnection::whereKey($connection->id)->first()->forceFill([
            'access_token' => 'from-other-worker',
            'access_token_expires_at' => now()->addHour(),
        ])->save();

        $this->assertSame('from-other-worker', $this->manager()->accessToken($connection));
        Http::assertNothingSent();
    }

    public function test_invalid_grant_requires_a_reconnect(): void
    {
        Event::fake([OAuthConnectionRevoked::class]);
        Http::fake(['*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'AADSTS70008: expired'], 400)]);
        $connection = OAuthConnection::factory()->expired()->create();

        try {
            $this->manager()->accessToken($connection);
            $this->fail('Expected OAuthReconnectRequired.');
        } catch (OAuthReconnectRequired $exception) {
            $this->assertStringNotContainsString('refresh-token-value', $exception->getMessage());
        }

        $connection->refresh();
        $this->assertSame(OAuthConnectionStatus::NeedsReconnect, $connection->status);
        $this->assertNull($connection->access_token);
        $this->assertStringContainsString('invalid_grant', $connection->last_error);
        Event::assertDispatchedTimes(OAuthConnectionRevoked::class, 1);

        // No further requests for an inactive connection.
        $this->expectException(OAuthReconnectRequired::class);
        $this->manager()->accessToken($connection);
    }

    public function test_other_errors_are_not_treated_as_revocation(): void
    {
        Http::fake(['*' => Http::response(['error' => 'temporarily_unavailable'], 503)]);
        $connection = OAuthConnection::factory()->expired()->create();

        try {
            $this->manager()->accessToken($connection);
            $this->fail('Expected OAuthRequestFailed.');
        } catch (OAuthRequestFailed $exception) {
            $this->assertFalse($exception->isInvalidGrant());
        }

        $this->assertSame(OAuthConnectionStatus::Active, $connection->refresh()->status);
    }

    public function test_client_credentials_request_a_new_token(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'app-token', 'expires_in' => 3599])]);
        $connection = OAuthConnection::factory()->expired()->create([
            'grant_type' => OAuthGrantType::ClientCredentials,
            'refresh_token' => null,
            'scopes' => ['https://outlook.office365.com/.default'],
        ]);

        $this->assertSame('app-token', $this->manager()->accessToken($connection));

        Http::assertSent(fn (Request $request): bool => $request['grant_type'] === 'client_credentials'
            && $request['scope'] === 'https://outlook.office365.com/.default');
    }

    public function test_revocation_is_recorded_on_the_mailboxes(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);
        $connection = OAuthConnection::factory()->expired()->create(['account_email' => 'info@contoso.com']);
        $mailbox = Mailbox::factory()->create(['auth_mode' => 'oauth', 'oauth_connection_id' => $connection->id]);

        rescue(fn () => $this->manager()->accessToken($connection), report: false);

        $this->assertStringContainsString('info@contoso.com', $mailbox->refresh()->last_sync_error);
    }

    protected function manager(): OAuthTokenManager
    {
        return $this->app->make(OAuthTokenManager::class);
    }
}
