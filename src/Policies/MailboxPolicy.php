<?php

namespace Cooolinho\FilamentMailbox\Policies;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Support\MailboxAccess;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Illuminate\Foundation\Auth\User;

class MailboxPolicy
{
    public function __construct(
        protected MailboxAuthorization $authorization,
        protected MailboxAccess $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Opening a mailbox and reading its messages requires an assignment,
     * even for users who may manage mailboxes.
     */
    public function view(User $user, Mailbox $mailbox): bool
    {
        return $this->access->isAssigned($user, $mailbox->getKey());
    }

    public function create(User $user): bool
    {
        return $this->authorization->canManage($user);
    }

    public function update(User $user, Mailbox $mailbox): bool
    {
        return $this->authorization->canManage($user);
    }

    public function delete(User $user, Mailbox $mailbox): bool
    {
        return $this->authorization->canManage($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->authorization->canManage($user);
    }

    /**
     * Creating, renaming, moving, deleting and subscribing folders.
     */
    public function manageFolders(User $user, Mailbox $mailbox): bool
    {
        return $this->authorization->canManageFolders($user);
    }

    public function send(User $user, Mailbox $mailbox): bool
    {
        return $this->access->isAssigned($user, $mailbox->getKey()) && $this->authorization->canSend($user);
    }
}
