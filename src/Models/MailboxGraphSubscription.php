<?php

namespace Cooolinho\FilamentMailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Microsoft Graph change notification subscription for one folder.
 *
 * @property int $id
 * @property int $folder_id
 * @property string $subscription_id
 * @property string $client_state
 * @property \Illuminate\Support\Carbon $expires_at
 * @property-read MailboxFolder $folder
 */
class MailboxGraphSubscription extends Model
{
    protected $table = 'mailbox_graph_subscriptions';

    protected $fillable = [
        'folder_id',
        'subscription_id',
        'client_state',
        'expires_at',
    ];

    protected $hidden = [
        'client_state',
    ];

    protected function casts(): array
    {
        return [
            'client_state' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MailboxFolder::class, 'folder_id');
    }
}
