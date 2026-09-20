<div class="fi-mailbox-preview">
    @if ($message)
        <div class="fi-mailbox-preview-header">
            <h2 class="fi-mailbox-preview-subject">
                {{ $message->subject ?: __('filament-mailbox::mailbox.messages.no_subject') }}
            </h2>

            <x-filament::actions :actions="$this->getToolbarActions()" />
        </div>

        {{ $this->infolist }}
    @else
        <x-filament::empty-state
            :heading="__('filament-mailbox::mailbox.split.empty.heading')"
            :description="__('filament-mailbox::mailbox.split.empty.description')"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedEnvelopeOpen"
            icon-color="gray"
        />
    @endif

    <x-filament-actions::modals />
</div>
