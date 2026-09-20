<?php

namespace Cooolinho\FilamentMailbox\Policies;

use Cooolinho\FilamentMailbox\Models\MailboxTemplate;
use Cooolinho\FilamentMailbox\Services\TemplateRepository;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Illuminate\Foundation\Auth\User;

/**
 * Managing the template catalogue requires the manage-mailbox-templates ability.
 * Using templates only requires access to the mailbox (see TemplateRepository).
 */
class MailboxTemplatePolicy
{
    public function __construct(
        protected MailboxAuthorization $authorization,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function view(User $user, MailboxTemplate $template): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, MailboxTemplate $template): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, MailboxTemplate $template): bool
    {
        return $this->manages($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->manages($user);
    }

    protected function manages(User $user): bool
    {
        return TemplateRepository::enabled() && $this->authorization->canManageTemplates($user);
    }
}
