<?php

namespace Cooolinho\FilamentMailbox\Policies;

use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Illuminate\Foundation\Auth\User;

/**
 * App registrations hold client secrets: only mailbox managers may see them.
 */
class OAuthApplicationPolicy
{
    public function __construct(
        protected MailboxAuthorization $authorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->canManage($user);
    }

    public function view(User $user, OAuthApplication $application): bool
    {
        return $this->authorization->canManage($user);
    }

    public function create(User $user): bool
    {
        return $this->authorization->canManage($user);
    }

    public function update(User $user, OAuthApplication $application): bool
    {
        return $this->authorization->canManage($user);
    }

    public function delete(User $user, OAuthApplication $application): bool
    {
        return $this->authorization->canManage($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->authorization->canManage($user);
    }
}
