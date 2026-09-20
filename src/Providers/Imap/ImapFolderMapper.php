<?php

namespace Cooolinho\FilamentMailbox\Providers\Imap;

use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use DirectoryTree\ImapEngine\FolderInterface;

class ImapFolderMapper
{
    public static function map(FolderInterface $folder): FolderData
    {
        $path = $folder->path();
        $delimiter = $folder->delimiter() ?: null;

        return new FolderData(
            name: $folder->name(),
            fullName: $path,
            delimiter: $delimiter,
            specialUse: static::specialUse($path, $folder->flags()),
            parentRemoteId: static::parent($path, $delimiter),
        );
    }

    /**
     * Resolve the folder role from IMAP metadata only - never from localized names.
     *
     * @param  array<int, string>  $flags
     */
    public static function specialUse(string $path, array $flags): ?SpecialUse
    {
        // "INBOX" is reserved by RFC 3501 / RFC 9051 and case-insensitive.
        if (strcasecmp($path, 'INBOX') === 0) {
            return SpecialUse::Inbox;
        }

        foreach ($flags as $flag) {
            if ($specialUse = SpecialUse::fromImapFlag($flag)) {
                return $specialUse;
            }
        }

        return null;
    }

    public static function parent(string $path, ?string $delimiter): ?string
    {
        if (! $delimiter || ! str_contains($path, $delimiter)) {
            return null;
        }

        return substr($path, 0, strrpos($path, $delimiter));
    }
}
