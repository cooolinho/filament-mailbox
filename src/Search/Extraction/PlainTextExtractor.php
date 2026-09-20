<?php

namespace Cooolinho\FilamentMailbox\Search\Extraction;

use Cooolinho\FilamentMailbox\Support\HtmlToText;

class PlainTextExtractor implements TextExtractor
{
    public function supports(string $mimeType, string $filename): bool
    {
        return in_array($mimeType, ['text/plain', 'text/csv', 'text/markdown', 'text/html'], true)
            || (bool) preg_match('/\.(txt|csv|md|log|html?)$/i', $filename);
    }

    public function extract(string $path): string
    {
        $contents = (string) file_get_contents($path);
        $contents = mb_check_encoding($contents, 'UTF-8') ? $contents : mb_convert_encoding($contents, 'UTF-8', 'ISO-8859-1');

        return str_contains($contents, '</') ? HtmlToText::convert($contents) : $contents;
    }
}
