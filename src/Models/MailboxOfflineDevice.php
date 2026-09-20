<?php

namespace Cooolinho\FilamentMailbox\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * A browser of a user that keeps an encrypted offline copy of selected folders.
 *
 * @property int $id
 * @property int $user_id
 * @property string $device_uuid
 * @property ?string $label
 * @property string $key
 * @property array{folder_ids: array<int, int>, days: int, max_messages: int, attachments: bool} $selection
 * @property ?\Illuminate\Support\Carbon $last_seen_at
 * @property ?\Illuminate\Support\Carbon $revoked_at
 */
class MailboxOfflineDevice extends Model
{
    protected $table = 'mailbox_offline_devices';

    protected $guarded = ['id'];

    protected $hidden = ['key'];

    protected function casts(): array
    {
        return [
            'key' => 'encrypted',
            'selection' => 'array',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isOwnedBy(Authenticatable $user): bool
    {
        return (string) $this->user_id === (string) $user->getAuthIdentifier();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
