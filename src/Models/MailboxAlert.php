<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Enums\AlertRule;
use Cooolinho\FilamentMailbox\Enums\AlertSeverity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property ?int $mailbox_id
 * @property AlertRule $rule
 * @property AlertSeverity $severity
 * @property string $message
 * @property \Illuminate\Support\Carbon $opened_at
 * @property ?\Illuminate\Support\Carbon $notified_at
 * @property ?\Illuminate\Support\Carbon $resolved_at
 * @property ?int $acknowledged_by
 */
class MailboxAlert extends Model
{
    public $timestamps = false;

    protected $table = 'mailbox_alerts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rule' => AlertRule::class,
            'severity' => AlertSeverity::class,
            'opened_at' => 'datetime',
            'notified_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }
}
