# New mail notifications

Users learn about new e-mails in their assigned mailboxes without keeping the inbox open — in three stages:

1. **Panel notifications** — Filament database notifications (bell icon).
2. **Desktop notifications** while a panel tab is open in the background (browser Notifications API).
3. **Web push** when the panel is closed (optional, needs the [PWA](pwa.md)).

## Requirements

Enable database notifications on the panel, and a `notifications` table (Laravel default) with a `Notifiable` user model:

```php
$panel->databaseNotifications()->databaseNotificationsPolling('30s');
```

```bash
php artisan make:notifications-table && php artisan migrate
```

## When users are notified

`SyncService` dispatches `MessagesImported($folder, $count, $messageIds, $isInitial)` per batch. The queued listener
`NotifyUsersAboutNewMessages` (sync queue) notifies each assigned user

- for newly created, **unread**, non-draft messages,
- **not** for the first synchronisation of a folder or mailbox, nor after a reset (e.g. UIDVALIDITY change),
- only if the user may still view the mailbox (assignment checked again),
- only for the folders of their preference (default `notifications.default_folders` = inbox),
- at most once per `throttle_seconds` (120) per user and mailbox; messages during the pause are added to the next
  notification ("5 new e-mails in Support").

One message: title = sender, body = subject; several: "3 new e-mails in Support" with the first senders. With
*Show sender and subject* turned off, only "New e-mail in Support" is shown. The *Open* button opens the message or
the folder. Errors of notifications never break a synchronisation.

With `notifications.broadcast`, the Filament notification is broadcast as well (needs Laravel Echo and
`->broadcastNotifications()` on the panel).

## Mailbox settings

**Mailbox settings** in the user menu:

- *Notify me about new e-mails* (global),
- *Show sender and subject* (lock screens, shared screens),
- per assigned mailbox: notifications on/off and folders (default: inbox),
- this device: *Allow desktop notifications* (explicit permission request, never automatic) and — with web push —
  *Enable push on this device*.

Stored in `mailbox_user_preferences` (`notifications`, `notifications_show_content`) and `mailbox_user`
(`notify`, `notify_folder_ids`). Only assigned mailboxes and their own folders are accepted.

## Desktop notifications (tab open)

A small script on panel pages polls `GET /admin/filament-mailbox/notifications/latest?since=…` every
`browser_poll_seconds` (30) when the permission is granted, and shows new, unread mail notifications as operating system
notifications while the tab is hidden (a click focuses the tab and opens the message). The endpoint returns only the
signed-in user's unread notifications of this package.

Expect a delay of up to the sync interval plus the poll interval.

## Web push (tab closed)

```bash
php artisan mailbox:vapid-keys
```

```dotenv
MAILBOX_PWA=true
MAILBOX_WEB_PUSH=true
MAILBOX_VAPID_PUBLIC_KEY=...
MAILBOX_VAPID_PRIVATE_KEY=...
MAILBOX_VAPID_SUBJECT=mailto:admin@example.com
```

- Users enable push per device on the settings page; the browser subscription endpoint is stored in
  `mailbox_push_subscriptions` (`POST`/`DELETE /admin/filament-mailbox/push-subscriptions`).
- Pushes are sent **without payload** (VAPID, RFC 8292, signed with ext-openssl — no extra package). The service
  worker then loads the unread notifications of the signed-in user from the endpoint above, so no sender or subject
  passes the push services. Without a session it shows a generic "New e-mail".
- Only endpoints of known push services are accepted (`web_push.allowed_hosts`: Google, Mozilla, Microsoft, Apple),
  because the server sends requests to them. Expired subscriptions (404/410) are removed; logout unsubscribes the device.
- iOS supports web push only for installed apps (iOS 16.4+).

## Data protection

Sender and subject are personal data and may appear on lock screens: users can hide them, and
`show_content_by_default` sets the default. Pushes contain no content.

## Configuration

```php
'notifications' => [
    'enabled' => true,                   // MAILBOX_NOTIFICATIONS
    'default_folders' => ['inbox'],      // special-use roles
    'throttle_seconds' => 120,           // MAILBOX_NOTIFICATIONS_THROTTLE
    'show_content_by_default' => true,
    'panel' => null,                     // panel id for links (default: first panel with the plugin)
    'broadcast' => false,                // MAILBOX_NOTIFICATIONS_BROADCAST
    'browser' => true,                   // MAILBOX_BROWSER_NOTIFICATIONS
    'browser_poll_seconds' => 30,
    'web_push' => [
        'enabled' => false,              // MAILBOX_WEB_PUSH
        'public_key' => null,            // MAILBOX_VAPID_PUBLIC_KEY
        'private_key' => null,           // MAILBOX_VAPID_PRIVATE_KEY
        'subject' => null,               // MAILBOX_VAPID_SUBJECT
        'ttl' => 3600,
        'allowed_hosts' => ['fcm.googleapis.com', 'updates.push.services.mozilla.com', '*.push.services.mozilla.com', '*.notify.windows.com', 'web.push.apple.com', '*.push.apple.com'],
    ],
],
```

## Browser checklist

Allow desktop notifications, hide the tab, receive a mail → OS notification, click opens the message; deny
permission → badge "blocked"; enable push in the installed app, close it, receive a mail → push; logout → push
subscription removed.
