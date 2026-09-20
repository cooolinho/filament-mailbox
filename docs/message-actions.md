# Message actions

Actions on single messages beyond reading, replying and deleting. Every action changes the server first
(through `MessageService`) and the local copy afterwards, unless stated otherwise.

## Forward

*Forward* on the message page opens the compose form prefilled with `Fwd: <subject>` and empty recipients.

| Mode | Body | Attachments |
|---|---|---|
| Inline (default) | Optional note, followed by the header block (From, Date, Subject, To, CC) and the original — sanitised HTML without images, or text (see [Composing](composing.md#replies-and-forwards)) | Original attachments as checkbox list (all selected), plus new uploads |
| As attachment | Optional note | The complete message as `<subject>.eml` (`message/rfc822`), plus new uploads |

- *As attachment* uses the original MIME source from `MailboxProvider::rawMessage()`. When the provider cannot
  deliver it, the message is rebuilt from the local copy (headers, text/HTML body, attachments).
- The forward carries a `References` header with the original Message-ID, but no `In-Reply-To`
  (`OutgoingMessageData::$forwardedMessageId`).
- Subject prefixes are not stacked (`Fwd:`, `FW:`, `WG:`, `Wtr:`, `TR:`). The prefix is configurable with
  `forward.subject_prefix`; `forward.default_mode` (`inline` or `attachment`) preselects the mode.
- The total attachment size is checked against `mail.max_attachment_size`.
- After sending, `mailbox_messages.forwarded_at` is set and providers with `ProviderCapability::Keywords` get
  the `$Forwarded` keyword, so other clients show the message as forwarded. `MessageForwarded` is dispatched
  (`message`, `recipients`, `asAttachment`).

**Security:** selected attachment ids are only resolved within the original message
(`ForwardBuilder::originalAttachments()`), the `.eml` filename is sanitised, and the action requires the
`forward` ability (assigned + may send messages).

## Star

Starring maps to the server-side "flagged" state: IMAP `\Flagged`, the Gmail label `STARRED` and the Outlook
follow-up flag (`flag.flagStatus`). Other clients show it as star, flag or follow-up, and changes made there
arrive with the next synchronisation.

- **Table** — the first column shows the star; clicking it toggles the state without opening the message.
  Bulk actions *Star* / *Remove star* and a *Starred* filter are available.
- **Message page** — *Star* / *Remove star* header action.
- **Navigation** — *Starred* follows the inbox and lists the starred messages of all active folders of the
  mailbox with an additional *Folder* column. The badge shows their number. Folders with the special-use
  roles in `starred.exclude_special_use` (trash and spam by default) are left out; `starred.navigation`
  disables the entry. The URL is `?view=starred`.
- Providers without `ProviderCapability::Flagged` only change the local copy.
- The toggle requires `update` on the message; bulk actions authorise every record.

## Snooze

*Snooze* hides messages until a chosen time (row, bulk and message page). Snooze is state of the app and applies
to the message for all users of the mailbox; only the user who snoozed it is notified.

- **Times** — *Later today* (+3 h), *Tomorrow* (08:00), *Next week* (Monday 08:00) or a custom date and time
  (in the future, at most one year ahead, validated on the server). Presets are relative date expressions in
  `snooze.presets`, calculated in the panel time zone (`FilamentTimezone`); the time is stored in the
  application time zone (UTC).
- **Hidden** — snoozed messages are left out of their folder, labels, *Starred* and the unread badge.
- **Navigation** — *Snoozed* follows *Starred* and lists the snoozed messages of the mailbox, the next returning
  first, with a *Snoozed until* column and *Unsnooze* (row and bulk). The badge shows their number. The URL is
  `?view=snoozed`.
- **Return** — `mailbox:wake-snoozed` (schedule it every minute) queues `WakeSnoozedMessagesJob` for due messages.
  A returning message is visible again, marked as unread (on the server as well with
  `snooze.mark_unread_on_wake`; server errors are logged and the local copy is unread anyway) and on top of
  the list. The user who snoozed it gets a database notification with a link — only while they may still
  view the mailbox. Every message is claimed with a conditional update, so parallel runs never wake it twice.
- **Order** — lists are sorted by `mailbox_messages.sort_at`: `received_at`, or the time a snoozed message
  returned. Sorting by the date column is still possible.
- **Synchronisation** never changes snooze columns. A message deleted on the server loses its snooze; restored
  moves keep it.
- **Server variant** — with `snooze.server_folder` (e.g. `Snoozed`) messages are also moved to that top-level
  folder on the server, so other clients do not show them meanwhile, and back to their folder
  (`snoozed_from_folder_id`, or the inbox) when they return or the snooze is cancelled. The folder is created when
  missing and folder management is available. Drafts are never moved; errors are logged and the snooze works
  locally.
- Events: `MessagesSnoozed` (`mailbox`, `messageIds`, `until`) and `MessagesUnsnoozed` (`mailbox`, `messageIds`,
  `woken`).
- Without a running scheduler snoozed messages do not return.
- The actions require `update` on the message; bulk actions authorise every record.

## Archive

*Archive* moves messages to the archive folder of their mailbox (row, bulk and message page). *Move to…* moves
them into any other folder. The archive folder is

1. the folder selected as *Archive folder* in the mailbox settings (section *Folders*, edit page only), or
2. the folder with the `\Archive` special-use role (Graph: well-known `archive`, Gmail API: *All mail*).

3. with `archive.create_folder_if_missing`: a top-level folder named `archive.folder_name` (default `Archive`),
   created on the server on the first archiving — or an existing top-level folder of that name — and then
   remembered as the archive folder of the mailbox. This needs [folder management](folders.md)
   (IMAP, Microsoft Graph).

IMAP `\All` ("All mail" of Gmail over IMAP) gets its own role `SpecialUse::All` and is never an archive target.

- The action is hidden in the archive, trash, drafts and *All mail*. Without an archive folder or without
  `ProviderCapability::MoveMessages` it is disabled with a hint.
- On the message page archiving redirects back to the folder.
- The success notification offers *Undo*. It dispatches `BrowseMailbox::UNDO_MOVE_EVENT` with the original
  folder of every message; `BrowseMailbox::undoMove()` resolves messages and folders through the mailbox and
  authorises every message again.
- `MessagesArchived` is dispatched (`mailbox`, `messageIds`).
- Gmail API: archiving removes the source label (e.g. `INBOX`).

### Moves without a new identity

When the server does not report the new identity (IMAP without `UIDPLUS`/`MOVE`, or a stale identifier), the
local message is soft-deleted and remembers its target in `mailbox_messages.pending_move_to`. The next
synchronisation of the target folder restores this record (matched by `message_id`) instead of importing a
duplicate — attachments, labels and tags stay as they are. This applies to every `MessageService::move()`.

## Spam

*Spam* moves messages to the spam folder, *Not spam* moves them from the spam folder back to the inbox
(row, bulk and message page, each with *Undo*). The spam folder is the folder selected as *Spam folder* in
the mailbox settings, otherwise the folder with the `\Junk` role (Graph: `junkemail`, Gmail API: `SPAM`).

- *Spam* is offered outside the spam folder, trash, drafts and sent mail; *Not spam* only in the spam folder.
- IMAP (`ProviderCapability::Keywords`, `spam.use_keywords`): the RFC 5788 keywords are set **before** the
  move — *Spam* adds `$Junk` and removes `$NotJunk`, *Not spam* the other way round. Dovecot/Rspamd
  (imapsieve), Thunderbird and Apple Mail use them for training. Whether the server actually learns depends
  on its configuration. If the server rejects the keywords, the message is moved anyway.
- Gmail API adds/removes the `SPAM` label, Graph moves to `junkemail`.
- `MessagesMarkedAsSpam` / `MessagesMarkedAsNotSpam` are dispatched (`mailbox`, `messageIds`).

### Strict rendering

With `spam.strict_rendering` (default) messages in the spam folder show a warning, links in the HTML body are
unwrapped to plain text (`HtmlBodySanitizer::document($html, strict: true)`, no `<base target>`), and
attachments are only downloaded after a confirmation.

### Blocked senders

With `spam.block_senders` (default) a mailbox has a list of blocked senders: exact addresses
(`user@example.com`) or whole domains (`*@example.com`), stored lowercase. Subdomains are not matched
implicitly and patterns are never regular expressions.

- Manage them in the *Blocked senders* relation manager on the mailbox edit page, or with *Block sender* on
  the message page (address or domain of the sender, optionally moving the message to spam).
- After a synchronisation `ApplyBlockedSendersJob` is queued for the new inbox messages; messages of blocked
  senders that are still in the inbox are moved to spam. Blocking works on the synchronised copy, so it takes
  effect after the next synchronisation and does not create a server-side rule.
- Both require managing the mailbox (`update` on the mailbox).

## Drag & drop

Drag a message row onto a folder in the mailbox navigation to move it. If the row is selected, all selected messages
are moved. The target folder is highlighted while dragging, the drag image shows the number of messages, and the
notification offers *Undo* (like archive and spam).

- Requires a provider that can move messages and `ui.drag_and_drop` (default on). One drop moves at most
  `ui.max_move_batch` (500) messages.
- Keyboard, screen reader and touch users use **Move to…** (row action and bulk action) — the same service method.
- The drop payload is controlled by the browser: `BrowseMailbox::moveMessages()` resolves the folder (active, same
  mailbox) and the messages through the mailbox, casts ids, limits the batch, authorises every message (`update`) and
  is rate limited (60 drops per minute per user). Rows and folders only carry integer ids in the markup
  (`fi-mailbox-message-<id>` class, `data-filament-mailbox-folder`).
- The navigation stays Filament's sub-navigation; drop targets are its folder items (not the mobile folder select).
- The script is inline on the mailbox page (no build step, no asset publishing).

Browser checklist: drag a single row, drag a selection, drop on the current folder (nothing happens), drop on a
label or *Starred* (not a target), undo, split view.
