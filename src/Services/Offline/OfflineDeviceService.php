<?php

namespace Cooolinho\FilamentMailbox\Services\Offline;

use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxOfflineDevice;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * Devices with an offline copy: opt-in per browser, revocable, with a key per device.
 */
class OfflineDeviceService
{
    public static function enabled(): bool
    {
        return (bool) config('filament-mailbox.offline.enabled', false);
    }

    /**
     * @param  array<string, mixed>  $selection  folder_ids, days, max_messages, attachments
     */
    public function register(Authenticatable $user, array $selection, ?string $label = null): MailboxOfflineDevice
    {
        return MailboxOfflineDevice::query()->create([
            'user_id' => $user->getAuthIdentifier(),
            'device_uuid' => (string) Str::uuid(),
            'label' => $label === null ? null : Str::limit(strip_tags($label), 120, ''),
            'key' => base64_encode(random_bytes(32)),
            'selection' => $this->normalizeSelection($user, $selection),
        ]);
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    public function updateSelection(MailboxOfflineDevice $device, Authenticatable $user, array $selection): void
    {
        $device->forceFill(['selection' => $this->normalizeSelection($user, $selection)])->save();
    }

    public function revoke(MailboxOfflineDevice $device): void
    {
        $device->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * The device of the signed-in user, if it is active.
     */
    public function find(Authenticatable $user, ?string $uuid): ?MailboxOfflineDevice
    {
        if (! Str::isUuid((string) $uuid)) {
            return null;
        }

        $device = MailboxOfflineDevice::query()->where('device_uuid', $uuid)->first();

        return $device && $device->isOwnedBy($user) ? $device : null;
    }

    /**
     * Only active folders of assigned mailboxes, limits capped by the configuration.
     *
     * @param  array<string, mixed>  $selection
     * @return array{folder_ids: array<int, int>, days: int, max_messages: int, attachments: bool}
     */
    public function normalizeSelection(Authenticatable $user, array $selection): array
    {
        $mailboxIds = Mailbox::query()->active()->assignedTo($user)->pluck('id');
        $folderIds = array_values(array_unique(array_map('intval', array_filter((array) ($selection['folder_ids'] ?? []), 'is_numeric'))));

        return [
            'folder_ids' => MailboxFolder::query()
                ->whereIn('mailbox_id', $mailboxIds)
                ->where('is_active', true)
                ->whereKey($folderIds)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all(),
            'days' => max(1, min((int) ($selection['days'] ?? static::maxDays()), static::maxDays())),
            'max_messages' => max(1, min((int) ($selection['max_messages'] ?? static::maxMessages()), static::maxMessages())),
            'attachments' => (bool) ($selection['attachments'] ?? false) && static::maxAttachmentKilobytes() > 0,
        ];
    }

    public static function maxDays(): int
    {
        return max(1, (int) config('filament-mailbox.offline.max_days', 30));
    }

    public static function maxMessages(): int
    {
        return max(1, (int) config('filament-mailbox.offline.max_messages', 500));
    }

    public static function maxAttachmentKilobytes(): int
    {
        return max(0, (int) config('filament-mailbox.offline.max_attachment_size', 2048));
    }
}
