# Folder management

Folders are created, renamed, moved, subscribed and deleted **on the server**; the local copy is updated
right afterwards without a full resynchronisation. Open *Manage folders* on the mailbox page or the mailbox
edit page (`/mailboxes/{id}/folders`).

| Provider | Create / rename / move / delete | Subscriptions | Statistics |
|---|---|---|---|
| IMAP | `CREATE`, `RENAME`, `DELETE` | `SUBSCRIBE` / `UNSUBSCRIBE`, `LSUB` | `STATUS` (MESSAGES, UNSEEN, SIZE with RFC 8438) |
| Microsoft Graph | `childFolders`, `PATCH displayName`, `move`, `DELETE` (to *Deleted Items*) | – | `totalItemCount`, `unreadItemCount` |
| Gmail API | – (folders are the fixed system labels; user labels are [Labels](labels.md)) | – | – |

Providers implement `Contracts\ManagesFolders` and report `ProviderCapability::FolderManagement`
(`FolderSubscriptions` for subscriptions).

## Page

- Tree with indentation, role, messages, unread, size and subscription state (statistics come from the last
  synchronisation; without them the local message count is shown).
- *New folder* (name, optional parent), *Rename*, *Move* (the folder itself and its subfolders are no valid
  parents), *Subscribe* / *Unsubscribe*, *Delete*.
- *Delete* removes the folder with all subfolders, deepest first. Messages are moved to the trash first, or
  deleted with the folders when `folders.allow_permanent_delete` is enabled and chosen. The folder name must
  be typed to confirm.
- System folders (INBOX and folders with a special-use role) cannot be renamed, moved or deleted
  (`folders.protect_special_use`; the INBOX is always protected).

## Moving messages

*Move to…* (row, bulk and message page) moves messages into any active folder of the mailbox, with *Undo*.

## Rules and consistency (`Services\FolderManager`)

1. Validation — names are trimmed, not empty, at most 255 characters, without control characters, `*`, `%` or
   the hierarchy delimiter, and unique (case-insensitive) among the siblings. Parents and targets are resolved
   through the mailbox; moving a folder into itself or a subfolder is rejected.
2. The provider operation.
3. The local update in a transaction:
   - *create* — `updateOrCreate` by remote id, so a folder deleted earlier is reactivated;
   - *rename / move* — the folder and all descendants get their new `full_name` (and, for IMAP, `remote_id`);
     messages keep their folder;
   - *delete* — folders are deactivated like after a sync, remaining local messages are soft-deleted.
4. IMAP servers may assign a new `UIDVALIDITY` on `RENAME`, so `SyncMailboxFolderJob` is queued for the renamed
   folders; the sync resets them if needed.

Folder operations and the folder listing of the synchronisation share the cache lock
`filament-mailbox-folders:{mailbox}` (`folders.lock_seconds`), so a running sync never deactivates a folder
that is being renamed.

IMAP paths are built by `Support\FolderPath`: modified UTF-7 encoding (`Entwürfe` → `Entw&APw-rfe`) and the
personal namespace prefix (`INBOX.` on Courier-style servers), derived from the existing folders.

Events: `FolderCreated`, `FolderRenamed` (`folder`, `oldFullName`), `FolderDeleted`.

## Authorization

Managing folders requires the `manageFolders` ability on the mailbox, by default *may manage mailboxes*:

```php
FilamentMailboxPlugin::make()
    ->canManageFolders(fn ($user) => $user->is_admin);
```

or a Gate named `manage-mailbox-folders`. Moving messages requires `update` on the messages.
