<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Database\Factories\MailboxDraftFactory;
use Cooolinho\FilamentMailbox\Services\InlineImageProcessor;
use Cooolinho\FilamentMailbox\Support\ComposeContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A message being written. The local record is the source of truth while
 * editing; a copy is stored in the drafts folder of the server.
 *
 * @property int $id
 * @property string $uuid
 * @property int $mailbox_id
 * @property ?int $user_id
 * @property ?int $reply_to_message_id
 * @property string $mode
 * @property ?array<int, string> $to
 * @property ?array<int, string> $cc
 * @property ?array<int, string> $bcc
 * @property ?string $subject
 * @property string $body_format
 * @property ?string $body
 * @property ?string $body_html
 * @property ?string $quoted
 * @property ?string $quoted_html
 * @property ?int $signature_id
 * @property bool $as_attachment
 * @property ?array<int, int> $original_attachment_ids
 * @property ?string $in_reply_to
 * @property ?string $references
 * @property ?int $remote_folder_id
 * @property ?string $remote_id
 * @property ?string $server_message_id
 * @property int $revision
 * @property ?int $server_revision
 * @property ?\Illuminate\Support\Carbon $server_synced_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class MailboxDraft extends Model
{
    /** @use HasFactory<MailboxDraftFactory> */
    use HasFactory;

    public const MODE_NEW = 'new';

    public const MODE_REPLY = 'reply';

    public const MODE_REPLY_ALL = 'reply_all';

    public const MODE_FORWARD = 'forward';

    protected $table = 'mailbox_drafts';

    protected $fillable = [
        'mailbox_id',
        'user_id',
        'reply_to_message_id',
        'mode',
        'to',
        'cc',
        'bcc',
        'subject',
        'body_format',
        'body',
        'body_html',
        'quoted',
        'quoted_html',
        'signature_id',
        'as_attachment',
        'original_attachment_ids',
        'in_reply_to',
        'references',
    ];

    protected $attributes = [
        'mode' => self::MODE_NEW,
        'body_format' => 'text',
        'as_attachment' => false,
        'revision' => 0,
    ];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'as_attachment' => 'boolean',
            'revision' => 'integer',
            'server_revision' => 'integer',
            'original_attachment_ids' => 'array',
            'server_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MailboxDraft $draft): void {
            $draft->uuid ??= (string) Str::uuid();
        });
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('filament-mailbox.user_model'));
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(MailboxMessage::class, 'reply_to_message_id');
    }

    /**
     * @return HasMany<MailboxDraftAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MailboxDraftAttachment::class, 'draft_id');
    }

    /**
     * Versions of the draft synchronised from the drafts folder.
     *
     * @return HasMany<MailboxMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(MailboxMessage::class, 'draft_id');
    }

    public function context(): ComposeContext
    {
        return match ($this->mode) {
            self::MODE_REPLY, self::MODE_REPLY_ALL => ComposeContext::Reply,
            self::MODE_FORWARD => ComposeContext::Forward,
            default => ComposeContext::New,
        };
    }

    public function isOwnedBy(?Authenticatable $user): bool
    {
        return $user !== null && $this->user_id !== null && (string) $this->user_id === (string) $user->getAuthIdentifier();
    }

    /**
     * Storage directory of attachments and inline images on the attachments disk.
     */
    public function directory(): string
    {
        return InlineImageProcessor::directory('drafts/'.$this->uuid);
    }

    public function hasRecipients(): bool
    {
        return filled($this->to) || filled($this->cc) || filled($this->bcc);
    }

    public function isSynced(): bool
    {
        return $this->server_revision !== null && $this->server_revision === $this->revision;
    }

    protected static function newFactory(): MailboxDraftFactory
    {
        return MailboxDraftFactory::new();
    }
}
