<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Illuminate\Foundation\Events\Dispatchable;

class OAuthConnectionRevoked
{
    use Dispatchable;

    public function __construct(
        public OAuthConnection $connection,
    ) {}
}
