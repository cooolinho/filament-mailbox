<?php

namespace Cooolinho\FilamentMailbox\Providers\Gmail;

use RuntimeException;

/**
 * A Gmail API request failed. Only status, reason and message of the error
 * are kept, never request headers (bearer tokens).
 */
class GmailRequestFailed extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $body  Decoded error response
     */
    public function __construct(public readonly int $status, ?array $body = null)
    {
        $message = is_string($body['error']['message'] ?? null) ? mb_substr($body['error']['message'], 0, 300) : null;

        parent::__construct(sprintf('Gmail request failed (HTTP %d)%s', $status, $message ? ": {$message}" : '.'));
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
