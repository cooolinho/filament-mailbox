@include('filament-mailbox::offline.client')

{{-- Updates the offline copy of this device while the panel is open (only after the opt-in on this device). --}}
<script>
    (() => {
        if (window.filamentMailboxOfflineSync) {
            return
        }

        const BASE = @js($baseUrl);
        const USER = @js($user);
        const INTERVAL = @js($interval) * 60000;
        const lib = window.FilamentMailboxOffline
        const state = window.filamentMailboxOfflineSync = { running: false }

        const call = async (path, device, binary = false) => {
            const response = await fetch(BASE + path, {
                credentials: 'same-origin',
                headers: { Accept: binary ? '*/*' : 'application/json', 'X-Mailbox-Offline-Device': device.uuid },
            })

            if (response.status === 403) {
                throw { revoked: true }
            }

            if (! response.ok) {
                throw { status: response.status }
            }

            return binary ? response.arrayBuffer() : response.json()
        }

        const removeMessage = async (id) => {
            for (const attachment of await lib.byIndex('attachments', 'message', id)) {
                await lib.delete('attachments', attachment.id)
            }

            await lib.delete('messages', id)
        }

        const sync = async () => {
            const device = lib.device()

            if (! device || state.running || ! navigator.onLine || ! window.crypto?.subtle) {
                return
            }

            // Another user signed in on this browser: the copy of the previous user is removed.
            if (device.user !== USER) {
                return lib.wipe()
            }

            state.running = true

            try {
                let key = await lib.key()

                if (! key) {
                    key = await lib.importKey((await call('/key', device)).key)
                    await lib.put('keys', { id: 'device', key })
                }

                const manifest = await call('/manifest', device)
                const folders = manifest.mailboxes.flatMap((mailbox) => mailbox.folders.map((folder) => folder.id))

                await lib.put('meta', { id: 'manifest', ...(await lib.encrypt(key, manifest)) })

                // Folders that left the selection or may no longer be read.
                for (const message of await lib.all('messages')) {
                    if (! folders.includes(message.folder)) {
                        await removeMessage(message.id)
                    }
                }

                for (const folder of folders) {
                    const cursor = (await lib.get('meta', 'cursor:' + folder))?.value
                    const changes = await call('/changes?folder=' + folder + (cursor ? '&since=' + encodeURIComponent(cursor) : ''), device)
                    const keep = new Set(changes.ids)

                    for (const id of await lib.byIndex('messages', 'folder', folder).then((items) => items.map((item) => item.id))) {
                        if (! keep.has(id)) {
                            await removeMessage(id)
                        }
                    }

                    for (const message of changes.messages) {
                        await lib.put('messages', { id: message.id, folder: message.folder, date: message.date, ...(await lib.encrypt(key, message)) })

                        for (const attachment of message.attachments.filter((item) => item.offline)) {
                            if (await lib.get('attachments', attachment.id)) {
                                continue
                            }

                            const data = await call('/attachments/' + attachment.id, device, true)
                            await lib.put('attachments', { id: attachment.id, message: message.id, ...(await lib.encrypt(key, data)) })
                        }
                    }

                    await lib.put('meta', { id: 'cursor:' + folder, value: changes.cursor })
                }

                await lib.put('meta', { id: 'synced_at', value: new Date().toISOString() })
                window.dispatchEvent(new CustomEvent('filament-mailbox-offline-synced'))
            } catch (error) {
                if (error?.revoked) {
                    await lib.wipe()
                    window.dispatchEvent(new CustomEvent('filament-mailbox-offline-revoked'))
                }
            } finally {
                state.running = false
            }
        }

        state.sync = sync

        window.addEventListener('filament-mailbox-offline-sync', sync)
        document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && sync())
        setInterval(sync, INTERVAL)
        setTimeout(sync, 3000)
    })()
</script>
