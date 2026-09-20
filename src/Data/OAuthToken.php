<?php

namespace Cooolinho\FilamentMailbox\Data;

use Carbon\CarbonInterface;

final readonly class OAuthToken
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?CarbonInterface $expiresAt = null,
        public array $scopes = [],
        public ?string $email = null,
        public ?string $subject = null,
    ) {}
}
