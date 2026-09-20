<?php

namespace Cooolinho\FilamentMailbox\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * An OAuth endpoint rejected a request. Only the error code and description
 * of the response are kept, never request parameters or tokens.
 */
class OAuthRequestFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $error = null)
    {
        parent::__construct($message);
    }

    public static function fromResponse(string $operation, Response $response): self
    {
        $error = is_string($response->json('error')) ? $response->json('error') : null;
        $description = is_string($response->json('error_description')) ? $response->json('error_description') : null;

        return new self(
            sprintf('OAuth %s failed (HTTP %d)%s', $operation, $response->status(), $error ? ": {$error}".($description ? " - ".mb_substr($description, 0, 300) : '') : '.'),
            $error,
        );
    }

    public function isInvalidGrant(): bool
    {
        return $this->error === 'invalid_grant';
    }
}
