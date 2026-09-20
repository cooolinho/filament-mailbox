<?php

namespace Cooolinho\FilamentMailbox\Policies;

use Cooolinho\FilamentMailbox\Models\MailboxSignature;
use Cooolinho\FilamentMailbox\Services\SignatureResolver;
use Cooolinho\FilamentMailbox\Support\MailboxAccess;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Illuminate\Foundation\Auth\User;

/**
 * Mailbox signatures are managed by users who may manage mailboxes, personal
 * signatures only by their owner (for mailboxes assigned to them).
 */
class MailboxSignaturePolicy
{
    public function __construct(
        protected MailboxAuthorization $authorization,
        protected MailboxAccess $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return SignatureResolver::enabled();
    }

    public function view(User $user, MailboxSignature $signature): bool
    {
        return $this->manages($user, $signature)
            || (! $signature->isPersonal() && $this->access->isAssigned($user, $signature->mailbox_id));
    }

    /**
     * Where a signature is created decides its kind: the mailbox relation manager
     * (managers) or the personal signatures page.
     */
    public function create(User $user): bool
    {
        return SignatureResolver::enabled();
    }

    public function update(User $user, MailboxSignature $signature): bool
    {
        return $this->manages($user, $signature);
    }

    public function delete(User $user, MailboxSignature $signature): bool
    {
        return $this->manages($user, $signature);
    }

    public function deleteAny(User $user): bool
    {
        return SignatureResolver::enabled();
    }

    protected function manages(User $user, MailboxSignature $signature): bool
    {
        if (! SignatureResolver::enabled()) {
            return false;
        }

        if ($signature->isPersonal()) {
            return SignatureResolver::personalEnabled()
                && $signature->isOwnedBy($user)
                && $this->access->isAssigned($user, $signature->mailbox_id);
        }

        return $this->authorization->canManage($user);
    }
}
