<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Database\Factories\MailboxBlockedSenderFactory;
use Cooolinho\FilamentMailbox\Services\BlockedSenderMatcher;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sender address (user@example.com) or domain (*@example.com) whose
 * messages are moved to spam when they arrive in the inbox.
 *
 * @property int $id
 * @property int $mailbox_id
 * @property string $pattern
 * @property ?int $created_by
 */
class MailboxBlockedSender extends Model
{
    /** @use HasFactory<MailboxBlockedSenderFactory> */
    use HasFactory;

    protected $table = 'mailbox_blocked_senders';

    protected $fillable = [
        'mailbox_id',
        'pattern',
        'created_by',
    ];

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    protected function pattern(): Attribute
    {
        return Attribute::set(fn (string $value): string => BlockedSenderMatcher::normalize($value));
    }

    protected static function newFactory(): MailboxBlockedSenderFactory
    {
        return MailboxBlockedSenderFactory::new();
    }
}
