<?php

namespace Cooolinho\FilamentMailbox\Pwa;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Throwable;

class PwaManifestFactory
{
    /**
     * @return array<string, mixed>
     */
    public function make(Panel $panel): array
    {
        $plugin = Pwa::plugin($panel);
        $name = $plugin?->getPwaName() ?? (string) (config('filament-mailbox.pwa.name') ?: config('app.name'));

        return [
            'id' => Pwa::scope($panel),
            'name' => $name,
            'short_name' => (string) (config('filament-mailbox.pwa.short_name') ?: $name),
            'description' => __('filament-mailbox::mailbox.pwa.description'),
            'lang' => app()->getLocale(),
            'start_url' => $this->startUrl($panel),
            'scope' => Pwa::scope($panel),
            'display' => 'standalone',
            'theme_color' => $this->themeColor($panel),
            'background_color' => (string) config('filament-mailbox.pwa.background_color', '#ffffff'),
            'icons' => [
                ['src' => Pwa::iconUrl($panel, 'icon-192'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => Pwa::iconUrl($panel, 'icon-512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => Pwa::iconUrl($panel, 'maskable-512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                [
                    'name' => __('filament-mailbox::mailbox.pwa.shortcuts.compose'),
                    'url' => Pwa::url($panel, 'filament-mailbox/launch/compose'),
                    'icons' => [['src' => Pwa::iconUrl($panel, 'icon-192'), 'sizes' => '192x192', 'type' => 'image/png']],
                ],
                [
                    'name' => __('filament-mailbox::mailbox.pwa.shortcuts.inbox'),
                    'url' => Pwa::url($panel, 'filament-mailbox/launch/inbox'),
                    'icons' => [['src' => Pwa::iconUrl($panel, 'icon-192'), 'sizes' => '192x192', 'type' => 'image/png']],
                ],
            ],
        ];
    }

    public function startUrl(Panel $panel): string
    {
        return Pwa::plugin($panel)?->getPwaStartUrl()
            ?? Pwa::url($panel, 'filament-mailbox/launch/inbox');
    }

    public function themeColor(Panel $panel): string
    {
        try {
            $primary = $panel->getColors()['primary'] ?? Color::Amber;

            return Color::convertToHex(is_array($primary) ? $primary[500] : $primary);
        } catch (Throwable) {
            return '#f59e0b';
        }
    }

    public static function mailboxListUrl(Panel $panel): string
    {
        return MailboxResource::getUrl(panel: $panel->getId());
    }
}
