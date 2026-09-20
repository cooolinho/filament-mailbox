<?php

namespace Cooolinho\FilamentMailbox\Contracts;

use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\FolderIdentifier;
use Cooolinho\FilamentMailbox\Data\FolderStatistics;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;

/**
 * Folder management on the server (ProviderCapability::FolderManagement).
 *
 * Names are plain display names; providers encode them. Use FolderManager
 * instead of calling these methods directly, it keeps the local copy consistent.
 */
interface ManagesFolders
{
    /**
     * Whether folders are identified by their path (IMAP): the remote id then
     * changes with every rename or move, also for all descendants.
     */
    public function identifiesFoldersByPath(): bool;

    /**
     * @param  ?FolderIdentifier  $parent  Null creates a top-level folder
     */
    public function createFolder(string $name, ?FolderIdentifier $parent): FolderData;

    /**
     * Rename and/or move a folder. Descendants move with it.
     *
     * @param  ?FolderIdentifier  $newParent  Null moves the folder to the top level
     */
    public function renameFolder(FolderIdentifier $folder, string $newName, ?FolderIdentifier $newParent): FolderData;

    /**
     * Delete a folder without descendants. Messages still in it are deleted with it.
     */
    public function deleteFolder(FolderIdentifier $folder): void;

    /**
     * @throws UnsupportedOperation when the provider has no subscriptions
     */
    public function subscribeFolder(FolderIdentifier $folder, bool $subscribed): void;

    /**
     * Remote ids of subscribed folders, or null when the provider has no subscriptions.
     *
     * @return ?array<int, string>
     */
    public function subscribedFolders(): ?array;

    public function folderStatistics(FolderIdentifier $folder): FolderStatistics;
}
