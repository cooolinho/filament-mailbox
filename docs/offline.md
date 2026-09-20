# Offline copy

Recently synchronised messages can be read without a connection — on a train, in a plane. This is **stage 1** of
the offline plan: a read-only copy. Actions and drafts made offline (stages 2 and 3) are not included.

Filament renders on the server, so there is no panel without a connection. The offline copy is a small separate
app shell (`/{panel}/filament-mailbox/offline/app`) that reads an encrypted local copy. It requires the
[Progressive Web App](pwa.md) and is **off by default** (`offline.enabled`).

> Message contents leave the server permanently and are stored on end devices. Only enable the feature if that is
> acceptable for your organisation (consider a data protection impact assessment) and tell users to use it on their
> own devices only.

## Using it

1. *Offline availability* in the user menu: choose folders of assigned mailboxes, the period (days), the number of
   messages per folder and whether small attachments are stored.
2. **Make available offline on this device** registers the browser (opt-in per device) and starts the first
   synchronisation. *Save selection* changes it, *Remove from this device* deletes the copy.
3. Without a connection, navigations inside the panel show the offline mailbox; the offline page links to it.
   The shell shows folders, the message list and the message with its attachments, and a banner *Offline · as of …*.

The list *Devices with an offline copy* shows every registered browser with its last update. **Revoke** deletes the
copy on that device the next time it is online.

## How it works

| Part | Behaviour |
|---|---|
| Registration | `mailbox_offline_devices`: device UUID, label (user agent), selection, a random 256-bit key (encrypted at rest). The browser keeps the UUID in `localStorage` |
| Synchronisation | `offline/sync` script on panel pages (only after the opt-in on this device): every `offline.sync_minutes` and when the tab becomes visible. It loads the manifest, then per folder the messages changed since its cursor and the ids of all messages that belong to the copy; everything else is removed locally |
| Local store | IndexedDB `filament-mailbox-offline` (`meta`, `keys`, `messages`, `attachments`). Contents are encrypted with AES-GCM (random IV per record); the key is imported as **non-extractable** `CryptoKey`. Folder id and date stay unencrypted for sorting |
| Shell | Public page without personal data, cached by the service worker. HTML bodies are the documents sanitised on the server (`HtmlBodySanitizer::document()`, with CSP, strict for spam), rendered in a sandboxed `srcdoc` iframe; attachments are only downloaded as files |
| Removal | Logout (the PWA logout handler), *Remove from this device*, revoked device, another user signing in on the browser, folders or mailboxes that leave the selection or are no longer accessible |

### Endpoints

All authenticated (panel session) with the header `X-Mailbox-Offline-Device`, throttled, `Cache-Control: no-store`.
Unknown, foreign or revoked devices get `403 {"revoked": true}` — the browser deletes its copy.

| Endpoint | Returns |
|---|---|
| `GET filament-mailbox/offline/key` | The key of the device |
| `GET filament-mailbox/offline/manifest` | Selected active folders of assigned, active mailboxes and the limits |
| `GET filament-mailbox/offline/changes?folder=&since=` | `ids` (newest messages of the folder within days and number), `messages` changed since the cursor (addresses, subject, flags, text or sanitised HTML, attachment list), next `cursor` |
| `GET filament-mailbox/offline/attachments/{id}` | An attachment up to `offline.max_attachment_size`, when attachments are selected; never from the spam folder |

The same rules as in the panel apply: only assigned users (`view` on the message), active mailboxes and folders;
folders outside the selection answer 404.

## Limits and risks

- The encryption protects against simply reading the browser database, not against malware running in the browser
  or a signed-in attacker.
- Browsers may evict storage (especially Safari). The page requests persistent storage; a missing copy is loaded
  again with the next synchronisation.
- The copy is as current as the last synchronisation while the panel was open.
- Offline actions (read, delete, archive), offline drafts and full-text search are not included.

## Manual browser checklist

1. Enable the PWA and `offline.enabled`, open *Offline availability*, select the inbox, enable on this device.
2. DevTools → Application → IndexedDB `filament-mailbox-offline`: messages contain `iv` and encrypted `data` only.
3. Switch the network to offline, reload a panel page: the offline mailbox shows the inbox; open a message with HTML
   and one with an attachment.
4. Online again: revoke the device on another browser/profile, return to the first one: the copy is deleted.
5. Log out: IndexedDB database and `localStorage` entry are gone.
