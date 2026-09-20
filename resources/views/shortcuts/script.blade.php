{{--
    Keyboard shortcuts of the mailbox pages. Keys never fire in form fields, with modifier keys or while a
    modal is open. Actions are mounted on the page component, so visibility and authorisation apply as for a
    click; row actions go through BrowseMailbox::shortcut() with the message id.
--}}
<div
    data-filament-mailbox-shortcuts
    data-page="{{ $page }}"
    data-bindings='@json($bindings)'
    data-inbox-url="{{ $inboxUrl }}"
    data-back-url="{{ $backUrl ?? '' }}"
    hidden
></div>

<style>
    tr.fi-mailbox-cursor > td { box-shadow: inset 0 0 0 9999px color-mix(in oklab, var(--primary-500) 10%, transparent); }
    tr.fi-mailbox-cursor > td:first-child { box-shadow: inset 3px 0 0 var(--primary-500), inset 0 0 0 9999px color-mix(in oklab, var(--primary-500) 10%, transparent); }
</style>

<script>
    (() => {
        if (window.filamentMailboxShortcuts) {
            return
        }

        const state = window.filamentMailboxShortcuts = { cursor: null, prefix: null, prefixTimer: null }

        const config = () => {
            const element = document.querySelector('[data-filament-mailbox-shortcuts]')

            if (! element) {
                return null
            }

            try {
                return { ...element.dataset, bindings: JSON.parse(element.dataset.bindings || '{}'), component: element.closest('[wire\\:id]') }
            } catch (error) {
                return null
            }
        }

        const wire = (element) => element ? window.Livewire?.find(element.getAttribute('wire:id')) : null

        const rows = () => [...document.querySelectorAll('tr.fi-mailbox-row')]

        const rowId = (row) => {
            const match = [...row.classList].map((name) => name.match(/^fi-mailbox-message-(\d+)$/)).find(Boolean)

            return match ? Number(match[1]) : null
        }

        const highlight = () => {
            rows().forEach((row) => {
                const active = state.cursor !== null && rowId(row) === state.cursor

                row.classList.toggle('fi-mailbox-cursor', active)
                row.setAttribute('aria-selected', active ? 'true' : 'false')
            })
        }

        const currentRow = () => rows().find((row) => rowId(row) === state.cursor)
            ?? document.querySelector('tr.fi-mailbox-row.fi-mailbox-selected')
            ?? null

        const move = (step, page) => {
            const list = rows()

            if (! list.length) {
                return
            }

            const index = list.indexOf(currentRow())
            const next = list[Math.min(list.length - 1, Math.max(0, index === -1 ? 0 : index + step))]

            state.cursor = rowId(next)
            highlight()
            next.scrollIntoView({ block: 'nearest' })

            // The split view shows the message in the reading pane.
            if (document.querySelector('.fi-mailbox-split-preview')) {
                wire(page.component)?.call('preview', state.cursor)
            }
        }

        const preview = () => wire(document.querySelector('.fi-mailbox-split-preview [wire\\:id]'))

        const isTyping = (target) => target?.closest?.('input, textarea, select, [contenteditable=""], [contenteditable="true"], .fi-fo-rich-editor')

        const combo = (event) => {
            const key = event.key === 'Delete' ? 'del' : event.key.toLowerCase()

            return event.shiftKey && /^[a-z]$/.test(key) ? 'shift+' + key : key
        }

        const run = (action, page, event) => {
            const component = wire(page.component)
            const id = state.cursor ?? (currentRow() ? rowId(currentRow()) : null)
            const messageActions = { reply: 'reply', reply_all: 'replyAll', forward: 'forward', archive: 'archive', star: 'toggleStar', delete: 'deleteMessage', mark_unread: 'markAsUnread' }

            event.preventDefault()

            switch (action) {
                case 'help':
                    return component?.mountAction('shortcutsHelp')
                case 'go_inbox':
                    return page.inboxUrl && (window.location.href = page.inboxUrl)
                case 'back':
                    return page.backUrl && (window.location.href = page.backUrl)
                case 'compose':
                    return component?.mountAction('compose')
                case 'search':
                    return document.querySelector('.fi-ta-search-field input')?.focus()
                case 'next':
                    return move(1, page)
                case 'previous':
                    return move(-1, page)
                case 'open': {
                    const row = currentRow()

                    if (! row) {
                        return
                    }

                    if (document.querySelector('.fi-mailbox-split-preview')) {
                        return component?.call('preview', rowId(row))
                    }

                    const link = row.querySelector('a[href]:not(.fi-dropdown-list-item)')

                    return link && (window.location.href = link.href)
                }
                case 'select':
                    return currentRow()?.querySelector('input.fi-ta-record-checkbox')?.click()
            }

            if (page.page === 'message') {
                return messageActions[action] && component?.mountAction(messageActions[action])
            }

            // Reply and forward work on the message in the reading pane.
            if (['reply', 'reply_all', 'forward'].includes(action)) {
                return preview()?.mountAction(messageActions[action])
            }

            if (id !== null) {
                component?.call('shortcut', action, id)
            }
        }

        document.addEventListener('keydown', (event) => {
            const page = config()

            if (! page || event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || event.isComposing
                || isTyping(event.target) || document.querySelector('.fi-modal.fi-modal-open')) {
                return
            }

            const key = combo(event)

            if (state.prefix) {
                const sequence = state.prefix + ' ' + key

                state.prefix = null
                clearTimeout(state.prefixTimer)

                if (page.bindings[sequence]) {
                    return run(page.bindings[sequence], page, event)
                }
            }

            if (Object.keys(page.bindings).some((binding) => binding.startsWith(key + ' '))) {
                state.prefix = key
                state.prefixTimer = setTimeout(() => state.prefix = null, 1500)
                event.preventDefault()

                return
            }

            if (page.bindings[key]) {
                run(page.bindings[key], page, event)
            }
        })

        // Livewire re-renders the table: keep the highlighted row.
        const hook = () => {
            if (state.hooked || ! window.Livewire?.hook) {
                return
            }

            state.hooked = true
            window.Livewire.hook('commit', ({ succeed }) => succeed(() => queueMicrotask(highlight)))
        }

        hook()
        document.addEventListener('livewire:init', hook)

        document.addEventListener('livewire:navigated', () => {
            state.cursor = null
        })
    })()
</script>
