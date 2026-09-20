<?php

namespace Cooolinho\FilamentMailbox\Http\Controllers;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Pwa\Pwa;
use Cooolinho\FilamentMailbox\Pwa\PwaManifestFactory;
use Cooolinho\FilamentMailbox\Services\Offline\OfflineDeviceService;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Public PWA endpoints (manifest, service worker, offline page, icons) contain no
 * personal data; the launch endpoint is authenticated.
 */
class PwaController
{
    public function manifest(PwaManifestFactory $manifests): JsonResponse
    {
        $panel = $this->panel();

        return response()
            ->json($manifests->make($panel), options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function serviceWorker(): Response
    {
        $panel = $this->panel();

        return response()
            ->view('filament-mailbox::pwa.service-worker', [
                'version' => Pwa::version(),
                'scope' => Pwa::scope($panel),
                'offlineUrl' => Pwa::offlineUrl($panel),
                'offlineAppUrl' => OfflineDeviceService::enabled() ? Pwa::url($panel, 'filament-mailbox/offline/app') : null,
                'icons' => array_map(fn (string $icon): string => Pwa::iconUrl($panel, $icon), ['icon-192', 'icon-512']),
                'launchUrl' => Pwa::url($panel, 'filament-mailbox/launch/inbox'),
                'notificationsUrl' => Pwa::url($panel, 'filament-mailbox/notifications/latest'),
            ])
            ->header('Content-Type', 'application/javascript; charset=UTF-8')
            ->header('Service-Worker-Allowed', Pwa::scope($panel))
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    public function offline(): Response
    {
        $panel = $this->panel();

        return response()
            ->view('filament-mailbox::pwa.offline', [
                'icon' => Pwa::iconUrl($panel, 'icon-192'),
                'offlineAppUrl' => OfflineDeviceService::enabled() ? Pwa::url($panel, 'filament-mailbox/offline/app') : null,
            ])
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function icon(string $icon): BinaryFileResponse
    {
        $this->panel();

        abort_unless(in_array($icon, Pwa::ICONS, true), 404);

        return response()->file(__DIR__."/../../../resources/pwa/icons/{$icon}.png", [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    /**
     * Start URL and shortcuts: the inbox of the first assigned mailbox, optionally with the compose form.
     */
    public function launch(string $target): RedirectResponse
    {
        $panel = $this->panel();
        $user = Filament::auth()->user();

        $mailbox = $user ? Mailbox::query()->active()->assignedTo($user)->orderBy('name')->first() : null;

        if (! $mailbox) {
            return redirect(PwaManifestFactory::mailboxListUrl($panel));
        }

        return redirect(MailboxResource::getUrl('browse', array_filter([
            'record' => $mailbox,
            'folder' => $mailbox->folderFor(SpecialUse::Inbox)?->getKey(),
            'action' => $target === 'compose' ? 'compose' : null,
        ]), panel: $panel->getId()));
    }

    protected function panel(): Panel
    {
        $panel = Filament::getCurrentPanel();

        abort_unless($panel && Pwa::enabled($panel), 404);

        return $panel;
    }
}
