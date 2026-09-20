{{--
    Local store of the offline copy: IndexedDB, contents encrypted with AES-GCM using a non-extractable key of the
    device. Shared by the sync script (panel pages), the settings page and the offline app shell.
--}}
<script>
    window.FilamentMailboxOffline ??= (() => {
        const DB = 'filament-mailbox-offline'
        const DEVICE = 'filament-mailbox-offline-device'
        const STORES = ['meta', 'keys', 'messages', 'attachments']

        const request = (req) => new Promise((resolve, reject) => {
            req.onsuccess = () => resolve(req.result)
            req.onerror = () => reject(req.error)
        })

        let connection = null

        const open = () => connection ??= new Promise((resolve, reject) => {
            const req = indexedDB.open(DB, 1)

            req.onupgradeneeded = () => {
                const db = req.result

                db.createObjectStore('meta', { keyPath: 'id' })
                db.createObjectStore('keys', { keyPath: 'id' })
                db.createObjectStore('messages', { keyPath: 'id' }).createIndex('folder', 'folder')
                db.createObjectStore('attachments', { keyPath: 'id' }).createIndex('message', 'message')
            }

            req.onsuccess = () => resolve(req.result)
            req.onerror = () => reject(req.error)
        })

        const store = async (name, mode = 'readonly') => (await open()).transaction(name, mode).objectStore(name)

        const lib = {
            DB,

            device() {
                try {
                    return JSON.parse(localStorage.getItem(DEVICE) || 'null')
                } catch (error) {
                    return null
                }
            },

            saveDevice(device) {
                localStorage.setItem(DEVICE, JSON.stringify(device))
            },

            async wipe() {
                localStorage.removeItem(DEVICE)

                if (connection) {
                    (await connection).close()
                    connection = null
                }

                await request(indexedDB.deleteDatabase(DB)).catch(() => {})
            },

            get: async (name, id) => request((await store(name)).get(id)),
            put: async (name, value) => request((await store(name, 'readwrite')).put(value)),
            delete: async (name, id) => request((await store(name, 'readwrite')).delete(id)),
            all: async (name) => request((await store(name)).getAll()),
            byIndex: async (name, index, value) => request((await store(name)).index(index).getAll(value)),

            async importKey(base64) {
                const bytes = Uint8Array.from(atob(base64), (char) => char.charCodeAt(0))

                // Not extractable: scripts can use the key, but cannot read it.
                return crypto.subtle.importKey('raw', bytes, 'AES-GCM', false, ['encrypt', 'decrypt'])
            },

            async key() {
                return (await lib.get('keys', 'device'))?.key ?? null
            },

            async encrypt(key, value) {
                const iv = crypto.getRandomValues(new Uint8Array(12))
                const data = value instanceof ArrayBuffer ? value : new TextEncoder().encode(JSON.stringify(value))

                return { iv, data: await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, data) }
            },

            async decrypt(key, record, binary = false) {
                const data = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: record.iv }, key, record.data)

                return binary ? data : JSON.parse(new TextDecoder().decode(data))
            },
        }

        return lib
    })()
</script>
