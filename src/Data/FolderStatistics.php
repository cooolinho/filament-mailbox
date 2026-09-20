<?php

namespace Cooolinho\FilamentMailbox\Data;

final readonly class FolderStatistics
{
    public function __construct(
        public ?int $messages = null,
        public ?int $unseen = null,
        public ?int $size = null,
    ) {}
}
