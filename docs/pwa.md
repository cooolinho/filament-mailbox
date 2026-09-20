# Progressive Web App

The panel with the plugin can be installed as an app (desktop, Android, iOS): own window and icon, start in the
inbox, app shortcuts, faster starts through cached static assets, an offline page, and the service worker used by
[desktop notifications](notifications.md) for push.

```php
FilamentMailboxPlugin::make()
    ->pwa()                                   // or MAILBOX_PWA=true
    ->pwaName('Support Mail')                 // default: filament-mailbox.pwa.name, then app.name
    ->pwaIcons([                              // optional, URLs
        'icon-192' => asset('pwa/192.png'),
        'icon-512' => asset('pwa/512.png'),
        'maskable-512' => asset('pwa/maskable.png'),
        'apple-touch-icon' => asset('pwa/apple.png'),
    ])
    ->pwaStartUrl(url('/admin/mailboxes'));   // default: inbox of the first assigned mailbox
```

HTTPS is required (browsers allow service workers on `localhost` without it).

## Endpoints

All below the panel path (`/admin` in the examples):

| Route | Auth | Content |
|---|---|---|
| `GET /admin/filament-mailbox/manifest.webmanifest` | public | Name, short name, `scope` = panel path, `start_url`, `display: standalone`, theme colour (panel primary 500), background colour, icons 192/512 + maskable, shortcuts |
| `GET /admin/filament-mailbox-sw.js` | public | Service worker, `Service-Worker-Allowed: /admin/`, `Cache-Control: no-cache` |
| `GET /admin/filament-mailbox/offline` | public | Static offline page |
| `GET /admin/filament-mailbox/pwa/{icon}.png` | public | Default icons (`icon-192`, `icon-512`, `maskable-512`, `apple-touch-icon`) |
| `GET /admin/filament-mailbox/launch/{inbox,compose}` | panel login | Start URL and shortcuts: inbox of the first assigned active mailbox (`?action=compose` opens the compose form), otherwise the mailbox list |

Browsers load manifests without cookies, so the public endpoints contain no personal data and answer 404 while the
PWA is disabled. Head tags (`<link rel="manifest">`, `theme-color`, Apple meta tags), the service worker registration
and an *Install app* button (shown next to the user menu when the browser offers installation) are added through
render hooks of the panel.

## Caching

| Request | Strategy |
|---|---|
| Page navigations | Network only; without connection the offline page |
| Same-origin `GET` below `/css/`, `/js/`, `/fonts/`, `/build/` and the PWA icons | Stale-while-revalidate in `fm-static-<version>` |
| Everything else (Livewire, attachments, downloads, APIs) | Not handled by the service worker |

Authenticated content is never cached (shared devices). The version changes with the service worker, the Vite
manifest, Filament's published assets and `pwa.cache_version`; old caches are removed on activation.

**Logout** removes the `fm-*` caches, tells the service worker to do the same and unsubscribes push on this device.

## Checklist

- Chrome DevTools → Application → Manifest: no errors, installable.
- Application → Cache Storage: only css/js/fonts/build/icon responses in `fm-static-*`.
- Network offline → reload: offline page.
- Logout → Cache Storage empty.
- iOS: "Add to Home Screen" in Safari; web push only for installed apps (iOS 16.4+).

## Configuration

```php
'pwa' => [
    'enabled' => false,                  // MAILBOX_PWA, or ->pwa() on the plugin
    'name' => null,                      // MAILBOX_PWA_NAME, default app.name
    'short_name' => 'Mail',              // MAILBOX_PWA_SHORT_NAME
    'background_color' => '#ffffff',
    'cache_version' => null,             // MAILBOX_PWA_CACHE_VERSION, change to drop cached assets
],
```

## Offline copy

With `offline.enabled` the service worker also caches the shell of the offline mailbox and shows it for panel
navigations without connection. See [Offline copy](offline.md).
