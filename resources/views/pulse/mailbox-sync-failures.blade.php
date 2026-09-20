<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        :name="__('filament-mailbox::mailbox.monitoring.pulse.failures')"
        x-bind:title="`Time: {{ number_format($time, 0) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.bug-ant />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($failures->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>{{ __('filament-mailbox::mailbox.monitoring.fields.error_type') }}</x-pulse::th>
                        <x-pulse::th class="text-right">Count</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($failures as $failure)
                        <tr wire:key="{{ $failure->type }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $failure->type }}-row">
                            <x-pulse::td class="text-xs text-gray-900 dark:text-gray-100">{{ $failure->type }}</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">{{ number_format($failure->count) }}</x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
