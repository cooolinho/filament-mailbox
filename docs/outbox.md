# Outbox and scheduled send

Every message sent from the app — new messages, replies, forwards and drafts — goes through the outbox of its
mailbox (`mailbox_outgoing_messages`). It records the status of every message and makes sending later, undo
and retries possible.

## Sending

The compose form has a *Send* field below the attachments:

| Choice | Behaviour |
|---|---|
| *Now* (default) | Sent in the request (`outbox.send_immediately_inline`). A failure keeps the form open with an error, and nothing stays in the outbox |
| *In one hour*, *Tomorrow morning*, *Monday morning* | Presets from `outbox.schedule_presets` (relative date expressions), calculated in the panel time zone; the option shows the resulting time |
| *Pick date & time* | Custom time in the panel time zone, in the future and at most one year ahead (validated on the server) |

- With `outbox.undo_seconds` (e.g. `10`) messages sent *now* wait for that long. The notification *Sending the
  message…* offers **Undo**; scheduled messages get *Scheduled for …* with **Undo** and a link to the outbox.
- Without `send_immediately_inline` every message is sent by the queue.
- *Save as draft* ignores the send time; the draft editor has the same field.

## Delivery

```text
scheduled ──(due)──► sending ──► sent
    │  ▲                 │
    │  └──(backoff)──────┤ error, attempts left
    │                    └──► failed ──(try again)──► scheduled
    └──► cancelled ──(send now / change send time)──► scheduled
```

- `SendOutgoingMessageJob` is dispatched with a delay up to the send time. `mailbox:send-due` (schedule it
  **every minute**) queues due messages as a fallback, e.g. for queues without delay or lost jobs. A queue worker
  is required for everything that is not sent in the request.
- Every transition is a conditional update (`… where status = 'scheduled' and send_at <= now()`), so parallel
  workers never send a message twice; duplicate jobs do nothing.
- The Message-ID is generated when the message is queued (`<uuid>@<mailbox domain>`) and kept for retries, so
  recipients can deduplicate a retry after an unclear SMTP timeout.
- Errors are retried after `outbox.retry_backoff_minutes` (1, 5, 15) up to `outbox.max_attempts` (3). Then the
  message is *failed*, `OutgoingMessageFailed` is dispatched and the sender gets a database notification.
- The permission to send is checked again before every attempt; a sender who is no longer assigned or may no
  longer send fails the message right away (not retried).
- Messages that hang in *sending* for `outbox.stuck_after_minutes` (e.g. a worker crashed) are marked *failed* by
  `mailbox:send-due`. They are never sent again automatically, because the server may have accepted them.
- A forwarded original is marked as forwarded (`forwarded_at`, `$Forwarded`, `MessageForwarded`) when the forward
  is really sent.
- Events: `OutgoingMessageQueued` (not for messages sent in the request), `OutgoingMessageSent`,
  `OutgoingMessageFailed`.

## Outbox page

*Outbox* in the mailbox header (badge: scheduled and failed messages) lists the user's messages — all messages
for users who may manage mailboxes — with status, recipients, send and sent time. It refreshes every 30 seconds.

| Action | Available for |
|---|---|
| Details (recipients incl. BCC, Message-ID, last error, text) | All |
| Edit recipients, subject and plain text body | Scheduled, cancelled |
| Change send time | Scheduled, cancelled |
| Send now | Scheduled, cancelled |
| Cancel sending | Scheduled |
| Try again | Failed |

HTML messages keep their body when edited: inline images are referenced by content ID. Cancelled messages stay in
the outbox, so an undone message can be edited and sent after all.

## Data and privacy

- BCC recipients are stored encrypted (`encrypted:array`).
- Attachments of queued messages are stored privately on `attachments.disk` below `<attachments.path>/outbox/<uuid>`
  and deleted once the message is sent. Messages sent in the request store no attachments.
- `mailbox:prune-outbox` (daily) removes sent, failed and cancelled messages after `outbox.keep_days`.
- Error messages are stored with credentials redacted.

## Authorization

`MailboxOutgoingMessagePolicy`: `view` for the sender or users who may manage mailboxes, while assigned to the
mailbox; `update` (edit, change send time, send now, cancel, undo, try again) additionally requires the permission
to send. The *Undo* event carries only the id; it is authorised again.

## API

```php
use Cooolinho\FilamentMailbox\Services\OutboxService;

$outbox = app(OutboxService::class);

// Right away (in the request), after the undo window, or at a time:
$outgoing = $outbox->send($mailbox, $data, $user, sendAt: now()->addDay());

$outbox->reschedule($outgoing, now()->addHours(2));
$outbox->cancel($outgoing);
$outbox->sendNow($outgoing);
$outbox->retry($outgoing);
```

`MailSender::send()` still sends directly, without the outbox.

## Not included

- Scheduling on the server through provider APIs (Microsoft Graph, Gmail), bulk mail, send times per recipient
  time zone.
- Storing sent messages in the IMAP *Sent* folder.
