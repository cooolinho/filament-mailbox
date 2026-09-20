<?php

namespace Cooolinho\FilamentMailbox\Services\Offline;

use Carbon\CarbonInterface;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\MessageInfolist;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxOfflineDevice;
use Cooolinho\FilamentMailbox\Services\AttachmentService;
use Cooolinho\FilamentMailbox\Services\HtmlBodySanitizer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Data of the offline copy: the same rules as the panel (assigned, active
 * mailboxes and folders), limited to the selection of the device. HTML is
 * sanitised on the server - the client never sanitises.
 */
class OfflineSnapshotService
{
    public function __construct(
        protected HtmlBodySanitizer $sanitizer,
        protected AttachmentService $attachments,
    ) {}

    /**
     * Folders of the selection the user may still read, grouped by mailbox.
     *
     * @return array<string, mixed>
     */
    public function manifest(MailboxOfflineDevice $device, Authenticatable $user): array
    {
        $folders = $this->folders($device, $user);

        return [
            'device' => $device->device_uuid,
            'revoked' => false,
            'selection' => [
                'days' => $device->selection['days'] ?? OfflineDeviceService::maxDays(),
                'max_messages' => $device->selection['max_messages'] ?? OfflineDeviceService::maxMessages(),
                'attachments' => (bool) ($device->selection['attachments'] ?? false),
            ],
            'mailboxes' => $folders->groupBy('mailbox_id')->map(fn (Collection $group): array => [
                'id' => (int) $group->first()->mailbox->getKey(),
                'name' => $group->first()->mailbox->name,
                'email' => $group->first()->mailbox->email,
                'folders' => $group->map(fn (MailboxFolder $folder): array => [
                    'id' => (int) $folder->getKey(),
                    'name' => $folder->special_use?->getLabel() ?? $folder->name,
                ])->values()->all(),
            ])->values()->all(),
            'server_time' => now()->toIso8601String(),
        ];
    }

    /**
     * Messages of a folder changed since the cursor, and the ids of all messages that belong to the copy.
     *
     * @return ?array<string, mixed> null when the folder is not part of the selection
     */
    public function changes(MailboxOfflineDevice $device, Authenticatable $user, int $folderId, ?CarbonInterface $since): ?array
    {
        $folder = $this->folders($device, $user)->firstWhere('id', $folderId);

        if (! $folder) {
            return null;
        }

        $now = now();
        $window = MailboxMessage::query()
            ->where('folder_id', $folder->getKey())
            ->where('received_at', '>=', $now->copy()->subDays((int) ($device->selection['days'] ?? OfflineDeviceService::maxDays())))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit((int) ($device->selection['max_messages'] ?? OfflineDeviceService::maxMessages()));

        $ids = (clone $window)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $changed = MailboxMessage::query()
            ->with(['attachments', 'folder', 'mailbox'])
            ->whereKey($ids)
            ->when($since, fn ($query) => $query->where('updated_at', '>', $since))
            ->get()
            ->filter(fn (MailboxMessage $message): bool => Gate::forUser($user)->allows('view', $message))
            ->map(fn (MailboxMessage $message): array => $this->message($message, (bool) ($device->selection['attachments'] ?? false)))
            ->values()
            ->all();

        $device->forceFill(['last_seen_at' => $now])->save();

        return [
            'folder' => (int) $folder->getKey(),
            'ids' => $ids,
            'messages' => $changed,
            // Overlap against clock differences between queries.
            'cursor' => $now->copy()->subSeconds(5)->toIso8601String(),
        ];
    }

    /**
     * Whether an attachment may be downloaded for the offline copy of the device.
     */
    public function allowsAttachment(MailboxOfflineDevice $device, Authenticatable $user, MailboxAttachment $attachment): bool
    {
        return (bool) ($device->selection['attachments'] ?? false)
            && $attachment->size <= OfflineDeviceService::maxAttachmentKilobytes() * 1024
            && $this->folders($device, $user)->contains('id', $attachment->message?->folder_id)
            && Gate::forUser($user)->allows('view', $attachment->message);
    }

    /**
     * @return Collection<int, MailboxFolder>
     */
    protected function folders(MailboxOfflineDevice $device, Authenticatable $user): Collection
    {
        $mailboxIds = Mailbox::query()->active()->assignedTo($user)->pluck('id');

        return MailboxFolder::query()
            ->with('mailbox')
            ->whereIn('mailbox_id', $mailboxIds)
            ->where('is_active', true)
            ->whereKey((array) ($device->selection['folder_ids'] ?? []))
            ->orderBy('mailbox_id')
            ->orderBy('full_name')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    protected function message(MailboxMessage $message, bool $attachments): array
    {
        $strict = MessageInfolist::isStrict($message);
        $limit = OfflineDeviceService::maxAttachmentKilobytes() * 1024;

        return [
            'id' => (int) $message->getKey(),
            'folder' => (int) $message->folder_id,
            'date' => ($message->received_at ?? $message->sent_at)?->toIso8601String(),
            'from' => MessageInfolist::formatAddress(['address' => $message->from_address, 'name' => $message->from_name]),
            'to' => MessageInfolist::formatAddresses($message->to),
            'cc' => MessageInfolist::formatAddresses($message->cc),
            'subject' => $message->subject,
            'preview' => Str::limit(trim(preg_replace('/\s+/', ' ', (string) ($message->text_body ?? strip_tags((string) $message->html_body))) ?? ''), 140),
            'is_read' => $message->is_read,
            'is_flagged' => $message->is_flagged,
            'text' => blank($message->html_body) ? (string) $message->text_body : null,
            // Complete sanitised document with its CSP, rendered in a sandboxed iframe.
            'html' => filled($message->html_body) ? $this->sanitizer->document($message->html_body, strict: $strict) : null,
            'attachments' => $message->attachments->map(fn (MailboxAttachment $attachment): array => [
                'id' => (int) $attachment->getKey(),
                'filename' => AttachmentService::sanitizeFilename($attachment->filename),
                'mime_type' => $attachment->mime_type,
                'size' => (int) $attachment->size,
                // Spam attachments are never stored on the device.
                'offline' => $attachments && ! $strict && $attachment->size <= $limit,
            ])->values()->all(),
        ];
    }
}
