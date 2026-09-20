# Authorization

The package registers two policies unless the application already registered its own for the models.

## Abilities

| Ability | Rule |
|---|---|
| List mailboxes (`viewAny`) | Always; non-managers only see assigned mailboxes |
| Open a mailbox / read messages (`view`) | User is **assigned** to the mailbox |
| Create, edit, delete mailboxes, test connection | User **may manage** mailboxes |
| Mark read/unread, star, archive, spam / not spam, undo (`update` on message) | User is assigned |
| Blocked senders (relation manager, *Block sender*) | User **may manage** mailboxes (and is assigned for the message action) |
| Delete messages (`delete` on message) | User is assigned **and** may delete messages |
| Compose (`send` on mailbox), reply (`reply`) and forward (`forward` on message) | User is assigned **and** may send messages |
| Drafts written in the app (`MailboxDraftPolicy`) | Author **or** may manage mailboxes; assigned and may send messages |
| Send a read receipt (`reply` on message) / ignore the request (`update`) | User is assigned **and** may send messages / user is assigned |
| Outbox (`MailboxOutgoingMessagePolicy`) | View: sender **or** may manage mailboxes, assigned; change, cancel, retry: additionally may send messages. The permission to send is checked again before sending |
| Edit a draft of another client (`reply` on message) | User is assigned **and** may send messages |
| Manage folders (`manageFolders` on mailbox) | User **may manage folders** (default: may manage mailboxes) |
| Move messages to another folder (`update` on message) | User is assigned |
| Mailbox signatures (`MailboxSignaturePolicy`) | User **may manage** mailboxes |
| Personal signatures, choosing a signature | Owner, assigned to the mailbox |
| Manage the template catalogue (`MailboxTemplatePolicy`) | User **may manage templates** (default: may manage mailboxes) |
| Insert templates | User may send messages in the mailbox; only templates of the mailbox or global ones |
| Manage the tag catalogue (`MailboxTagPolicy`) | User **may manage tags** (default: may manage mailboxes) |
| Tag messages (`tag` on message) | User is assigned **and** may tag messages |
| Sync history, mailbox alerts (`MailboxAlertPolicy`) | User **may manage** mailboxes |
| Mailbox health page, its widgets and actions | User **may manage** mailboxes (synchronising from there does not require an assignment) |
| Statistics page, widgets and CSV export | User **may manage** mailboxes (all mailboxes); with `statistics.visible_to = assigned_users` also other users for their assigned mailboxes |
| Mailbox settings, latest notifications, push subscriptions | Signed-in user, own data only; mailbox preferences only for assigned mailboxes |
| Offline copy (page, device endpoints) | Signed-in user, own devices only; messages of assigned active mailboxes in the selection (`view` on the message) |
| Metrics endpoint | Bearer token from `monitoring.prometheus.token`, no panel session |
| Download attachment | User may `view` the message; otherwise 404 |

Managing does not grant reading: an administrator who configures mailboxes has to be assigned to a
mailbox before its messages become visible.

## Configuring "may manage / send / delete"

Each ability is resolved in this order:

1. A closure passed to the plugin:

   ```php
   FilamentMailboxPlugin::make()
       ->canManageMailboxes(fn ($user) => $user->is_admin)
       ->canSendMessages(fn ($user) => ! $user->is_readonly)
       ->canDeleteMessages(fn ($user) => $user->hasRole('support'));
   ```

2. A Gate with the same name, if the application defines one:
   `manage-mailboxes`, `send-mailbox-messages`, `delete-mailbox-messages`, `manage-mailbox-folders`, `manage-mailbox-tags`, `manage-mailbox-templates`,
   `tag-mailbox-messages`.
3. Otherwise the ability is **granted** to every panel user.

Restrict panel access itself with Filament's `FilamentUser::canAccessPanel()`.

## Security measures

- Mailbox passwords are stored with Laravel's `encrypted` cast and hidden from serialisation and form state.
- Connection and sync errors are redacted before they are logged, stored or shown.
- Folders and messages are always resolved through the current mailbox, so crafted IDs lead to 404.
- Bulk actions authorise every selected record individually.
- HTML mail is sanitised with Symfony HtmlSanitizer and rendered in a sandboxed iframe with
  `default-src 'none'`. Messages in the spam folder are rendered without links.
- Composed HTML is sanitised on the server with a narrow allow-list; inline images are only read from the
  user's own upload directory, remote images are removed (see [Composing](composing.md#sanitising)).
- Attachments are stored under random paths on a private disk and downloaded with
  `Content-Type: application/octet-stream`, `X-Content-Type-Options: nosniff` and a sanitised filename.
