<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Data\AttachmentData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * @property int $id
 * @property int $draft_id
 * @property string $filename
 * @property ?string $mime_type
 * @property int $size
 * @property string $disk
 * @property string $storage_path
 */
class MailboxDraftAttachment extends Model
{
    protected $table = 'mailbox_draft_attachments';

    protected $fillable = [
        'draft_id',
        'filename',
        'mime_type',
        'size',
        'disk',
        'storage_path',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (MailboxDraftAttachment $attachment): void {
            rescue(fn () => Storage::disk($attachment->disk)->delete($attachment->storage_path), report: false);
        });
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(MailboxDraft::class, 'draft_id');
    }

    public function toData(): AttachmentData
    {
        try {
            $contents = (string) Storage::disk($this->disk)->get($this->storage_path);
        } catch (Throwable) {
            $contents = '';
        }

        return new AttachmentData($this->filename, $this->mime_type ?: 'application/octet-stream', $contents);
    }
}
