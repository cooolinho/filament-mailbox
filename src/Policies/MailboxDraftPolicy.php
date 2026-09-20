<?php

namespace Cooolinho\FilamentMailbox\Policies;

use Cooolinho\FilamentMailbox\Models\MailboxDraft;
use Cooolinho\FilamentMailbox\Support\MailboxAccess;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Illuminate\Foundation\Auth\User;

/**
 * Drafts written in the app are private: only their creator (or a user who may
 * manage mailboxes) may open, change, send or discard them, and only while
 * assigned to the mailbox and allowed to send.
 */
class MailboxDraftPolicy
{
    public function __construct(
        protected MailboxAuthorization $authorization,
        protected MailboxAccess $access,
    ) {}

    public function view(User $user, MailboxDraft $draft): bool
    {
        return $this->access->isAssigned($user, $draft->mailbox_id)
            && $this->authorization->canSend($user)
            && ($draft->isOwnedBy($user) || $this->authorization->canManage($user));
    }

    public function update(User $user, MailboxDraft $draft): bool
    {
        return $this->view($user, $draft);
    }

    public function delete(User $user, MailboxDraft $draft): bool
    {
        return $this->view($user, $draft);
    }
}
