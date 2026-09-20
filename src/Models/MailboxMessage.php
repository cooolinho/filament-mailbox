<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Database\Factories\MailboxMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $mailbox_id
 * @property int $folder_id
 * @property ?string $remote_id
 * @property ?string $thread_id
 * @property ?int $uid
 * @property ?int $uid_validity
 * @property ?string $message_id
 * @property ?string $in_reply_to
 * @property ?string $references
 * @property ?string $from_address
 * @property ?string $from_name
 * @property ?array<int, array{address: string, name: ?string}> $reply_to
 * @property ?array<int, array{address: string, name: ?string}> $to
 * @property ?array<int, array{address: string, name: ?string}> $cc
 * @property ?string $subject
 * @property ?string $text_body
 * @property ?string $html_body
 * @property bool $is_read
 * @property bool $is_flagged
 * @property bool $is_answered
 * @property ?array<int, string> $keywords
 * @property bool $has_attachments
 * @property bool $is_draft
 * @property bool $is_auto_generated
 * @property ?int $draft_id
 * @property ?\Illuminate\Support\Carbon $forwarded_at
 * @property ?int $pending_move_to
 * @property ?\Illuminate\Support\Carbon $sent_at
 * @property ?\Illuminate\Support\Carbon $received_at
 * @property ?\Illuminate\Support\Carbon $snoozed_until
 * @property ?int $snoozed_by
 * @property ?\Illuminate\Support\Carbon $unsnoozed_at
 * @property ?\Illuminate\Support\Carbon $sort_at
 * @property ?int $snoozed_from_folder_id
 * @property ?string $mdn_requested_to
 * @property ?string $mdn_status
 * @property bool $is_receipt
 */
class MailboxMessage extends Model
{
    /** @use HasFactory<MailboxMessageFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'mailbox_messages';

    protected $fillable = [
        'mailbox_id',
        'folder_id',
        'remote_id',
        'thread_id',
        'uid',
        'uid_validity',
        'message_id',
        'in_reply_to',
        'references',
        'from_address',
        'from_name',
        'reply_to',
        'to',
        'cc',
        'subject',
        'text_body',
        'html_body',
        'is_read',
        'is_flagged',
        'is_answered',
        'is_draft',
        'draft_id',
        'keywords',
        'has_attachments',
        'is_auto_generated',
        'mdn_requested_to',
        'mdn_status',
        'is_receipt',
        'sent_at',
        'received_at',
    ];

    protected $attributes = [
        'is_read' => false,
        'is_flagged' => false,
        'is_answered' => false,
        'is_draft' => false,
        'has_attachments' => false,
        'is_auto_generated' => false,
        'is_receipt' => false,
    ];

    protected function casts(): array
    {
        return [
            'uid' => 'integer',
            'uid_validity' => 'integer',
            'reply_to' => 'array',
            'to' => 'array',
            'cc' => 'array',
            'is_read' => 'boolean',
            'is_flagged' => 'boolean',
            'is_answered' => 'boolean',
            'is_draft' => 'boolean',
            'is_auto_generated' => 'boolean',
            'is_receipt' => 'boolean',
            'keywords' => 'array',
            'has_attachments' => 'boolean',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'forwarded_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'unsnoozed_at' => 'datetime',
            'sort_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MailboxMessage $message): void {
            $message->sort_at ??= $message->received_at ?? now();
        });
    }

    /**
     * Messages that are not snoozed (anymore).
     */
    public function scopeNotSnoozed(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull($query->qualifyColumn('snoozed_until'))
            ->orWhere($query->qualifyColumn('snoozed_until'), '<=', now()));
    }

    public function scopeSnoozed(Builder $query): void
    {
        $query->where($query->qualifyColumn('snoozed_until'), '>', now());
    }

    public function isSnoozed(): bool
    {
        return $this->snoozed_until !== null && $this->snoozed_until->isFuture();
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MailboxFolder::class, 'folder_id');
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(MailboxDraft::class, 'draft_id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(MailboxLabel::class, 'mailbox_message_labels', 'message_id', 'label_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(MailboxTag::class, 'mailbox_message_tag', 'message_id', 'tag_id')
            ->withPivot(['message_key', 'tagged_by'])
            ->withTimestamps();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MailboxAttachment::class, 'message_id');
    }

    public function fromLabel(): string
    {
        return $this->from_name ?: ($this->from_address ?? '');
    }

    protected static function newFactory(): MailboxMessageFactory
    {
        return MailboxMessageFactory::new();
    }
}
