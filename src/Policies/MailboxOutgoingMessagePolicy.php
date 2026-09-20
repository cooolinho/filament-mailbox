<?php

namespace Cooolinho\FilamentMailbox\Policies;

use Cooolinho\FilamentMailbox\Models\MailboxOutgoingMessage;
use Cooolinho\FilamentMailbox\Support\MailboxAccess;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Illuminate\Foundation\Auth\User;

/**
 * Outbox messages are visible to their sender (or a user who may manage
 * mailboxes) while assigned to the mailbox; changing them requires the
 * permission to send.
 */
class MailboxOutgoingMessagePolicy
{
    public function __construct(
        protected MailboxAuthorization $authorization,
        protected MailboxAccess $access,
    ) {}

    public function view(User $user, MailboxOutgoingMessage $message): bool
    {
        return $this->access->isAssigned($user, $message->mailbox_id)
            && ($message->isOwnedBy($user) || $this->authorization->canManage($user));
    }

    /**
     * Editing, rescheduling, sending now, cancelling and retrying.
     */
    public function update(User $user, MailboxOutgoingMessage $message): bool
    {
        return $this->view($user, $message) && $this->authorization->canSend($user);
    }
}
