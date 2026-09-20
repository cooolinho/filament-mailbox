<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Database\Factories\MailboxAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $message_id
 * @property string $filename
 * @property ?string $mime_type
 * @property int $size
 * @property string $disk
 * @property string $storage_path
 */
class MailboxAttachment extends Model
{
    /** @use HasFactory<MailboxAttachmentFactory> */
    use HasFactory;

    protected $table = 'mailbox_attachments';

    protected $fillable = [
        'message_id',
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

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailboxMessage::class, 'message_id');
    }

    protected static function newFactory(): MailboxAttachmentFactory
    {
        return MailboxAttachmentFactory::new();
    }
}
