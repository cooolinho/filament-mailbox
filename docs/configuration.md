# Configuration

Publish the file with `php artisan vendor:publish --tag=filament-mailbox-config`. Every option can also be
set through the environment variable shown.

Mailboxes themselves (host, port, credentials, …) are **not** configured through the environment — they are
created in the panel and stored in the `mailboxes` table with an encrypted password. OAuth app
registrations are managed in the panel as well, see [OAuth2](oauth.md).

| Key | Env | Default | Description |
|---|---|---|---|
| `user_model` | `MAILBOX_USER_MODEL` | `App\Models\User` | Model mailboxes are assigned to |
| `providers` | – | `imap` → `ImapProvider`, `graph` → `GraphProvider`, `gmail` → `GmailProvider` | Provider classes keyed by the mailbox `provider` value, see [Architecture](architecture.md#providers) |
| `imap.timeout` | `MAILBOX_IMAP_TIMEOUT` | `30` | Connection timeout in seconds |
| `gmail.*` | `MAILBOX_GMAIL_*` | see [Gmail API](gmail.md#configuration) | Initial sync days, hidden labels, batch size, rate limit, push |
| `graph.*` | `MAILBOX_GRAPH_*` | see [Microsoft Graph](graph.md#configuration) | Graph API base URL, retries, webhooks |
| `oauth.providers.microsoft.authority` | `MAILBOX_MS_AUTHORITY` | `https://login.microsoftonline.com` | Entra authority (national clouds) |
| `oauth.providers.{microsoft,google}.imap` / `.smtp` | – | Office 365 / Gmail servers | Server settings prefilled for OAuth mailboxes and SMTP defaults |
| `oauth.refresh_margin_seconds` | `MAILBOX_OAUTH_REFRESH_MARGIN` | `300` | Renew access tokens this long before they expire |
| `oauth.state_lifetime_seconds` | – | `600` | Lifetime of a started OAuth sign-in |
| `html.max_input_length` | `MAILBOX_HTML_MAX_INPUT_LENGTH` | `1048576` | HTML bodies above this size (bytes) are truncated before sanitising |
| `attachments.disk` | `MAILBOX_ATTACHMENTS_DISK` | `local` | Filesystem disk for imported attachments — use a private disk |
| `attachments.path` | `MAILBOX_ATTACHMENTS_PATH` | `mailbox` | Directory on that disk |
| `mail.mailer` | `MAILBOX_MAILER` | `null` | Mailer from `config/mail.php` used for mailboxes without own SMTP settings (`null` = default mailer) |
| `mail.max_attachment_size` | `MAILBOX_MAX_ATTACHMENT_SIZE` | `10240` | Upload limit per attachment in KB |
| `compose.default_format` | `MAILBOX_COMPOSE_FORMAT` | `html` | Preselected compose format (`html` or `text`), per mailbox overridable, see [Composing](composing.md) |
| `compose.inline_images` | `MAILBOX_COMPOSE_INLINE_IMAGES` | `true` | Image uploads in the rich editor, sent as inline parts |
| `compose.max_inline_image_size` | `MAILBOX_COMPOSE_MAX_INLINE_IMAGE_SIZE` | `2048` | Size limit per inline image in KB |
| `compose.prune_uploads_after_hours` | – | `24` | Age of unsent uploads removed by `mailbox:prune-compose-uploads` |
| `signatures.enabled` | `MAILBOX_SIGNATURES` | `true` | Signatures in the compose form, see [Signatures](signatures.md) |
| `signatures.personal` | `MAILBOX_PERSONAL_SIGNATURES` | `true` | Personal signatures and the *My signatures* page |
| `signatures.separator` | – | `"-- \n"` | Separator before plain text signatures |
| `templates.enabled` | `MAILBOX_TEMPLATES` | `true` | Template catalogue and *Insert template*, see [Templates](templates.md) |
| `templates.max_attachment_size` | `MAILBOX_TEMPLATE_MAX_ATTACHMENT_SIZE` | `10240` | Size limit per template attachment in KB |
| `drafts.enabled` | `MAILBOX_DRAFTS` | `true` | *Save as draft*, draft editor and list, see [Drafts](drafts.md) |
| `drafts.autosave_seconds` | `MAILBOX_DRAFT_AUTOSAVE` | `30` | Autosave interval of the draft editor (`0` = off) |
| `drafts.sync_to_server` | `MAILBOX_DRAFT_SYNC_TO_SERVER` | `true` | Store drafts in the drafts folder (IMAP) |
| `drafts.server_sync_delay_seconds` | `MAILBOX_DRAFT_SERVER_SYNC_DELAY` | `10` | Debounce of the server copy |
| `drafts.prune_after_days` | `MAILBOX_DRAFT_PRUNE_AFTER_DAYS` | `null` | Drafts discarded by `mailbox:prune-drafts` (`null` = never) |
| `read_receipts.enabled` | `MAILBOX_READ_RECEIPTS` | `true` | Request, answer and assign read receipts, see [Read receipts](read-receipts.md) |
| `read_receipts.request_by_default` | `MAILBOX_READ_RECEIPTS_BY_DEFAULT` | `false` | Preselect *Request read receipt* (per mailbox overridable) |
| `read_receipts.allow_sending` | `MAILBOX_READ_RECEIPTS_ALLOW_SENDING` | `true` | Offer *Send receipt* for requests (otherwise only *Ignore*) |
| `read_receipts.notify_sender` | – | `true` | Database notification when a receipt arrives |
| `read_receipts.hide_receipt_messages` | `MAILBOX_HIDE_RECEIPT_MESSAGES` | `false` | Hide read receipts and delivery reports from folder lists |
| `delivery_receipts.enabled` | `MAILBOX_DELIVERY_RECEIPTS` | `true` | Request DSNs and assign reports and bounces, see [Delivery receipts](delivery-receipts.md) |
| `delivery_receipts.request_success_by_default` | `MAILBOX_DELIVERY_RECEIPTS_BY_DEFAULT` | `false` | Preselect *Request delivery receipt* (success reports) |
| `delivery_receipts.bounce_heuristics` | `MAILBOX_BOUNCE_HEURISTICS` | `true` | Recognise bounces without a delivery status report |
| `outbox.scheduling` | `MAILBOX_SCHEDULED_SEND` | `true` | *Send* field (now or later) in the compose form, see [Outbox](outbox.md) |
| `outbox.schedule_presets` | – | in one hour, tomorrow morning, Monday morning | Relative date expressions keyed by preset, calculated in the panel time zone |
| `outbox.undo_seconds` | `MAILBOX_UNDO_SEND_SECONDS` | `0` | Undo window for messages sent now |
| `outbox.send_immediately_inline` | `MAILBOX_SEND_IMMEDIATELY_INLINE` | `true` | Send messages without undo window and send time in the request instead of the queue |
| `outbox.max_attempts` | – | `3` | Attempts before a message fails |
| `outbox.retry_backoff_minutes` | – | `[1, 5, 15]` | Wait before the next attempt |
| `outbox.stuck_after_minutes` | – | `15` | Messages hanging in *sending* are failed after this time |
| `outbox.keep_days` | `MAILBOX_OUTBOX_KEEP_DAYS` | `30` | Sent, failed and cancelled messages removed by `mailbox:prune-outbox` |
| `outbox.queue_connection` / `outbox.queue` | `MAILBOX_OUTBOX_QUEUE_CONNECTION` / `MAILBOX_OUTBOX_QUEUE` | `null` | Queue of `SendOutgoingMessageJob` (`null` = the sync queue) |
| `offline.enabled` | `MAILBOX_OFFLINE` | `false` | Encrypted read-only offline copy per device (requires the PWA), see [Offline copy](offline.md) |
| `offline.max_messages` | `MAILBOX_OFFLINE_MAX_MESSAGES` | `500` | Upper limit of messages per folder |
| `offline.max_days` | `MAILBOX_OFFLINE_MAX_DAYS` | `30` | Upper limit of the period in days |
| `offline.max_attachment_size` | `MAILBOX_OFFLINE_MAX_ATTACHMENT_SIZE` | `2048` | Largest attachment stored offline in KB (`0` = none) |
| `offline.sync_minutes` | – | `5` | Update interval while the panel is open |
| `shortcuts.enabled` | `MAILBOX_SHORTCUTS` | `true` | Keyboard shortcuts on mailbox and message pages (users can switch them off), see [Keyboard shortcuts](shortcuts.md) |
| `shortcuts.bindings` | – | Gmail-like, see [Keyboard shortcuts](shortcuts.md) | Bindings keyed by action |
| `folders.management` | `MAILBOX_FOLDER_MANAGEMENT` | `true` | Folder management page and operations |
| `folders.protect_special_use` | – | `true` | Lock system folders (INBOX is always locked) |
| `folders.allow_permanent_delete` | `MAILBOX_FOLDER_ALLOW_PERMANENT_DELETE` | `false` | Offer deleting folder contents permanently instead of moving them to the trash |
| `folders.lock_seconds` | – | `120` | Lock shared by folder operations and the folder sync |
| `forward.subject_prefix` | `MAILBOX_FORWARD_SUBJECT_PREFIX` | `Fwd: ` | Subject prefix of forwarded messages |
| `forward.default_mode` | `MAILBOX_FORWARD_DEFAULT_MODE` | `inline` | Preselected forward mode: `inline` or `attachment` |
| `starred.navigation` | `MAILBOX_STARRED_NAVIGATION` | `true` | Show the *Starred* navigation entry |
| `starred.exclude_special_use` | – | `['trash', 'junk']` | Special-use folders left out of *Starred* |
| `snooze.enabled` | `MAILBOX_SNOOZE` | `true` | *Snooze* actions and the *Snoozed* view, see [Message actions](message-actions.md#snooze) |
| `snooze.presets` | – | later today, tomorrow, next week | Relative date expressions keyed by preset, calculated in the panel time zone |
| `snooze.server_folder` | `MAILBOX_SNOOZE_SERVER_FOLDER` | `null` | Also move snoozed messages to this server folder and back |
| `snooze.mark_unread_on_wake` | – | `true` | Mark returning messages as unread on the server |
| `archive.create_folder_if_missing` | `MAILBOX_ARCHIVE_CREATE_FOLDER` | `false` | Create an archive folder on the first archiving when the mailbox has none |
| `archive.folder_name` | `MAILBOX_ARCHIVE_FOLDER_NAME` | `Archive` | Name of that folder (an existing top-level folder of that name is used) |
| `spam.use_keywords` | `MAILBOX_SPAM_USE_KEYWORDS` | `true` | Set `$Junk`/`$NotJunk` before moving (keyword providers) |
| `spam.block_senders` | `MAILBOX_SPAM_BLOCK_SENDERS` | `true` | Blocked senders list and `ApplyBlockedSendersJob` |
| `spam.strict_rendering` | `MAILBOX_SPAM_STRICT_RENDERING` | `true` | Render spam without links, confirm attachment downloads |
| `labels.hidden_keywords` | – | `$Forwarded`, `$MDNSent`, `$Junk`, … | IMAP keywords never shown as labels |
| `labels.thunderbird_defaults` | – | `$label1` → Important, … | Display names of Thunderbird keywords |
| `search.engine` | `MAILBOX_SEARCH_ENGINE` | `like` | `like`, `database`, `meilisearch`, `scout` or a registered engine, see [Search](search.md) |
| `search.meilisearch.host` / `.key` / `.index` | `MAILBOX_MEILISEARCH_HOST` / `_KEY` / `_INDEX` | `http://localhost:7700`, –, `filament_mailbox_messages` | Meilisearch instance, API key (not the master key) and index |
| `search.meilisearch.fallback_engine` | `MAILBOX_MEILISEARCH_FALLBACK` | `database` | Engine used while Meilisearch is unreachable (`""` = fail) |
| `search.meilisearch.timeout` / `.batch_size` | – | `10` s / `500` | Request timeout and documents per batch |
| `search.engines` | – | `[]` | Additional engine names mapped to classes |
| `search.candidate_limit` | – | `2000` | Hits of an engine that the database post-filter works on |
| `search.scout.index` / `.adapter` / `.fields` | `MAILBOX_SCOUT_INDEX` / `MAILBOX_SCOUT_ADAPTER` | `filament_mailbox_messages`, –, `[]` | Scout index, adapter (`typesense` or a class) and indexed fields |
| `search.scout.external_processing_acknowledged` | `MAILBOX_SEARCH_EXTERNAL_ACK` | `false` | Required for Scout cloud engines (contents leave the application) |
| `search.include_body` | `MAILBOX_SEARCH_INCLUDE_BODY` | `false` | `like` engine: also search the plain-text body |
| `search.max_body_length` | – | `100000` | Body characters in the search index |
| `search.max_results` | – | `1000` | Hits of engines outside the database |
| `search.snippets` | – | `true` | *Match* column with highlighted excerpt while searching |
| `search.mysql_min_token_size` | – | `3` | `innodb_ft_min_token_size`; shorter terms use LIKE |
| `search.postgres_language` | `MAILBOX_SEARCH_POSTGRES_LANGUAGE` | `german` | Text search configuration of the `tsvector` |
| `search.queue_connection` / `search.queue` | `MAILBOX_SEARCH_QUEUE_CONNECTION` / `MAILBOX_SEARCH_QUEUE` | `null` | Queue of index and extraction jobs (`null` = the sync queue) |
| `search.attachments.extract_text` | `MAILBOX_SEARCH_EXTRACT_ATTACHMENTS` | `false` | Extract attachment texts (PDF, DOCX, ODT, text) |
| `search.attachments.max_size` / `max_text_length` / `timeout` | – | `20480` KB / `200000` / `30` s | Extraction limits |
| `search.attachments.pdftotext_binary` | `MAILBOX_PDFTOTEXT` | `pdftotext` | Path of pdftotext (poppler-utils) |
| `monitoring.enabled` | `MAILBOX_MONITORING` | `true` | Record sync runs and evaluate alerts, see [Monitoring](monitoring.md) |
| `monitoring.expected_sync_interval_minutes` | `MAILBOX_EXPECTED_SYNC_INTERVAL` | `5` | Interval of `mailbox:sync`, basis of the stale rule |
| `monitoring.run_timeout_minutes` | – | `60` | Runs still running after this are closed as failed |
| `monitoring.retention_days` | `MAILBOX_MONITORING_RETENTION_DAYS` | `30` | Kept by `mailbox:prune-monitoring` |
| `monitoring.alerts.*` | `MAILBOX_ALERT_MAIL`, `MAILBOX_ALERT_SLACK_WEBHOOK` | 3 failures, factor 3, `['database']` | Alert thresholds and channels (`database`, `mail`, `slack`) |
| `monitoring.pulse` | `MAILBOX_PULSE` | `true` | Record runs in Laravel Pulse and register the cards (when installed) |
| `monitoring.opentelemetry` | `MAILBOX_OPENTELEMETRY` | `false` | OpenTelemetry spans for sync runs (needs `open-telemetry/sdk`) |
| `monitoring.prometheus.*` | `MAILBOX_METRICS`, `MAILBOX_METRICS_TOKEN` | disabled | Metrics endpoint, window, per-mailbox series |
| `notifications.enabled` | `MAILBOX_NOTIFICATIONS` | `true` | New mail notifications and *Mailbox settings*, see [Notifications](notifications.md) |
| `notifications.default_folders` | – | `['inbox']` | Folders notified without a user choice |
| `notifications.throttle_seconds` | `MAILBOX_NOTIFICATIONS_THROTTLE` | `120` | At most one notification per user and mailbox in this time |
| `notifications.show_content_by_default` | – | `true` | Sender and subject in notifications |
| `notifications.panel` | `MAILBOX_NOTIFICATIONS_PANEL` | `null` | Panel for notification links |
| `notifications.broadcast` | `MAILBOX_NOTIFICATIONS_BROADCAST` | `false` | Also broadcast the Filament notification |
| `notifications.browser` / `browser_poll_seconds` | `MAILBOX_BROWSER_NOTIFICATIONS` | `true` / `30` | Desktop notifications while a tab is open |
| `notifications.web_push.*` | `MAILBOX_WEB_PUSH`, `MAILBOX_VAPID_*` | disabled | Payload-less web push (needs the PWA) |
| `pwa.enabled` | `MAILBOX_PWA` | `false` | Installable app (or `->pwa()`), see [PWA](pwa.md) |
| `pwa.name` / `pwa.short_name` | `MAILBOX_PWA_NAME`, `MAILBOX_PWA_SHORT_NAME` | `app.name` / `Mail` | App names |
| `pwa.background_color` | – | `#ffffff` | Splash background |
| `pwa.cache_version` | `MAILBOX_PWA_CACHE_VERSION` | `null` | Change to drop cached static assets |
| `ui.drag_and_drop` | `MAILBOX_DRAG_AND_DROP` | `true` | Drag messages onto folders, see [Message actions](message-actions.md#drag--drop) |
| `ui.max_move_batch` | – | `500` | Messages moved by one drop at most |
| `ui.split_pane.enabled` | `MAILBOX_SPLIT_PANE` | `true` | List/split view switch in the mailbox, see [Split view](split-view.md) |
| `ui.split_pane.default` | `MAILBOX_SPLIT_PANE_DEFAULT` | `list` | Layout for users without a stored choice (`list` or `split`) |
| `statistics.enabled` | `MAILBOX_STATISTICS` | `false` | Page *Statistics*, reply links and daily aggregates, see [Statistics](statistics.md) |
| `statistics.visible_to` | `MAILBOX_STATISTICS_VISIBLE_TO` | `managers` | `managers` or `assigned_users` (their assigned mailboxes) |
| `statistics.timezone` | `MAILBOX_STATISTICS_TIMEZONE` | `null` | Time zone of mailboxes without business hours time zone (`null` = `app.timezone`) |
| `statistics.top_domains` | – | `10` | Sender domains kept per day |
| `statistics.sla_default_minutes` | `MAILBOX_STATISTICS_SLA_MINUTES` | `240` | SLA of mailboxes without own value |
| `statistics.live_cache_seconds` | – | `300` | Cache of unanswered, unread and storage figures |
| `statistics.retention_days` | `MAILBOX_STATISTICS_RETENTION_DAYS` | `730` | Aggregates kept by `mailbox:stats-aggregate` |
| `health.enabled` | `MAILBOX_HEALTH` | `true` | Page *Mailbox health* and health rating (needs monitoring), see [Health dashboard](health.md) |
| `health.polling` | `MAILBOX_HEALTH_POLLING` | `30s` | Refresh interval of the page and its widgets |
| `health.statistics_cache_seconds` | – | `30` | Cache lifetime of the key figures |
| `health.queue_heartbeat` / `health.scheduler_heartbeat` | – | warning after 10, failed after 20 minutes | Heartbeat thresholds |
| `health.search_warning_ms` | – | `2000` | Search check warns above this latency |
| `health.checks` | – | queue, scheduler, attachment disk, search, OAuth, failed jobs | System checks (`Health\HealthCheck`) |
| `sync.queue_connection` | `MAILBOX_SYNC_QUEUE_CONNECTION` | `null` | Queue connection for `SyncMailboxJob` |
| `sync.queue` | `MAILBOX_SYNC_QUEUE` | `default` | Queue name for `SyncMailboxJob` |
| `sync.chunk_size` | `MAILBOX_SYNC_CHUNK_SIZE` | `50` | Messages fetched per provider request (`changes()` limit) |
| `sync.log_channel` | `MAILBOX_SYNC_LOG_CHANNEL` | `null` | Log channel for sync warnings (`null` = default) |

## Plugin options

```php
FilamentMailboxPlugin::make()
    ->navigationGroup('Mail')          // string, UnitEnum or Closure
    ->navigationSort(10)
    ->canManageMailboxes(fn ($user) => ...)
    ->canSendMessages(fn ($user) => ...)
    ->canDeleteMessages(fn ($user) => ...)
    ->canManageFolders(fn ($user) => ...)  // default: canManageMailboxes
    ->canManageTags(fn ($user) => ...)     // default: canManageMailboxes
    ->canManageTemplates(fn ($user) => ...) // default: canManageMailboxes
    ->canTagMessages(fn ($user) => ...)
    ->healthWidgetsOnDashboard()           // mailbox health key figures on the panel dashboard
    ->pwa()                                // installable app, see pwa.md
    ->pwaName('Mail')
    ->pwaIcons(['icon-192' => '...', 'icon-512' => '...', 'maskable-512' => '...', 'apple-touch-icon' => '...'])
    ->pwaStartUrl('...');
```

## Queue

Synchronisation always runs in `SyncMailboxJob` (never while rendering a page). The job is unique per
mailbox, retried three times with a 60 second backoff, and skips inactive mailboxes. Make sure a worker
is running, e.g. `php artisan queue:work`.

## Encryption values

| Value | Transport | Default port |
|---|---|---|
| `ssl` | Implicit TLS | 993 |
| `tls` | Implicit TLS | 993 |
| `starttls` | Plain connection upgraded with STARTTLS | 143 |
| `none` | Unencrypted — development only | 143 |
