<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Enums\OutgoingStatus;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A message of the outbox: every message sent from the app, scheduled or immediate.
 *
 * @property int $id
 * @property string $uuid
 * @property int $mailbox_id
 * @property ?int $user_id
 * @property OutgoingStatus $status
 * @property array<int, string> $to
 * @property ?array<int, string> $cc
 * @property ?array<int, string> $bcc
 * @property string $subject
 * @property string $body
 * @property ?string $body_html
 * @property string $message_id
 * @property ?string $in_reply_to
 * @property ?array<int, string> $references
 * @property ?string $provider_thread_id
 * @property ?string $forwarded_message_id
 * @property ?array<string, string> $headers
 * @property ?int $reply_to_message_id
 * @property ?int $forward_of_message_id
 * @property bool $forward_as_attachment
 * @property \Illuminate\Support\Carbon $send_at
 * @property ?\Illuminate\Support\Carbon $sent_at
 * @property int $attempts
 * @property ?string $last_error
 * @property ?string $dsn_notify
 * @property ?bool $dsn_supported
 */
class MailboxOutgoingMessage extends Model
{
    protected $table = 'mailbox_outgoing_messages';

    protected $guarded = ['id'];

    protected $attributes = [
        'attempts' => 0,
        'forward_as_attachment' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => OutgoingStatus::class,
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'encrypted:array',
            'references' => 'array',
            'headers' => 'array',
            'forward_as_attachment' => 'boolean',
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
            'attempts' => 'integer',
            'dsn_supported' => 'boolean',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('filament-mailbox.user_model'), 'user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MailboxOutgoingAttachment::class, 'outgoing_message_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(MailboxMessage::class, 'reply_to_message_id');
    }

    public function forwardOf(): BelongsTo
    {
        return $this->belongsTo(MailboxMessage::class, 'forward_of_message_id');
    }

    public function isOwnedBy(Authenticatable $user): bool
    {
        return $this->user_id !== null && (string) $this->user_id === (string) $user->getAuthIdentifier();
    }

    /**
     * @return array<int, string>
     */
    public function recipients(): array
    {
        return [...$this->to ?? [], ...$this->cc ?? [], ...$this->bcc ?? []];
    }

    public function directory(): string
    {
        return \Cooolinho\FilamentMailbox\Services\InlineImageProcessor::directory('outbox/'.$this->uuid);
    }
}
