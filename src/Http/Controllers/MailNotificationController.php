<?php

namespace Cooolinho\FilamentMailbox\Http\Controllers;

use Cooolinho\FilamentMailbox\Models\MailboxPushSubscription;
use Cooolinho\FilamentMailbox\Notifications\NewMailNotifier;
use Cooolinho\FilamentMailbox\Services\NotificationPreferences;
use Cooolinho\FilamentMailbox\WebPush\WebPushSender;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Endpoints of the signed-in user for browser notifications and web push.
 */
class MailNotificationController
{
    /**
     * Unread "new e-mail" notifications since a cursor (browser polling) or of the last minutes (service worker after a push).
     */
    public function latest(Request $request): JsonResponse
    {
        abort_unless(NotificationPreferences::enabled(), 404);

        $user = Filament::auth()->user();
        $now = now();

        if ($request->boolean('init')) {
            return response()->json(['cursor' => $now->toIso8601String(), 'notifications' => []]);
        }

        try {
            $since = $request->filled('since') ? Carbon::parse((string) $request->query('since')) : $now->copy()->subMinutes(5);
        } catch (Throwable) {
            $since = $now->copy()->subMinutes(5);
        }

        // Never more than a day back.
        $since = $since->max($now->copy()->subDay());

        $notifications = DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getAuthIdentifier())
            ->whereNull('read_at')
            ->where('created_at', '>', $since)
            ->where('data', 'like', '%'.NewMailNotifier::MARKER.'%')
            ->orderBy('created_at')
            ->limit(10)
            ->get(['id', 'data', 'created_at'])
            ->map(function (object $row): ?array {
                $data = (array) json_decode((string) $row->data, true);
                $meta = $data['viewData'][NewMailNotifier::MARKER] ?? null;

                return is_array($meta) ? [
                    'id' => $row->id,
                    'title' => (string) ($data['title'] ?? ''),
                    'body' => isset($data['body']) ? strip_tags((string) $data['body']) : null,
                    'url' => (string) ($meta['url'] ?? ''),
                ] : null;
            })
            ->filter()
            ->values();

        return response()
            ->json(['cursor' => $now->toIso8601String(), 'notifications' => $notifications])
            ->header('Cache-Control', 'no-store');
    }

    public function subscribe(Request $request): Response
    {
        abort_unless(WebPushSender::enabled(), 404);

        $endpoint = (string) $request->input('endpoint');

        abort_unless(WebPushSender::isAllowedEndpoint($endpoint), 422);

        $user = Filament::auth()->user();

        MailboxPushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => MailboxPushSubscription::hash($endpoint)],
            ['endpoint' => $endpoint, 'user_id' => $user->getAuthIdentifier()],
        );

        return response()->noContent();
    }

    public function unsubscribe(Request $request): Response
    {
        MailboxPushSubscription::query()
            ->where('user_id', Filament::auth()->user()->getAuthIdentifier())
            ->where('endpoint_hash', MailboxPushSubscription::hash((string) $request->input('endpoint')))
            ->delete();

        return response()->noContent();
    }
}
