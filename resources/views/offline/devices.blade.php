<ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
    @forelse ($devices as $device)
        <li class="flex flex-wrap items-center gap-3 py-2" wire:key="offline-device-{{ $device->id }}">
            <div class="min-w-0 flex-1">
                <div class="truncate">{{ $device->label ?: __('filament-mailbox::mailbox.offline.unknown_device') }}</div>
                <div class="text-xs text-gray-500">
                    {{ __('filament-mailbox::mailbox.offline.device_since', ['date' => $device->created_at->toFormattedDateString()]) }}
                    @if ($device->last_seen_at)
                        · {{ __('filament-mailbox::mailbox.offline.last_seen', ['time' => $device->last_seen_at->diffForHumans()]) }}
                    @endif
                </div>
            </div>
            <x-filament::button
                size="sm"
                color="danger"
                outlined
                wire:click="revokeDevice({{ (int) $device->id }})"
                wire:confirm="{{ __('filament-mailbox::mailbox.offline.revoke_confirm') }}"
            >
                {{ __('filament-mailbox::mailbox.offline.actions.revoke') }}
            </x-filament::button>
        </li>
    @empty
        <li class="py-2 text-gray-500">{{ __('filament-mailbox::mailbox.offline.no_devices') }}</li>
    @endforelse
</ul>
