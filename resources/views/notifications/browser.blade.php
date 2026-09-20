{{-- Shows new mailbox notifications as operating system notifications while the panel is open in a background tab. --}}
<script>
    (() => {
        if (! ('Notification' in window) || window.filamentMailboxNotifications) {
            return
        }

        window.filamentMailboxNotifications = true

        const endpoint = @js($endpoint);
        const icon = @js($icon);
        const storageKey = 'filament-mailbox-notifications-cursor'

        const storage = {
            get: () => { try { return localStorage.getItem(storageKey) } catch (error) { return null } },
            set: (value) => { try { localStorage.setItem(storageKey, value) } catch (error) {} },
        }

        const poll = async () => {
            if (Notification.permission !== 'granted') {
                return
            }

            const cursor = storage.get()
            const response = await fetch(endpoint + (cursor ? '?since=' + encodeURIComponent(cursor) : '?init=1'), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            }).catch(() => null)

            if (! response?.ok) {
                return
            }

            const data = await response.json()
            storage.set(data.cursor)

            // The panel shows its own notifications while the tab is visible.
            if (! cursor || ! document.hidden) {
                return
            }

            for (const item of data.notifications) {
                const notification = new Notification(item.title, { body: item.body ?? undefined, icon, tag: 'filament-mailbox-' + item.id })

                notification.onclick = () => {
                    window.focus()
                    window.location.href = item.url
                    notification.close()
                }
            }
        }

        poll()
        setInterval(poll, @js($interval) * 1000)
    })()
</script>
