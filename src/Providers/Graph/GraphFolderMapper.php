<?php

namespace Cooolinho\FilamentMailbox\Providers\Graph;

use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;

class GraphFolderMapper
{
    /**
     * Well-known folder names of Graph and their roles.
     */
    public const WELL_KNOWN = [
        'inbox' => SpecialUse::Inbox,
        'sentitems' => SpecialUse::Sent,
        'drafts' => SpecialUse::Drafts,
        'deleteditems' => SpecialUse::Trash,
        'junkemail' => SpecialUse::Junk,
        'archive' => SpecialUse::Archive,
    ];

    /**
     * @param  array<string, mixed>  $folder  mailFolder resource
     * @param  array<string, SpecialUse>  $roles  Special-use roles keyed by folder id
     */
    public static function map(array $folder, ?FolderData $parent, array $roles): FolderData
    {
        $name = (string) ($folder['displayName'] ?? $folder['id']);

        return new FolderData(
            name: $name,
            fullName: $parent ? $parent->fullName.'/'.$name : $name,
            delimiter: '/',
            specialUse: $roles[$folder['id']] ?? null,
            parentRemoteId: $parent?->remoteId,
            remoteId: (string) $folder['id'],
        );
    }
}
