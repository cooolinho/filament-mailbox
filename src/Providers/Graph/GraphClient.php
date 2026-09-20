<?php

namespace Cooolinho\FilamentMailbox\Providers\Graph;

use Cooolinho\FilamentMailbox\Exceptions\ConnectionFailed;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\OAuth\OAuthTokenManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Minimal Microsoft Graph client on Laravel's HTTP client.
 *
 * - immutable message ids and HTML bodies via Prefer headers
 * - throttling (429/503/504) honours Retry-After with exponential backoff
 * - 401 triggers one forced token renewal
 */
class GraphClient
{
    protected ?string $token = null;

    public function __construct(
        protected Mailbox $mailbox,
        protected OAuthTokenManager $tokens,
    ) {}

    /**
     * "me" for delegated access, "users/{upn}" for application access or shared mailboxes.
     */
    public function mailboxPath(): string
    {
        if (filled($this->mailbox->remote_user)) {
            return 'users/'.rawurlencode($this->mailbox->remote_user);
        }

        return $this->mailbox->oauthConnection?->grant_type?->value === 'client_credentials'
            ? 'users/'.rawurlencode((string) $this->mailbox->email)
            : 'me';
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], array $headers = []): array
    {
        return $this->send('get', $this->url($path), ['query' => $query], $headers)->json() ?? [];
    }

    /**
     * GET an absolute @odata.nextLink / @odata.deltaLink.
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function getUrl(string $absoluteUrl, array $headers = []): array
    {
        if (! str_starts_with($absoluteUrl, $this->baseUrl().'/')) {
            throw new RuntimeException('Refusing to follow a link outside the Graph API.');
        }

        return $this->send('get', $absoluteUrl, [], $headers)->json() ?? [];
    }

    public function raw(string $path): string
    {
        return $this->send('get', $this->url($path))->body();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        return $this->send('post', $this->url($path), ['json' => $body])->json() ?? [];
    }

    public function postRaw(string $path, string $body, string $contentType): void
    {
        $this->send('post', $this->url($path), ['body' => $body, 'content_type' => $contentType]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function patch(string $path, array $body): array
    {
        return $this->send('patch', $this->url($path), ['json' => $body])->json() ?? [];
    }

    public function delete(string $path): void
    {
        $this->send('delete', $this->url($path));
    }

    /**
     * Path relative to the base URL, e.g. "subscriptions", without the mailbox prefix.
     */
    public function url(string $path): string
    {
        $path = ltrim($path, '/');

        return $this->baseUrl().'/'.(str_starts_with($path, '~/') ? substr($path, 2) : $this->mailboxPath().'/'.$path);
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('filament-mailbox.graph.base_url', 'https://graph.microsoft.com/v1.0'), '/');
    }

    /**
     * @param  array{query?: array<string, scalar>, json?: array<string, mixed>, body?: string, content_type?: string}  $options
     * @param  array<string, string>  $headers
     */
    protected function send(string $method, string $url, array $options = [], array $headers = []): Response
    {
        $maxRetries = max(0, (int) config('filament-mailbox.graph.max_retries', 5));
        $renewed = false;

        for ($attempt = 0; ; $attempt++) {
            try {
                $request = $this->request($headers);

                if (isset($options['body'])) {
                    $request->withBody($options['body'], $options['content_type'] ?? 'text/plain');
                }

                $response = $request->send(strtoupper($method), $url, array_filter([
                    'json' => $options['json'] ?? null,
                    'query' => $options['query'] ?? null,
                ], fn ($value): bool => $value !== null));
            } catch (ConnectionException $exception) {
                throw ConnectionFailed::for($this->mailbox, $exception);
            }

            if ($response->status() === 401 && ! $renewed) {
                // The token may have been revoked or expired early: renew once.
                $this->token = $this->tokens->renew($this->mailbox->oauthConnection);
                $renewed = true;

                continue;
            }

            if (in_array($response->status(), [429, 503, 504], true) && $attempt < $maxRetries) {
                Sleep::for($this->retryAfter($response, $attempt))->seconds();

                continue;
            }

            if ($response->status() === 401) {
                throw ConnectionFailed::for($this->mailbox, new RuntimeException('Graph rejected the access token (HTTP 401).'));
            }

            if ($response->failed()) {
                throw new GraphRequestFailed($response);
            }

            return $response;
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function request(array $headers): PendingRequest
    {
        $this->token ??= $this->tokens->accessToken($this->mailbox->oauthConnection
            ?? throw new ConnectionFailed('The mailbox has no OAuth connection. Connect an account first.'));

        return Http::withToken($this->token)
            ->acceptJson()
            ->timeout((int) config('filament-mailbox.graph.timeout', 60))
            ->withHeaders([
                'Prefer' => 'IdType="ImmutableId", outlook.body-content-type="html"',
                ...$headers,
            ]);
    }

    protected function retryAfter(Response $response, int $attempt): int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? min((int) $header, 300) : min(2 ** $attempt, 60);
    }
}
