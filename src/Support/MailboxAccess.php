<?php

namespace Cooolinho\FilamentMailbox\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Request-scoped cache of mailbox assignments, so policy checks for every
 * table row do not each hit the database.
 */
class MailboxAccess
{
    /** @var array<string, bool> */
    protected array $assigned = [];

    public function isAssigned(Authenticatable $user, int|string|null $mailboxId): bool
    {
        if ($mailboxId === null) {
            return false;
        }

        return $this->assigned[$user->getAuthIdentifier().':'.$mailboxId] ??= DB::table('mailbox_user')
            ->where('mailbox_id', $mailboxId)
            ->where('user_id', $user->getAuthIdentifier())
            ->exists();
    }

    public function flush(): void
    {
        $this->assigned = [];
    }
}
