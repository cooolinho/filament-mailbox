# Gmail API provider

Mailboxes with the provider **Gmail API** use the Gmail REST API instead of IMAP. Compared to Gmail IMAP
this gives the history API for incremental sync, native labels (no duplicates in "[Gmail]/All Mail"),
push notifications via Pub/Sub, sending in the right thread with automatic *Sent* filing and domain-wide
delegation for Workspace mailboxes.

## Setup

1. *Google Cloud console*: enable the **Gmail API**, configure the OAuth consent screen and create an
   OAuth client of type *Web application* with the redirect URI shown in the panel
   (see [OAuth2](oauth.md#google)).
2. Create the **OAuth application** (provider Google) in the panel.
3. Create a mailbox with **Provider: Gmail API** and either
   - **Connect with Google** — delegated access for a Gmail or Workspace user, or
   - **Use service account** — for Workspace mailboxes (see below) acting for the mailbox address.
4. Optional **Initially synchronised days** (default `gmail.initial_sync_days` = 90).
5. **Test connection**, then synchronise.

### Scopes and verification

| Mode | Flow | Scopes |
|---|---|---|
| Delegated | Authorization code + PKCE, `access_type=offline`, `prompt=consent` | `gmail.modify`, `gmail.send`, `openid`, `email` |
| Service account | JWT bearer grant with `sub` = mailbox address (domain-wide delegation) | `gmail.modify`, `gmail.send` |

`gmail.modify` is a **restricted scope**. Apps used by external accounts need Google's verification
including a paid security assessment (weeks). Internal Workspace apps do not. Self-hosted operators carry
this themselves; also observe the Google API Services User Data Policy (*Limited Use*). The full
`https://mail.google.com/` scope is intentionally not requested — therefore messages cannot be deleted
permanently (see below).

### Service account (Workspace)

1. Create a service account and a JSON key; paste the JSON into **Service account key (JSON)** of the
   Google OAuth application (stored encrypted). Client ID and secret are not needed for this mode.
2. *Admin console → Security → API controls → Domain-wide delegation*: add the service account's client
   ID with exactly `https://www.googleapis.com/auth/gmail.modify,https://www.googleapis.com/auth/gmail.send`.
3. On the mailbox choose **Use service account** with the mailbox address in *E-mail address*.

## Folders and labels

Each message is stored **once**. Its folder is the primary system label:

| Gmail label | Folder / flag |
|---|---|
| `TRASH` > `SPAM` > `DRAFT` > `INBOX` > `SENT` | Trash, Spam, Drafts, Inbox, Sent (first match wins) |
| none of them | **All mail** (archive) |
| `UNREAD` | read state (inverted) |
| `STARRED` | flagged |
| user labels (`Label_…`) | [labels](labels.md) — navigation, filter, badges; nested names like `Kunden/Acme` are kept |
| `IMPORTANT`, `CHAT`, `CATEGORY_*` | hidden (`gmail.hidden_labels`) |

Actions map to labels: *Move* adds the target label and removes the source label (moving to *All mail*
archives), *Delete* moves to the trash (`messages.trash`). Messages in the trash cannot be deleted
permanently; the action is labelled *Move to trash* and hidden in the trash.

## Synchronisation

Gmail's history id is mailbox-wide, so the mailbox is synchronised at once
(`ProviderCapability::MailboxWideSync`, `SyncService::syncMailboxWide()`); the cursor is stored in
`mailboxes.sync_cursor`.

1. **Initial**: the current history id is read from the profile, then `messages.list`
   (`newer_than:{days}d`, incl. spam and trash) is paged with `sync.chunk_size`. Labels of each page are
   loaded with one **batch request** (`format=minimal`), unknown messages are downloaded as raw MIME in
   batches and parsed with `MimeMessageMapper`; `internalDate` becomes the received date, `threadId` is stored.
2. **Incremental**: `users.history.list` with `messageAdded`, `messageDeleted`, `labelAdded`,
   `labelRemoved`. Records are reduced to changed and deleted message ids (a deletion is final), the
   current labels of changed messages are loaded in a batch, and messages move between folders.
3. **Expired history** (HTTP 404): a limited resync of the last N days starts. Existing messages are
   matched by id and updated — not deleted and not downloaded again.

Requests are limited per mailbox (`gmail.requests_per_second`, Laravel `RateLimiter`), 429, 5xx and
`rateLimitExceeded`/`userRateLimitExceeded` are retried with `Retry-After` or exponential backoff, a 401
renews the token once.

## Sending and replies

Messages are rendered to MIME exactly like SMTP mail and sent with `messages.send`. Replies pass the
original message's `threadId`, and `In-Reply-To`/`References` plus the `Re:` subject keep them in the
same conversation. Gmail files sent messages under *Sent*.

## Push notifications (optional)

```dotenv
MAILBOX_GMAIL_PUSH=true
MAILBOX_GMAIL_PUBSUB_TOPIC=projects/my-project/topics/gmail
MAILBOX_GMAIL_PUSH_AUDIENCE=https://mail.example.com/filament-mailbox/webhooks/gmail
MAILBOX_GMAIL_PUSH_SERVICE_ACCOUNT=push-invoker@my-project.iam.gserviceaccount.com
```

```php
Schedule::command('mailbox:gmail-watch')->daily();
```

1. Create the Pub/Sub topic and grant `gmail-api-push@system.gserviceaccount.com` the *Pub/Sub Publisher*
   role on it.
2. Create a **push** subscription to `https://…/filament-mailbox/webhooks/gmail` with authentication
   enabled (service account = `MAILBOX_GMAIL_PUSH_SERVICE_ACCOUNT`, audience = `MAILBOX_GMAIL_PUSH_AUDIENCE`).
3. `mailbox:gmail-watch` calls `users.watch` for the INBOX of every active Gmail mailbox and renews
   watches that expire within a day (they last 7 days); `--force` renews all.

The webhook route is outside the panel authentication and rate limited. It verifies the OIDC token
(RS256 signature against Google's JWKs, issuer, audience, expiry and service account e-mail) and only
dispatches `SyncMailboxJob` for the mailbox named in the payload — the history id in the payload is not
trusted. Keep the regular `mailbox:sync` schedule as a fallback.

## Configuration

| Key | Env | Default |
|---|---|---|
| `gmail.initial_sync_days` | `MAILBOX_GMAIL_INITIAL_SYNC_DAYS` | `90` |
| `gmail.hidden_labels` | – | `IMPORTANT`, `CHAT`, `CATEGORY_*` |
| `gmail.batch_size` | `MAILBOX_GMAIL_BATCH_SIZE` | `50` (max. 100) |
| `gmail.requests_per_second` | `MAILBOX_GMAIL_REQUESTS_PER_SECOND` | `40` |
| `gmail.max_retries` | `MAILBOX_GMAIL_MAX_RETRIES` | `5` |
| `gmail.timeout` | `MAILBOX_GMAIL_TIMEOUT` | `60` |
| `gmail.push.enabled` / `topic` / `audience` / `service_account` | `MAILBOX_GMAIL_PUSH*` | disabled |

## Limits

- Gmail counts quota units per user (e.g. `messages.get` = 5); large initial syncs take time, which is
  why only the last N days are imported.
- Categories (Promotions, Social) are not offered as filters yet.
- There is no conversation (thread) view; threads are only used for replies.

## Manual checklist

See [Testing](testing.md#manual-gmail-checklist).
