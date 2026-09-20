# Upgrade

## Provider-neutral identifiers (next major release)

This release generalises the provider contract so that further providers (Microsoft Graph, Gmail API, …)
and message operations (move, flag, append) can be added.

### Database

Run `php artisan migrate`. The migration `generalize_mailbox_sync_identifiers`

- adds `mailbox_folders.remote_id` and `sync_cursor`,
- adds `mailbox_messages.remote_id`, `is_flagged`, `is_answered`, `keywords`,
- fills them from the existing data in chunks (`remote_id = uid_validity:uid`), so **no messages are
  imported again**, and
- makes `mailbox_messages.uid` / `uid_validity` nullable.

The migration is idempotent: rows that already have a `remote_id` are skipped. On very large
installations run it during a maintenance window. The legacy columns `uid`, `uid_validity` and
`last_synced_uid` are no longer read and will be removed in the following release.

### Breaking changes for custom providers

| Before | After |
|---|---|
| `folderStatus()`, `messages($folder, $afterUid, $limit)`, `seenFlags()` | `changes(FolderIdentifier, SyncCursor, int): FolderChanges` |
| – | `capabilities()`, `supports()`, `isStale()`, `setFlags()`, `moveMessage()`, `appendMessage()`, `rawMessage()` |
| `FolderIdentifier::$fullName` | `FolderIdentifier::$remoteId` |
| `MessageIdentifier::$uid` (int) | `MessageIdentifier::$remoteId` (string) |
| `FolderData::$parentFullName` | `FolderData::$parentRemoteId` (+ `remoteId`, defaults to `fullName`) |
| `MessageData::$uid`, `$seen` | `MessageData::$remoteId`, `$flags` (`MessageFlags`) |
| `FolderStatus` | removed |
| `Providers\Imap\ImapMessageMapper::map($message, $uid, $seen)` | `Providers\MimeMessageMapper::map($message, $remoteId, $flags)` (provider-neutral) |
| `DefaultMailboxProviderFactory` matches the enum | resolves `config('filament-mailbox.providers')` |

Extend `AbstractMailboxProvider` to get defaults for optional operations, and run
`tests/Contracts/MailboxProviderContractTest` against your provider.

### Microsoft Graph release

- Run `php artisan migrate`: `mailboxes.host`, `port`, `encryption` and `username` become nullable,
  `mailboxes.remote_user` and `mailbox_graph_subscriptions` are added.
- `OAuthProvider::scopes()` gained a `ProviderType` parameter; `OAuthFlow::begin()` and
  `OAuthConnectionManager::connectApplication()` accept the mailbox provider type.
- `MailboxProvider::fetchMessages()` was added (default in `AbstractMailboxProvider`), `FolderChanges`
  got `includesNew` and `MessageData` got `receivedAt`.
- `Providers\Imap\ImapMessageMapper` was renamed to `Providers\MimeMessageMapper`.

### Gmail API release

- Run `php artisan migrate`: `mailboxes.sync_cursor`, `initial_sync_days`, `watch_expires_at` and
  `mailbox_messages.thread_id` are added.
- New capabilities `MailboxWideSync` and `PermanentDelete`. Custom providers that can delete messages in the
  trash permanently must report `PermanentDelete`, otherwise the delete action is hidden in the trash.
- `OAuthProvider::clientCredentials()` got an optional `$subject` and the new `applicationGrant()` method.
- `OutgoingMessageData` got `providerThreadId`, `MessageData` got `threadId`.

### Forward

- Run `php artisan migrate`: `mailbox_messages.forwarded_at` is added.
- `MailboxMessagePolicy` got the `forward` ability. Applications with their own message policy must add it,
  otherwise the *Forward* action is hidden.
- `OutgoingMessageData` got `forwardedMessageId`; `ComposeMessageForm::components()` and `toData()` accept
  optional arguments for the body requirement, additional attachments and the forwarded Message-ID.
- The HTML to text conversion of `ReplyBuilder::quote()` moved to `Support\HtmlToText` and now drops
  `<script>`/`<style>` contents.

### Star

- Run `php artisan migrate`: an index on `mailbox_messages (mailbox_id, is_flagged)` is added.
- `MessageService::setFlagged()` no longer throws `UnsupportedOperation` without `ProviderCapability::Flagged`;
  it changes the local copy only.
- `BrowseMailbox` got the `?view=starred` URL parameter (`$virtualView`) and `spansFolders()`.

### Archive

- Run `php artisan migrate`: `mailboxes.archive_folder_id` and `mailbox_messages.pending_move_to` are added.
- IMAP `\All` folders are no longer detected as `SpecialUse::Archive` but as the new `SpecialUse::All`; run a
  synchronisation to update existing folders.
- `MessageService::move()` keeps moved messages without a new identity as soft-deleted records with
  `pending_move_to`; the sync restores them instead of importing duplicates.
- `MessageActions::run()` accepts an optional closure with notification actions.

### Spam

- Run `php artisan migrate`: `mailboxes.spam_folder_id` and the `mailbox_blocked_senders` table are added.
- `HtmlBodySanitizer::sanitize()` and `document()` got an optional `$strict` argument.
- `SyncService` queues `ApplyBlockedSendersJob` after importing inbox messages of mailboxes with blocked
  senders; make sure a queue worker runs.

### Tags

- Run `php artisan migrate`: `mailbox_tags` and `mailbox_message_tag` are added.
- The plugin registers `MailboxTagResource`; `MailboxMessagePolicy` got the `tag` ability and
  `MailboxTagPolicy` is registered. Applications with their own message policy must add `tag`.
- `SyncService` got `TagService` as constructor dependency.
- Optionally schedule `mailbox:prune-tag-assignments`.

### Folder management

- Run `php artisan migrate`: `mailbox_folders.is_subscribed`, `message_count`, `unseen_count`, `size_bytes` and
  `statistics_updated_at` are added.
- New capabilities `FolderManagement` (IMAP, Graph) and `FolderSubscriptions` (IMAP); new contract
  `ManagesFolders`. `MailboxPolicy` got `manageFolders` — applications with their own mailbox policy must add it.
- `SyncService::syncFolders()` takes a cache lock and stores subscriptions; `syncFolder()` stores folder statistics.
  Use a cache store that supports locks (not `file` across servers).
- `ImapProvider` connects with `ImapClientConnection` (LSUB) and looks folders up by their raw path; folders with
  non-ASCII names were not found before.

### Monitoring

- Run `php artisan migrate`: `mailbox_sync_runs` and `mailbox_alerts` are added.
- Schedule `mailbox:monitor` (every five minutes) and `mailbox:prune-monitoring` (daily).
- `SyncMailboxJob` and `SyncMailboxFolderJob` got a `SyncTrigger` argument (defaults `schedule` / `webhook`);
  `SyncService::syncMailbox()` and `syncFolder()` accept an optional `SyncObserver`.
- `SyncResult` got `updated` and `deleted`, `SyncFailed::because()` an optional `cause`.
- Optional integrations: `laravel/pulse` (cards `filament-mailbox.pulse.syncs` / `.sync-failures`) and
  `open-telemetry/sdk` (`MAILBOX_OPENTELEMETRY=true`) are suggested, not required.
- Alert notifications need the `Notifiable` trait on the user model; for Filament database notifications enable
  `->databaseNotifications()` on the panel and create the `notifications` table.

### Rich text editor

- Run `php artisan migrate`: `mailboxes.compose_format` is added.
- New messages, replies and forwards default to HTML (`MAILBOX_COMPOSE_FORMAT=text` restores plain text).
- `ComposeMessageForm` got the fields `format`, `body_html`, `quoted` and `quoted_html`; the quote of replies and
  forwards is no longer part of `body`. `components()` got a `$quote` argument, the body of forwards is optional.
  `ReplyBuilder::quote()` no longer starts with blank lines.
- `ReplyBuilder::formData()` and `ForwardBuilder::formData()` accept a format and return the new fields.
- `OutgoingMessageData` got `bodyHtml`, `AttachmentData` got `inline`. `OutgoingMessage` renders HTML messages
  through `HtmlMailRenderer` and excludes inline parts from `attachments()`.
- Optionally schedule `mailbox:prune-compose-uploads`.

### Signatures

- Run `php artisan migrate`: `mailbox_signatures` is added.
- The plugin registers the `MySignatures` page with a user menu item and `SignaturesRelationManager` on the mailbox;
  `MailboxSignaturePolicy` is registered.
- `ComposeMessageForm::components()` got a `$mailbox` closure and `toData()`/`body()` a `$mailbox` argument — without
  it no signature is added. `ComposeMessageForm::defaults()` returns the format and default signature of a context.
- `HtmlBodySanitizer::outgoing()` got `$keepImageIds` for stored HTML.

### Templates

- Run `php artisan migrate`: `mailbox_templates` and `mailbox_template_attachments` are added.
- The plugin registers `MailboxTemplateResource`; `MailboxTemplatePolicy` is registered and the plugin got
  `canManageTemplates()` (`manage-mailbox-templates`, default: may manage mailboxes).
- `ComposeMessageForm::components()` got `$context` and `$original`, `toData()` got `$context`; custom actions call
  `ComposeMessageForm::afterSent()` after sending to count template usage.

### Drafts

- Run `php artisan migrate`: `mailbox_drafts` and `mailbox_draft_attachments` are added, `mailbox_messages` got
  `is_draft` and `draft_id`. Existing messages in drafts folders are marked as drafts when they are imported again.
- `MailboxResource` got the pages `drafts` (`ListDrafts`) and `draft` (`EditDraft`); `MailboxDraftPolicy` is registered.
- `ComposeMessageForm::components()` takes `$bodyRequired` as bool and got `$imageDirectories`; `toData()` got
  `$imageDirectories` and `$user`. Required fields are not enforced while saving a draft
  (`ComposeMessageForm::isSavingDraft()`).
- `ForwardAction::components()` returns the forward form.
- `OutgoingMessageData` got `messageId` and `headers`.
- `SyncService::importMessage()` sets `is_draft` and links draft versions.
- The draft server copy is written by `SaveDraftToServerJob` on the sync queue. Optionally schedule
  `mailbox:prune-drafts`.

### Health dashboard

- Run `php artisan migrate`: `mailboxes` got `health`, `consecutive_failures`, `last_success_at` (filled from
  `last_synced_at`), `last_run_status`, `last_run_duration_ms`, `last_error_type` and `failing_folders`. Existing
  mailboxes are rated by the next run or `mailbox:monitor`.
- The plugin registers the page `Filament\Pages\MailboxHealth`; `MailboxesTable` shows a health column to managers.
- `mailbox:monitor` also rates mailboxes, runs the system checks, writes the scheduler heartbeat and dispatches
  `MailboxHeartbeatJob` on the sync queue.
- `TestConnectionAction` works in table context (tests the stored record) as well as on the edit page.

### New mail notifications

- Run `php artisan migrate`: `mailbox_user` got `notify` and `notify_folder_ids`, `mailbox_push_subscriptions` is added.
- `MessagesImported` got `$messageIds` and `$isInitial` (optional constructor arguments).
- The queued listener `NotifyUsersAboutNewMessages` runs on the sync queue. Enable `->databaseNotifications()` on the
  panel; disable with `MAILBOX_NOTIFICATIONS=false`.
- The plugin registers the page `MailboxSettings` (user menu), authenticated routes for the latest notifications and push
  subscriptions, and a body render hook with the desktop notification script.

### Progressive Web App

- No migration. The plugin registers public panel routes (`filament-mailbox/manifest.webmanifest`,
  `filament-mailbox-sw.js`, `filament-mailbox/offline`, `filament-mailbox/pwa/{icon}.png`) that answer 404 unless the
  PWA is enabled, the authenticated route `filament-mailbox/launch/{target}` and render hooks (head, body end,
  before the user menu) that render nothing while it is disabled.

### Drag & drop

- No migration. Folder items of the mailbox navigation got `data-filament-mailbox-folder` attributes, message rows
  the classes `fi-mailbox-draggable fi-mailbox-message-<id>`; `BrowseMailbox::moveMessages()` handles drops.

### Split view

- Run `php artisan migrate`: `mailbox_user_preferences` is added.
- The actions of `ViewMessage` moved to `MessageViewActions::make($message, $removed, $markedUnread)`; the page no longer
  has `moveAndRedirect()`, and its `folderUrl()` takes a folder id.
- `BlockSenderAction::make()` got an optional `$movedToSpam` callback.
- Marking as read on open moved to `Services\MessageViewer`.
- `BrowseMailbox` got the `message` URL parameter; `MessagesTable` uses compact columns and row actions in the split view.

### Statistics

- Run `php artisan migrate`: `mailbox_stats_daily`, `mailbox_stats_domains_daily`, `mailbox_stats_dirty_days` and
  `mailbox_reply_links` are added, `mailboxes` got `business_hours` and `sla_minutes`, `mailbox_messages` got
  `is_auto_generated` and indexes on `(mailbox_id, message_id)` and `(mailbox_id, in_reply_to)`. On large message
  tables run the migration during a maintenance window.
- `MessageData` got `isAutoGenerated` (set by `MimeMessageMapper` from the headers). Messages imported before the
  update are not marked as automatic.
- The plugin registers `Filament\Pages\MailboxStatistics` (hidden while `statistics.enabled` is false).
- To use statistics, enable them, schedule `mailbox:stats-aggregate` hourly and run `mailbox:stats-backfill` once.

### Snooze

- Run `php artisan migrate`: `mailbox_messages` got `snoozed_until`, `snoozed_by`, `unsnoozed_at`, `sort_at` and
  `snoozed_from_folder_id`; `sort_at` is filled from `received_at` in id ranges. On large message tables run the
  migration during a maintenance window.
- Message lists are sorted by `sort_at` instead of `received_at`. New messages get `sort_at = received_at`.
- Schedule `mailbox:wake-snoozed` every minute.
- `FolderNavigation::unreadCount()` and `starredQuery()` leave snoozed messages out; the sort order of the
  navigation items of `BrowseMailbox` changed (steps of three).

### Outbox and scheduled send

- Run `php artisan migrate`: `mailbox_outgoing_messages` and `mailbox_outgoing_attachments` are added.
- Compose, reply, forward and the draft editor send through `OutboxService`. Messages without send time and undo
  window are still sent in the request by default; every sent message now gets a Message-ID generated by the app.
- `ComposeMessageAction::send()` got the signature `send(Mailbox $mailbox, OutgoingMessageData $data, Action $action,
  array $formData = [], array $context = [])` and returns the outbox message; the `MailSender` parameter is gone.
- `ForwardAction` no longer marks the original itself: the outbox does it when the forward is sent.
  `DraftService::sent()` got `$markForwarded` (default `true`).
- The compose form got the fields `send_at_preset` and `send_at`.
- Schedule `mailbox:send-due` every minute and `mailbox:prune-outbox` daily, and run a queue worker for scheduled
  messages.

### Read receipts

- Run `php artisan migrate`: `mailbox_messages` got `mdn_requested_to`, `mdn_status` and `is_receipt`, `mailboxes` got
  `request_read_receipts`; `mailbox_receipt_requests` and `mailbox_receipts` are added. Messages imported before
  the update have no request status.
- `MessageData` got `headers` and `reportParts`, `OutgoingMessageData` got `report`; custom providers that do not use
  `MimeMessageMapper` should fill `headers` to support requests.
- `MessageService::addKeywords()` is new. `MessagesTable::subjectIcon()` replaces the inline draft icon closure.
- The compose form got the field `request_read_receipt`.

### Delivery receipts

- Run `php artisan migrate`: `mailbox_receipts` got `status_code`, `source` and `reporting_mta`,
  `mailbox_outgoing_messages` got `dsn_notify` and `dsn_supported`.
- `SmtpMailboxTransport::transport()` creates a `DsnEsmtpTransport` (an `EsmtpTransport`). Transports may implement
  `Contracts\ReportsDsnSupport`; `MailSender::dsnSupported()` is new.
- `OutgoingMessageData` got `requestDeliveryReceipt` and `dsn`, the compose form the field `request_delivery_receipt`.
- To request DSNs with the application's mail configuration, use a mailer with `'transport' => 'mailbox-dsn'`.

### Keyboard shortcuts

- No migration; the preference `shortcuts_enabled` is stored in `mailbox_user_preferences`.
- Message rows got the classes `fi-mailbox-row` and `fi-mailbox-message-<id>` (also without drag & drop).
- `BrowseMailbox` got `shortcut()` and the header action `shortcutsHelp` (also on `ViewMessage`).
- The *Mailbox settings* page is available when notifications or shortcuts are enabled; its user menu icon changed.

### Offline copy

- Run `php artisan migrate`: `mailbox_offline_devices` is added.
- The plugin registers the page `OfflineAvailability` (user menu), the public route `filament-mailbox/offline/app`,
  authenticated offline endpoints and a body render hook with the sync script — all inactive while `offline.enabled`
  is false (default).
- The PWA logout handler also deletes the offline copy; the service worker caches the offline shell when enabled.

### Full-text search

- Run `php artisan migrate`: `mailbox_search_documents` is added (with a `FULLTEXT` index on MySQL/MariaDB, a generated
  `tsvector` column and GIN index on PostgreSQL, the FTS5 table `mailbox_search_fts` on SQLite), `mailbox_attachments`
  got `extracted_text`, `extracted_at` and `extraction_error`.
- The default engine stays `like`. To use the index set `MAILBOX_SEARCH_ENGINE=database` and run
  `php artisan mailbox:search-reindex`.
- `MessageSearch::apply()` got a `SearchScope` parameter and delegates to `Search\SearchManager`; without a scope it
  finds nothing. The search field now interprets operators (`from:`, `is:`, …) and quotes.
- `BrowseMailbox` got the URL parameter `all` (search all folders), `searchScope()` and `searchSnippet()`;
  `MessagesTable` got the header actions `searchAllFolders` and `searchHelp` and the column `search_snippet`.

### Meilisearch

- Run `php artisan migrate`: `mailbox_search_tasks` is added.
- `MAILBOX_SEARCH_ENGINE=meilisearch` with host and key switches to Meilisearch; run
  `php artisan mailbox:search-reindex --now --swap` and schedule `mailbox:search-tasks-check` every minute.
- The sandbox `docker-compose.yml` got the service `sandbox-meilisearch` (profile `search`).

### External search engines

- No migration. `SearchManager` now resolves engines through `Search\SearchEngineRegistry`
  (`FilamentMailboxPlugin::searchEngine()` or `search.engines`) and applies what an engine cannot do itself
  afterwards in the database (`search.candidate_limit`).
- `Search\Meilisearch\MeilisearchDocumentMapper` became `Search\SearchDocumentMapper`.
- The `scout` engine needs `laravel/scout`; cloud drivers additionally need
  `search.scout.external_processing_acknowledged`.
- Engines of other packages can use the shipped contract suite `Testing\MessageSearchEngineTests`.

### IMAP servers without MOVE/UIDPLUS

Moving falls back to COPY + delete. The new UID is unknown then, so the local message is soft-deleted and
restored in the target folder with the next synchronisation.
