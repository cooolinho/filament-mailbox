<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The model mailboxes are assigned to. Only assigned users can access a
    | mailbox and its messages.
    |
    */

    'user_model' => env('MAILBOX_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Provider classes keyed by the mailbox "provider" value. Classes are
    | resolved from the container with the mailbox as "mailbox" parameter.
    |
    */

    'providers' => [
        'imap' => \Cooolinho\FilamentMailbox\Providers\Imap\ImapProvider::class,
        'graph' => \Cooolinho\FilamentMailbox\Providers\Graph\GraphProvider::class,
        'gmail' => \Cooolinho\FilamentMailbox\Providers\Gmail\GmailProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | IMAP
    |--------------------------------------------------------------------------
    */

    'imap' => [
        'timeout' => (int) env('MAILBOX_IMAP_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Microsoft Graph
    |--------------------------------------------------------------------------
    |
    | Mailboxes with the "graph" provider use the Graph Mail API with an OAuth
    | connection. Webhooks (change notifications) need a public HTTPS URL
    | and "php artisan mailbox:graph-subscriptions" in the scheduler.
    |
    */

    'graph' => [
        'base_url' => env('MAILBOX_GRAPH_BASE_URL', 'https://graph.microsoft.com/v1.0'),
        'page_size' => (int) env('MAILBOX_GRAPH_PAGE_SIZE', 50),
        'max_retries' => (int) env('MAILBOX_GRAPH_MAX_RETRIES', 5),
        'timeout' => (int) env('MAILBOX_GRAPH_TIMEOUT', 60),
        'webhooks' => [
            'enabled' => (bool) env('MAILBOX_GRAPH_WEBHOOKS', false),
            'notification_url' => env('MAILBOX_GRAPH_NOTIFICATION_URL'),
            'lifetime_minutes' => (int) env('MAILBOX_GRAPH_SUBSCRIPTION_MINUTES', 4200),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Gmail API
    |--------------------------------------------------------------------------
    |
    | Mailboxes with the "gmail" provider. The initial synchronisation imports
    | the messages of the last "initial_sync_days" (per mailbox overridable).
    | Push notifications use Google Cloud Pub/Sub and need
    | "php artisan mailbox:gmail-watch" in the scheduler.
    |
    */

    'gmail' => [
        'initial_sync_days' => (int) env('MAILBOX_GMAIL_INITIAL_SYNC_DAYS', 90),
        'hidden_labels' => ['IMPORTANT', 'CHAT', 'CATEGORY_*'],
        'batch_size' => (int) env('MAILBOX_GMAIL_BATCH_SIZE', 50),
        'requests_per_second' => (int) env('MAILBOX_GMAIL_REQUESTS_PER_SECOND', 40),
        'max_retries' => (int) env('MAILBOX_GMAIL_MAX_RETRIES', 5),
        'timeout' => (int) env('MAILBOX_GMAIL_TIMEOUT', 60),
        'push' => [
            'enabled' => (bool) env('MAILBOX_GMAIL_PUSH', false),
            'topic' => env('MAILBOX_GMAIL_PUBSUB_TOPIC'),
            'audience' => env('MAILBOX_GMAIL_PUSH_AUDIENCE'),
            'service_account' => env('MAILBOX_GMAIL_PUSH_SERVICE_ACCOUNT'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth2
    |--------------------------------------------------------------------------
    |
    | IMAP and SMTP login with XOAUTH2. App registrations (client ID and
    | secret) are managed in the panel. The server settings below prefill the
    | mailbox form. Access tokens are renewed "refresh_margin_seconds" before
    | they expire.
    |
    */

    'oauth' => [
        'providers' => [
            'microsoft' => [
                'authority' => env('MAILBOX_MS_AUTHORITY', 'https://login.microsoftonline.com'),
                'imap' => ['host' => 'outlook.office365.com', 'port' => 993, 'encryption' => 'ssl'],
                'smtp' => ['host' => 'smtp.office365.com', 'port' => 587],
            ],
            'google' => [
                'imap' => ['host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl'],
                'smtp' => ['host' => 'smtp.gmail.com', 'port' => 587],
            ],
        ],
        'refresh_margin_seconds' => (int) env('MAILBOX_OAUTH_REFRESH_MARGIN', 300),
        'state_lifetime_seconds' => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTML bodies
    |--------------------------------------------------------------------------
    |
    | HTML mail is sanitised before rendering. Larger bodies are truncated.
    |
    */

    'html' => [
        'max_input_length' => (int) env('MAILBOX_HTML_MAX_INPUT_LENGTH', 1_048_576),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Imported attachments are stored on this filesystem disk. Use a private
    | disk - downloads are served through authorized Filament actions only.
    |
    */

    'attachments' => [
        'disk' => env('MAILBOX_ATTACHMENTS_DISK', 'local'),
        'path' => env('MAILBOX_ATTACHMENTS_PATH', 'mailbox'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outgoing mail
    |--------------------------------------------------------------------------
    |
    | Mail is sent through Laravel's mail configuration. "mailer" selects a
    | mailer from config/mail.php (null uses the default mailer). The
    | attachment size limit is given in kilobytes.
    |
    */

    'mail' => [
        'mailer' => env('MAILBOX_MAILER'),
        'max_attachment_size' => (int) env('MAILBOX_MAX_ATTACHMENT_SIZE', 10240),
    ],

    /*
    |--------------------------------------------------------------------------
    | Composing
    |--------------------------------------------------------------------------
    |
    | New messages, replies and forwards are written as HTML with the rich
    | editor or as plain text ("default_format", per mailbox overridable).
    | HTML is sanitised and sent with a generated text alternative. Pasted or
    | uploaded images are sent as inline parts (cid:) - never as remote URLs.
    | "mailbox:prune-compose-uploads" removes unsent uploads.
    |
    */

    'compose' => [
        'default_format' => env('MAILBOX_COMPOSE_FORMAT', 'html'),
        'inline_images' => (bool) env('MAILBOX_COMPOSE_INLINE_IMAGES', true),
        'max_inline_image_size' => (int) env('MAILBOX_COMPOSE_MAX_INLINE_IMAGE_SIZE', 2048), // KB
        'prune_uploads_after_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbox and scheduled send
    |--------------------------------------------------------------------------
    |
    | Every message sent from the app is recorded in the outbox of its
    | mailbox. Messages are sent right away in the request
    | ("send_immediately_inline"), after "undo_seconds" or at a chosen time
    | ("Send" in the compose form) by SendOutgoingMessageJob - schedule
    | "mailbox:send-due" every minute and run a queue worker. Failed attempts
    | are retried after "retry_backoff_minutes" up to "max_attempts" times.
    | Attachments of queued messages are stored on the attachments disk until
    | they are sent. "mailbox:prune-outbox" removes sent, failed and cancelled
    | messages after "keep_days".
    |
    */

    'outbox' => [
        'scheduling' => (bool) env('MAILBOX_SCHEDULED_SEND', true),
        'schedule_presets' => [
            'in_one_hour' => '+1 hour',
            'tomorrow_morning' => 'tomorrow 08:00',
            'monday_morning' => 'next monday 08:00',
        ],
        'undo_seconds' => (int) env('MAILBOX_UNDO_SEND_SECONDS', 0),
        // Without undo window and schedule: send in the request instead of the queue.
        'send_immediately_inline' => (bool) env('MAILBOX_SEND_IMMEDIATELY_INLINE', true),
        'max_attempts' => 3,
        'retry_backoff_minutes' => [1, 5, 15],
        // Messages hanging in "sending" this long are failed (never sent again automatically).
        'stuck_after_minutes' => 15,
        'keep_days' => (int) env('MAILBOX_OUTBOX_KEEP_DAYS', 30),
        'queue_connection' => env('MAILBOX_OUTBOX_QUEUE_CONNECTION'),   // null = sync.queue_connection
        'queue' => env('MAILBOX_OUTBOX_QUEUE'),                         // null = sync.queue
    ],

    /*
    |--------------------------------------------------------------------------
    | Read receipts
    |--------------------------------------------------------------------------
    |
    | Message Disposition Notifications (RFC 8098). "Request read receipt" in
    | the compose form adds a Disposition-Notification-To header (default per
    | mailbox, otherwise "request_by_default"). Incoming requests are never
    | answered automatically: the message view asks the user
    | ("allow_sending" = false only offers to ignore). Receipts for messages
    | sent from the app are assigned to them and shown in the message view
    | and the outbox; "notify_sender" sends a database notification.
    |
    */

    'read_receipts' => [
        'enabled' => (bool) env('MAILBOX_READ_RECEIPTS', true),
        'request_by_default' => (bool) env('MAILBOX_READ_RECEIPTS_BY_DEFAULT', false),
        'allow_sending' => (bool) env('MAILBOX_READ_RECEIPTS_ALLOW_SENDING', true),
        'notify_sender' => true,
        'hide_receipt_messages' => (bool) env('MAILBOX_HIDE_RECEIPT_MESSAGES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery receipts
    |--------------------------------------------------------------------------
    |
    | Delivery status notifications (RFC 3461/3464): SMTP transports ask the
    | server for failure and delay reports of every message sent through the
    | outbox ("Request delivery receipt" adds success reports). Needs a
    | server with the DSN extension - the mailbox SMTP transport or a Laravel
    | mailer with 'transport' => 'mailbox-dsn'; Microsoft Graph and Gmail API
    | cannot request them. Incoming reports and - with "bounce_heuristics" -
    | classic bounces are assigned per recipient and shown in the outbox.
    |
    */

    'delivery_receipts' => [
        'enabled' => (bool) env('MAILBOX_DELIVERY_RECEIPTS', true),
        'request_success_by_default' => (bool) env('MAILBOX_DELIVERY_RECEIPTS_BY_DEFAULT', false),
        'bounce_heuristics' => (bool) env('MAILBOX_BOUNCE_HEURISTICS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signatures
    |--------------------------------------------------------------------------
    |
    | Signatures of a mailbox (managed with the mailbox) and personal
    | signatures of users ("personal", page "My signatures" in the user
    | menu). The default signature of the compose context (new, reply,
    | forward) is inserted above the quote; plain text signatures follow the
    | "separator" (RFC 3676: dash, dash, space).
    |
    */

    'signatures' => [
        'enabled' => (bool) env('MAILBOX_SIGNATURES', true),
        'personal' => (bool) env('MAILBOX_PERSONAL_SIGNATURES', true),
        'separator' => "-- \n",
    ],

    /*
    |--------------------------------------------------------------------------
    | Templates
    |--------------------------------------------------------------------------
    |
    | Reusable texts with subject, placeholders and attachments, global or per
    | mailbox, inserted with "Insert template" in the compose form. Managed by
    | users who may manage templates (default: may manage mailboxes).
    | Attachment sizes are given in kilobytes.
    |
    */

    'templates' => [
        'enabled' => (bool) env('MAILBOX_TEMPLATES', true),
        'max_attachment_size' => (int) env('MAILBOX_TEMPLATE_MAX_ATTACHMENT_SIZE', 10240),
    ],

    /*
    |--------------------------------------------------------------------------
    | Drafts
    |--------------------------------------------------------------------------
    |
    | Drafts are saved in the app ("Save as draft", autosave in the editor)
    | and - for providers that can store messages (IMAP) - appended to the
    | drafts folder, so other clients show them. Server copies are written
    | "server_sync_delay_seconds" after a change (debounced). Drafts written
    | elsewhere can be opened in the editor. "mailbox:prune-drafts" discards
    | drafts older than "prune_after_days" (null = never).
    |
    */

    'drafts' => [
        'enabled' => (bool) env('MAILBOX_DRAFTS', true),
        'autosave_seconds' => (int) env('MAILBOX_DRAFT_AUTOSAVE', 30),
        'sync_to_server' => (bool) env('MAILBOX_DRAFT_SYNC_TO_SERVER', true),
        'server_sync_delay_seconds' => (int) env('MAILBOX_DRAFT_SERVER_SYNC_DELAY', 10),
        'prune_after_days' => env('MAILBOX_DRAFT_PRUNE_AFTER_DAYS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | New mail notifications
    |--------------------------------------------------------------------------
    |
    | Assigned users get a Filament database notification for new unread
    | messages (panel needs ->databaseNotifications()), bundled per sync batch
    | and throttled per user and mailbox. Never for the first synchronisation
    | of a folder. Users choose mailboxes, folders and whether sender/subject
    | are shown on the "Mailbox settings" page (user menu). "browser" shows
    | operating system notifications while a panel tab is open; "web_push"
    | also when it is closed (needs the PWA and VAPID keys from
    | "php artisan mailbox:vapid-keys"). Pushes carry no content.
    |
    */

    'notifications' => [
        'enabled' => (bool) env('MAILBOX_NOTIFICATIONS', true),
        'default_folders' => ['inbox'],
        'throttle_seconds' => (int) env('MAILBOX_NOTIFICATIONS_THROTTLE', 120),
        'show_content_by_default' => true,
        // Panel for notification links (null = the first panel with the plugin).
        'panel' => env('MAILBOX_NOTIFICATIONS_PANEL'),
        // Also broadcast the Filament notification (Laravel Echo, ->broadcastNotifications()).
        'broadcast' => (bool) env('MAILBOX_NOTIFICATIONS_BROADCAST', false),
        'browser' => (bool) env('MAILBOX_BROWSER_NOTIFICATIONS', true),
        'browser_poll_seconds' => 30,
        'web_push' => [
            'enabled' => (bool) env('MAILBOX_WEB_PUSH', false),
            'public_key' => env('MAILBOX_VAPID_PUBLIC_KEY'),
            'private_key' => env('MAILBOX_VAPID_PRIVATE_KEY'),
            'subject' => env('MAILBOX_VAPID_SUBJECT'),   // mailto: or https: contact
            'ttl' => 3600,
            // The server only sends requests to these push services.
            'allowed_hosts' => [
                'fcm.googleapis.com',
                'updates.push.services.mozilla.com',
                '*.push.services.mozilla.com',
                '*.notify.windows.com',
                'web.push.apple.com',
                '*.push.apple.com',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Progressive Web App
    |--------------------------------------------------------------------------
    |
    | Makes the panel installable (manifest, service worker, offline page, app
    | shortcuts). Enable here or with ->pwa() on the plugin. The service worker
    | caches static assets only (css, js, fonts, build, icons) - never pages,
    | Livewire requests or attachments. Requires HTTPS (except localhost).
    | Change "cache_version" to drop cached assets on all devices.
    |
    */

    'pwa' => [
        'enabled' => (bool) env('MAILBOX_PWA', false),
        'name' => env('MAILBOX_PWA_NAME'),          // default: app.name
        'short_name' => env('MAILBOX_PWA_SHORT_NAME', 'Mail'),
        'background_color' => '#ffffff',
        'cache_version' => env('MAILBOX_PWA_CACHE_VERSION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Offline copy
    |--------------------------------------------------------------------------
    |
    | Read recently synchronised messages without a connection (stage 1,
    | read-only; requires the PWA). Users opt in per device on the page
    | "Offline availability" (user menu) and choose folders and limits; the
    | copy is stored encrypted (AES-GCM, non-extractable key per device) in
    | IndexedDB, updated every "sync_minutes" while the panel is open and
    | deleted on logout, revocation or lost access. Message contents leave
    | the server permanently - off by default; consider a data protection
    | impact assessment. Attachment sizes are given in kilobytes.
    |
    */

    'offline' => [
        'enabled' => (bool) env('MAILBOX_OFFLINE', false),
        'max_messages' => (int) env('MAILBOX_OFFLINE_MAX_MESSAGES', 500),
        'max_days' => (int) env('MAILBOX_OFFLINE_MAX_DAYS', 30),
        'max_attachment_size' => (int) env('MAILBOX_OFFLINE_MAX_ATTACHMENT_SIZE', 2048),
        'sync_minutes' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail client layout
    |--------------------------------------------------------------------------
    |
    | "split_pane": list and reading pane side by side (from the xl breakpoint,
    | smaller screens open the message page). Users switch between list and
    | split view in the mailbox header; the choice is stored per user.
    | "default" applies to users without a stored choice.
    |
    */

    'ui' => [
        // Drag messages onto folders of the navigation (move); "max_move_batch" limits one drop.
        'drag_and_drop' => (bool) env('MAILBOX_DRAG_AND_DROP', true),
        'max_move_batch' => 500,
        'split_pane' => [
            'enabled' => (bool) env('MAILBOX_SPLIT_PANE', true),
            'default' => env('MAILBOX_SPLIT_PANE_DEFAULT', 'list'), // list | split
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Keyboard shortcuts
    |--------------------------------------------------------------------------
    |
    | Shortcuts on the mailbox and message pages, never in form fields or
    | while a modal is open. Users switch them off on the "Mailbox settings"
    | page; "?" lists them. Bindings: single keys, "shift+<letter>", "enter",
    | "del" and sequences like "g i".
    |
    */

    'shortcuts' => [
        'enabled' => (bool) env('MAILBOX_SHORTCUTS', true),
        'bindings' => [
            'compose' => ['c'],
            'search' => ['/'],
            'next' => ['j'],
            'previous' => ['k'],
            'open' => ['enter', 'o'],
            'back' => ['u'],
            'reply' => ['r'],
            'reply_all' => ['a'],
            'forward' => ['f'],
            'delete' => ['#', 'del'],
            'archive' => ['e'],
            'star' => ['s'],
            'mark_read' => ['shift+i'],
            'mark_unread' => ['shift+u'],
            'select' => ['x'],
            'go_inbox' => ['g i'],
            'help' => ['?'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Folder management
    |--------------------------------------------------------------------------
    |
    | Folders are created, renamed, moved and deleted on the server. System
    | folders (inbox, sent, trash, ...) are protected by default. Deleting a
    | folder moves its messages to the trash unless permanent deletion is
    | allowed and chosen.
    |
    */

    'folders' => [
        'management' => (bool) env('MAILBOX_FOLDER_MANAGEMENT', true),
        'protect_special_use' => true,
        'allow_permanent_delete' => (bool) env('MAILBOX_FOLDER_ALLOW_PERMANENT_DELETE', false),
        'lock_seconds' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Forwarding
    |--------------------------------------------------------------------------
    |
    | "default_mode" preselects forwarding inline (quoted, with the original
    | attachments) or as attachment (the complete message as .eml file).
    |
    */

    'forward' => [
        'subject_prefix' => env('MAILBOX_FORWARD_SUBJECT_PREFIX', 'Fwd: '),
        'default_mode' => env('MAILBOX_FORWARD_DEFAULT_MODE', 'inline'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Starred messages
    |--------------------------------------------------------------------------
    |
    | Starred messages (IMAP \Flagged, Gmail STARRED, Outlook follow-up flag)
    | are listed in a "Starred" navigation entry across all folders, except
    | folders with the special-use roles below.
    |
    */

    'starred' => [
        'navigation' => (bool) env('MAILBOX_STARRED_NAVIGATION', true),
        'exclude_special_use' => ['trash', 'junk'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Snooze
    |--------------------------------------------------------------------------
    |
    | Snoozed messages are hidden from their folder and listed in "Snoozed"
    | until their time has come. "mailbox:wake-snoozed" (every minute) lets
    | them return: unread (locally and on the server with
    | "mark_unread_on_wake"), on top of the list, and the user who snoozed
    | them gets a database notification. Presets are relative date
    | expressions, calculated in the panel time zone. With "server_folder"
    | (e.g. "Snoozed") messages are also moved to that folder on the server
    | and back - created when missing (needs folder management).
    |
    */

    'snooze' => [
        'enabled' => (bool) env('MAILBOX_SNOOZE', true),
        'presets' => [
            'later_today' => '+3 hours',
            'tomorrow' => 'tomorrow 08:00',
            'next_week' => 'next monday 08:00',
        ],
        'server_folder' => env('MAILBOX_SNOOZE_SERVER_FOLDER'),
        'mark_unread_on_wake' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Archive
    |--------------------------------------------------------------------------
    |
    | Without an archive folder (no \Archive role and none selected in the
    | mailbox settings), "create_folder_if_missing" creates a top-level folder
    | named "folder_name" on the first archiving - or uses an existing one -
    | and remembers it as the archive folder of the mailbox. Requires folder
    | management.
    |
    */

    'archive' => [
        'create_folder_if_missing' => (bool) env('MAILBOX_ARCHIVE_CREATE_FOLDER', false),
        'folder_name' => env('MAILBOX_ARCHIVE_FOLDER_NAME', 'Archive'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Spam
    |--------------------------------------------------------------------------
    |
    | "use_keywords" sets the $Junk / $NotJunk keywords on IMAP servers before
    | moving, so server-side filters can learn. Blocked senders are moved to
    | spam after the synchronisation. Messages in the spam folder are rendered
    | without links with "strict_rendering".
    |
    */

    'spam' => [
        'use_keywords' => (bool) env('MAILBOX_SPAM_USE_KEYWORDS', true),
        'block_senders' => (bool) env('MAILBOX_SPAM_BLOCK_SENDERS', true),
        'strict_rendering' => (bool) env('MAILBOX_SPAM_STRICT_RENDERING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Labels
    |--------------------------------------------------------------------------
    |
    | Server-side labels (IMAP keywords, Gmail labels, Outlook categories).
    | Technical keywords listed below are never shown as labels. Thunderbird
    | keywords get readable names.
    |
    */

    'labels' => [
        'hidden_keywords' => ['$Forwarded', '$MDNSent', '$Junk', '$NotJunk', 'NonJunk', 'Junk', '$Phishing', '$SubmitPending', '$Submitted'],
        'thunderbird_defaults' => [
            '$label1' => 'Important',
            '$label2' => 'Work',
            '$label3' => 'Personal',
            '$label4' => 'To-do',
            '$label5' => 'Later',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | "engine": "like" (substring search on the messages table, no index),
    | "database" (full-text index in mailbox_search_documents: MySQL/MariaDB
    | FULLTEXT, PostgreSQL tsvector, SQLite FTS5 - run
    | "mailbox:search-reindex" after switching) or a class implementing
    | Search\Contracts\MessageSearchEngine ("engines" maps names to classes).
    | The search field understands operators like from:, subject:,
    | has:attachment, is:unread, in:, before:, "phrases", -exclusion and OR.
    | "include_body" applies to the like engine; the index always contains the
    | body (up to "max_body_length" characters). Attachment texts (PDF with
    | pdftotext, DOCX, ODT, text) are extracted by a queue job when
    | "attachments.extract_text" is enabled (sizes in kilobytes).
    |
    */

    'search' => [
        'engine' => env('MAILBOX_SEARCH_ENGINE', 'like'),
        'engines' => [],
        'include_body' => (bool) env('MAILBOX_SEARCH_INCLUDE_BODY', false),
        'max_body_length' => 100_000,
        // Upper limit of hits of engines outside the database.
        'max_results' => 1000,
        'snippets' => true,
        'mysql_min_token_size' => 3,   // innodb_ft_min_token_size
        'postgres_language' => env('MAILBOX_SEARCH_POSTGRES_LANGUAGE', 'german'),
        'queue_connection' => env('MAILBOX_SEARCH_QUEUE_CONNECTION'),    // null = sync.queue_connection
        'queue' => env('MAILBOX_SEARCH_QUEUE'),                          // null = sync.queue
        // Hits of an engine that cannot filter everything itself; the rest is
        // filtered in the database on this many candidates.
        'candidate_limit' => 2000,
        // Only for the "scout" engine: the Scout engine of the application
        // (config/scout.php). "adapter" adds engine-specific filters
        // ("typesense" or a ScoutAdapter class), "fields" limits the indexed
        // fields (data minimisation). Cloud engines send message contents to
        // an external service, so they need an explicit acknowledgement.
        'scout' => [
            'index' => env('MAILBOX_SCOUT_INDEX', 'filament_mailbox_messages'),
            'adapter' => env('MAILBOX_SCOUT_ADAPTER'),
            'fields' => [],
            'external_processing_acknowledged' => (bool) env('MAILBOX_SEARCH_EXTERNAL_ACK', false),
        ],
        // Only for the "meilisearch" engine: typo-tolerant search in a Meilisearch
        // instance. Use an API key limited to the index, never the master key, and
        // schedule "mailbox:search-tasks-check" every minute.
        'meilisearch' => [
            'host' => env('MAILBOX_MEILISEARCH_HOST', 'http://localhost:7700'),
            'key' => env('MAILBOX_MEILISEARCH_KEY'),
            'index' => env('MAILBOX_MEILISEARCH_INDEX', 'filament_mailbox_messages'),
            'timeout' => 10,
            'batch_size' => 500,
            // Used when the instance cannot be reached ("" = fail instead).
            'fallback_engine' => env('MAILBOX_MEILISEARCH_FALLBACK', 'database'),
        ],
        'attachments' => [
            'extract_text' => (bool) env('MAILBOX_SEARCH_EXTRACT_ATTACHMENTS', false),
            'max_size' => 20_480,
            'max_text_length' => 200_000,
            'pdftotext_binary' => env('MAILBOX_PDFTOTEXT', 'pdftotext'),
            'timeout' => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Synchronisation
    |--------------------------------------------------------------------------
    */

    'sync' => [
        'queue_connection' => env('MAILBOX_SYNC_QUEUE_CONNECTION'),
        'queue' => env('MAILBOX_SYNC_QUEUE', 'default'),
        'chunk_size' => (int) env('MAILBOX_SYNC_CHUNK_SIZE', 50),
        'log_channel' => env('MAILBOX_SYNC_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring
    |--------------------------------------------------------------------------
    |
    | Every synchronisation is recorded as a sync run (status, duration,
    | counts, classified error, per-folder statistics, provider latencies).
    | "mailbox:monitor" (every five minutes) closes hanging runs and opens or
    | resolves alerts, "mailbox:prune-monitoring" (daily) removes old data.
    | Alerts are sent to users who may manage mailboxes ("database", "mail")
    | and optionally to "mail_to" and a Slack incoming webhook ("slack").
    | Laravel Pulse and OpenTelemetry are used when their packages are installed.
    |
    */

    'monitoring' => [
        'enabled' => (bool) env('MAILBOX_MONITORING', true),
        'expected_sync_interval_minutes' => (int) env('MAILBOX_EXPECTED_SYNC_INTERVAL', 5),
        'run_timeout_minutes' => 60,
        'retention_days' => (int) env('MAILBOX_MONITORING_RETENTION_DAYS', 30),
        'alerts' => [
            'consecutive_failures' => 3,
            'stale_factor' => 3,
            'channels' => ['database'],
            'mail_to' => env('MAILBOX_ALERT_MAIL'),
            'slack_webhook' => env('MAILBOX_ALERT_SLACK_WEBHOOK'),
        ],
        // Laravel Pulse recorder and cards, only when laravel/pulse is installed.
        'pulse' => (bool) env('MAILBOX_PULSE', true),
        // OpenTelemetry spans through the global tracer provider (open-telemetry/sdk).
        'opentelemetry' => (bool) env('MAILBOX_OPENTELEMETRY', false),
        'prometheus' => [
            'enabled' => (bool) env('MAILBOX_METRICS', false),
            'token' => env('MAILBOX_METRICS_TOKEN'),
            'window_minutes' => 15,
            // Adds a time series per mailbox (id only).
            'mailbox_labels' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    |
    | Page "Statistics" with daily aggregates per mailbox (volume, busy hours,
    | first-reply times, SLA, sender domains) and live figures (unanswered,
    | unread, storage). Needs "mailbox:stats-aggregate" in the scheduler.
    | Replies are sent messages in the sent folder referencing an inbound
    | message. Statistics contain mailbox aggregates only - never figures per
    | person (employee monitoring). "visible_to": "managers" or
    | "assigned_users" (managers see all mailboxes, other users their assigned
    | ones). "timezone" is used for mailboxes without business hours time zone
    | (null = app.timezone).
    |
    */

    'statistics' => [
        'enabled' => (bool) env('MAILBOX_STATISTICS', false),
        'visible_to' => env('MAILBOX_STATISTICS_VISIBLE_TO', 'managers'),
        'timezone' => env('MAILBOX_STATISTICS_TIMEZONE'),
        'top_domains' => 10,
        'sla_default_minutes' => (int) env('MAILBOX_STATISTICS_SLA_MINUTES', 240),
        'live_cache_seconds' => 300,
        'retention_days' => (int) env('MAILBOX_STATISTICS_RETENTION_DAYS', 730),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health dashboard
    |--------------------------------------------------------------------------
    |
    | Page "Mailbox health" for users who may manage mailboxes (requires
    | monitoring). Mailboxes are rated after every sync run and by
    | "mailbox:monitor", which also runs the system checks below, writes the
    | scheduler heartbeat and dispatches a heartbeat job on the sync queue.
    | Heartbeat thresholds are given in minutes. Own checks implement
    | Health\HealthCheck.
    |
    */

    'health' => [
        'enabled' => (bool) env('MAILBOX_HEALTH', true),
        'polling' => env('MAILBOX_HEALTH_POLLING', '30s'),
        'statistics_cache_seconds' => 30,
        'queue_heartbeat' => ['warning_after' => 10, 'failed_after' => 20],
        'scheduler_heartbeat' => ['warning_after' => 10, 'failed_after' => 20],
        'search_warning_ms' => 2000,
        'checks' => [
            \Cooolinho\FilamentMailbox\Health\Checks\QueueHeartbeatCheck::class,
            \Cooolinho\FilamentMailbox\Health\Checks\SchedulerHeartbeatCheck::class,
            \Cooolinho\FilamentMailbox\Health\Checks\AttachmentDiskCheck::class,
            \Cooolinho\FilamentMailbox\Health\Checks\SearchEngineCheck::class,
            \Cooolinho\FilamentMailbox\Health\Checks\OAuthConnectionsCheck::class,
            \Cooolinho\FilamentMailbox\Health\Checks\FailedJobsCheck::class,
        ],
    ],

];
