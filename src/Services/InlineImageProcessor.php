<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Data\AttachmentData;
use Cooolinho\FilamentMailbox\Data\InlineImages;
use DOMDocument;
use DOMElement;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns the images of composed HTML into inline MIME parts.
 *
 * The rich editor stores uploaded images on the attachments disk and keeps
 * the storage path in "data-id". Only paths inside the given directories are
 * embedded; every other image (remote URLs, data URIs, foreign paths) is
 * removed, so no remote resource or foreign file ends up in a sent message.
 */
class InlineImageProcessor
{
    /**
     * @param  array<int, string>  $directories  Directories on the attachments disk images may come from
     * @param  bool  $asDataUri  Embed the images as data URIs instead (preview)
     */
    public function process(?string $html, array $directories, bool $asDataUri = false): InlineImages
    {
        if (blank($html)) {
            return new InlineImages('');
        }

        if (! str_contains(strtolower($html), '<img')) {
            return new InlineImages($html);
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $attachments = [];
        $contentIds = [];

        /** @var array<int, DOMElement> $images */
        $images = iterator_to_array($document->getElementsByTagName('img'));

        foreach ($images as $image) {
            $path = $this->normalizePath($image->getAttribute('data-id'));
            $contents = $path !== null && static::isAllowedPath($path, $directories) ? $this->read($path) : null;

            if ($contents === null) {
                $image->parentNode?->removeChild($image);

                continue;
            }

            [$data, $mimeType] = $contents;

            $image->removeAttribute('data-id');
            $image->removeAttribute('style');

            if ($asDataUri) {
                $image->setAttribute('src', 'data:'.$mimeType.';base64,'.base64_encode($data));

                continue;
            }

            if (! isset($contentIds[$path])) {
                $contentIds[$path] = Str::uuid()->toString().'@filament-mailbox';

                $attachments[] = new AttachmentData(
                    filename: AttachmentService::sanitizeFilename(basename($path)),
                    mimeType: $mimeType,
                    contents: $data,
                    contentId: $contentIds[$path],
                    inline: true,
                );
            }

            $image->setAttribute('src', 'cid:'.$contentIds[$path]);
        }

        $body = $document->getElementsByTagName('body')->item(0);
        $result = '';

        foreach ($body?->childNodes ?? [] as $child) {
            $result .= $document->saveHTML($child);
        }

        return new InlineImages($result, $attachments, array_keys($contentIds));
    }

    /**
     * Copy images from temporary directories (e.g. the compose uploads) into a
     * permanent directory and point "data-id" to the copies. Images elsewhere
     * are left unchanged.
     *
     * @param  array<int, string>  $fromDirectories
     */
    public function relocate(?string $html, array $fromDirectories, string $toDirectory): ?string
    {
        if (blank($html) || ! str_contains(strtolower($html), 'data-id')) {
            return $html;
        }

        $disk = $this->disk();

        return preg_replace_callback('/\sdata-id="([^"]*)"/i', function (array $match) use ($disk, $fromDirectories, $toDirectory): string {
            $path = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);

            if (! static::isAllowedPath($path, $fromDirectories) || static::isAllowedPath($path, [$toDirectory])) {
                return $match[0];
            }

            $target = rtrim($toDirectory, '/').'/'.basename($path);

            try {
                if (! $disk->exists($target)) {
                    $disk->copy($path, $target);
                }
            } catch (Throwable) {
                return $match[0];
            }

            return ' data-id="'.e($target).'"';
        }, $html);
    }

    /**
     * Directory of rich editor uploads while composing, per user.
     */
    public static function composeDirectory(int|string|null $userId): string
    {
        return static::directory('compose/'.($userId ?? 'guest'));
    }

    public static function directory(string $name): string
    {
        return trim((string) config('filament-mailbox.attachments.path', 'mailbox'), '/').'/'.$name;
    }

    /**
     * @param  array<int, string>  $directories
     */
    public static function isAllowedPath(string $path, array $directories): bool
    {
        if (str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return false;
        }

        foreach ($directories as $directory) {
            if (str_starts_with($path, rtrim($directory, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    public function disk(): Filesystem
    {
        return Storage::disk(config('filament-mailbox.attachments.disk'));
    }

    protected function normalizePath(string $path): ?string
    {
        $path = trim($path);

        return $path === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) ? null : $path;
    }

    /**
     * @return array{string, string}|null
     */
    protected function read(string $path): ?array
    {
        $maxBytes = (int) config('filament-mailbox.compose.max_inline_image_size', 2048) * 1024;

        try {
            $disk = $this->disk();

            if (! $disk->exists($path) || $disk->size($path) > $maxBytes) {
                return null;
            }

            $mimeType = (string) $disk->mimeType($path);

            if (! in_array($mimeType, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                return null;
            }

            $contents = $disk->get($path);
        } catch (Throwable) {
            return null;
        }

        return $contents === null ? null : [$contents, $mimeType];
    }
}
