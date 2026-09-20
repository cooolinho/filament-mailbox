<?php

namespace Cooolinho\FilamentMailbox\Search\Extraction;

use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Extracts the text of an attachment into mailbox_attachments.extracted_text
 * (limits for size, text length and time; errors are stored, not thrown).
 */
class AttachmentTextExtractor
{
    /** @var array<int, class-string<TextExtractor>> */
    public const EXTRACTORS = [PlainTextExtractor::class, PdfExtractor::class, OfficeDocumentExtractor::class];

    public static function enabled(): bool
    {
        return (bool) config('filament-mailbox.search.attachments.extract_text', false);
    }

    public function supports(MailboxAttachment $attachment): bool
    {
        return $this->extractor($attachment) !== null;
    }

    public function extract(MailboxAttachment $attachment): bool
    {
        $extractor = $this->extractor($attachment);

        if (! $extractor) {
            return false;
        }

        $temporary = null;

        try {
            if ($attachment->size > max(1, (int) config('filament-mailbox.search.attachments.max_size', 20480)) * 1024) {
                throw new RuntimeException('File too large.');
            }

            $temporary = tempnam(sys_get_temp_dir(), 'fm-extract-');
            file_put_contents($temporary, Storage::disk($attachment->disk)->get($attachment->storage_path) ?? throw new RuntimeException('File missing.'));

            $text = mb_substr(trim((string) preg_replace('/[ \t]+/u', ' ', mb_scrub($extractor->extract($temporary)))), 0, max(1000, (int) config('filament-mailbox.search.attachments.max_text_length', 200_000)));

            $attachment->forceFill(['extracted_text' => $text === '' ? null : $text, 'extracted_at' => now(), 'extraction_error' => null])->save();

            return true;
        } catch (Throwable $exception) {
            $attachment->forceFill(['extracted_text' => null, 'extracted_at' => now(), 'extraction_error' => mb_substr($exception->getMessage(), 0, 255)])->save();

            return false;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    protected function extractor(MailboxAttachment $attachment): ?TextExtractor
    {
        foreach (self::EXTRACTORS as $class) {
            $extractor = app($class);

            if ($extractor->supports(strtolower((string) $attachment->mime_type), $attachment->filename)) {
                return $extractor;
            }
        }

        return null;
    }
}
