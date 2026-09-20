<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Data\AttachmentData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An attachment of a queued message, stored privately until it is sent.
 *
 * @property int $id
 * @property int $outgoing_message_id
 * @property string $filename
 * @property ?string $mime_type
 * @property int $size
 * @property ?string $content_id
 * @property bool $inline
 * @property string $disk
 * @property string $storage_path
 */
class MailboxOutgoingAttachment extends Model
{
    protected $table = 'mailbox_outgoing_attachments';

    protected $fillable = [
        'outgoing_message_id',
        'filename',
        'mime_type',
        'size',
        'content_id',
        'inline',
        'disk',
        'storage_path',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'inline' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (MailboxOutgoingAttachment $attachment): void {
            rescue(fn () => Storage::disk($attachment->disk)->delete($attachment->storage_path), report: false);
        });
    }

    public function outgoingMessage(): BelongsTo
    {
        return $this->belongsTo(MailboxOutgoingMessage::class, 'outgoing_message_id');
    }

    /**
     * A missing file fails the delivery instead of sending an empty attachment.
     */
    public function toData(): AttachmentData
    {
        $contents = Storage::disk($this->disk)->get($this->storage_path);

        if ($contents === null) {
            throw new \RuntimeException("The attachment [{$this->filename}] of the queued message is missing.");
        }

        return new AttachmentData($this->filename, $this->mime_type ?: 'application/octet-stream', $contents, $this->content_id, $this->inline);
    }
}
