# Tags

Tags are **app-only** categories for team workflows — e.g. *Open*, *Waiting for customer*, *Accounting*. They
never reach the mail server and work the same for every provider. (Server-side tags are [Labels](labels.md).)

## Catalogue

*Tags* in the plugin navigation group (`MailboxTagResource`): name, colour (fixed `LabelColor` list),
description, and an optional mailbox. Tags without a mailbox are available in every mailbox; tags of a mailbox
only there. Names are unique per mailbox and among the global tags. Drag rows to change the order.

## Usage

- **Table** — tag badges, filter by one or more tags, bulk *Add tag* / *Remove tag*.
- **Row and message page** — *Tags* action with the tags available for the mailbox; the message page shows
  the tags below the subject.

All tag actions are hidden while no tag is available for the mailbox.

## Stable assignments

Messages are soft-deleted and imported again when the server changes their identity (e.g. UIDVALIDITY
change, messages moved by another client). Every assignment therefore also stores a `message_key`:
`sha1(mailbox_id | Message-ID)`, or `sha1(mailbox_id | sender | date | subject)` for messages without a
Message-ID header (heuristic). After importing a message, `TagService::reattach()` moves the assignments of
deleted copies with the same key to the new message.

Assignments of deleted messages are kept for that purpose. Remove old ones with

```bash
php artisan mailbox:prune-tag-assignments --days=30
```

## Authorization

```php
FilamentMailboxPlugin::make()
    ->canManageTags(fn ($user) => $user->is_admin)       // default: may manage mailboxes
    ->canTagMessages(fn ($user) => ! $user->is_readonly); // default: everyone assigned
```

Instead of closures, Gates named `manage-mailbox-tags` and `tag-mailbox-messages` can be defined.

## Security

- Tag ids from forms are resolved against the tags available for the mailbox again when saving.
- `TagService` rejects tags of another mailbox (`InvalidArgumentException`).
- Bulk actions authorise every selected message (`tag` ability).

## Events

`MessageTagsChanged` (`mailbox`, `messageIds`) is dispatched whenever assignments change, e.g. to reindex search.
