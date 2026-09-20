# Keyboard shortcuts

The mailbox page and the message page can be used with the keyboard, with bindings known from Gmail. **?** (or the
keyboard button in the header) lists them.

| Key | Page | Function |
|---|---|---|
| `c` | Mailbox | New e-mail |
| `/` | Mailbox | Focus the search |
| `j` / `k` | Mailbox | Next / previous message (highlighted row; in the split view shown in the reading pane) |
| `Enter` / `o` | Mailbox | Open the message |
| `u` | Message | Back to the folder |
| `r` / `a` / `f` | Message, split view | Reply / reply all / forward |
| `#` / `Del` | Mailbox, message | Delete (with confirmation) |
| `e` | Mailbox, message | Archive |
| `s` | Mailbox, message | Star / remove star |
| `Shift + I` / `Shift + U` | Mailbox (read), mailbox and message (unread) | Mark as read / unread |
| `x` | Mailbox | Select the message |
| `g`, `i` | Mailbox, message | Go to the inbox |
| `?` | Mailbox, message | Show the shortcuts |

- Shortcuts never fire while typing in a field (inputs, selects, text areas, the rich editor), with Ctrl/Cmd/Alt or
  while a modal is open.
- The bindings come from `shortcuts.bindings` (single keys, `shift+<letter>`, `enter`, `del`, sequences like `g i`).
  Users cannot change them; they switch shortcuts off on the *Mailbox settings* page (user menu,
  preference `shortcuts_enabled`). `shortcuts.enabled` switches them off for everyone.
- On keyboard layouts where `#` is hard to reach, `Del` does the same.

## How it works

Filament's `keyBindings()` are registered globally and would also fire inside form fields, so the package ships a
small script (`filament-mailbox::shortcuts.script`, rendered only when shortcuts are enabled) instead:

- Page actions are mounted on the page component (`mountAction('reply')`, …) — hidden or unauthorised actions do
  nothing, confirmations and forms open as with a click. In the split view reply and forward use the reading pane.
- Row shortcuts call `BrowseMailbox::shortcut($shortcut, $messageId)`. It accepts only `archive`, `delete`, `star`,
  `mark_read` and `mark_unread`, resolves the id through the table query of the current view (other mailboxes or
  folders are ignored) and mounts the existing row action, so visibility, authorisation and the delete
  confirmation apply. The star uses the `update` ability like the star column.
- Rows carry the classes `fi-mailbox-row fi-mailbox-message-<id>`; the highlighted row gets `fi-mailbox-cursor` and
  `aria-selected`.

All actions stay reachable with the mouse and the Tab key; the help modal lists every shortcut for screen reader
users.

## Manual browser checklist

1. `j`/`k` move the highlight, `Enter` opens the message; in the split view the reading pane follows.
2. Typing `e` in the search field or the compose form does not archive.
3. `#` opens the delete confirmation; `Esc` closes it without deleting.
4. `g` then `i` opens the inbox; `?` opens the help.
5. After switching shortcuts off in the mailbox settings nothing happens.
