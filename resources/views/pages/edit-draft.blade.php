<x-filament-panels::page>
    <div
        @if ($this->getAutosaveSeconds() > 0)
            wire:poll.{{ $this->getAutosaveSeconds() }}s="autosave"
        @endif
    >
        {{ $this->content }}
    </div>
</x-filament-panels::page>
