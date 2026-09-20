# Split view

Mailboxes can be read in a **list view** (default; a click opens the message page) or a **split view** with the
message list and a reading pane side by side, like Outlook or Thunderbird. Switch with the two icon buttons in the
mailbox header; the choice is stored per user (`mailbox_user_preferences`).

## Behaviour

- A click on a row shows the message in the reading pane, highlights the row and marks the message as read (like
  the message page). Drafts written in the app still open the draft editor.
- The URL contains the selection (`/admin/mailboxes/1?folder=5&message=123`), so reloading and sharing restore it.
  Foreign or inaccessible message ids are dropped.
- The reading pane has the same actions as the message page (reply, reply all, forward, star, labels, tags, mark as
  unread, move, archive, spam, block sender, delete) plus *Open full view*. Frequent actions are buttons, the others
  are in a menu.
- After deleting, moving, archiving or marking as spam, the next message at the same list position is selected.
  *Mark as unread* closes the pane, so the message stays unread.
- In the split view the list uses compact rows (sender and subject stacked, date and attachment icon on the right).
- The reading pane is shown from the `xl` breakpoint (80rem). On smaller screens a click opens the message page as in
  the list view.

## Components

| Class | Purpose |
|---|---|
| `Filament\Livewire\MessagePreview` | Reading pane (`filament-mailbox.message-preview`), receives the mailbox id (locked) and the message id (reactive) only |
| `Filament\Resources\Mailboxes\Actions\MessageViewActions` | Actions of an opened message, shared by `ViewMessage` and `MessagePreview` |
| `Services\MessageViewer` | Resolves a message through its mailbox, authorises `view` and marks it as read |
| `Services\UserPreferences` | Per-user settings (`layout`) |

Events between the pane and `BrowseMailbox`: `filament-mailbox-preview-removed` (`id`), `-closed`, `-updated`.

## Configuration

```php
'ui' => [
    'split_pane' => [
        'enabled' => true,     // MAILBOX_SPLIT_PANE
        'default' => 'list',   // MAILBOX_SPLIT_PANE_DEFAULT: list | split, for users without a stored choice
    ],
],
```

Disabling hides the switch and always uses the list view.
