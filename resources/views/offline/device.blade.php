{{-- Opt-in on this browser. The device id is kept in localStorage; the local copy is removed when disabling. --}}
<div
    x-data="{
        device: null,
        supported: 'indexedDB' in window && !! window.crypto?.subtle,
        usage: null,
        async init() {
            const stored = window.FilamentMailboxOffline?.device()

            if (stored && stored.user === @js($user)) {
                this.device = await $wire.loadDevice(stored.uuid)
            }

            if (stored && ! this.device) {
                await window.FilamentMailboxOffline?.wipe()
            }

            this.estimate()
            window.addEventListener('filament-mailbox-offline-synced', () => this.estimate())
        },
        async estimate() {
            const estimate = await navigator.storage?.estimate?.().catch(() => null)
            this.usage = estimate?.usage ? Math.round(estimate.usage / 1048576 * 10) / 10 : null
        },
        async enable() {
            const device = await $wire.enableDevice(navigator.userAgent.slice(0, 120))

            if (! device) {
                return
            }

            await window.FilamentMailboxOffline.wipe()
            window.FilamentMailboxOffline.saveDevice(device)
            this.device = device
            navigator.storage?.persist?.()
            window.dispatchEvent(new CustomEvent('filament-mailbox-offline-sync'))
        },
        async save() {
            await $wire.saveSelection(this.device.uuid)
            window.dispatchEvent(new CustomEvent('filament-mailbox-offline-sync'))
        },
        async disable() {
            await $wire.disableDevice(this.device?.uuid)
            await window.FilamentMailboxOffline.wipe()
            this.device = null
            this.estimate()
        },
    }"
    class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
>
    <template x-if="! supported">
        <p class="text-sm text-danger-600">{{ __('filament-mailbox::mailbox.offline.unsupported') }}</p>
    </template>

    <template x-if="supported">
        <div class="flex flex-wrap items-center gap-3">
            <p class="flex-1 text-sm" x-text="device ? @js(__('filament-mailbox::mailbox.offline.this_device_on')) : @js(__('filament-mailbox::mailbox.offline.this_device_off'))"></p>

            <span class="text-xs text-gray-500" x-show="usage !== null" x-text="@js(__('filament-mailbox::mailbox.offline.usage')).replace(':size', usage)"></span>

            <template x-if="! device">
                <x-filament::button x-on:click="enable()" icon="heroicon-o-cloud-arrow-down">
                    {{ __('filament-mailbox::mailbox.offline.actions.enable') }}
                </x-filament::button>
            </template>

            <template x-if="device">
                <div class="flex flex-wrap gap-2">
                    <x-filament::button x-on:click="save()" color="gray">
                        {{ __('filament-mailbox::mailbox.offline.actions.save') }}
                    </x-filament::button>
                    <x-filament::button tag="a" href="{{ $appUrl }}" color="gray" icon="heroicon-o-arrow-top-right-on-square">
                        {{ __('filament-mailbox::mailbox.offline.actions.open') }}
                    </x-filament::button>
                    <x-filament::button x-on:click="disable()" color="danger">
                        {{ __('filament-mailbox::mailbox.offline.actions.disable') }}
                    </x-filament::button>
                </div>
            </template>
        </div>
    </template>
</div>
