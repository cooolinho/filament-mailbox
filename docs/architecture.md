# Architecture

```text
Filament UI (resource pages, actions)
     │   asks Mailbox::supports(ProviderCapability) — never the provider type
     ▼
Application services
     ├── SyncService          folders + provider-neutral change loop per folder
     ├── MessageService       read/unread, flag, move, delete
     ├── LabelService         labels on the server, then locally (LabelSynchronizer for sync)
     ├── AttachmentService    storage + download
     ├── MessageSearch        search through the configured engine (Search\SearchManager)
     ├── MailSender           OutgoingTransport: provider API, mailbox SMTP (XOAUTH2) or Laravel Mail
     ├── OAuth                OAuthFlow (PKCE), OAuthTokenManager, OAuthConnectionManager
     ├── ReplyBuilder         reply recipients, subject, quote (text/HTML), references
     ├── DraftService         drafts, server copies (appendMessage), import of client drafts
     └── Composing            HtmlBodySanitizer::outgoing(), InlineImageProcessor (cid:), HtmlMailRenderer, HtmlToText,
                              SignatureResolver/SignatureRenderer, TemplateRepository/TemplateRenderer, PlaceholderRenderer
     │
     ▼
MailboxProvider (contract)  ◄── MailboxProviderFactory ◄── config('filament-mailbox.providers')
     │
     ▼
AbstractMailboxProvider (defaults, UnsupportedOperation)
     │
     ├── ImapProvider (DirectoryTree ImapEngine) ──► IMAP server
     ├── GraphProvider (GraphClient on Laravel Http) ──► Microsoft Graph
     └── GmailProvider (GmailClient on Laravel Http, batch) ──► Gmail API
```

The UI never calls the provider directly. Providers return provider-independent DTOs from `src/Data`.

## Providers

A provider implements `MailboxProvider`, usually by extending `AbstractMailboxProvider`:

| Method | Purpose |
|---|---|
| `capabilities()` / `supports()` | Static list of `ProviderCapability` cases |
| `testConnection()`, `folders()` | Connection check, folder tree (`FolderData` with a stable `remoteId`) |
| `changes(folder, cursor, limit)` | Next batch of changes since an opaque `SyncCursor` → `FolderChanges` |
| `isStale(message, cursor)` | Whether a stored identity is outdated (IMAP: UIDVALIDITY changed); never connects |
| `setFlags(message, add, remove)` | `MessageFlags`: seen, flagged, answered, draft, keywords |
| `markRead()` / `markUnread()` | Convenience, delegate to `setFlags()` |
| `moveMessage(message, target)` | Returns the new `MessageIdentifier` or `null` when unknown |
| `delete(message, ?trash)` | Moves to trash via `moveMessage()`, otherwise deletes permanently |
| `appendMessage(folder, raw, flags)` | Stores a MIME message, e.g. drafts or sent mail |
| `rawMessage(message)` | The MIME source |

Optional operations throw `UnsupportedOperation` by default. `MessageService` checks the capability first
and `MessageActions::run()` shows a warning notification for unsupported operations.

Providers are registered in the config and resolved from the container with the mailbox as `mailbox`
parameter:

```php
'providers' => [
    'imap' => \Cooolinho\FilamentMailbox\Providers\Imap\ImapProvider::class,
],
```

Capabilities are resolved per provider type without connecting (`Support\ProviderCapabilities`,
request-scoped).

### Identifiers and cursors

| | Folder `remote_id` | Message `remote_id` | `sync_cursor` |
|---|---|---|---|
| IMAP | path (`full_name`) | `<uidvalidity>:<uid>` | `{"uid_validity": 123, "last_uid": 456}` |
| Graph | folder id | immutable message id | `{"delta_link": "…"}` (+ `next_link` while paging) |
| Gmail | system label id (`INBOX`, …, `__archive__`) | message id | **mailbox** cursor `{"history_id": "…"}` |

Including UIDVALIDITY in the IMAP message id keeps identities unique after a UIDVALIDITY change, without
collisions with soft-deleted records.

### Change semantics

`FolderChanges` contains `created` messages, `updated` flags keyed by remote id, `deleted` remote ids and
the new `cursor`, plus four switches:

- `hasMore` — call `changes()` again with the returned cursor.
- `reset` — local messages of the folder are outdated and soft-deleted before applying the batch.
- `snapshot` — `updated` lists **every** message left on the server; local messages missing from it are
  soft-deleted. IMAP uses this once a folder is caught up, delta-based providers report `deleted` instead.
- `includesNew` — `updated` may contain messages never synchronised (Graph delta pages). Unknown ids are
  loaded with `fetchMessages()`, soft-deleted local copies are restored without a download.

A created message that already exists locally only updates its flags; a soft-deleted one is restored
(e.g. after it was moved back or re-delivered after a `reset`).

## Data model

```text
mailbox_oauth_applications ──< mailbox_oauth_connections ──< mailboxes (oauth_connection_id)

mailboxes ──< mailbox_user >── users
    │
    ├──< mailbox_folders (parent_id → mailbox_folders)
    │         │
    ├──< mailbox_labels ──< mailbox_message_labels >──┐
    │                                                 │
    ├──< mailbox_messages (folder_id → mailbox_folders) ┘
    │             │  └── draft_id → mailbox_drafts
    │             └──< mailbox_attachments
    │
    ├──< mailbox_drafts (reply_to_message_id → mailbox_messages) ──< mailbox_draft_attachments
    ├──< mailbox_signatures
    └──< mailbox_templates (or global) ──< mailbox_template_attachments
```

- `mailbox_folders.remote_id` identifies the folder on the server, `full_name` is the display path and
  `name` the decoded display name.
- `special_use` is derived from RFC 6154 attributes (and the reserved `INBOX` name).
- `sync_cursor` is tracked **per folder**.
- `mailbox_messages` is unique on `(folder_id, remote_id)`, stores `is_read`, `is_flagged`, `is_answered`
  and `keywords`, and soft-deletes.
- Folders missing on the server are deactivated (`is_active = false`), never deleted.
- `uid`, `uid_validity` and `last_synced_uid` are legacy columns, no longer read, and will be dropped in
  the next major release (see [Upgrade](upgrade.md)).

## Synchronisation

```text
connect → list folders → upsert folders by remote_id + hierarchy → deactivate missing folders
        → for each active folder:
              loop changes(cursor, chunk_size)
              ├─ reset?    soft-delete local messages
              ├─ created   import (+ attachments) in a transaction, restore or update existing
              ├─ updated   mirror flags; snapshot → soft-delete messages missing on the server
              ├─ deleted   soft-delete
              └─ store cursor
              until hasMore = false
```

### Mailbox-wide synchronisation

Providers with `ProviderCapability::MailboxWideSync` implement `SupportsMailboxWideSync::mailboxChanges()`
(Gmail history). `SyncService::syncMailboxWide()` then runs instead of the folder loop: every change carries
the current folder and flags of a message, messages are matched by `mailbox_id + remote_id`, moved between
folders, restored, imported via `fetchMessages()` or soft-deleted. The cursor lives in `mailboxes.sync_cursor`.

IMAP implementation of `changes()`: STATUS (UIDVALIDITY) → compare with the cursor (`reset`) → fetch
UIDs above `last_uid` in chunks → once caught up, fetch all flags as snapshot.

A failing folder is recorded and the remaining folders continue. If the connection or folder listing
fails, `SyncFailed` is thrown so the queue job can retry.

### Events

| Event | When |
|---|---|
| `MessagesImported` | A batch of a folder imported at least one message (`folder`, `count`) |
| `FolderSynced` | A folder was synchronised (`folder`, `imported`) |
| `FolderSyncFailed` | A folder failed (`folder`, redacted `error`) |
| `MailboxSynced` | A mailbox run finished (`mailbox`, `SyncResult`) |
| `LabelsChanged` | Labels of messages changed (`mailbox`, `messageIds`) |
| `MailboxSyncFailed` | Connection or folder listing failed (`mailbox`, redacted `error`) |
| `SyncRunStarted` / `SyncRunFinished` | A recorded sync run started / finished (`run`), see [Monitoring](monitoring.md) |
| `FolderCreated` / `FolderRenamed` / `FolderDeleted` | Folder management operations (`folder`, `oldFullName`) |
| `MessagesArchived` | Messages were archived (`mailbox`, `messageIds`) |
| `MessagesMarkedAsSpam` / `MessagesMarkedAsNotSpam` | Messages were moved to / out of spam (`mailbox`, `messageIds`) |
| `MessageTagsChanged` | Tag assignments changed (`mailbox`, `messageIds`) |
| `MessageForwarded` | A message was forwarded (`message`, `recipients`, `asAttachment`) |

## Labels

Providers implementing `SupportsLabels` expose server-side labels; keys travel in `MessageFlags::$keywords`
and the catalogue is mirrored after the folders. See [Labels](labels.md).

## Authentication

`mailboxes.auth_mode` is `password` or `oauth`. For OAuth mailboxes `ImapProvider` authenticates with
XOAUTH2 using `OAuthTokenManager::accessToken()`, and `MailSender` picks `SmtpMailboxTransport`. A revoked
refresh token raises `OAuthReconnectRequired` (a `ConnectionFailed`), which makes the sync fail
permanently. See [OAuth2](oauth.md).

## Extension points

- `filament-mailbox.providers` / `MailboxProviderFactory` — add providers.
- `ManagesFolders` — folder management for a provider, see [Folder management](folders.md).
- Sync events — monitoring, notifications, search indexing.
- `SyncObserver` — pass an observer to `SyncService::syncMailbox()`/`syncFolder()` to receive folder and provider measurements.
- `OutgoingTransport` — deliver mail differently per mailbox; providers implementing `SupportsSending`
  receive the rendered MIME (`MimeMessageBuilder`) through `ProviderTransport`.
- `OAuthConnectionRevoked` event — custom alerting.
- Policies — register your own `Mailbox`/`MailboxMessage` policies before the package boots.

## Testing providers

`tests/Contracts/MailboxProviderContractTest` describes the behaviour every provider must fulfil. The
in-memory `FakeMailboxProvider` and the `ImapProvider` (against GreenMail) both run it.
