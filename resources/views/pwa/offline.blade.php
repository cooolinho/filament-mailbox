<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('filament-mailbox::mailbox.pwa.offline.title') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, sans-serif; background: #f8fafc; color: #0f172a; }
        @media (prefers-color-scheme: dark) { body { background: #0f172a; color: #e2e8f0; } }
        main { max-width: 24rem; padding: 2rem; text-align: center; }
        img { width: 4rem; height: 4rem; border-radius: 1rem; }
        h1 { font-size: 1.25rem; margin: 1rem 0 0.5rem; }
        p { margin: 0 0 1.5rem; opacity: 0.8; }
        button { font: inherit; padding: 0.5rem 1rem; border-radius: 0.5rem; border: 0; background: #1e293b; color: #fff; cursor: pointer; }
    </style>
</head>
<body>
    <main>
        <img src="{{ $icon }}" alt="">
        <h1>{{ __('filament-mailbox::mailbox.pwa.offline.title') }}</h1>
        <p>{{ __('filament-mailbox::mailbox.pwa.offline.description') }}</p>
        <button type="button" onclick="location.reload()">{{ __('filament-mailbox::mailbox.pwa.offline.retry') }}</button>
        @if ($offlineAppUrl ?? null)
            <p><a href="{{ $offlineAppUrl }}">{{ __('filament-mailbox::mailbox.offline.app.open') }}</a></p>
        @endif
    </main>
</body>
</html>
