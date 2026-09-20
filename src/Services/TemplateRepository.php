<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Data\AttachmentData;
use Cooolinho\FilamentMailbox\Events\TemplateUsed;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxTemplate;
use Cooolinho\FilamentMailbox\Models\MailboxTemplateAttachment;
use Cooolinho\FilamentMailbox\Support\ComposeContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TemplateRepository
{
    public static function enabled(): bool
    {
        return (bool) config('filament-mailbox.templates.enabled', true);
    }

    /**
     * @return Collection<int, MailboxTemplate>
     */
    public function availableFor(Mailbox $mailbox, ComposeContext $context): Collection
    {
        if (! static::enabled()) {
            return new Collection;
        }

        return MailboxTemplate::query()
            ->availableFor($mailbox, $context)
            // Templates without category last.
            ->orderByRaw('case when category is null then 1 else 0 end')
            ->orderBy('category')
            ->orderByDesc('usage_count')
            ->orderBy('name')
            ->get();
    }

    public function find(Mailbox $mailbox, ComposeContext $context, mixed $id): ?MailboxTemplate
    {
        if (! static::enabled() || blank($id) || ! is_scalar($id)) {
            return null;
        }

        return MailboxTemplate::query()->availableFor($mailbox, $context)->whereKey($id)->first();
    }

    /**
     * Select options grouped by category.
     *
     * @return array<string, array<int|string, string>>
     */
    public function options(Mailbox $mailbox, ComposeContext $context): array
    {
        return $this->availableFor($mailbox, $context)
            ->groupBy(fn (MailboxTemplate $template): string => $template->category ?? __('filament-mailbox::mailbox.templates.uncategorized'))
            ->map(fn (Collection $templates): array => $templates->pluck('name', 'id')->all())
            ->all();
    }

    /**
     * Selected attachments of a template available in the mailbox; other ids are ignored.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, AttachmentData>
     */
    public function attachments(Mailbox $mailbox, ComposeContext $context, mixed $templateId, array $ids): array
    {
        $template = $ids === [] ? null : $this->find($mailbox, $context, $templateId);

        if (! $template) {
            return [];
        }

        return $template->attachments()
            ->whereKey($ids)
            ->get()
            ->map(fn (MailboxTemplateAttachment $attachment): AttachmentData => new AttachmentData(
                filename: $attachment->filename,
                mimeType: $attachment->mime_type ?: 'application/octet-stream',
                contents: $attachment->contents(),
            ))
            ->all();
    }

    /**
     * Count the use of templates after sending and dispatch TemplateUsed.
     *
     * @param  array<int, int|string>  $ids
     */
    public function recordUsage(Mailbox $mailbox, ComposeContext $context, array $ids, ?Authenticatable $user): void
    {
        foreach (array_unique(array_filter($ids, is_scalar(...))) as $id) {
            if ($template = $this->find($mailbox, $context, $id)) {
                $template->newQuery()->whereKey($template->getKey())->update(['usage_count' => DB::raw('usage_count + 1')]);

                TemplateUsed::dispatch($template, $mailbox, $context, $user?->getAuthIdentifier());
            }
        }
    }

    /**
     * Replace the attachments of a template with the files of the form.
     *
     * @param  array<int|string, string>  $paths  Storage paths on the attachments disk
     * @param  array<string, string>  $names  Original file names keyed by path
     */
    public function syncAttachments(MailboxTemplate $template, array $paths, array $names): void
    {
        $disk = (string) config('filament-mailbox.attachments.disk');
        $paths = array_values(array_filter($paths, is_string(...)));

        $template->attachments()->whereNotIn('storage_path', $paths)->get()->each->delete();

        $existing = $template->attachments()->pluck('storage_path')->all();

        foreach (array_diff($paths, $existing) as $path) {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                continue;
            }

            $template->attachments()->create([
                'filename' => AttachmentService::sanitizeFilename($names[$path] ?? basename($path)),
                'mime_type' => $storage->mimeType($path) ?: null,
                'size' => $storage->size($path),
                'disk' => $disk,
                'storage_path' => $path,
            ]);
        }
    }
}
