<?php

namespace Cooolinho\FilamentMailbox\Data;

final readonly class RenderedTemplate
{
    /**
     * @param  array<int, string>  $unknown  Placeholders without a value, left unchanged
     */
    public function __construct(
        public string $content,
        public array $unknown = [],
    ) {}
}
