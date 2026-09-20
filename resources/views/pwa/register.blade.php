<script>
    (() => {
        if (! ('serviceWorker' in navigator) || window.filamentMailboxPwa) {
            return
        }

        window.filamentMailboxPwa = { installPrompt: null }

        navigator.serviceWorker.register(@js($serviceWorkerUrl), { scope: @js($scope) }).catch(() => {});

        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault()
            window.filamentMailboxPwa.installPrompt = event
            window.dispatchEvent(new CustomEvent('filament-mailbox-pwa-installable'))
        })

        window.filamentMailboxPwa.install = async () => {
            const prompt = window.filamentMailboxPwa.installPrompt

            if (! prompt) {
                return
            }

            prompt.prompt()
            await prompt.userChoice
            window.filamentMailboxPwa.installPrompt = null
            window.dispatchEvent(new CustomEvent('filament-mailbox-pwa-installed'))
        }

        // Logout: remove cached assets and push subscriptions of this device (shared devices).
        const logoutUrl = @js($logoutUrl);

        document.addEventListener('submit', (event) => {
            const form = event.target

            if (! (form instanceof HTMLFormElement) || form.action !== logoutUrl) {
                return
            }

            window.caches?.keys().then((keys) => keys.filter((key) => key.startsWith('fm-')).forEach((key) => caches.delete(key)))
            // The offline copy is removed as well.
            window.FilamentMailboxOffline?.wipe() ?? (localStorage.removeItem('filament-mailbox-offline-device'), indexedDB.deleteDatabase('filament-mailbox-offline'))
            navigator.serviceWorker.controller?.postMessage({ type: 'logout' })
            navigator.serviceWorker.ready
                .then((registration) => registration.pushManager?.getSubscription())
                .then((subscription) => subscription?.unsubscribe())
                .catch(() => {})
        }, true)
    })()
</script>
