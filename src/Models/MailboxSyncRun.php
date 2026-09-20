<?php

namespace Cooolinho\FilamentMailbox\Models;

use Cooolinho\FilamentMailbox\Enums\SyncErrorType;
use Cooolinho\FilamentMailbox\Enums\SyncRunStatus;
use Cooolinho\FilamentMailbox\Enums\SyncTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $mailbox_id
 * @property SyncTrigger $trigger
 * @property SyncRunStatus $status
 * @property ?\Illuminate\Support\Carbon $queued_at
 * @property \Illuminate\Support\Carbon $started_at
 * @property ?\Illuminate\Support\Carbon $finished_at
 * @property ?int $duration_ms
 * @property ?int $queue_wait_ms
 * @property int $folders
 * @property int $imported
 * @property int $updated
 * @property int $deleted
 * @property ?SyncErrorType $error_type
 * @property ?string $error
 * @property ?array<string, array{duration_ms: int, imported: int, updated: int, deleted: int, error_type: ?string, error: ?string}> $folder_stats
 * @property ?array<string, array{count: int, errors: int, total_ms: float, max_ms: float}> $provider_stats
 */
class MailboxSyncRun extends Model
{
    public $timestamps = false;

    protected $table = 'mailbox_sync_runs';

    protected $guarded = [];

    protected $attributes = [
        'folders' => 0,
        'imported' => 0,
        'updated' => 0,
        'deleted' => 0,
    ];

    protected function casts(): array
    {
        return [
            'trigger' => SyncTrigger::class,
            'status' => SyncRunStatus::class,
            'error_type' => SyncErrorType::class,
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'queue_wait_ms' => 'integer',
            'folders' => 'integer',
            'imported' => 'integer',
            'updated' => 'integer',
            'deleted' => 'integer',
            'folder_stats' => 'array',
            'provider_stats' => 'array',
        ];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
