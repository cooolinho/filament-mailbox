# Drafts

Unfinished messages are saved as drafts, edited later and sent — without losing anything when a modal is
closed. For mailboxes whose provider can store messages (IMAP), drafts are also kept in the drafts folder of the
server, so Outlook, Thunderbird or a phone show them.

## Saving

- **Save as draft** in the footer of *New e-mail*, *Reply*, *Reply all* and *Forward* stores the form — also
  without recipients or subject — and opens the draft editor.
- **Draft editor** (`EditDraft`, `/mailboxes/{mailbox}/drafts/{draft}`): the compose form with *Send*
  (`mod+enter`), *Save* (`mod+s`) and *Discard*. The subheading shows when the draft was saved last.
- **Autosave** every `drafts.autosave_seconds` (Livewire poll) when the form changed. Invalid input (e.g. an
  incomplete address) is not reported while typing; it is saved with the next valid state.
- **Drafts** (header action of the mailbox with a counter, `ListDrafts`): the drafts written in the app, with
  mode, recipients, attachments, *In drafts folder* and *Discard*.

Required fields (recipients, subject, text) are only enforced when sending.

## What a draft keeps

Recipients (including BCC), subject, format, text or HTML body, quoted original, signature, the reply or forward
relation (threading headers, forward mode, selected original attachments) and attachments:

- New uploads and inserted template attachments are copied to `{attachments.path}/drafts/{uuid}/attachments`
  when saving; stored attachments can be deselected in *Attachments of the draft*.
- Images of the rich editor are copied from the compose uploads into `{attachments.path}/drafts/{uuid}`, so
  `mailbox:prune-compose-uploads` never breaks a draft.

Sending a draft or discarding it deletes the record, its files, its synchronised copies and its version on the
server.

## Drafts folder

```text
mailbox_drafts (source of truth while editing)
      │ after saving: MIME with Message-ID <uuid.random@filament-mailbox> and X-Filament-Mailbox-Draft
      │ SaveDraftToServerJob → appendMessage(Drafts, \Draft \Seen), previous version deleted
      ▼
drafts folder on the server ──sync──► mailbox_messages (is_draft, draft_id)
```

- Saving increments `revision`; `SaveDraftToServerJob` (unique per draft until processing, delayed by
  `drafts.server_sync_delay_seconds`) stores the latest state once, even after several quick saves.
- IMAP messages cannot be changed, so every stored version replaces the previous one (deleted permanently).
- The synchronisation marks messages in the drafts folder (or with `\Draft`) as `is_draft` and links a version
  stored by the app to its draft by Message-ID — it is listed once and opens in the editor. Servers without
  `UIDPLUS` do not report the new UID; the link then also provides the remote id for the next replacement.
- A draft without any recipient stays local until it has one (a MIME message needs a recipient).
- Providers without `ProviderCapability::AppendMessages` (Microsoft Graph, Gmail API) and
  `drafts.sync_to_server = false` keep drafts in the app only.
- The drafts folder lists recipients instead of the sender; drafts show a pencil icon.

### Drafts of other clients

Messages in the drafts folder that were not written in the app have *Edit draft* on the message page. It
creates a draft from the message (recipients, BCC from the MIME source, subject, text/HTML, attachments,
threading headers) and links it; saving replaces the version of the other client. Inline images of such
drafts are not kept.

## Configuration

| Key | Env | Default | Description |
|---|---|---|---|
| `drafts.enabled` | `MAILBOX_DRAFTS` | `true` | *Save as draft*, editor and drafts list |
| `drafts.autosave_seconds` | `MAILBOX_DRAFT_AUTOSAVE` | `30` | Autosave interval of the editor, `0` disables it |
| `drafts.sync_to_server` | `MAILBOX_DRAFT_SYNC_TO_SERVER` | `true` | Store drafts in the drafts folder (providers with append support) |
| `drafts.server_sync_delay_seconds` | `MAILBOX_DRAFT_SERVER_SYNC_DELAY` | `10` | Debounce of the server copy |
| `drafts.prune_after_days` | `MAILBOX_DRAFT_PRUNE_AFTER_DAYS` | `null` | Age of drafts discarded by `mailbox:prune-drafts` (`null` = never) |

```php
Schedule::command('mailbox:prune-drafts')->daily();        // only with drafts.prune_after_days
Schedule::command('mailbox:prune-drafts --days=90')->daily();
```

The server copy is written by a queued job (`sync.queue_connection` / `sync.queue`).

## Security

- `MailboxDraftPolicy`: drafts written in the app are private — only their author or a user who may manage
  mailboxes opens, sends or discards them, and only while assigned to the mailbox and allowed to send. Drafts
  are always resolved through the mailbox of the URL.
- *Edit draft* for messages in the drafts folder requires the `reply` ability; drafts linked to another user's
  app draft are not taken over.
- The copy on the server contains BCC recipients, unlike sent mail. Everyone with access to the mailbox (in the app
  or another client) can read them there.
- The draft header and the draft Message-ID are not part of the sent message.
- Attachments and images are stored below the random draft UUID on the private attachments disk; stored image paths
  outside the draft, the user's compose uploads, signatures and templates are rejected by the editor.
