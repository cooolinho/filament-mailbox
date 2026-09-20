<?php

namespace Cooolinho\FilamentMailbox\OAuth;

use Cooolinho\FilamentMailbox\Enums\OAuthConnectionStatus;
use Cooolinho\FilamentMailbox\Enums\OAuthGrantType;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Cooolinho\FilamentMailbox\OAuth\Providers\AbstractOAuthProvider;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Authorization code flow with PKCE. State, code verifier and return URL are
 * kept in the session only and bound to the signed-in user.
 */
class OAuthFlow
{
    public const SESSION_KEY = 'filament-mailbox.oauth';

    public const CALLBACK_ROUTE = 'filament-mailbox.oauth.callback';

    public function __construct(
        protected OAuthProviders $providers,
        protected OAuthTokenManager $tokens,
    ) {}

    /**
     * Start the flow and return the authorization URL to redirect to.
     *
     * @param  string  $returnUrl  Internal URL to return to after the callback
     */
    public function begin(OAuthApplication $application, string $returnUrl, ?Mailbox $mailbox = null, ProviderType $provider = ProviderType::Imap): string
    {
        $state = Str::random(40);
        $verifier = Str::random(96);

        session()->put(static::SESSION_KEY.'.'.$state, [
            'application_id' => $application->getKey(),
            'code_verifier' => $verifier,
            'return_url' => $this->internalUrl($returnUrl),
            'mailbox_id' => $mailbox?->getKey(),
            'provider' => $provider->value,
            'user_id' => Filament::auth()->id(),
            'expires_at' => now()->addSeconds((int) config('filament-mailbox.oauth.state_lifetime_seconds', 600))->getTimestamp(),
        ]);

        return $this->providers->for($application->provider)->authorizationUrl(
            $application,
            $this->redirectUri($application),
            $state,
            AbstractOAuthProvider::base64UrlEncode(hash('sha256', $verifier, true)),
            $this->providers->for($application->provider)->scopes(OAuthGrantType::AuthorizationCode, $provider),
        );
    }

    /**
     * Validate the callback and pull its context from the session. The state
     * can only be used once.
     *
     * @return array{application_id: int, code_verifier: string, return_url: string, mailbox_id: ?int, provider: string, user_id: int|string}
     *
     * @throws HttpException 403 for unknown, expired or foreign states
     */
    public function context(Request $request, string $provider): array
    {
        $state = (string) $request->query('state');
        $context = $state !== '' && ctype_alnum($state) ? session()->pull(static::SESSION_KEY.'.'.$state) : null;

        abort_unless(
            is_array($context)
            && $context['expires_at'] >= now()->getTimestamp()
            && (string) $context['user_id'] === (string) Filament::auth()->id(),
            403,
        );

        $application = OAuthApplication::find($context['application_id']);

        abort_unless($application && $application->provider->value === $provider, 403);

        return $context;
    }

    /**
     * Exchange the code and create or update the connection of the account.
     */
    public function complete(OAuthApplication $application, string $code, string $codeVerifier, ProviderType $mailboxProvider = ProviderType::Imap): OAuthConnection
    {
        $provider = $this->providers->for($application->provider);
        $token = $provider->exchangeCode($application, $code, $codeVerifier, $this->redirectUri($application));

        $connection = $token->subject
            ? OAuthConnection::query()
                ->where('application_id', $application->getKey())
                ->where('grant_type', OAuthGrantType::AuthorizationCode)
                ->where('account_subject', $token->subject)
                ->first()
            : null;

        $connection ??= new OAuthConnection([
            'application_id' => $application->getKey(),
            'grant_type' => OAuthGrantType::AuthorizationCode,
            'created_by' => Filament::auth()->id(),
        ]);

        $connection->forceFill([
            'account_email' => $token->email ?? $connection->account_email,
            'account_subject' => $token->subject ?? $connection->account_subject,
            'scopes' => $token->scopes ?: $provider->scopes(OAuthGrantType::AuthorizationCode, $mailboxProvider),
            'status' => OAuthConnectionStatus::Active,
        ]);

        $this->tokens->store($connection, $token);

        return $connection;
    }

    public function redirectUri(OAuthApplication $application): string
    {
        $panel = Filament::getCurrentOrDefaultPanel()->getId();

        return route("filament.{$panel}.".static::CALLBACK_ROUTE, ['provider' => $application->provider->value]);
    }

    /**
     * Only URLs of this application are accepted, so the callback can never
     * be used as an open redirect.
     */
    protected function internalUrl(string $url): string
    {
        $base = rtrim(url('/'), '/');

        return $url === $base || str_starts_with($url, $base.'/') ? $url : Filament::getCurrentOrDefaultPanel()->getUrl();
    }
}
