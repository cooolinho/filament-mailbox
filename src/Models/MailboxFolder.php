<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Data\SyncCursor;
use Cooolinho\FilamentMailbox\Database\Factories\MailboxFolderFactory;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $mailbox_id
 * @property ?int $parent_id
 * @property string $name
 * @property string $full_name
 * @property ?string $remote_id
 * @property ?string $delimiter
 * @property ?SpecialUse $special_use
 * @property ?int $uid_validity
 * @property int $last_synced_uid
 * @property ?array<string, scalar|null> $sync_cursor
 * @property bool $is_active
 * @property bool $is_subscribed
 * @property ?int $message_count
 * @property ?int $unseen_count
 * @property ?int $size_bytes
 * @property ?\Illuminate\Support\Carbon $statistics_updated_at
 * @property ?\Illuminate\Support\Carbon $last_synced_at
 */
class MailboxFolder extends Model
{
    /** @use HasFactory<MailboxFolderFactory> */
    use HasFactory;

    protected $table = 'mailbox_folders';

    protected $fillable = [
        'mailbox_id',
        'parent_id',
        'name',
        'full_name',
        'remote_id',
        'delimiter',
        'special_use',
        'uid_validity',
        'last_synced_uid',
        'sync_cursor',
        'is_active',
        'is_subscribed',
        'last_synced_at',
    ];

    protected $attributes = [
        'last_synced_uid' => 0,
        'is_active' => true,
        'is_subscribed' => true,
    ];

    protected function casts(): array
    {
        return [
            'special_use' => SpecialUse::class,
            'uid_validity' => 'integer',
            'last_synced_uid' => 'integer',
            'sync_cursor' => 'array',
            'is_active' => 'boolean',
            'is_subscribed' => 'boolean',
            'message_count' => 'integer',
            'unseen_count' => 'integer',
            'size_bytes' => 'integer',
            'statistics_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MailboxMessage::class, 'folder_id');
    }

    /**
     * Active descendants, deepest first.
     *
     * @return Collection<int, MailboxFolder>
     */
    public function descendants(bool $includeInactive = false): Collection
    {
        $all = static::query()
            ->where('mailbox_id', $this->mailbox_id)
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->get(['id', 'parent_id', 'mailbox_id', 'name', 'full_name', 'remote_id', 'delimiter', 'special_use', 'is_active']);

        $byParent = $all->groupBy('parent_id');
        $result = [];
        $walk = function (int $id) use (&$walk, &$result, $byParent): void {
            foreach ($byParent->get($id, []) as $child) {
                $walk($child->id);
                $result[] = $child;
            }
        };

        $walk($this->getKey());

        return new Collection($result);
    }

    public function isSystemFolder(): bool
    {
        return $this->special_use !== null || strcasecmp($this->full_name, 'INBOX') === 0;
    }

    public function identifier(): FolderIdentifier
    {
        return new FolderIdentifier($this->remote_id ?? $this->full_name);
    }

    public function cursor(): SyncCursor
    {
        return new SyncCursor($this->sync_cursor ?? []);
    }

    protected static function newFactory(): MailboxFolderFactory
    {
        return MailboxFolderFactory::new();
    }
}
