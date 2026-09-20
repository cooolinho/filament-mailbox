<?php

namespace Cooolinho\FilamentMailbox\Policies;

use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Support\MailboxAccess;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Illuminate\Foundation\Auth\User;

class MailboxMessagePolicy
{
    public function __construct(
        protected MailboxAuthorization $authorization,
        protected MailboxAccess $access,
    ) {}

    public function view(User $user, MailboxMessage $message): bool
    {
        return $this->access->isAssigned($user, $message->mailbox_id);
    }

    /**
     * Changing the read state.
     */
    public function update(User $user, MailboxMessage $message): bool
    {
        return $this->view($user, $message);
    }

    public function delete(User $user, MailboxMessage $message): bool
    {
        return $this->view($user, $message) && $this->authorization->canDelete($user);
    }

    public function reply(User $user, MailboxMessage $message): bool
    {
        return $this->view($user, $message) && $this->authorization->canSend($user);
    }

    /**
     * Assigning app-only tags.
     */
    public function tag(User $user, MailboxMessage $message): bool
    {
        return $this->view($user, $message) && $this->authorization->canTag($user);
    }

    public function forward(User $user, MailboxMessage $message): bool
    {
        return $this->reply($user, $message);
    }
}
