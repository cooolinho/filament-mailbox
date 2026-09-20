{{-- Service worker of the mailbox PWA. Caches versioned static assets only - never pages, Livewire requests or attachments. --}}
const VERSION = @js($version);
const CACHE = @js(\Cooolinho\FilamentMailbox\Pwa\Pwa::CACHE_PREFIX) + VERSION;
const OFFLINE_URL = @js($offlineUrl);
const OFFLINE_APP_URL = @js($offlineAppUrl ?? null);
const PRECACHE = [OFFLINE_URL, ...(OFFLINE_APP_URL ? [OFFLINE_APP_URL] : []), ...@js($icons)];
const ICON = @js($icons[0] ?? null);
const LAUNCH_URL = @js($launchUrl);
const NOTIFICATIONS_URL = @js($notificationsUrl);
const FALLBACK_TITLE = @js(__('filament-mailbox::mailbox.notifications.push_fallback'));

// Allowlist of static paths (paths, not URLs: Filament appends version query strings).
const STATIC_PATHS = [/^\/css\//, /^\/js\//, /^\/fonts\//, /^\/build\//, /\/filament-mailbox\/pwa\/[a-z0-9-]+\.png$/]

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()))
})

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('fm-static-') && key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    )
})

const isStatic = (url) =>
    url.origin === self.location.origin &&
    ! url.pathname.includes('/livewire') &&
    ! url.pathname.includes('/filament-mailbox/attachments') &&
    STATIC_PATHS.some((pattern) => pattern.test(url.pathname))

self.addEventListener('fetch', (event) => {
    const request = event.request

    if (request.method !== 'GET') {
        return
    }

    // Pages: always from the network, the offline page without connection.
    // The shell of the offline copy contains no personal data and is kept up to date in the cache.
    if (request.mode === 'navigate') {
        const shell = OFFLINE_APP_URL && new URL(request.url).pathname === new URL(OFFLINE_APP_URL).pathname

        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (shell && response.ok) {
                        const copy = response.clone()
                        caches.open(CACHE).then((cache) => cache.put(OFFLINE_APP_URL, copy))
                    }

                    return response
                })
                .catch(() => caches.match(shell ? OFFLINE_APP_URL : OFFLINE_URL)),
        )

        return
    }

    const url = new URL(request.url)

    if (! isStatic(url)) {
        return
    }

    // Stale-while-revalidate for static assets.
    event.respondWith(
        caches.open(CACHE).then((cache) =>
            cache.match(request).then((cached) => {
                const network = fetch(request)
                    .then((response) => {
                        if (response.ok && response.type === 'basic') {
                            cache.put(request, response.clone())
                        }

                        return response
                    })
                    .catch(() => cached)

                return cached ?? network
            }),
        ),
    )
})

self.addEventListener('message', (event) => {
    if (event.data?.type === 'logout') {
        event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key.startsWith('fm-')).map((key) => caches.delete(key)))))
    }
})

// Web push without payload: load the unread mail notifications of the signed-in user.
self.addEventListener('push', (event) => {
    const fallback = () => self.registration.showNotification(FALLBACK_TITLE, { icon: ICON, tag: 'filament-mailbox', data: { url: LAUNCH_URL } })

    event.waitUntil(
        fetch(NOTIFICATIONS_URL, { credentials: 'include', headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => {
                const items = data?.notifications ?? []

                if (! items.length) {
                    return fallback()
                }

                return Promise.all(items.slice(-3).map((item) => self.registration.showNotification(item.title, {
                    body: item.body ?? undefined,
                    icon: ICON,
                    tag: 'filament-mailbox-' + item.id,
                    data: { url: item.url },
                })))
            })
            .catch(fallback),
    )
})

self.addEventListener('notificationclick', (event) => {
    event.notification.close()

    const url = event.notification.data?.url || LAUNCH_URL

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const client = windows.find((candidate) => 'focus' in candidate)

            if (client) {
                return client.focus().then(() => client.navigate?.(url))
            }

            return self.clients.openWindow(url)
        }),
    )
})
