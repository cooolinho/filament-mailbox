<?php

namespace Cooolinho\FilamentMailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A received read receipt (MDN) or delivery report for one recipient.
 *
 * @property int $id
 * @property int $request_id
 * @property ?int $receipt_message_id
 * @property string $recipient
 * @property string $disposition
 * @property ?string $diagnostic
 * @property \Illuminate\Support\Carbon $reported_at
 * @property ?string $status_code
 * @property string $source
 * @property ?string $reporting_mta
 */
class MailboxReceipt extends Model
{
    public const SOURCE_MDN = 'mdn';

    public const SOURCE_DSN = 'dsn';

    public const SOURCE_BOUNCE_HEURISTIC = 'bounce_heuristic';

    public const DELIVERED = 'delivered';

    public const DELAYED = 'delayed';

    public const FAILED = 'failed';

    protected $table = 'mailbox_receipts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MailboxReceiptRequest::class, 'request_id');
    }

    public function receiptMessage(): BelongsTo
    {
        return $this->belongsTo(MailboxMessage::class, 'receipt_message_id');
    }
}
