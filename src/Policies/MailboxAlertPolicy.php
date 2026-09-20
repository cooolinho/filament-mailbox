<?php

namespace Cooolinho\FilamentMailbox\Policies;

use Cooolinho\FilamentMailbox\Models\MailboxAlert;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Illuminate\Foundation\Auth\User;

/**
 * Alerts are visible to users who may manage mailboxes. They are created by
 * the monitoring only; acknowledging an alert is an update.
 */
class MailboxAlertPolicy
{
    public function __construct(
        protected MailboxAuthorization $authorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->canManage($user);
    }

    public function view(User $user, MailboxAlert $alert): bool
    {
        return $this->authorization->canManage($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, MailboxAlert $alert): bool
    {
        return $this->authorization->canManage($user);
    }

    public function delete(User $user, MailboxAlert $alert): bool
    {
        return false;
    }
}
