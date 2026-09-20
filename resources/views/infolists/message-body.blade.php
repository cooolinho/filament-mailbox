<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <iframe
        sandbox="allow-popups allow-popups-to-escape-sandbox"
        referrerpolicy="no-referrer"
        srcdoc="{{ $getState() }}"
        title="{{ __('filament-mailbox::mailbox.messages.fields.body') }}"
        style="display: block; width: 100%; min-height: 32rem; border: 0; border-radius: 0.5rem; background: #fff;"
    ></iframe>
</x-dynamic-component>
