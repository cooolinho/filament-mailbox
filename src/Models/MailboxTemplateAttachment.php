<?php

namespace Cooolinho\FilamentMailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * @property int $id
 * @property int $template_id
 * @property string $filename
 * @property ?string $mime_type
 * @property int $size
 * @property string $disk
 * @property string $storage_path
 */
class MailboxTemplateAttachment extends Model
{
    protected $table = 'mailbox_template_attachments';

    protected $fillable = [
        'template_id',
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
        static::deleted(function (MailboxTemplateAttachment $attachment): void {
            rescue(fn () => Storage::disk($attachment->disk)->delete($attachment->storage_path), report: false);
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MailboxTemplate::class, 'template_id');
    }

    public function contents(): string
    {
        try {
            return (string) Storage::disk($this->disk)->get($this->storage_path);
        } catch (Throwable) {
            return '';
        }
    }
}
