# Labels

Labels are **server-side** tags on messages: IMAP keywords, Gmail labels (Gmail API provider) and Outlook
categories (Microsoft Graph provider). They are synchronised in both directions and show up in other mail clients too.
(App-only tags that never reach the server are a different feature, see [Tags](tags.md).)

| | Source | Server key | Catalogue |
|---|---|---|---|
| IMAP | `imap_keyword` | keyword atom, e.g. `Kunde_Acme`, `$label1` | FLAGS/PERMANENTFLAGS of the INBOX + keywords in use |
| Gmail API | `gmail` | label id | `labels.list` |
| Microsoft Graph | `graph_category` | category name | master categories |

## Usage

- **Navigation** — the mailbox sidebar has a *Labels* group; a label lists its messages across all folders
  (with an extra *Folder* column).
- **Table** — label badges, filter by one or more labels, bulk *Add label* / *Remove label*.
- **Row and message view** — *Labels* action: tick existing labels or type a new one.
- **Catalogue** — *Labels* relation manager on the mailbox edit page: name, colour, hide, create, delete.

All actions are only visible when the provider reports `ProviderCapability::Labels` or `Keywords`.

## IMAP specifics

- A label name is turned into a keyword with `ImapKeyword::fromName()`: transliterated to ASCII, spaces
  become `_`, only a whitelist of characters is kept. User input never reaches IMAP commands unfiltered.
- Renaming only changes the local display name — the keyword stays.
- Deleting a label removes the keyword from all locally known messages.
- New keywords need `\*` in the server's `PERMANENTFLAGS`; otherwise creating a label shows an
  "unsupported" warning and only existing keywords can be used. Some servers limit the number of keywords
  per folder.
- Technical keywords (`$Forwarded`, `$MDNSent`, `$Junk`, …) are never shown, see
  `labels.hidden_keywords`. Thunderbird's `$label1`–`$label5` get readable names
  (`labels.thunderbird_defaults`).

## Synchronisation

Labels travel in `MessageFlags::$keywords`. On import and whenever the keywords of a message change,
`LabelSynchronizer` creates unknown labels and aligns the `mailbox_message_labels` pivot. After all
folders are synchronised the catalogue is mirrored; labels that disappeared on the server and are no
longer assigned to any message are deleted. `LabelsChanged` is dispatched for changed messages (e.g. to
reindex search).

## Providers

A provider supports labels by implementing `Contracts\SupportsLabels` (`labelSource()`, `labels()`,
`createLabel()`, `renameLabel()`, `deleteLabel()`, `changeLabels()`) and reporting the capability.
`LabelService` always updates the server first and the local copy afterwards.

## Security

- Label ids from the URL or forms are always resolved through the mailbox; foreign ids are ignored.
- Managing the catalogue requires managing mailboxes (`MailboxLabelPolicy`); assigning labels requires
  `update` on the message.
- Colours come from the fixed `LabelColor` enum, never from free input.
