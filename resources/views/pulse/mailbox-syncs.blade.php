<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        :name="__('filament-mailbox::mailbox.monitoring.pulse.syncs')"
        x-bind:title="`Time: {{ number_format($time, 0) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.arrows-left-right />
        </x-slot:icon>
        <x-slot:actions>
            <x-pulse::select
                wire:model.live="orderBy"
                id="select-mailbox-syncs-order-by"
                label="Sort by"
                :options="[
                    'slowest' => 'slowest',
                    'average' => 'average',
                    'count' => 'count',
                ]"
                @change="loading = true"
            />
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($syncs->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>{{ __('filament-mailbox::mailbox.resource.label') }}</x-pulse::th>
                        <x-pulse::th class="text-right">Runs</x-pulse::th>
                        <x-pulse::th class="text-right">Average</x-pulse::th>
                        <x-pulse::th class="text-right">Slowest</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($syncs->take(100) as $sync)
                        <tr wire:key="{{ $sync->mailbox }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $sync->mailbox }}-row">
                            <x-pulse::td class="max-w-[1px]">
                                <span class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $sync->mailbox }}">{{ $sync->mailbox }}</span>
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                {{ number_format($sync->count) }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                {{ $sync->average === null ? '—' : number_format($sync->average) }} ms
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                <strong>{{ $sync->slowest === null ? '—' : number_format($sync->slowest) }}</strong> ms
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
