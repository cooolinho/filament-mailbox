<?php

namespace Cooolinho\FilamentMailbox\Health\Checks;

use Cooolinho\FilamentMailbox\Health\HealthCheck;
use Cooolinho\FilamentMailbox\Health\HealthCheckResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes, reads and deletes a probe file on the attachment disk.
 */
class AttachmentDiskCheck implements HealthCheck
{
    public function key(): string
    {
        return 'attachment_disk';
    }

    public function label(): string
    {
        return __('filament-mailbox::mailbox.health.checks.attachment_disk.label');
    }

    public function isLive(): bool
    {
        return false;
    }

    public function run(): HealthCheckResult
    {
        $diskName = (string) config('filament-mailbox.attachments.disk', 'local');
        $path = trim((string) config('filament-mailbox.attachments.path', 'mailbox'), '/').'/.health/'.Str::uuid().'.txt';
        $content = Str::random(32);

        try {
            $disk = Storage::disk($diskName);

            if (! $disk->put($path, $content)) {
                return HealthCheckResult::failed(__('filament-mailbox::mailbox.health.checks.attachment_disk.not_writable', ['disk' => $diskName]));
            }

            $readable = $disk->get($path) === $content;
            $disk->delete($path);
        } catch (Throwable $exception) {
            return HealthCheckResult::failed(__('filament-mailbox::mailbox.health.checks.attachment_disk.error', [
                'disk' => $diskName,
                'error' => class_basename($exception),
            ]));
        }

        return $readable
            ? HealthCheckResult::ok(__('filament-mailbox::mailbox.health.checks.attachment_disk.ok', ['disk' => $diskName]))
            : HealthCheckResult::failed(__('filament-mailbox::mailbox.health.checks.attachment_disk.not_readable', ['disk' => $diskName]));
    }
}
