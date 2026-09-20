<div
    x-data="{
        supported: 'Notification' in window,
        permission: 'Notification' in window ? Notification.permission : 'unsupported',
        pushSupported: 'serviceWorker' in navigator && 'PushManager' in window,
        pushed: false,
        busy: false,
        async init() {
            if (this.pushSupported && @js($push)) {
                const registration = await navigator.serviceWorker.getRegistration()
                this.pushed = !! (await registration?.pushManager.getSubscription())
            }
        },
        async allow() {
            this.permission = await Notification.requestPermission()
        },
        key() {
            const base64 = @js($publicKey).replace(/-/g, '+').replace(/_/g, '/')
            const raw = atob(base64 + '='.repeat((4 - base64.length % 4) % 4))

            return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)))
        },
        async send(method, subscription) {
            await fetch(@js($subscribeUrl), {
                method,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                },
                body: JSON.stringify({ endpoint: subscription.endpoint }),
            })
        },
        async togglePush() {
            this.busy = true

            try {
                const registration = await navigator.serviceWorker.ready
                const existing = await registration.pushManager.getSubscription()

                if (existing) {
                    await this.send('DELETE', existing)
                    await existing.unsubscribe()
                    this.pushed = false
                } else {
                    if (this.permission !== 'granted') {
                        await this.allow()
                    }

                    const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this.key() })
                    await this.send('POST', subscription)
                    this.pushed = true
                }
            } finally {
                this.busy = false
            }
        },
    }"
    class="fi-mailbox-device-settings"
    style="display: flex; flex-direction: column; gap: 1rem;"
>
    @if ($browser)
        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem;">
            <span x-show="permission === 'granted'">
                <x-filament::badge color="success" :icon="\Filament\Support\Icons\Heroicon::OutlinedCheckCircle">{{ __('filament-mailbox::mailbox.notifications.settings.browser_granted') }}</x-filament::badge>
            </span>
            <span x-show="permission === 'denied'" x-cloak>
                <x-filament::badge color="danger">{{ __('filament-mailbox::mailbox.notifications.settings.browser_denied') }}</x-filament::badge>
            </span>
            <span x-show="permission === 'unsupported'" x-cloak>
                <x-filament::badge color="gray">{{ __('filament-mailbox::mailbox.notifications.settings.browser_unsupported') }}</x-filament::badge>
            </span>
            <span x-show="permission === 'default'" x-cloak>
                <x-filament::button color="gray" :icon="\Filament\Support\Icons\Heroicon::OutlinedBell" x-on:click="allow()">
                    {{ __('filament-mailbox::mailbox.notifications.settings.browser_allow') }}
                </x-filament::button>
            </span>
        </div>
    @endif

    @if ($push)
        <div x-show="pushSupported" x-cloak>
            <x-filament::button color="gray" :icon="\Filament\Support\Icons\Heroicon::OutlinedDevicePhoneMobile" x-on:click="togglePush()" x-bind:disabled="busy">
                <span x-show="! pushed">{{ __('filament-mailbox::mailbox.notifications.settings.push_enable') }}</span>
                <span x-show="pushed" x-cloak>{{ __('filament-mailbox::mailbox.notifications.settings.push_disable') }}</span>
            </x-filament::button>
        </div>
    @endif
</div>
