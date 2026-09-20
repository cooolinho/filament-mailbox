# Read receipts

Read receipts are Message Disposition Notifications (MDN, [RFC 8098](https://datatracker.ietf.org/doc/html/rfc8098)).
The package requests them, answers requests only after the user agreed, and assigns incoming receipts to the
sent message. Tracking pixels or link tracking are deliberately not supported.

## Requesting

*Request read receipt* in the compose form (new message, reply, forward, draft editor) adds
`Disposition-Notification-To: <mailbox address>`. The checkbox is preselected with *Request read receipts* of
the mailbox (edit form), otherwise `read_receipts.request_by_default`.

The request is recorded with the Message-ID of the outbox message in `mailbox_receipt_requests`
(type `read`), so incoming receipts can be assigned. Recipients are not obliged to answer, and many clients never
do — missing receipts say nothing.

## Answering requests

Imported messages with `Disposition-Notification-To` get `mailbox_messages.mdn_status`:

| Status | When | Message view |
|---|---|---|
| `pending` | One address, not from the mailbox itself, not in sent, drafts, spam or trash | Callout *The sender asks for a read receipt* with **Send receipt** and **Ignore** |
| `unsafe` | The address differs from the `Return-Path` (RFC 8098 §2.1) | Warning callout with **Ignore** only |
| `not_applicable` | Several addresses, own message, or in sent, drafts, spam or trash | Nothing |
| `sent` / `ignored` | Answered or ignored here, or `$MDNSent` set by another client | Nothing |

- Receipts are **never sent automatically** — opening a message does not answer the request.
- *Send receipt* sends `multipart/report; report-type=disposition-notification` with a text part and a
  `message/disposition-notification` part (`Reporting-UA`, `Original-Recipient`, `Final-Recipient`,
  `Original-Message-ID`, `Disposition: manual-action/MDN-sent-manually; displayed`), subject *Read: …*,
  `Auto-Submitted: auto-replied`, through the transport of the mailbox (not the outbox). It requires the `reply`
  ability (assigned and may send).
- Sending and ignoring set the keyword `$MDNSent` on the server (providers with keywords), so other clients do not
  ask again, and the local status.
- Messages in the spam folder never show the request. `read_receipts.allow_sending = false` only offers *Ignore*.

## Incoming receipts

During the synchronisation `multipart/report` messages are marked as `is_receipt` (icon in the list, filter
*Read receipts and delivery reports*, hidden from folders with `read_receipts.hide_receipt_messages`). For a
`message/disposition-notification` part the fields are parsed (`ReceiptParser`, only known fields, values limited)
and assigned by `Original-Message-ID` — only to requests of the same mailbox. `mailbox_receipts` stores recipient,
disposition (`displayed`, `deleted`, …) and time, once per recipient and disposition.

- The sent message (message view, matched by Message-ID) and the outbox details list the receipts.
- `ReadReceiptReceived` is dispatched; with `read_receipts.notify_sender` the sender gets a database notification.
- A malformed report never breaks the synchronisation.

## API

```php
use Cooolinho\FilamentMailbox\Services\Receipts\ReadReceiptService;

$data = new OutgoingMessageData(['jane@example.com'], 'Offer', 'Body', headers: ReadReceiptService::requestHeaders($mailbox));

$receipts = app(ReadReceiptService::class);
$receipts->canRespond($message); // pending, allowed, not spam
$receipts->send($message);
$receipts->ignore($message);
$receipts->receiptsFor($sentMessageOrOutgoingMessage);
```

`MessageData` got `headers` (`Content-Type`, `Disposition-Notification-To`, `Return-Path`, `Return-Receipt-To`,
`X-Failed-Recipients`) and `reportParts` (machine-readable parts of reports, up to 64 KB each).
`OutgoingMessageData::$report` (`ReportData`) sends a `multipart/report` with every transport.
