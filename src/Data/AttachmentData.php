<?php

namespace Cooolinho\FilamentMailbox\Data;

final readonly class AttachmentData
{
    /**
     * @param  bool  $inline  Inline part referenced from the HTML body with "cid:{contentId}"
     */
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $contents,
        public ?string $contentId = null,
        public bool $inline = false,
    ) {}

    public function size(): int
    {
        return strlen($this->contents);
    }
}
