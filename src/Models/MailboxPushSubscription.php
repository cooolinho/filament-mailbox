<?php

namespace Cooolinho\FilamentMailbox\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Web push subscription of a browser. Pushes carry no payload, so no
 * encryption keys are stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 */
class MailboxPushSubscription extends Model
{
    protected $table = 'mailbox_push_subscriptions';

    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash'];

    protected $hidden = ['endpoint'];

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
