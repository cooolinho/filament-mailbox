<?php

namespace Cooolinho\FilamentMailbox\Http\Controllers;

use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxOfflineDevice;
use Cooolinho\FilamentMailbox\Pwa\Pwa;
use Cooolinho\FilamentMailbox\Services\AttachmentService;
use Cooolinho\FilamentMailbox\Services\Offline\OfflineDeviceService;
use Cooolinho\FilamentMailbox\Services\Offline\OfflineSnapshotService;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Offline copy: the public app shell (no personal data, cached by the service
 * worker) and authenticated JSON endpoints for a registered device.
 */
class OfflineController
{
    public const DEVICE_HEADER = 'X-Mailbox-Offline-Device';

    public function __construct(
        protected OfflineDeviceService $devices,
        protected OfflineSnapshotService $snapshots,
    ) {}

    public function shell(): Response
    {
        $panel = $this->panel();

        return response()
            ->view('filament-mailbox::offline.app', [
                'icon' => Pwa::iconUrl($panel, 'icon-192'),
                'panelUrl' => url($panel->getPath()),
            ])
            ->header('Cache-Control', 'no-cache')
            ->header('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; frame-src 'self' blob: data:; connect-src 'self'; object-src 'none'; base-uri 'none'");
    }

    public function manifest(Request $request): JsonResponse
    {
        $this->panel();
        $device = $this->device($request);

        if (! $device) {
            return $this->revoked();
        }

        return $this->json($this->snapshots->manifest($device, Filament::auth()->user()));
    }

    public function changes(Request $request): JsonResponse
    {
        $this->panel();
        $device = $this->device($request);

        if (! $device) {
            return $this->revoked();
        }

        try {
            $since = $request->filled('since') ? Carbon::parse((string) $request->query('since')) : null;
        } catch (Throwable) {
            $since = null;
        }

        $changes = $this->snapshots->changes($device, Filament::auth()->user(), (int) $request->query('folder'), $since?->min(now()));

        abort_if($changes === null, 404);

        return $this->json($changes);
    }

    /**
     * Key of the local copy; the browser imports it as non-extractable key.
     */
    public function key(Request $request): JsonResponse
    {
        $this->panel();
        $device = $this->device($request);

        return $device ? $this->json(['key' => $device->key]) : $this->revoked();
    }

    public function attachment(Request $request, MailboxAttachment $attachment, AttachmentService $attachments): SymfonyResponse
    {
        $this->panel();
        $device = $this->device($request);

        abort_unless($device && $this->snapshots->allowsAttachment($device, Filament::auth()->user(), $attachment), 404);

        return $attachments->download($attachment);
    }

    protected function device(Request $request): ?MailboxOfflineDevice
    {
        $device = $this->devices->find(Filament::auth()->user(), $request->header(self::DEVICE_HEADER));

        return $device && ! $device->isRevoked() ? $device : null;
    }

    /**
     * Unknown or revoked devices delete their local copy.
     */
    protected function revoked(): JsonResponse
    {
        return $this->json(['revoked' => true], 403);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }

    protected function panel(): Panel
    {
        $panel = Filament::getCurrentPanel();

        abort_unless($panel && OfflineDeviceService::enabled() && Pwa::enabled($panel), 404);

        return $panel;
    }
}
