<?php

namespace Cooolinho\FilamentMailbox\Data;

/**
 * HTML with its images turned into inline parts.
 */
final readonly class InlineImages
{
    /**
     * @param  array<int, AttachmentData>  $attachments  Inline parts referenced with "cid:"
     * @param  array<int, string>  $paths  Storage paths of the embedded images
     */
    public function __construct(
        public string $html,
        public array $attachments = [],
        public array $paths = [],
    ) {}
}
