<?php

namespace Cooolinho\FilamentMailbox\Providers\Gmail;

use Cooolinho\FilamentMailbox\Exceptions\ConnectionFailed;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\OAuth\OAuthTokenManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Minimal Gmail REST client on Laravel's HTTP client.
 *
 * - retries 429, 5xx and 403 rate limit errors with Retry-After / exponential backoff
 * - limits requests per mailbox (RateLimiter "gmail:{mailbox}")
 * - renews the token once on 401
 * - batch requests (up to 100 per call) for message downloads
 */
class GmailClient
{
    public const BASE_URL = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public const BATCH_URL = 'https://gmail.googleapis.com/batch/gmail/v1';

    protected ?string $token = null;

    public function __construct(
        protected Mailbox $mailbox,
        protected OAuthTokenManager $tokens,
    ) {}

    /**
     * @param  array<string, scalar|array<int, scalar>>  $query  Arrays are sent as repeated parameters
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $this->url($path, $query))->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        return $this->send('POST', $this->url($path), $body)->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function patch(string $path, array $body): array
    {
        return $this->send('PATCH', $this->url($path), $body)->json() ?? [];
    }

    public function delete(string $path): void
    {
        $this->send('DELETE', $this->url($path));
    }

    /**
     * Run GET requests in batches. Returns the decoded bodies keyed like the
     * input; items that do not exist (404) are null.
     *
     * @param  array<string, string>  $paths  Relative paths incl. query, keyed by caller key
     * @return array<string, array<string, mixed>|null>
     */
    public function batchGet(array $paths): array
    {
        $results = [];
        $size = max(1, min(100, (int) config('filament-mailbox.gmail.batch_size', 50)));

        foreach (array_chunk($paths, $size, preserve_keys: true) as $chunk) {
            $results += count($chunk) === 1 ? $this->single($chunk) : $this->batch($chunk);
        }

        return $results;
    }

    /**
     * @param  array<string, scalar|array<int, scalar>>  $query
     */
    public function url(string $path, array $query = []): string
    {
        $pairs = [];

        foreach ($query as $key => $value) {
            foreach ((array) $value as $item) {
                $pairs[] = rawurlencode($key).'='.rawurlencode((string) $item);
            }
        }

        return static::BASE_URL.'/'.ltrim($path, '/').($pairs === [] ? '' : (str_contains($path, '?') ? '&' : '?').implode('&', $pairs));
    }

    /**
     * @param  array<string, string>  $chunk
     * @return array<string, array<string, mixed>|null>
     */
    protected function single(array $chunk): array
    {
        $key = array_key_first($chunk);

        try {
            return [$key => $this->send('GET', static::BASE_URL.'/'.ltrim($chunk[$key], '/'))->json()];
        } catch (GmailRequestFailed $exception) {
            if ($exception->isNotFound()) {
                return [$key => null];
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, string>  $chunk
     * @return array<string, array<string, mixed>|null>
     */
    protected function batch(array $chunk): array
    {
        $results = [];
        $pending = $chunk;
        $maxRetries = max(0, (int) config('filament-mailbox.gmail.max_retries', 5));

        for ($attempt = 0; $pending !== []; $attempt++) {
            $boundary = 'batch_'.Str::random(16);
            $keys = array_keys($pending);
            $body = '';

            foreach ($keys as $index => $key) {
                $body .= "--{$boundary}\r\nContent-Type: application/http\r\nContent-ID: <item-{$index}>\r\n\r\n"
                    .'GET /gmail/v1/users/me/'.ltrim($pending[$key], '/')."\r\n\r\n";
            }

            $body .= "--{$boundary}--\r\n";

            $response = $this->send('POST', static::BATCH_URL, rawBody: $body, contentType: "multipart/mixed; boundary={$boundary}", units: count($keys));
            $retry = [];

            foreach (static::parseBatch($response) as $index => [$status, $json]) {
                $key = $keys[$index] ?? null;

                if ($key === null) {
                    continue;
                }

                match (true) {
                    $status >= 200 && $status < 300 => $results[$key] = $json,
                    $status === 404 => $results[$key] = null,
                    ($status === 429 || $status >= 500 || static::isRateLimited($status, $json)) && $attempt < $maxRetries => $retry[$key] = $pending[$key],
                    default => throw new GmailRequestFailed($status, $json),
                };

                unset($pending[$key]);
            }

            // Items missing in the response are retried as well.
            $pending = [...$retry, ...$pending];

            if ($pending !== []) {
                if ($attempt >= $maxRetries) {
                    throw new RuntimeException('Gmail batch request did not complete.');
                }

                Sleep::for(min(2 ** $attempt, 60))->seconds();
            }
        }

        return $results;
    }

    /**
     * @return array<int, array{int, array<string, mixed>|null}> Status and body keyed by item index
     */
    public static function parseBatch(Response $response): array
    {
        if (! preg_match('/boundary="?([^";]+)"?/i', (string) $response->header('Content-Type'), $matches)) {
            throw new RuntimeException('Invalid Gmail batch response.');
        }

        $parts = [];

        foreach (explode('--'.$matches[1], $response->body()) as $part) {
            if (! preg_match('/Content-ID:\s*<response-item-(\d+)>/i', $part, $id)
                || ! preg_match('/HTTP\/[\d.]+\s+(\d{3})/', $part, $status)) {
                continue;
            }

            $inner = substr($part, strpos($part, $status[0]));
            $separator = preg_split('/\r?\n\r?\n/', $inner, 2);

            $parts[(int) $id[1]] = [(int) $status[1], json_decode(trim($separator[1] ?? ''), true)];
        }

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function send(string $method, string $url, ?array $body = null, ?string $rawBody = null, ?string $contentType = null, int $units = 1): Response
    {
        $maxRetries = max(0, (int) config('filament-mailbox.gmail.max_retries', 5));
        $renewed = false;

        for ($attempt = 0; ; $attempt++) {
            $this->throttle($units);

            try {
                $request = Http::withToken($this->token())
                    ->acceptJson()
                    ->timeout((int) config('filament-mailbox.gmail.timeout', 60));

                if ($rawBody !== null) {
                    $request->withBody($rawBody, (string) $contentType);
                }

                $response = $request->send($method, $url, $body === null ? [] : ['json' => $body]);
            } catch (ConnectionException $exception) {
                throw ConnectionFailed::for($this->mailbox, $exception);
            }

            if ($response->status() === 401 && ! $renewed) {
                $this->token = $this->tokens->renew($this->mailbox->oauthConnection);
                $renewed = true;

                continue;
            }

            $retryable = $response->status() === 429 || $response->status() >= 500 || static::isRateLimited($response->status(), $response->json());

            if ($retryable && $attempt < $maxRetries) {
                $header = $response->header('Retry-After');
                Sleep::for(is_numeric($header) ? min((int) $header, 300) : min(2 ** $attempt, 60))->seconds();

                continue;
            }

            if ($response->status() === 401) {
                throw ConnectionFailed::for($this->mailbox, new RuntimeException('Gmail rejected the access token (HTTP 401).'));
            }

            if ($response->failed()) {
                throw new GmailRequestFailed($response->status(), $response->json());
            }

            return $response;
        }
    }

    /**
     * @param  mixed  $body  Decoded error response
     */
    protected static function isRateLimited(int $status, mixed $body): bool
    {
        $reason = is_array($body) ? ($body['error']['errors'][0]['reason'] ?? null) : null;

        return $status === 403 && in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true);
    }

    /**
     * Keep the requests per mailbox below the per-user quota.
     */
    protected function throttle(int $units): void
    {
        $key = 'filament-mailbox:gmail:'.$this->mailbox->getKey();
        $max = max(1, (int) config('filament-mailbox.gmail.requests_per_second', 40));

        // The window is one second, so a single wait frees the budget again.
        if (RateLimiter::tooManyAttempts($key, $max)) {
            Sleep::for(max(1, RateLimiter::availableIn($key)))->seconds();
            RateLimiter::clear($key);
        }

        RateLimiter::increment($key, 1, $units);
    }

    protected function token(): string
    {
        return $this->token ??= $this->tokens->accessToken($this->mailbox->oauthConnection
            ?? throw new ConnectionFailed('The mailbox has no OAuth connection. Connect an account first.'));
    }
}
