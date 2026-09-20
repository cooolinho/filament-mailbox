<div style="display: grid; gap: 1rem;">
    @if ($subject)
        <div>
            <div style="font-size: 0.75rem; opacity: 0.7;">{{ __('filament-mailbox::mailbox.messages.fields.subject') }}</div>
            <div style="font-weight: 600;">{{ $subject }}</div>
        </div>
    @endif

    @if ($unknown !== [])
        <div style="padding: 0.5rem 0.75rem; border-radius: 0.5rem; background: rgb(254 243 199); color: rgb(146 64 14); font-size: 0.875rem;">
            {{ __('filament-mailbox::mailbox.templates.unknown_placeholders', ['placeholders' => collect($unknown)->map(fn ($name) => '{'.$name.'}')->implode(', ')]) }}
        </div>
    @endif

    {{-- Sanitised with the outgoing profile; placeholders of the template are wrapped in <mark>. --}}
    <div class="fi-prose" style="padding: 0.75rem; border: 1px solid rgb(229 231 235); border-radius: 0.5rem;">{!! $html !!}</div>

    <details>
        <summary style="cursor: pointer; font-size: 0.875rem;">{{ __('filament-mailbox::mailbox.compose.formats.text') }}</summary>
        <pre style="white-space: pre-wrap; font-size: 0.875rem;">{{ $text }}</pre>
    </details>
</div>
