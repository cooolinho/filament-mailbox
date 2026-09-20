<?php

namespace Cooolinho\FilamentMailbox\Policies;

use Cooolinho\FilamentMailbox\Models\MailboxTag;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Illuminate\Foundation\Auth\User;

/**
 * Managing the tag catalogue requires the manage-mailbox-tags ability. Assigning tags
 * to messages is authorized through the message policy ("tag").
 */
class MailboxTagPolicy
{
    public function __construct(
        protected MailboxAuthorization $authorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->canManageTags($user);
    }

    public function view(User $user, MailboxTag $tag): bool
    {
        return $this->authorization->canManageTags($user);
    }

    public function create(User $user): bool
    {
        return $this->authorization->canManageTags($user);
    }

    public function update(User $user, MailboxTag $tag): bool
    {
        return $this->authorization->canManageTags($user);
    }

    public function delete(User $user, MailboxTag $tag): bool
    {
        return $this->authorization->canManageTags($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->authorization->canManageTags($user);
    }

    public function reorder(User $user): bool
    {
        return $this->authorization->canManageTags($user);
    }
}
