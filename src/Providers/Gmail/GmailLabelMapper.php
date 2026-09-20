<?php

namespace Cooolinho\FilamentMailbox\Providers\Gmail;

use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\LabelData;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Enums\LabelSource;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;

/**
 * Maps Gmail labels to folders, flags and labels.
 *
 * Each message is stored once. Its folder is the "primary" system label
 * (Trash > Spam > Drafts > Inbox > Sent, otherwise "All mail"); user labels
 * become mailbox labels, UNREAD and STARRED become flags.
 */
final class GmailLabelMapper
{
    /** Folder of messages without Inbox, Sent, Drafts, Spam or Trash label. */
    public const ARCHIVE = '__archive__';

    /** @var array<string, SpecialUse> In order of precedence */
    public const FOLDERS = [
        'TRASH' => SpecialUse::Trash,
        'SPAM' => SpecialUse::Junk,
        'DRAFT' => SpecialUse::Drafts,
        'INBOX' => SpecialUse::Inbox,
        'SENT' => SpecialUse::Sent,
    ];

    public const SYSTEM_LABELS = ['INBOX', 'SENT', 'DRAFT', 'SPAM', 'TRASH', 'UNREAD', 'STARRED', 'IMPORTANT', 'CHAT'];

    /**
     * @return array<int, FolderData>
     */
    public static function folders(): array
    {
        $folders = [];

        foreach (['INBOX', 'SENT', 'DRAFT', 'SPAM', 'TRASH'] as $id) {
            $folders[] = new FolderData(ucfirst(strtolower($id)), $id, '/', static::FOLDERS[$id], remoteId: $id);
        }

        $folders[] = new FolderData('All mail', 'All mail', '/', SpecialUse::Archive, remoteId: static::ARCHIVE);

        return $folders;
    }

    /**
     * @param  array<int, string>  $labelIds
     */
    public static function folder(array $labelIds): string
    {
        foreach (array_keys(static::FOLDERS) as $id) {
            if (in_array($id, $labelIds, true)) {
                return $id;
            }
        }

        return static::ARCHIVE;
    }

    /**
     * @param  array<int, string>  $labelIds
     */
    public static function flags(array $labelIds): MessageFlags
    {
        return new MessageFlags(
            seen: ! in_array('UNREAD', $labelIds, true),
            flagged: in_array('STARRED', $labelIds, true),
            draft: in_array('DRAFT', $labelIds, true),
            keywords: array_values(array_filter($labelIds, fn (string $id): bool => static::isUserLabel($id))),
        );
    }

    /**
     * Labels to add and remove on the server for a flag change.
     *
     * @return array{array<int, string>, array<int, string>}
     */
    public static function modification(MessageFlags $add, MessageFlags $remove): array
    {
        $adds = $add->keywords;
        $removes = $remove->keywords;

        // "Seen" is the absence of UNREAD.
        if ($add->seen) {
            $removes[] = 'UNREAD';
        }

        if ($remove->seen) {
            $adds[] = 'UNREAD';
        }

        if ($add->flagged) {
            $adds[] = 'STARRED';
        }

        if ($remove->flagged) {
            $removes[] = 'STARRED';
        }

        return [array_values(array_unique($adds)), array_values(array_unique($removes))];
    }

    /**
     * @param  array<string, mixed>  $label  label resource
     */
    public static function label(array $label): ?LabelData
    {
        if (($label['type'] ?? 'user') !== 'user' || ! static::isUserLabel((string) $label['id'])) {
            return null;
        }

        return new LabelData(LabelSource::Gmail, (string) $label['id'], (string) $label['name']);
    }

    public static function isUserLabel(string $id): bool
    {
        if (in_array($id, static::SYSTEM_LABELS, true)) {
            return false;
        }

        foreach ((array) config('filament-mailbox.gmail.hidden_labels', []) as $pattern) {
            if (fnmatch((string) $pattern, $id)) {
                return false;
            }
        }

        return true;
    }
}
