<?php

namespace Cooolinho\FilamentMailbox\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Meilisearch task (indexing runs asynchronously there), so failures can be retried.
 *
 * @property int $id
 * @property int $task_uid
 * @property string $type
 * @property ?array<int, int> $message_ids
 * @property string $status
 * @property ?string $error
 * @property int $attempts
 */
class MailboxSearchTask extends Model
{
    public const ENQUEUED = 'enqueued';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    protected $table = 'mailbox_search_tasks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'message_ids' => 'array',
            'attempts' => 'integer',
        ];
    }
}
