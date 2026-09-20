<?php

namespace Cooolinho\FilamentMailbox\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Semantic folder roles (RFC 6154 special-use attributes).
 */
enum SpecialUse: string implements HasIcon, HasLabel
{
    case Inbox = 'inbox';
    case Sent = 'sent';
    case Drafts = 'drafts';
    case Trash = 'trash';
    case Junk = 'junk';
    case Archive = 'archive';

    /** Virtual "All mail" folder (Gmail IMAP). Not a valid archive target. */
    case All = 'all';

    public static function fromImapFlag(string $flag): ?self
    {
        return match (strtolower($flag)) {
            '\sent' => self::Sent,
            '\drafts' => self::Drafts,
            '\trash' => self::Trash,
            '\junk' => self::Junk,
            '\archive' => self::Archive,
            '\all' => self::All,
            default => null,
        };
    }

    public function getLabel(): string
    {
        return __('filament-mailbox::mailbox.special_use.'.$this->value);
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Inbox => Heroicon::OutlinedInbox,
            self::Sent => Heroicon::OutlinedPaperAirplane,
            self::Drafts => Heroicon::OutlinedPencilSquare,
            self::Trash => Heroicon::OutlinedTrash,
            self::Junk => Heroicon::OutlinedNoSymbol,
            self::Archive => Heroicon::OutlinedArchiveBox,
            self::All => Heroicon::OutlinedRectangleStack,
        };
    }

    public function sort(): int
    {
        return array_search($this, self::cases(), true);
    }
}
