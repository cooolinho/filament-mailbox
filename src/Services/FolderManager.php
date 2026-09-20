<?php

namespace Cooolinho\FilamentMailbox\Services;

use Closure;
use Cooolinho\FilamentMailbox\Contracts\MailboxProvider;
use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Contracts\ManagesFolders;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Enums\SyncTrigger;
use Cooolinho\FilamentMailbox\Events\FolderCreated;
use Cooolinho\FilamentMailbox\Events\FolderDeleted;
use Cooolinho\FilamentMailbox\Events\FolderRenamed;
use Cooolinho\FilamentMailbox\Exceptions\InvalidFolderOperation;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;
use Cooolinho\FilamentMailbox\Jobs\SyncMailboxFolderJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Support\FolderPath;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Folder operations: validated, executed on the server first and then
 * mirrored locally without a full resynchronisation.
 */
class FolderManager
{
    public const DELETE_TO_TRASH = 'trash';

    public const DELETE_PERMANENTLY = 'delete';

    public function __construct(
        protected MailboxProviderFactory $providers,
        protected MessageService $messages,
    ) {}

    /**
     * Folder operations and the folder part of the sync share this lock, so a
     * running sync never deactivates a folder that is being renamed.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function locked(Mailbox $mailbox, Closure $callback): mixed
    {
        $seconds = (int) config('filament-mailbox.folders.lock_seconds', 120);

        return Cache::lock("filament-mailbox-folders:{$mailbox->getKey()}", $seconds)->block($seconds, $callback);
    }

    public function supports(Mailbox $mailbox): bool
    {
        return config('filament-mailbox.folders.management', true)
            && $mailbox->supports(ProviderCapability::FolderManagement);
    }

    public function create(Mailbox $mailbox, string $name, ?MailboxFolder $parent = null): MailboxFolder
    {
        $this->ensureOwn($mailbox, $parent);

        $name = $this->validName($mailbox, $name, $parent);

        $folder = $this->withProvider($mailbox, function (ManagesFolders&MailboxProvider $provider) use ($mailbox, $name, $parent): MailboxFolder {
            $data = $provider->createFolder($name, $parent?->identifier());

            return DB::transaction(function () use ($mailbox, $data, $parent, $provider): MailboxFolder {
                $fullName = $this->localFullName($provider, $data, $parent);
                $this->releaseFullName($mailbox, $fullName, $data->remoteId);

                return $mailbox->folders()->updateOrCreate(['remote_id' => $data->remoteId], [
                    'parent_id' => $parent?->getKey(),
                    'name' => $data->name,
                    'full_name' => $fullName,
                    'delimiter' => $data->delimiter ?? $parent?->delimiter,
                    'special_use' => $data->specialUse,
                    'is_active' => true,
                    'is_subscribed' => true,
                ]);
            });
        });

        FolderCreated::dispatch($folder);

        return $folder;
    }

    public function rename(MailboxFolder $folder, string $name): MailboxFolder
    {
        return $this->relocate($folder, $name, $folder->parent_id ? $folder->parent : null);
    }

    public function move(MailboxFolder $folder, ?MailboxFolder $parent): MailboxFolder
    {
        return $this->relocate($folder, $folder->name, $parent);
    }

    /**
     * Delete a folder with its descendants. Their messages are moved to the
     * trash first, or deleted with the folders.
     */
    public function delete(MailboxFolder $folder, string $mode = self::DELETE_TO_TRASH): void
    {
        $mailbox = $folder->mailbox;

        $this->ensureModifiable($folder);

        if ($mode === self::DELETE_PERMANENTLY && ! config('filament-mailbox.folders.allow_permanent_delete', false)) {
            throw InvalidFolderOperation::because('permanent_delete_disabled');
        }

        $folders = $folder->descendants()->push($folder);
        $trash = null;

        if ($mode === self::DELETE_TO_TRASH) {
            $trash = $mailbox->folderFor(SpecialUse::Trash);
            $hasMessages = $folders->contains(fn (MailboxFolder $item): bool => $item->messages()->exists());

            if ($hasMessages && ! $trash) {
                throw InvalidFolderOperation::because('no_trash');
            }
        }

        $this->withProvider($mailbox, function (ManagesFolders&MailboxProvider $provider) use ($folders, $mode, $trash): void {
            // Deepest folders first: IMAP DELETE does not remove descendants.
            foreach ($folders as $item) {
                if ($trash && $item->messages()->exists()) {
                    $this->messages->move($item->messages()->with(['mailbox', 'folder'])->get(), $trash);
                }

                $provider->deleteFolder($item->identifier());

                DB::transaction(function () use ($item): void {
                    // Messages deleted with the folder; the folder is kept inactive like after a sync.
                    $item->messages()->get()->each->delete();
                    $item->forceFill(['is_active' => false])->save();
                });
            }
        });

        FolderDeleted::dispatch($folder->refresh());
    }

    public function subscribe(MailboxFolder $folder, bool $subscribed): void
    {
        $this->withProvider($folder->mailbox, function (ManagesFolders&MailboxProvider $provider) use ($folder, $subscribed): void {
            $provider->subscribeFolder($folder->identifier(), $subscribed);

            $folder->forceFill(['is_subscribed' => $subscribed])->save();
        });
    }

    /**
     * Whether the folder may be renamed, moved or deleted.
     */
    public function isModifiable(MailboxFolder $folder): bool
    {
        return strcasecmp($folder->full_name, 'INBOX') !== 0
            && ! ($folder->special_use !== null && config('filament-mailbox.folders.protect_special_use', true));
    }

    /**
     * Folders the given folder can be moved below: not itself, no descendant.
     *
     * @return Collection<int, MailboxFolder>
     */
    public function possibleParents(MailboxFolder $folder): Collection
    {
        $excluded = $folder->descendants()->modelKeys();
        $excluded[] = $folder->getKey();

        return $folder->mailbox->folders()
            ->where('is_active', true)
            ->whereKeyNot($excluded)
            ->orderBy('full_name')
            ->get();
    }

    protected function relocate(MailboxFolder $folder, string $name, ?MailboxFolder $parent): MailboxFolder
    {
        $mailbox = $folder->mailbox;

        $this->ensureModifiable($folder);
        $this->ensureOwn($mailbox, $parent);

        if ($parent && ($parent->is($folder) || $folder->descendants()->contains($parent))) {
            throw InvalidFolderOperation::because('cycle');
        }

        $name = $this->validName($mailbox, $name, $parent, $folder);

        if ($name === $folder->name && $parent?->getKey() === $folder->parent_id) {
            return $folder;
        }

        $oldFullName = $folder->full_name;

        $this->withProvider($mailbox, function (ManagesFolders&MailboxProvider $provider) use ($mailbox, $folder, $name, $parent): void {
            $oldRemoteId = $folder->identifier()->remoteId;
            $data = $provider->renameFolder($folder->identifier(), $name, $parent?->identifier());
            $byPath = $provider->identifiesFoldersByPath();

            DB::transaction(function () use ($mailbox, $folder, $data, $parent, $provider, $oldRemoteId, $byPath): void {
                $oldFullName = $folder->full_name;
                $newFullName = $this->localFullName($provider, $data, $parent);
                $delimiter = (string) ($folder->delimiter ?? $data->delimiter ?? '');
                $descendants = $folder->descendants(includeInactive: true);

                $this->releaseFullName($mailbox, $newFullName, $data->remoteId, [$folder->getKey(), ...$descendants->modelKeys()]);

                foreach ($descendants as $descendant) {
                    $descendant->forceFill([
                        'full_name' => FolderPath::replacePrefix($descendant->full_name, $oldFullName, $newFullName, $delimiter),
                        'remote_id' => $byPath
                            ? FolderPath::replacePrefix((string) $descendant->remote_id, $oldRemoteId, $data->remoteId, $delimiter)
                            : $descendant->remote_id,
                    ])->save();
                }

                $folder->forceFill([
                    'name' => $data->name,
                    'full_name' => $newFullName,
                    'remote_id' => $data->remoteId,
                    'parent_id' => $parent?->getKey(),
                ])->save();
            });

            // Some IMAP servers assign a new UIDVALIDITY on RENAME; the sync detects it and resets the folder.
            if ($byPath) {
                foreach ($folder->descendants()->push($folder) as $item) {
                    SyncMailboxFolderJob::dispatch($item, SyncTrigger::FolderChange);
                }
            }
        });

        FolderRenamed::dispatch($folder, $oldFullName);

        return $folder;
    }

    /**
     * @template T
     *
     * @param  Closure(ManagesFolders&MailboxProvider): T  $callback
     * @return T
     */
    protected function withProvider(Mailbox $mailbox, Closure $callback): mixed
    {
        if (! config('filament-mailbox.folders.management', true)) {
            throw UnsupportedOperation::for('manage folders', ProviderCapability::FolderManagement);
        }

        return static::locked($mailbox, function () use ($mailbox, $callback): mixed {
            $provider = $this->providers->make($mailbox);

            try {
                if (! $provider instanceof ManagesFolders || ! $provider->supports(ProviderCapability::FolderManagement)) {
                    throw UnsupportedOperation::for('manage folders', ProviderCapability::FolderManagement);
                }

                return $callback($provider);
            } finally {
                $provider->disconnect();
            }
        });
    }

    protected function validName(Mailbox $mailbox, string $name, ?MailboxFolder $parent, ?MailboxFolder $ignore = null): string
    {
        $delimiter = $parent?->delimiter ?? $ignore?->delimiter ?? $mailbox->folders()->whereNotNull('delimiter')->value('delimiter');

        if ($error = FolderPath::nameError($name, $delimiter)) {
            throw InvalidFolderOperation::because($error, ['delimiter' => (string) $delimiter]);
        }

        $name = trim($name);

        $exists = $mailbox->folders()
            ->where('is_active', true)
            ->where('parent_id', $parent?->getKey())
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->get(['id', 'name'])
            ->contains(fn (MailboxFolder $sibling): bool => mb_strtolower($sibling->name) === mb_strtolower($name));

        if ($exists || ($parent === null && strcasecmp($name, 'INBOX') === 0)) {
            throw InvalidFolderOperation::because('name_taken', ['name' => $name]);
        }

        return $name;
    }

    protected function ensureModifiable(MailboxFolder $folder): void
    {
        if (! $this->isModifiable($folder)) {
            throw InvalidFolderOperation::because('protected');
        }
    }

    protected function ensureOwn(Mailbox $mailbox, ?MailboxFolder $folder): void
    {
        if ($folder && ($folder->mailbox_id !== $mailbox->getKey() || ! $folder->is_active)) {
            throw InvalidFolderOperation::because('foreign_folder');
        }
    }

    /**
     * Path-identified providers report the full path; for the others it is
     * built from the parent like during the sync.
     */
    protected function localFullName(ManagesFolders $provider, FolderData $data, ?MailboxFolder $parent): string
    {
        if ($provider->identifiesFoldersByPath()) {
            return $data->fullName;
        }

        return $parent ? $parent->full_name.($data->delimiter ?? '/').$data->name : $data->name;
    }

    /**
     * Inactive folders keep their full name; free it for a new or renamed folder.
     *
     * @param  array<int, int>  $except
     */
    protected function releaseFullName(Mailbox $mailbox, string $fullName, string $remoteId, array $except = []): void
    {
        $mailbox->folders()
            ->where('full_name', $fullName)
            ->where('remote_id', '!=', $remoteId)
            ->whereKeyNot($except)
            ->where('is_active', false)
            ->get()
            ->each(fn (MailboxFolder $inactive) => $inactive->forceFill(['full_name' => mb_substr($inactive->full_name, 0, 200).' [inactive '.$inactive->getKey().']'])->save());
    }
}
