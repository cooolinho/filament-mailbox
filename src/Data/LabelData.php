<?php

namespace Cooolinho\FilamentMailbox\Data;

use Cooolinho\FilamentMailbox\Enums\LabelColor;
use Cooolinho\FilamentMailbox\Enums\LabelSource;

final readonly class LabelData
{
    /**
     * @param  string  $remoteKey  IMAP keyword, Gmail label id or Graph category name
     */
    public function __construct(
        public LabelSource $source,
        public string $remoteKey,
        public string $name,
        public ?LabelColor $color = null,
    ) {}
}
