<?php

namespace Cooolinho\FilamentMailbox\Policies;

use Cooolinho\FilamentMailbox\Models\MailboxLabel;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Illuminate\Foundation\Auth\User;

/**
 * Managing the label catalogue requires managing mailboxes. Assigning labels
 * to messages is authorized through the message policy ("update").
 */
class MailboxLabelPolicy
{
    public function __construct(
        protected MailboxAuthorization $authorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->canManage($user);
    }

    public function view(User $user, MailboxLabel $label): bool
    {
        return $this->authorization->canManage($user);
    }

    public function create(User $user): bool
    {
        return $this->authorization->canManage($user);
    }

    public function update(User $user, MailboxLabel $label): bool
    {
        return $this->authorization->canManage($user);
    }

    public function delete(User $user, MailboxLabel $label): bool
    {
        return $this->authorization->canManage($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->authorization->canManage($user);
    }
}
