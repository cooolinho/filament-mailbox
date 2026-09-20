# 📬 Filament Mailbox

*IMAP, Microsoft 365 and Gmail mailbox integration for Filament v5 — sync, read, search and reply to e-mails inside your admin panel.*

> **Status:** IMAP (password or OAuth2), Microsoft Graph and Gmail API; see [Limitations](#limitations).

## 📖 About

A reusable Filament v5 plugin that connects IMAP mailboxes to a Filament panel. The IMAP server is the
source of truth: folders and messages are synchronised per folder into the local database by a queue
job, so tables, search, pagination and folder switching never hit the IMAP server directly.

All mail operations go through a provider abstraction (`MailboxProvider`): IMAP, the Microsoft Graph Mail
API and the Gmail API are included, further providers can be added without touching the Filament UI.

## ✨ Features

- **Mailbox management** — host, port, encryption, credentials (encrypted at rest), active flag, assigned users
- **OAuth2 sign-in** for Microsoft 365 and Google (IMAP/SMTP `XOAUTH2`, PKCE, automatic token refresh, app-only access for Microsoft)
- **Microsoft Graph provider** — delta sync, immutable ids, Outlook categories as labels, sending via `sendMail`, optional change notifications
- **Gmail API provider** — history sync, native labels, batch downloads, replies in the Gmail thread, service accounts, optional Pub/Sub push
- **Per-mailbox SMTP** — OAuth mailboxes send with their own identity; optional SMTP host for password mailboxes
- **Connection test** from the mailbox form, using the unsaved form values
- **Folder management** — create, rename, move, subscribe and delete folders on the server; move messages to any folder, also by drag & drop onto the folder navigation
- **Folder detection** — hierarchy and special-use roles (Inbox, Sent, Drafts, Trash, Spam, Archive) from IMAP metadata, never from folder names
- **Per-folder synchronisation** honouring `UIDVALIDITY`, incremental by UID, without duplicates
- **Flag sync** (read, flagged, answered, keywords) in both directions; messages deleted on the server are soft-deleted locally
- **Provider-neutral core** — capabilities, opaque sync cursors, move/flag/append operations and sync events
- **Inbox** with folder navigation, unread highlighting, attachment indicator and pagination
- **Keyboard shortcuts** — Gmail-like keys for navigating, opening, replying, archiving, starring and deleting, help with `?`, per-user switch
- **Split view** — message list and reading pane side by side with all message actions, deep links and a per-user layout choice
- **Message view** with sanitised HTML in a sandboxed iframe (strict CSP, no scripts, no remote resources)
- **Attachments** stored on a configurable disk and downloaded through an authorised route
- **Actions** — mark read/unread, star, archive, spam / not spam (with undo) and delete (move to trash), single and bulk
- **Spam protection** — `$Junk`/`$NotJunk` keywords, strict rendering without links, blocked senders
- **Starred** view across all folders with count, star column and filter
- **Snooze** — hide messages until later today, tomorrow, next week or a chosen time; they return unread on top with a notification
- **Tags** — app-only, global or per mailbox, stable across re-imports, with catalogue, filter and bulk actions
- **Labels** synchronised with IMAP keywords — navigation, filter, badges, bulk actions and a colour-coded catalogue
- **Search** with Gmail-like operators (`from:`, `has:attachment`, `is:unread`, `in:`, dates, phrases, OR), all-folder search and highlighted matches; LIKE, database full-text (MySQL/MariaDB, PostgreSQL, SQLite FTS5), Meilisearch or any Laravel Scout engine, with optional attachment text extraction and a contract suite for own engines
- **Compose, reply, reply all and forward** (inline with original attachments or as `.eml` attachment) with threading headers
- **Outbox and scheduled send** — send later with presets or a chosen time, optional undo window, retries with backoff, status of every sent message, edit, reschedule, cancel and retry
- **Read receipts** — request MDNs (RFC 8098), answer requests only after asking the user, assign incoming receipts to sent messages
- **Delivery receipts** — DSN parameters in the SMTP dialogue, delivery reports and bounces assigned per recipient, delivery status in the outbox
- **Drafts** — save from every compose form, editor with autosave, copies in the IMAP drafts folder (linked, not duplicated), drafts of other clients editable
- **Templates** — reusable texts with categories, placeholders, subject and attachments, inserted into new messages, replies and forwards
- **Signatures** — per mailbox and personal, defaults for new messages, replies and forwards, placeholders, HTML with inline images
- **Rich text editor** — formatted HTML mail with text alternative, inline images (`cid:`), HTML quotes, preview and server-side sanitising; plain text per message or mailbox
- **Monitoring** — sync runs with durations, counts and classified errors, sync history, alerts (database, mail, Slack), optional Laravel Pulse cards, OpenTelemetry spans and Prometheus metrics
- **Statistics** — received and sent volume, busy hours, first-reply times within business hours, SLA share, unanswered by age, sender domains, CSV export (mailbox aggregates only, opt-in)
- **Health dashboard** — health rating per mailbox (healthy, degraded, stale, failing, reconnect), key figures, system checks (queue, scheduler, storage, search, OAuth, failed jobs) and quick actions
- **New mail notifications** — bundled panel notifications for assigned users, desktop notifications and optional payload-less web push, with per-mailbox and folder preferences
- **Progressive Web App** — installable panel with manifest, static-asset service worker, offline page and app shortcuts (opt-in)
- **Offline copy** — opt-in per device: read recent messages without connection from an AES-GCM encrypted IndexedDB copy, revocable, deleted on logout (read-only, off by default)
- **Authorization** — users only access assigned mailboxes; managing, sending and deleting are configurable

## 🚀 Getting Started

### Requirements

- PHP 8.2+
- Laravel 11+ with Filament 5
- A running queue worker for synchronisation and scheduled messages
- No `ext-imap` needed (uses [DirectoryTree ImapEngine](https://github.com/DirectoryTree/ImapEngine))

### Installation

```bash
composer require cooolinho/filament-mailbox
php artisan migrate
```

Register the plugin in your panel provider:

```php
use Cooolinho\FilamentMailbox\FilamentMailboxPlugin;

$panel->plugin(
    FilamentMailboxPlugin::make()
        ->navigationGroup('Mail'),
);
```

Optionally publish the configuration:

```bash
php artisan vendor:publish --tag=filament-mailbox-config
```

### Scheduling the synchronisation

The package does not register a schedule. Add one to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('mailbox:sync')->everyFiveMinutes();
Schedule::command('mailbox:monitor')->everyFiveMinutes(); // alerts, health rating and system checks
Schedule::command('mailbox:prune-monitoring')->daily();
Schedule::command('mailbox:prune-tag-assignments, mailbox:monitor, mailbox:prune-monitoring')->daily();
Schedule::command('mailbox:prune-compose-uploads')->hourly();
Schedule::command('mailbox:wake-snoozed')->everyMinute(); // only with snooze.enabled
Schedule::command('mailbox:send-due')->everyMinute(); // scheduled messages and undo window
Schedule::command('mailbox:prune-outbox')->daily();
Schedule::command('mailbox:stats-aggregate')->hourly(); // only with statistics.enabled
Schedule::command('mailbox:prune-drafts')->daily(); // only with drafts.prune_after_days
// Only with Microsoft Graph change notifications enabled:
Schedule::command('mailbox:graph-subscriptions')->everySixHours();
// Only with Gmail push notifications enabled:
Schedule::command('mailbox:gmail-watch')->daily();
```

## 📋 Usage

1. Open **Mailboxes** in the panel and create a mailbox with its IMAP connection data — or register an
   **OAuth application** first and connect a Microsoft/Google account ([OAuth2](docs/oauth.md)).
2. Assign the users who may read it (a manager is not automatically allowed to read mail).
3. Use **Test connection** on the edit page.
4. Open the mailbox and click **Synchronise** — a `SyncMailboxJob` is queued.
5. Browse folders, open messages, download attachments, mark, delete, search, compose, reply and forward.

### Synchronisation command

```bash
php artisan mailbox:sync            # queue a job for every active mailbox
php artisan mailbox:sync 1 3        # only the given mailbox IDs
php artisan mailbox:sync --now      # synchronise immediately, without the queue
```

### Authorization

```php
FilamentMailboxPlugin::make()
    ->canManageMailboxes(fn ($user) => $user->is_admin)
    ->canSendMessages(fn ($user) => true)
    ->canDeleteMessages(fn ($user) => $user->hasRole('support'))
    ->canManageFolders(fn ($user) => $user->is_admin)
    ->canManageTags(fn ($user) => $user->is_admin)
    ->canManageTemplates(fn ($user) => $user->is_admin)
    ->canTagMessages(fn ($user) => true);
```

See [docs/authorization.md](docs/authorization.md) for the complete rules.

### Limitations

- Providers: IMAP (password or OAuth2), Microsoft Graph and Gmail API; no EWS or POP3.
- Gmail API: no permanent deletion in the trash (restricted `mail.google.com` scope is not requested), no thread view.
- IMAP: sent mail is delivered via Laravel Mail or the mailbox's SMTP server and is **not** appended to the "Sent" folder
  (Graph and Gmail store it themselves).
  It only appears there if your mail server stores sent messages itself.
- No multi-tenancy; Gmail API folders (system labels) cannot be managed.
- Drafts are stored on the server for IMAP only (Microsoft Graph and Gmail API: in the app).
- Offline: read-only copy; no offline actions, drafts or search.
- Remote images and `<style>` blocks in HTML mail are not rendered.
- Composed HTML is limited to basic formatting (no fonts, colours or alignment); quoted originals lose their images.

## 📁 Project Structure

```text
config/filament-mailbox.php     Package configuration
database/migrations/            mailboxes, folders, messages, attachments, identifier generalisation, OAuth applications/connections, signatures, templates, drafts
database/factories/             Model factories
resources/lang/{en,de}/         Translations
resources/views/                Message body entry, keyboard shortcut script and help, offline app shell and sync script, compose preview, mail templates, PWA views and service worker
resources/pwa/icons/            Default app icons
src/
├── WebPush/                    VAPID keys and payload-less push sender
├── Pwa/                        Manifest factory and PWA URLs
├── Health/                     Mailbox health evaluator, system checks, key figures
├── Search/                     Query parser, engine registry, search engines (like, database, Meilisearch, Scout) and grammars, index writer, snippets, attachment text extraction
├── Statistics/                 Reply linker, business hours, daily aggregator, statistics queries, live figures
├── Commands/                   mailbox:sync, mailbox:wake-snoozed, mailbox:send-due, mailbox:prune-outbox, mailbox:search-reindex, mailbox:search-extract-attachments, mailbox:search-tasks-check, mailbox:monitor, mailbox:stats-aggregate, mailbox:stats-backfill, mailbox:graph-subscriptions, mailbox:gmail-watch, mailbox:prune-tag-assignments, mailbox:prune-compose-uploads, mailbox:prune-drafts
├── Contracts/                  MailboxProvider, MailboxProviderFactory
├── Data/                       Provider-independent DTOs (identifiers, cursors, flags, changes)
├── Enums/                      Provider type, capabilities, encryption, special-use roles
├── Events/                     Synchronisation events
├── Exceptions/                 Connection, operation, sync and unsupported-operation errors
├── Filament/Pages/             My signatures, mailbox settings, offline availability, mailbox health, statistics
├── Filament/Widgets/           Health key figures, system checks, statistics charts and tables
├── Filament/Livewire/          Reading pane of the split view
├── Filament/Resources/         MailboxResource with inbox, message and outbox pages, OAuthApplicationResource, MailboxTagResource, MailboxTemplateResource
├── Http/Controllers/           Authorised attachment download, OAuth callback, Graph and Gmail webhooks, PWA and offline endpoints
├── Listeners/                  Revoked OAuth connection handling, alert evaluation after sync runs
├── Monitoring/                 Sync run recorder, error classifier, alert evaluator and notifier
├── Notifications/              Alert notification, Slack webhook channel, new mail notifier, returned snoozed messages, failed outgoing messages, received read receipts, failed deliveries
├── Jobs/                       SyncMailboxJob, WakeSnoozedMessagesJob, SendOutgoingMessageJob, MailboxHeartbeatJob, SyncMailboxFolderJob, SaveDraftToServerJob, DeleteDraftFromServerJob
├── Mail/                       OutgoingMessage mailable, HTML mail renderer, MIME builder, Laravel/SMTP (with DSN)/provider transports, multipart/report parts
├── Models/                     Mailbox, MailboxFolder, MailboxMessage, MailboxAttachment, MailboxSignature, MailboxTemplate, MailboxDraft, MailboxOutgoingMessage, MailboxReceiptRequest, MailboxReceipt, OAuthApplication, OAuthConnection
├── OAuth/                      PKCE flow, token manager, Microsoft/Google providers
├── Policies/                   MailboxPolicy, MailboxMessagePolicy, MailboxSignaturePolicy, MailboxTemplatePolicy, MailboxDraftPolicy, MailboxOutgoingMessagePolicy, OAuthApplicationPolicy
├── Providers/                  Provider factory, AbstractMailboxProvider, MimeMessageMapper
│   ├── Imap/                   ImapEngine-based provider and folder mapper
│   ├── Graph/                  Graph client, provider, folder/category mapping, subscriptions
│   └── Gmail/                  Gmail client (batch), provider, label mapping, history, watch
├── Services/                   Sync, messages, snooze, outbox, read and delivery receipts, offline copy, labels, attachments, search, sending, replies, sanitising, inline images, signatures, templates, drafts
├── Testing/                    Search engine contract suite for other packages
└── Support/                    Authorization, capabilities, IMAP keyword, xtext, relative dates, shortcut registry and credential redaction helpers
tests/                          Feature tests, provider contract suite and GreenMail integration tests
```

## 📚 Documentation

- [Configuration](docs/configuration.md)
- [OAuth2 (Microsoft 365, Google)](docs/oauth.md)
- [Composing](docs/composing.md)
- [Signatures](docs/signatures.md)
- [Templates](docs/templates.md)
- [Drafts](docs/drafts.md)
- [Outbox and scheduled send](docs/outbox.md)
- [Read receipts](docs/read-receipts.md)
- [Delivery receipts](docs/delivery-receipts.md)
- [Message actions](docs/message-actions.md)
- [Split view](docs/split-view.md)
- [Keyboard shortcuts](docs/shortcuts.md)
- [Progressive Web App](docs/pwa.md)
- [Offline copy](docs/offline.md)
- [New mail notifications](docs/notifications.md)
- [Folder management](docs/folders.md)
- [Monitoring](docs/monitoring.md)
- [Health dashboard](docs/health.md)
- [Statistics](docs/statistics.md)
- [Search](docs/search.md)
- [Labels](docs/labels.md)
- [Tags](docs/tags.md)
- [Microsoft Graph](docs/graph.md)
- [Gmail API](docs/gmail.md)
- [Authorization](docs/authorization.md)
- [Architecture](docs/architecture.md)
- [Testing](docs/testing.md)
- [Upgrade](docs/upgrade.md)

## 🔗 References

- [Filament plugin development](https://filamentphp.com/docs/5.x/plugins/getting-started)
- [DirectoryTree ImapEngine](https://github.com/DirectoryTree/ImapEngine)
- [Symfony HtmlSanitizer](https://symfony.com/doc/current/html_sanitizer.html)
- [RFC 9051 — IMAP4rev2](https://datatracker.ietf.org/doc/html/rfc9051) · [RFC 6154 — Special-use mailboxes](https://datatracker.ietf.org/doc/html/rfc6154)
- [GreenMail](https://greenmail-mail-test.github.io/greenmail/)
- [Meilisearch](https://www.meilisearch.com/docs) · [Laravel Scout](https://laravel.com/docs/scout)
- [MySQL full-text search](https://dev.mysql.com/doc/refman/8.0/en/fulltext-search.html) · [PostgreSQL text search](https://www.postgresql.org/docs/current/textsearch.html) · [SQLite FTS5](https://www.sqlite.org/fts5.html)
- [RFC 3461 — SMTP DSN extension](https://datatracker.ietf.org/doc/html/rfc3461) · [RFC 3464 — Delivery status notifications](https://datatracker.ietf.org/doc/html/rfc3464)
- [RFC 8098 — Message Disposition Notification](https://datatracker.ietf.org/doc/html/rfc8098) · [RFC 6522 — Multipart/Report](https://datatracker.ietf.org/doc/html/rfc6522)
- [RFC 3834 — Automatic responses to electronic mail](https://datatracker.ietf.org/doc/html/rfc3834) · [RFC 2919 — List-Id](https://datatracker.ietf.org/doc/html/rfc2919)
- [RFC 8292 — VAPID](https://datatracker.ietf.org/doc/html/rfc8292) · [Push API](https://developer.mozilla.org/docs/Web/API/Push_API) · [Notifications API](https://developer.mozilla.org/docs/Web/API/Notifications_API)
- [Web app manifest](https://developer.mozilla.org/docs/Web/Progressive_web_apps/Manifest) · [Service worker API](https://developer.mozilla.org/docs/Web/API/Service_Worker_API)
- [Laravel Pulse](https://pulse.laravel.com) · [OpenTelemetry PHP](https://opentelemetry.io/docs/languages/php/) · [Prometheus exposition format](https://prometheus.io/docs/instrumenting/exposition_formats/)
- [Microsoft: Authenticate IMAP/SMTP with OAuth](https://learn.microsoft.com/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth) · [Google: OAuth 2.0 for IMAP/SMTP](https://developers.google.com/gmail/imap/xoauth2-protocol) · [RFC 7636 — PKCE](https://datatracker.ietf.org/doc/html/rfc7636)
- [Microsoft Graph Mail API](https://learn.microsoft.com/graph/api/resources/mail-api-overview) · [Delta query for messages](https://learn.microsoft.com/graph/delta-query-messages) · [Change notifications](https://learn.microsoft.com/graph/change-notifications-overview)
- [Gmail API](https://developers.google.com/gmail/api/guides) · [Synchronizing clients](https://developers.google.com/gmail/api/guides/sync) · [Batching](https://developers.google.com/gmail/api/guides/batch) · [Push notifications](https://developers.google.com/gmail/api/guides/push) · [Domain-wide delegation](https://developers.google.com/workspace/guides/create-credentials#service-account)

## 📄 License

MIT — see [LICENSE](LICENSE).
