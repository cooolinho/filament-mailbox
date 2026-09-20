<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the folder tree shown in the mailbox navigation from local data only.
 */
class FolderNavigation
{
    /**
     * @return Collection<int, MailboxFolder>
     */
    public function folders(Mailbox $mailbox): Collection
    {
        return $mailbox->folders()
            ->where('is_active', true)
            ->orderBy('full_name')
            ->get();
    }

    /**
     * @param  Collection<int, MailboxFolder>  $folders
     * @return Collection<int, MailboxFolder>
     */
    public function systemFolders(Collection $folders): Collection
    {
        return $folders
            ->filter(fn (MailboxFolder $folder): bool => $folder->special_use !== null)
            ->sortBy(fn (MailboxFolder $folder): int => $folder->special_use->sort())
            ->values();
    }

    /**
     * Custom folders with their top-level custom ancestor and a label relative to it.
     *
     * @param  Collection<int, MailboxFolder>  $folders
     * @return array<int, array{folder: MailboxFolder, root: ?MailboxFolder, label: string}>
     */
    public function customFolders(Collection $folders): array
    {
        $byId = $folders->keyBy('id');
        $items = [];

        foreach ($folders as $folder) {
            if ($folder->special_use !== null) {
                continue;
            }

            // Walk up to the top-most ancestor that is itself a custom folder.
            $chain = [$folder];
            $current = $folder;

            while ($current->parent_id && ($parent = $byId->get($current->parent_id)) && $parent->special_use === null) {
                array_unshift($chain, $parent);
                $current = $parent;
            }

            $root = count($chain) > 1 ? $chain[0] : null;

            $items[] = [
                'folder' => $folder,
                'root' => $root,
                'label' => implode(' / ', array_map(
                    fn (MailboxFolder $item): string => $item->name,
                    $root ? array_slice($chain, 1) : $chain,
                )),
            ];
        }

        return $items;
    }

    public function defaultFolder(Mailbox $mailbox): ?MailboxFolder
    {
        return $mailbox->folderFor(SpecialUse::Inbox)
            ?? $mailbox->folders()->where('is_active', true)->orderBy('full_name')->first();
    }

    /**
     * Starred messages of all active folders of the mailbox, except the
     * configured special-use folders (trash and spam by default).
     *
     * @return Builder<MailboxMessage>
     */
    public function starredQuery(Mailbox $mailbox): Builder
    {
        $excluded = array_values(array_filter(array_map(
            fn (string|SpecialUse $specialUse): ?SpecialUse => $specialUse instanceof SpecialUse ? $specialUse : SpecialUse::tryFrom($specialUse),
            (array) config('filament-mailbox.starred.exclude_special_use', ['trash', 'junk']),
        )));

        return MailboxMessage::query()
            ->where('mailbox_id', $mailbox->getKey())
            ->where('is_flagged', true)
            ->notSnoozed()
            ->whereHas('folder', fn (Builder $folders) => $folders
                ->where('is_active', true)
                ->where(fn (Builder $query) => $query
                    ->whereNull('special_use')
                    ->orWhereNotIn('special_use', array_map(fn (SpecialUse $specialUse): string => $specialUse->value, $excluded))));
    }

    public function starredCount(Mailbox $mailbox): int
    {
        return $this->starredQuery($mailbox)->count();
    }

    /**
     * Snoozed messages of the mailbox, in all folders.
     *
     * @return Builder<MailboxMessage>
     */
    public function snoozedQuery(Mailbox $mailbox): Builder
    {
        return MailboxMessage::query()
            ->where('mailbox_id', $mailbox->getKey())
            ->snoozed();
    }

    public function snoozedCount(Mailbox $mailbox): int
    {
        return $this->snoozedQuery($mailbox)->count();
    }

    public function unreadCount(MailboxFolder $folder): int
    {
        return $folder->messages()->where('is_read', false)->notSnoozed()->count();
    }
}
