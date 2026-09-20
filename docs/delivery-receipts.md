# Delivery receipts

Delivery Status Notifications (DSN, [RFC 3461](https://datatracker.ietf.org/doc/html/rfc3461) /
[RFC 3464](https://datatracker.ietf.org/doc/html/rfc3464)) tell whether a sent message reached its recipients.
The package requests them in the SMTP dialogue, assigns incoming reports and classic bounces per recipient and
shows the result in the [outbox](outbox.md).

## Requesting

Every message sent through the outbox asks for **failure and delay** reports (`NOTIFY=FAILURE,DELAY`).
*Request delivery receipt* in the compose form (preselected with `delivery_receipts.request_success_by_default`)
adds **success** reports (`NOTIFY=SUCCESS,FAILURE,DELAY`).

`DsnEsmtpTransport` adds the parameters when the server offers the `DSN` extension in its EHLO answer:

```text
MAIL FROM:<support@example.com> RET=HDRS ENVID=<outbox uuid>
RCPT TO:<jane@example.com> NOTIFY=FAILURE,DELAY ORCPT=rfc822;jane@example.com
```

- `ENVID` and `ORCPT` are xtext-encoded (`Support\Xtext`), so CR/LF, spaces, `+` and `=` can never inject commands.
- Without the extension the message is sent normally; `mailbox_outgoing_messages.dsn_supported` records the answer
  (*Not supported by the server* in the outbox).
- Symfony builds `MAIL FROM`/`RCPT TO` in private methods, so the transport extends them in `executeCommand()`.

| Transport | DSN |
|---|---|
| Mailbox SMTP (OAuth mailboxes, own SMTP host) | Always `DsnEsmtpTransport` |
| Laravel mailer | Only a mailer with `'transport' => 'mailbox-dsn'` (same options as `smtp`); other mail of the application never gets DSN parameters |
| Microsoft Graph, Gmail API | Not possible; the status stays *Unknown*, bounces are still recognised |

```php
// config/mail.php
'mailers' => [
    'mailbox' => [
        'transport' => 'mailbox-dsn',
        'host' => env('MAIL_HOST'),
        'port' => env('MAIL_PORT', 587),
        'username' => env('MAIL_USERNAME'),
        'password' => env('MAIL_PASSWORD'),
    ],
],
// .env: MAILBOX_MAILER=mailbox
```

Many submission servers (e.g. Microsoft 365, Gmail SMTP) ignore DSN parameters — *Unknown* is common and says
nothing about a failure.

## Incoming reports

During the synchronisation (`DeliveryReportService`):

1. **DSN** — `multipart/report; report-type=delivery-status`: the `message/delivery-status` part is parsed
   (`Original-Envelope-Id`, `Reporting-MTA`, per recipient `Final-Recipient`, `Action`, `Status`,
   `Diagnostic-Code`). It is assigned by the envelope id (the outbox UUID), otherwise by the Message-ID of the
   returned headers (`text/rfc822-headers`).
2. **Bounces without report** (`delivery_receipts.bounce_heuristics`) — sender `MAILER-DAEMON`/`postmaster` or an
   `X-Failed-Recipients` header, with a typical subject (several languages): assigned when the text contains the
   Message-ID of a sent message; failed recipients from `X-Failed-Recipients` or the text. Stored with the source
   `bounce_heuristic`.

- Reports are untrusted: only messages sent from the same mailbox and only their recipients are updated; diagnostic
  texts are limited and shown as plain text.
- `mailbox_receipts` stores one entry per recipient and status (`delivered`, `delayed`, `failed`) with status code,
  diagnostic, reporting MTA and source; importing the same report again does not duplicate it.
- The report message is marked as receipt (icon, filter in the message list).
- `DeliveryReportReceived` is dispatched for every entry, `DeliveryFailed` for failures; the sender gets a database
  notification for failed recipients.

## Outbox

- Column *Delivery* with the aggregated status of sent messages: *Failed* (any recipient), *Delayed*, *Delivered*
  (all recipients, or success reports only), *Not supported by the server*, *Unknown*.
- Filter *Failed deliveries*.
- *Details* lists every report: recipient, status, time, status code, diagnostic and whether it was recognised from
  a bounce.

## Not included

Webhooks of transactional mail services (Postmark, Amazon SES, Mailgun) — the data model has a `source` column for
them — and automatic block lists for failed recipients.
