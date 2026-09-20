<?php

namespace Cooolinho\FilamentMailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A read receipt or delivery report requested for a sent message.
 *
 * @property int $id
 * @property int $mailbox_id
 * @property ?int $outgoing_message_id
 * @property string $message_id
 * @property string $type
 * @property array<int, string> $recipients
 */
class MailboxReceiptRequest extends Model
{
    public const TYPE_READ = 'read';

    public const TYPE_DELIVERY = 'delivery';

    protected $table = 'mailbox_receipt_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function outgoingMessage(): BelongsTo
    {
        return $this->belongsTo(MailboxOutgoingMessage::class, 'outgoing_message_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(MailboxReceipt::class, 'request_id');
    }
}
