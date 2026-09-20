<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Notification settings of a user: globally (mailbox_user_preferences) and per
 * assigned mailbox (mailbox_user.notify, notify_folder_ids).
 */
class NotificationPreferences
{
    public function __construct(
        protected UserPreferences $preferences,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('filament-mailbox.notifications.enabled', true);
    }

    public function isEnabled(Authenticatable $user): bool
    {
        return (bool) $this->preferences->get($user, 'notifications', true);
    }

    public function showContent(Authenticatable $user): bool
    {
        return (bool) $this->preferences->get($user, 'notifications_show_content', config('filament-mailbox.notifications.show_content_by_default', true));
    }

    public function wantsNotification(Authenticatable $user, Mailbox $mailbox, MailboxFolder $folder): bool
    {
        if (! static::enabled() || ! $this->isEnabled($user)) {
            return false;
        }

        $assignment = $this->assignment($user, $mailbox);

        if (! $assignment || ! $assignment['notify']) {
            return false;
        }

        return in_array($folder->getKey(), $this->folderIds($mailbox, $assignment['folder_ids']), true);
    }

    /**
     * @return ?array{notify: bool, folder_ids: ?array<int, int>}
     */
    public function assignment(Authenticatable $user, Mailbox $mailbox): ?array
    {
        $row = DB::table('mailbox_user')
            ->where('mailbox_id', $mailbox->getKey())
            ->where('user_id', $user->getAuthIdentifier())
            ->first(['notify', 'notify_folder_ids']);

        if (! $row) {
            return null;
        }

        $folderIds = $row->notify_folder_ids === null ? null : array_map('intval', (array) json_decode((string) $row->notify_folder_ids, true));

        return ['notify' => (bool) $row->notify, 'folder_ids' => $folderIds];
    }

    /**
     * @param  ?array<int, int>  $folderIds  null = default folders
     */
    public function setMailbox(Authenticatable $user, Mailbox $mailbox, bool $notify, ?array $folderIds): void
    {
        // Only folders of this mailbox are stored.
        $folderIds = $folderIds === null ? null : $mailbox->folders()->whereKey($folderIds)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        DB::table('mailbox_user')
            ->where('mailbox_id', $mailbox->getKey())
            ->where('user_id', $user->getAuthIdentifier())
            ->update([
                'notify' => $notify,
                'notify_folder_ids' => $folderIds === null || $folderIds === [] ? null : json_encode(array_values($folderIds)),
            ]);
    }

    /**
     * @param  ?array<int, int>  $selected
     * @return array<int, int>
     */
    public function folderIds(Mailbox $mailbox, ?array $selected): array
    {
        if ($selected !== null && $selected !== []) {
            return $selected;
        }

        $roles = array_filter(array_map(
            fn (mixed $role): ?SpecialUse => is_string($role) ? SpecialUse::tryFrom($role) : null,
            (array) config('filament-mailbox.notifications.default_folders', ['inbox']),
        ));

        return $mailbox->folders()
            ->where('is_active', true)
            ->whereIn('special_use', $roles)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
