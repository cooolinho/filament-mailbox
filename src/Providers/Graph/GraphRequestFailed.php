<?php

namespace Cooolinho\FilamentMailbox\Providers\Graph;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A Graph request failed. Only the status and the Graph error code/message
 * are kept, never request headers (bearer tokens).
 */
class GraphRequestFailed extends RuntimeException
{
    public readonly int $status;

    public readonly ?string $graphCode;

    public function __construct(Response $response)
    {
        $this->status = $response->status();
        $this->graphCode = is_string($response->json('error.code')) ? $response->json('error.code') : null;
        $message = is_string($response->json('error.message')) ? mb_substr($response->json('error.message'), 0, 300) : null;

        parent::__construct(sprintf('Graph request failed (HTTP %d)%s', $this->status, $this->graphCode ? ": {$this->graphCode}".($message ? " - {$message}" : '') : '.'));
    }

    public function isSyncStateExpired(): bool
    {
        return $this->status === 410 || in_array(strtolower((string) $this->graphCode), ['syncstatenotfound', 'syncstateinvalid', 'resyncrequired'], true);
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
