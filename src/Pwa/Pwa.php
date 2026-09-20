<?php

namespace Cooolinho\FilamentMailbox\Pwa;

use Cooolinho\FilamentMailbox\FilamentMailboxPlugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Throwable;

/**
 * Installable app for the panel the plugin is registered in: manifest,
 * service worker (static assets only), offline page and icons.
 */
class Pwa
{
    public const ICONS = ['icon-192', 'icon-512', 'maskable-512', 'apple-touch-icon'];

    public const CACHE_PREFIX = 'fm-static-';

    public static function plugin(?Panel $panel = null): ?FilamentMailboxPlugin
    {
        $panel ??= Filament::getCurrentPanel();

        try {
            return $panel?->hasPlugin(FilamentMailboxPlugin::ID) ? $panel->getPlugin(FilamentMailboxPlugin::ID) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function enabled(?Panel $panel = null): bool
    {
        return (bool) static::plugin($panel)?->isPwaEnabled();
    }

    public static function url(Panel $panel, string $path): string
    {
        return url(trim($panel->getPath(), '/').'/'.ltrim($path, '/'));
    }

    /**
     * The service worker controls the panel path only.
     */
    public static function scope(Panel $panel): string
    {
        return '/'.trim(trim($panel->getPath(), '/').'/', '/').'/';
    }

    public static function manifestUrl(Panel $panel): string
    {
        return static::url($panel, 'filament-mailbox/manifest.webmanifest');
    }

    public static function serviceWorkerUrl(Panel $panel): string
    {
        return static::url($panel, 'filament-mailbox-sw.js');
    }

    public static function offlineUrl(Panel $panel): string
    {
        return static::url($panel, 'filament-mailbox/offline');
    }

    public static function iconUrl(Panel $panel, string $icon): string
    {
        return static::plugin($panel)?->getPwaIcons()[$icon] ?? static::url($panel, "filament-mailbox/pwa/{$icon}.png");
    }

    /**
     * Changes whenever the service worker, the package or the application assets change,
     * so old static caches are dropped.
     */
    public static function version(): string
    {
        $parts = [
            (string) @filemtime(__DIR__.'/../../resources/views/pwa/service-worker.blade.php'),
            (string) @filemtime(__DIR__.'/../../resources/views/offline/app.blade.php'),
            (string) @filemtime(__DIR__.'/../../resources/views/offline/client.blade.php'),
            (string) config('filament-mailbox.pwa.cache_version', ''),
            (string) @filemtime(public_path('build/manifest.json')),
            (string) @filemtime(public_path('js/filament/filament/app.js')),
        ];

        return substr(md5(implode('|', $parts)), 0, 12);
    }
}
