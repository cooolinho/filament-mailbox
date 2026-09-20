<?php

namespace Cooolinho\FilamentMailbox\Search\Extraction;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * pdftotext (poppler-utils) without shell, with a timeout.
 */
class PdfExtractor implements TextExtractor
{
    public function supports(string $mimeType, string $filename): bool
    {
        return $mimeType === 'application/pdf' || str_ends_with(strtolower($filename), '.pdf');
    }

    public function extract(string $path): string
    {
        $result = Process::timeout(max(1, (int) config('filament-mailbox.search.attachments.timeout', 30)))
            ->run([(string) config('filament-mailbox.search.attachments.pdftotext_binary', 'pdftotext'), '-enc', 'UTF-8', '-q', $path, '-']);

        if (! $result->successful()) {
            throw new RuntimeException('pdftotext failed: '.mb_substr(trim($result->errorOutput()), 0, 200));
        }

        return $result->output();
    }
}
