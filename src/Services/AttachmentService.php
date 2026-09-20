<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Data\AttachmentData;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentService
{
    public function store(MailboxMessage $message, AttachmentData $data): MailboxAttachment
    {
        $disk = (string) config('filament-mailbox.attachments.disk');

        // The original filename is never used as part of the storage path.
        $path = implode('/', [
            trim((string) config('filament-mailbox.attachments.path'), '/'),
            $message->mailbox_id,
            $message->getKey(),
            (string) Str::uuid(),
        ]);

        Storage::disk($disk)->put($path, $data->contents);

        return $message->attachments()->create([
            'filename' => static::sanitizeFilename($data->filename),
            'mime_type' => mb_substr($data->mimeType, 0, 255),
            'size' => $data->size(),
            'disk' => $disk,
            'storage_path' => $path,
        ]);
    }

    public function contents(MailboxAttachment $attachment): string
    {
        return (string) Storage::disk($attachment->disk)->get($attachment->storage_path);
    }

    public const DOWNLOAD_ROUTE = 'filament-mailbox.attachments.download';

    public function download(MailboxAttachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->storage_path), 404);

        return $disk->download($attachment->storage_path, static::sanitizeFilename($attachment->filename), [
            // Never let the browser render untrusted attachment content inline.
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function downloadUrl(MailboxAttachment $attachment): string
    {
        $panel = Filament::getCurrentOrDefaultPanel()->getId();

        return route("filament.{$panel}.".static::DOWNLOAD_ROUTE, ['attachment' => $attachment->getKey()]);
    }

    public function deleteFiles(MailboxMessage $message): void
    {
        $message->attachments->each(
            fn (MailboxAttachment $attachment) => Storage::disk($attachment->disk)->delete($attachment->storage_path),
        );
    }

    public static function sanitizeFilename(string $filename): string
    {
        // Strip directory parts, control characters and header-breaking characters.
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F"\/\\\\]/u', '', $filename) ?? '';
        $filename = trim($filename, " .\t");

        return mb_substr($filename !== '' ? $filename : 'attachment', 0, 255);
    }
}
