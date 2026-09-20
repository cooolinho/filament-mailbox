<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('filament-mailbox::mailbox.offline.app.title') }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f8fafc; --panel: #fff; --text: #0f172a; --muted: #64748b; --line: #e2e8f0; --accent: #d97706; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0b1120; --panel: #111827; --text: #e2e8f0; --muted: #94a3b8; --line: #1f2937; } }
        * { box-sizing: border-box; }
        body { margin: 0; font: 14px/1.5 system-ui, sans-serif; background: var(--bg); color: var(--text); }
        header { display: flex; gap: 1rem; align-items: center; padding: 0.5rem 1rem; background: var(--panel); border-bottom: 1px solid var(--line); }
        header img { width: 2rem; height: 2rem; border-radius: 0.5rem; }
        header h1 { font-size: 1rem; margin: 0; flex: 1; }
        .status { font-size: 0.8rem; padding: 0.125rem 0.5rem; border-radius: 999px; background: color-mix(in oklab, var(--accent) 18%, transparent); }
        .status.online { background: color-mix(in oklab, #16a34a 18%, transparent); }
        main { display: grid; grid-template-columns: 14rem 22rem 1fr; height: calc(100vh - 3rem); }
        @media (max-width: 64rem) { main { grid-template-columns: 1fr; height: auto; } }
        nav, .list, .reader { overflow: auto; border-right: 1px solid var(--line); }
        nav h2 { font-size: 0.75rem; text-transform: uppercase; color: var(--muted); margin: 1rem 1rem 0.25rem; }
        button.item { display: block; width: 100%; text-align: left; border: 0; background: none; color: inherit; font: inherit; padding: 0.5rem 1rem; cursor: pointer; }
        button.item[aria-current="true"] { background: color-mix(in oklab, var(--accent) 14%, transparent); }
        .list button.item { border-bottom: 1px solid var(--line); }
        .list .unread { font-weight: 700; }
        .muted { color: var(--muted); }
        .ellipsis { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .reader { padding: 1rem; }
        .reader h2 { margin: 0 0 0.5rem; font-size: 1.125rem; }
        .reader dl { display: grid; grid-template-columns: max-content 1fr; gap: 0.125rem 0.75rem; margin: 0 0 1rem; }
        .reader dt { color: var(--muted); }
        .reader dd { margin: 0; }
        .reader iframe { width: 100%; min-height: 60vh; border: 1px solid var(--line); border-radius: 0.5rem; background: #fff; }
        .reader pre { white-space: pre-wrap; font: inherit; }
        .attachments a, .attachments span { display: inline-block; margin: 0 0.5rem 0.5rem 0; }
        .empty { padding: 2rem; text-align: center; color: var(--muted); }
        a { color: var(--accent); }
    </style>
</head>
<body>
    <header>
        <img src="{{ $icon }}" alt="">
        <h1>{{ __('filament-mailbox::mailbox.offline.app.title') }}</h1>
        <span class="status" id="status"></span>
        <a href="{{ $panelUrl }}">{{ __('filament-mailbox::mailbox.offline.app.open_panel') }}</a>
    </header>
    <main id="app">
        <nav id="folders" aria-label="{{ __('filament-mailbox::mailbox.offline.app.folders') }}"></nav>
        <section class="list" id="messages" aria-label="{{ __('filament-mailbox::mailbox.offline.app.messages') }}"></section>
        <section class="reader" id="reader" aria-live="polite"></section>
    </main>

    @include('filament-mailbox::offline.client')

    <script>
        (async () => {
            const lib = window.FilamentMailboxOffline
            const T = @js([
                'empty' => __('filament-mailbox::mailbox.offline.app.empty'),
                'not_set_up' => __('filament-mailbox::mailbox.offline.app.not_set_up'),
                'select' => __('filament-mailbox::mailbox.offline.app.select'),
                'offline' => __('filament-mailbox::mailbox.offline.app.offline'),
                'online' => __('filament-mailbox::mailbox.offline.app.online'),
                'synced' => __('filament-mailbox::mailbox.offline.app.synced'),
                'from' => __('filament-mailbox::mailbox.messages.fields.from'),
                'to' => __('filament-mailbox::mailbox.messages.fields.to'),
                'cc' => __('filament-mailbox::mailbox.messages.fields.cc'),
                'date' => __('filament-mailbox::mailbox.messages.fields.date'),
                'no_subject' => __('filament-mailbox::mailbox.messages.no_subject'),
                'not_offline' => __('filament-mailbox::mailbox.offline.app.attachment_online'),
            ]);

            const el = (tag, attributes = {}, ...children) => {
                const node = document.createElement(tag)

                Object.entries(attributes).forEach(([name, value]) => name.startsWith('on') ? node.addEventListener(name.slice(2), value) : node.setAttribute(name, value))
                children.flat().forEach((child) => node.append(child instanceof Node ? child : document.createTextNode(child ?? '')))

                return node
            }

            const format = (date) => date ? new Date(date).toLocaleString() : ''
            const folders = document.getElementById('folders')
            const list = document.getElementById('messages')
            const reader = document.getElementById('reader')
            const status = document.getElementById('status')

            const showStatus = async () => {
                const synced = (await lib.get('meta', 'synced_at').catch(() => null))?.value

                status.textContent = (navigator.onLine ? T.online : T.offline) + (synced ? ' · ' + T.synced.replace(':time', format(synced)) : '')
                status.classList.toggle('online', navigator.onLine)
            }

            window.addEventListener('online', showStatus)
            window.addEventListener('offline', showStatus)

            const key = lib.device() && window.crypto?.subtle ? await lib.key().catch(() => null) : null
            const manifestRecord = key ? await lib.get('meta', 'manifest').catch(() => null) : null

            await showStatus()

            if (! key || ! manifestRecord) {
                document.getElementById('app').replaceChildren(el('p', { class: 'empty' }, T.not_set_up))

                return
            }

            const manifest = await lib.decrypt(key, manifestRecord)

            const openMessage = async (record, button) => {
                list.querySelectorAll('[aria-current]').forEach((node) => node.removeAttribute('aria-current'))
                button.setAttribute('aria-current', 'true')

                const message = await lib.decrypt(key, record)
                const attachments = el('div', { class: 'attachments' })

                for (const attachment of message.attachments) {
                    const stored = attachment.offline ? await lib.get('attachments', attachment.id) : null

                    if (! stored) {
                        attachments.append(el('span', { class: 'muted', title: T.not_offline }, attachment.filename))

                        continue
                    }

                    attachments.append(el('a', {
                        href: '#',
                        onclick: async (event) => {
                            event.preventDefault()

                            // Downloaded as file, never rendered.
                            const blob = new Blob([await lib.decrypt(key, stored, true)], { type: 'application/octet-stream' })
                            const link = el('a', { href: URL.createObjectURL(blob), download: attachment.filename })

                            link.click()
                            setTimeout(() => URL.revokeObjectURL(link.href), 1000)
                        },
                    }, attachment.filename))
                }

                const body = message.html !== null
                    // Sanitised on the server, with its CSP; no scripts, no same origin.
                    ? Object.assign(el('iframe', { sandbox: '', referrerpolicy: 'no-referrer', title: message.subject || T.no_subject }), { srcdoc: message.html })
                    : el('pre', {}, message.text || '')

                reader.replaceChildren(
                    el('h2', {}, message.subject || T.no_subject),
                    el('dl', {},
                        el('dt', {}, T.from), el('dd', {}, message.from || ''),
                        el('dt', {}, T.to), el('dd', {}, message.to.join(', ')),
                        ...(message.cc.length ? [el('dt', {}, T.cc), el('dd', {}, message.cc.join(', '))] : []),
                        el('dt', {}, T.date), el('dd', {}, format(message.date)),
                    ),
                    attachments,
                    body,
                )
            }

            const openFolder = async (folder, button) => {
                folders.querySelectorAll('[aria-current]').forEach((node) => node.removeAttribute('aria-current'))
                button.setAttribute('aria-current', 'true')
                reader.replaceChildren(el('p', { class: 'empty' }, T.select))

                const records = (await lib.byIndex('messages', 'folder', folder.id)).sort((a, b) => (b.date || '').localeCompare(a.date || ''))

                if (! records.length) {
                    list.replaceChildren(el('p', { class: 'empty' }, T.empty))

                    return
                }

                list.replaceChildren()

                for (const record of records) {
                    const summary = await lib.decrypt(key, record)
                    const item = el('button', { class: 'item' + (summary.is_read ? '' : ' unread'), type: 'button' },
                        el('div', { class: 'ellipsis' }, summary.from || ''),
                        el('div', { class: 'ellipsis' }, summary.subject || T.no_subject),
                        el('div', { class: 'ellipsis muted' }, format(summary.date)),
                    )

                    item.addEventListener('click', () => openMessage(record, item))
                    list.append(item)
                }
            }

            let first = null

            for (const mailbox of manifest.mailboxes) {
                folders.append(el('h2', {}, mailbox.name))

                for (const folder of mailbox.folders) {
                    const button = el('button', { class: 'item', type: 'button' }, folder.name)

                    button.addEventListener('click', () => openFolder(folder, button))
                    folders.append(button)
                    first ??= [folder, button]
                }
            }

            first ? openFolder(...first) : list.replaceChildren(el('p', { class: 'empty' }, T.empty))
        })()
    </script>
</body>
</html>
