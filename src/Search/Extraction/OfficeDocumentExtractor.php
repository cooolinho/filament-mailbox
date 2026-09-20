<?php

namespace Cooolinho\FilamentMailbox\Search\Extraction;

use RuntimeException;
use ZipArchive;

/**
 * DOCX and ODT: the text of the main XML part of the ZIP container.
 */
class OfficeDocumentExtractor implements TextExtractor
{
    public const PARTS = ['word/document.xml', 'content.xml'];

    /** Protection against ZIP bombs. */
    public const MAX_XML_BYTES = 50 * 1024 * 1024;

    public function supports(string $mimeType, string $filename): bool
    {
        return in_array($mimeType, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.oasis.opendocument.text'], true)
            || (bool) preg_match('/\.(docx|odt)$/i', $filename);
    }

    public function extract(string $path): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ext-zip is required.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Not a valid document.');
        }

        try {
            foreach (self::PARTS as $part) {
                $stat = $zip->statName($part);

                if ($stat === false) {
                    continue;
                }

                if ($stat['size'] > self::MAX_XML_BYTES) {
                    throw new RuntimeException('Document too large.');
                }

                $xml = (string) $zip->getFromName($part);
                // Paragraph and line breaks become new lines; entities are decoded without loading external resources.
                $xml = preg_replace('/<\/(w:p|text:p|text:h)>|<(w:br|text:line-break)\s*\/>/', "\n", $xml) ?? $xml;

                return trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            }
        } finally {
            $zip->close();
        }

        throw new RuntimeException('No text part found.');
    }
}
