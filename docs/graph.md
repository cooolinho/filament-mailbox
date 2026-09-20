# Microsoft Graph provider

Mailboxes with the provider **Microsoft Graph** use the Graph Mail API instead of IMAP. Compared to
IMAP with OAuth this gives delta queries instead of UID scans, change notifications, sending with
automatic storage in *Sent Items*, immutable message ids and shared mailboxes via application permissions.

## Setup

1. Register an app in Microsoft Entra ID (see [OAuth2](oauth.md#microsoft-entra-id)) and add the
   **Microsoft Graph** permissions — least privilege:

   | Mode | Permissions |
   |---|---|
   | Delegated (personal mailbox) | `Mail.ReadWrite`, `Mail.Send`, `User.Read`, `offline_access` |
   | Application (shared/functional mailboxes) | `Mail.ReadWrite`, `Mail.Send` (application) + admin consent |

2. Create the **OAuth application** in the panel.
3. Create a mailbox, choose **Provider: Microsoft Graph** (sign-in switches to OAuth2), select the
   application and either
   - **Connect with Microsoft** (delegated, `/me`), or
   - **Use application permissions** (app-only, `/users/{e-mail address}`).
4. Optional **Mailbox (shared or app-only)**: address of a shared mailbox to access as
   `/users/{address}` instead of the signed-in account (delegated access needs `Mail.ReadWrite.Shared`).
5. **Test connection**, then synchronise.

> **Application permissions are tenant-wide.** Without restriction the app can read every mailbox.
> Restrict it to the required mailboxes with RBAC for Applications in Exchange Online (or the legacy
> *Application Access Policy*: `New-ApplicationAccessPolicy -AccessRight RestrictAccess ...`). This is a
> mandatory step.

## How it works

| Operation | Graph |
|---|---|
| Test connection | `GET /{mailbox}/mailFolders/inbox` |
| Folders | `GET /{mailbox}/mailFolders` + `childFolders`, roles via well-known names (`inbox`, `sentitems`, `drafts`, `deleteditems`, `junkemail`, `archive`) |
| Sync | `GET …/mailFolders/{id}/messages/delta` (paged with `odata.maxpagesize` = `sync.chunk_size`), cursor `{"delta_link": …}` |
| New messages | `GET /{mailbox}/messages/{id}/$value` (MIME) → `MimeMessageMapper`, flags and `receivedDateTime` from the delta page |
| Read / flag / categories | `PATCH /{mailbox}/messages/{id}` |
| Move / delete | `POST …/move`; delete moves to *Deleted Items*, in there `DELETE` |
| Send / reply | `POST /{mailbox}/sendMail` with Base64 MIME (same threading headers and attachments as SMTP) |
| Labels | Outlook categories (`/outlook/masterCategories`); names cannot be renamed in Graph, only colours |

- `Prefer: IdType="ImmutableId"` keeps message ids stable when messages are moved.
- Delta pages don't tell new from changed messages: they are reported as `updated` with
  `FolderChanges::$includesNew`; only unknown messages are downloaded.
- An expired delta token (HTTP 410 / `SyncStateNotFound`) restarts the delta query with `reset`;
  existing messages are matched by id and restored, not downloaded again.
- Throttling (429/503/504) honours `Retry-After` with exponential backoff up to `graph.max_retries`; a
  401 renews the token once, then the connection fails. Tokens never appear in exceptions or logs.

## Change notifications (optional)

Subscriptions per folder trigger a `SyncMailboxFolderJob` for that folder only — nothing from the
notification payload is used.

```dotenv
MAILBOX_GRAPH_WEBHOOKS=true
MAILBOX_GRAPH_NOTIFICATION_URL=https://mail.example.com/filament-mailbox/webhooks/graph
```

```php
Schedule::command('mailbox:graph-subscriptions')->everySixHours();
```

- The URL must be public HTTPS (for local sandboxes use a tunnel). The route
  `POST /filament-mailbox/webhooks/graph` is outside the panel authentication, rate limited, answers the
  validation handshake and compares `clientState` (random per subscription, encrypted at rest) in
  constant time.
- The command creates missing subscriptions, renews those expiring within 12 hours, recreates
  subscriptions unknown to Graph and removes those of deactivated folders.
- Keep the regular `mailbox:sync` schedule as a fallback.

## Configuration

| Key | Env | Default |
|---|---|---|
| `graph.base_url` | `MAILBOX_GRAPH_BASE_URL` | `https://graph.microsoft.com/v1.0` (national clouds: e.g. `https://graph.microsoft.us/v1.0`, plus `MAILBOX_MS_AUTHORITY`) |
| `graph.max_retries` | `MAILBOX_GRAPH_MAX_RETRIES` | `5` |
| `graph.timeout` | `MAILBOX_GRAPH_TIMEOUT` | `60` |
| `graph.webhooks.enabled` | `MAILBOX_GRAPH_WEBHOOKS` | `false` |
| `graph.webhooks.notification_url` | `MAILBOX_GRAPH_NOTIFICATION_URL` | – |
| `graph.webhooks.lifetime_minutes` | `MAILBOX_GRAPH_SUBSCRIPTION_MINUTES` | `4200` |

## Limits and troubleshooting

- Graph throttles about 10,000 requests per 10 minutes per mailbox; the initial sync of large mailboxes
  downloads one MIME message per request and takes a while. The cursor is stored after every page, so a
  cancelled job continues where it stopped.
- `ErrorAccessDenied` for app-only access: the mailbox is not covered by the access policy/RBAC scope.
- `Graph rejected the access token`: check admin consent and that the connection was created for the
  Graph provider (Graph and IMAP tokens have different audiences).
- Data stays in the Microsoft tenant; the local copy is handled like IMAP (document it in your
  data processing records).

## Manual end-to-end checklist

See [Testing](testing.md#manual-microsoft-graph-checklist).
