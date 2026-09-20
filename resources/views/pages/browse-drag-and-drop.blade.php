{{--
    Drag messages from the list onto folders of the sub-navigation. Rows carry their id in a class
    (fi-mailbox-message-<id>), folders in data-filament-mailbox-folder; only integers are rendered.
    The server resolves folder and messages through the mailbox and authorises every message.
--}}
<div
    data-filament-mailbox-dnd
    data-label-one="{{ __('filament-mailbox::mailbox.drag_and_drop.one') }}"
    data-label-many="{{ __('filament-mailbox::mailbox.drag_and_drop.many') }}"
    hidden
></div>

<style>
    tr.fi-mailbox-draggable { cursor: grab; }
    [data-filament-mailbox-folder].fi-mailbox-drop-target > a {
        outline: 2px dashed var(--primary-500);
        outline-offset: -2px;
        background-color: color-mix(in oklab, var(--primary-500) 12%, transparent);
    }
    .fi-mailbox-drag-ghost {
        position: absolute;
        top: -1000px;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        background: var(--primary-600);
        color: #fff;
        font-size: 0.875rem;
        font-weight: 600;
    }
</style>

<script>
    (() => {
        if (window.filamentMailboxDnd) {
            return
        }

        const type = 'application/x-filament-mailbox-messages'

        const messageId = (row) => {
            const match = [...row.classList].map((name) => name.match(/^fi-mailbox-message-(\d+)$/)).find(Boolean)

            return match ? match[1] : null
        }

        const labels = () => document.querySelector('[data-filament-mailbox-dnd]')?.dataset ?? {}

        window.filamentMailboxDnd = { dragging: false }

        // Rows become draggable when the pointer is on them, so links and actions keep working.
        document.addEventListener('pointerdown', (event) => {
            const row = event.target.closest?.('tr.fi-mailbox-draggable')

            if (row && ! event.target.closest('input, button, label, .fi-dropdown-panel')) {
                row.setAttribute('draggable', 'true')
            }
        })

        document.addEventListener('dragstart', (event) => {
            const row = event.target.closest?.('tr.fi-mailbox-draggable')
            const id = row ? messageId(row) : null

            if (! id) {
                return
            }

            let selected = []

            try {
                selected = [...(window.Alpine?.$data(row)?.selectedRecords ?? [])].map(String)
            } catch (error) {}

            // A selected row drags the whole selection.
            const ids = selected.includes(id) ? selected : [id]

            event.dataTransfer.effectAllowed = 'move'
            event.dataTransfer.setData(type, JSON.stringify(ids))

            const ghost = document.createElement('div')
            ghost.className = 'fi-mailbox-drag-ghost'
            ghost.textContent = ids.length === 1 ? labels().labelOne : (labels().labelMany ?? '').replace(':count', ids.length)
            document.body.appendChild(ghost)
            event.dataTransfer.setDragImage(ghost, 10, 10)
            setTimeout(() => ghost.remove())

            window.filamentMailboxDnd.dragging = true
        })

        document.addEventListener('dragend', (event) => {
            window.filamentMailboxDnd.dragging = false
            event.target.closest?.('tr.fi-mailbox-draggable')?.removeAttribute('draggable')
            document.querySelectorAll('.fi-mailbox-drop-target').forEach((el) => el.classList.remove('fi-mailbox-drop-target'))
        })

        document.addEventListener('dragover', (event) => {
            const folder = event.target.closest?.('[data-filament-mailbox-folder]')

            if (! folder || ! event.dataTransfer.types.includes(type)) {
                return
            }

            event.preventDefault()
            event.dataTransfer.dropEffect = 'move'
            folder.classList.add('fi-mailbox-drop-target')
        })

        document.addEventListener('dragleave', (event) => {
            const folder = event.target.closest?.('[data-filament-mailbox-folder]')

            if (folder && ! folder.contains(event.relatedTarget)) {
                folder.classList.remove('fi-mailbox-drop-target')
            }
        })

        document.addEventListener('drop', (event) => {
            const folder = event.target.closest?.('[data-filament-mailbox-folder]')

            if (! folder || ! event.dataTransfer.types.includes(type)) {
                return
            }

            event.preventDefault()
            folder.classList.remove('fi-mailbox-drop-target')

            let ids = []

            try {
                ids = JSON.parse(event.dataTransfer.getData(type)).map(Number).filter(Number.isInteger)
            } catch (error) {
                return
            }

            const component = folder.closest('[wire\\:id]')

            if (component && ids.length) {
                window.Livewire.find(component.getAttribute('wire:id')).call('moveMessages', Number(folder.dataset.filamentMailboxFolder), ids)
            }
        })
    })()
</script>
