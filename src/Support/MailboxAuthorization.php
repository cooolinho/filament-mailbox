<?php

namespace Cooolinho\FilamentMailbox\Support;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Configurable abilities on top of the mailbox assignment.
 *
 * Reading a mailbox always requires the user to be assigned to it. The
 * callbacks below can further restrict managing mailboxes, sending and
 * deleting messages, managing tags, templates and folders, and tagging. Without a callback, a Gate of the same name is used
 * when the application defines one; otherwise the ability is granted.
 */
class MailboxAuthorization
{
    public const MANAGE = 'manage-mailboxes';

    public const SEND = 'send-mailbox-messages';

    public const DELETE = 'delete-mailbox-messages';

    public const MANAGE_TAGS = 'manage-mailbox-tags';

    public const TAG = 'tag-mailbox-messages';

    public const MANAGE_FOLDERS = 'manage-mailbox-folders';

    public const MANAGE_TEMPLATES = 'manage-mailbox-templates';

    /** @var array<string, Closure(Authenticatable): bool> */
    protected array $callbacks = [];

    /**
     * @param  Closure(Authenticatable): bool  $callback
     */
    public function using(string $ability, Closure $callback): static
    {
        $this->callbacks[$ability] = $callback;

        return $this;
    }

    public function canManage(Authenticatable $user): bool
    {
        return $this->check(self::MANAGE, $user);
    }

    public function canSend(Authenticatable $user): bool
    {
        return $this->check(self::SEND, $user);
    }

    public function canDelete(Authenticatable $user): bool
    {
        return $this->check(self::DELETE, $user);
    }

    /**
     * Managing the tag catalogue. Defaults to managing mailboxes.
     */
    public function canManageTags(Authenticatable $user): bool
    {
        return $this->isConfigured(self::MANAGE_TAGS)
            ? $this->check(self::MANAGE_TAGS, $user)
            : $this->canManage($user);
    }

    /**
     * Managing the folders of mailboxes. Defaults to managing mailboxes.
     */
    public function canManageFolders(Authenticatable $user): bool
    {
        return $this->isConfigured(self::MANAGE_FOLDERS)
            ? $this->check(self::MANAGE_FOLDERS, $user)
            : $this->canManage($user);
    }

    /**
     * Managing the template catalogue. Defaults to managing mailboxes.
     */
    public function canManageTemplates(Authenticatable $user): bool
    {
        return $this->isConfigured(self::MANAGE_TEMPLATES)
            ? $this->check(self::MANAGE_TEMPLATES, $user)
            : $this->canManage($user);
    }

    /**
     * Tagging messages of assigned mailboxes.
     */
    public function canTag(Authenticatable $user): bool
    {
        return $this->check(self::TAG, $user);
    }

    protected function isConfigured(string $ability): bool
    {
        return isset($this->callbacks[$ability]) || Gate::has($ability);
    }

    protected function check(string $ability, Authenticatable $user): bool
    {
        if (isset($this->callbacks[$ability])) {
            return (bool) ($this->callbacks[$ability])($user);
        }

        if (Gate::has($ability)) {
            return Gate::forUser($user)->allows($ability);
        }

        return true;
    }
}
