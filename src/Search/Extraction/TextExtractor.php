<?php

namespace Cooolinho\FilamentMailbox\Search\Extraction;

interface TextExtractor
{
    public function supports(string $mimeType, string $filename): bool;

    /**
     * @param  string  $path  Local file
     */
    public function extract(string $path): string;
}
