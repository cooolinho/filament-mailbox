<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Data\AddressData;
use Cooolinho\FilamentMailbox\Data\FolderData;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Data\MessageFlags;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\MessagesImported;
use Cooolinho\FilamentMailbox\Filament\Pages\MailboxSettings;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxPushSubscription;
use Cooolinho\FilamentMailbox\Notifications\NewMailNotifier;
use Cooolinho\FilamentMailbox\Services\NotificationPreferences;
use Cooolinho\FilamentMailbox\Services\SyncService;
use Cooolinho\FilamentMailbox\Tests\Fixtures\FakeMailboxProvider;
use Cooolinho\FilamentMailbox\Tests\Fixtures\User;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Cooolinho\FilamentMailbox\WebPush\VapidKeys;
use Cooolinho\FilamentMailbox\WebPush\WebPushSender;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class NewMailNotificationsTest extends TestCase
{
    protected FakeMailboxProvider $provider;

    protected Mailbox $mailbox;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filament-mailbox.notifications.throttle_seconds', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->fakeProvider();
        $this->provider
            ->addFolder(new FolderData('INBOX', 'INBOX', '/', SpecialUse::Inbox))
            ->addFolder(new FolderData('Projects', 'Projects', '/'));

        $this->mailbox = Mailbox::factory()->create(['name' => 'Support']);
        $this->mailbox->users()->attach($this->user);
    }

    protected function incoming(int $uid, bool $seen = false, string $folder = 'INBOX'): void
    {
        $this->provider->addMessage($folder, new MessageData(
            remoteId: (string) $uid,
            messageId: "m{$uid}@example.com",
            from: new AddressData('john@example.com', 'John Doe'),
            subject: "Invoice {$uid}",
            flags: new MessageFlags(seen: $seen),
        ));
    }

    protected function sync(): void
    {
        app(SyncService::class)->syncMailbox($this->mailbox);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function notificationsOf(User $user): array
    {
        return DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->orderBy('created_at')
            ->pluck('data')
            ->map(fn (string $data): array => json_decode($data, true))
            ->all();
    }

    public function test_the_import_event_carries_new_message_ids_and_the_initial_flag(): void
    {
        Event::fake([MessagesImported::class]);

        $this->incoming(1);
        $this->sync();

        Event::assertDispatched(MessagesImported::class, fn (MessagesImported $event): bool => $event->isInitial && count($event->messageIds) === 1);

        $this->incoming(2);
        $this->sync();

        Event::assertDispatched(MessagesImported::class, fn (MessagesImported $event): bool => ! $event->isInitial && $event->count === 1 && count($event->messageIds) === 1);
    }

    public function test_new_unread_inbox_messages_notify_assigned_users(): void
    {
        $other = User::make('Not assigned');

        $this->incoming(1);
        $this->sync();
        $this->assertSame([], $this->notificationsOf($this->user), 'No notifications for the initial synchronisation.');

        $this->incoming(2);
        $this->incoming(3, seen: true);
        $this->incoming(4, folder: 'Projects');
        $this->sync();

        $notifications = $this->notificationsOf($this->user);
        $this->assertCount(1, $notifications);
        $this->assertSame('John Doe', $notifications[0]['title']);
        $this->assertSame('Invoice 2', $notifications[0]['body']);
        $this->assertSame('filament', $notifications[0]['format']);

        $message = $this->mailbox->messages()->where('remote_id', 'like', '%:2')->sole();
        $this->assertSame(MailboxResource::getUrl('message', ['record' => $this->mailbox, 'message' => $message], panel: 'admin'), $notifications[0]['viewData'][NewMailNotifier::MARKER]['url']);
        $this->assertSame([], $this->notificationsOf($other));
    }

    public function test_notification_errors_never_break_the_synchronisation(): void
    {
        $this->sync();
        $this->app->bind(NewMailNotifier::class, fn () => throw new \RuntimeException('notifier down'));
        $this->incoming(1);

        $result = app(SyncService::class)->syncMailbox($this->mailbox);

        $this->assertSame([], $result->errors);
        $this->assertSame(1, $result->imported);
    }

    public function test_several_messages_are_bundled(): void
    {
        $this->sync();

        $this->incoming(1);
        $this->incoming(2);
        $this->incoming(3);
        $this->sync();

        $notifications = $this->notificationsOf($this->user);
        $this->assertCount(1, $notifications);
        $this->assertSame('3 new e-mails in Support', $notifications[0]['title']);
    }

    public function test_notifications_are_throttled_and_summarised(): void
    {
        config(['filament-mailbox.notifications.throttle_seconds' => 120]);
        $this->sync();

        $this->incoming(1);
        $this->sync();
        $this->incoming(2);
        $this->incoming(3);
        $this->sync();

        $this->assertCount(1, $this->notificationsOf($this->user));

        $this->travel(3)->minutes();
        $this->incoming(4);
        $this->sync();

        $notifications = $this->notificationsOf($this->user);
        $this->assertCount(2, $notifications);
        $this->assertSame('3 new e-mails in Support', $notifications[1]['title']);
    }

    public function test_preferences_are_respected(): void
    {
        $this->sync();
        $preferences = app(NotificationPreferences::class);
        $projects = $this->mailbox->folders()->where('remote_id', 'Projects')->sole();

        // Hidden content.
        app(\Cooolinho\FilamentMailbox\Services\UserPreferences::class)->set($this->user, 'notifications_show_content', false);
        $this->incoming(1);
        $this->sync();
        $this->assertSame('New e-mail in Support', $this->notificationsOf($this->user)[0]['title']);
        $this->assertNull($this->notificationsOf($this->user)[0]['body']);

        // Only the projects folder.
        $preferences->setMailbox($this->user, $this->mailbox, true, [$projects->id, 99999]);
        $this->assertSame([$projects->id], $preferences->assignment($this->user, $this->mailbox)['folder_ids']);
        $this->incoming(2);
        $this->incoming(3, folder: 'Projects');
        $this->sync();
        $this->assertCount(2, $this->notificationsOf($this->user));

        // Mailbox muted.
        $preferences->setMailbox($this->user, $this->mailbox, false, null);
        $this->incoming(4, folder: 'Projects');
        $this->sync();
        $this->assertCount(2, $this->notificationsOf($this->user));

        // Removed assignment.
        $preferences->setMailbox($this->user, $this->mailbox, true, null);
        $this->mailbox->users()->detach($this->user);
        $this->incoming(5);
        $this->sync();
        $this->assertCount(2, $this->notificationsOf($this->user));

        // Globally disabled.
        config(['filament-mailbox.notifications.enabled' => false]);
        $this->mailbox->users()->attach($this->user);
        $this->incoming(6);
        $this->sync();
        $this->assertCount(2, $this->notificationsOf($this->user));
    }

    public function test_the_settings_page_stores_preferences_for_assigned_mailboxes_only(): void
    {
        $this->sync();
        $projects = $this->mailbox->folders()->where('remote_id', 'Projects')->sole();
        $foreign = Mailbox::factory()->create(['name' => 'Foreign']);

        Livewire::test(MailboxSettings::class)
            ->assertOk()
            ->assertSee('Support')
            ->assertDontSee('Foreign')
            ->fillForm([
                'notifications' => true,
                'show_content' => false,
                'mailboxes' => [
                    'm'.$this->mailbox->id => ['notify' => true, 'folders' => [$projects->id]],
                    'm'.$foreign->id => ['notify' => false, 'folders' => []],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('filament-mailbox::mailbox.notifications.settings.saved'));

        $preferences = app(NotificationPreferences::class);
        $this->assertFalse((new NotificationPreferences(new \Cooolinho\FilamentMailbox\Services\UserPreferences))->showContent($this->user));
        $this->assertSame([$projects->id], $preferences->assignment($this->user, $this->mailbox)['folder_ids']);
        $this->assertNull($preferences->assignment($this->user, $foreign));
    }

    public function test_the_latest_endpoint_returns_only_own_unread_mail_notifications(): void
    {
        $this->sync();
        $this->incoming(1);
        $this->sync();

        $other = User::make('Other');
        $this->mailbox->users()->attach($other);
        \Filament\Notifications\Notification::make()->title('Unrelated')->sendToDatabase($this->user);

        $this->getJson('/admin/filament-mailbox/notifications/latest?init=1')
            ->assertOk()
            ->assertJsonPath('notifications', []);

        $response = $this->getJson('/admin/filament-mailbox/notifications/latest?since='.urlencode(now()->subMinute()->toIso8601String()))
            ->assertOk();

        $this->assertSame(['John Doe'], array_column($response->json('notifications'), 'title'));
        $this->assertNotEmpty($response->json('cursor'));

        $this->actingAs($other);
        $this->getJson('/admin/filament-mailbox/notifications/latest')->assertOk()->assertJsonPath('notifications', []);
    }

    public function test_the_browser_script_is_rendered_for_signed_in_users(): void
    {
        $this->get(MailboxResource::getUrl())
            ->assertOk()
            ->assertSee('window.filamentMailboxNotifications', false);

        config(['filament-mailbox.notifications.browser' => false]);

        $this->get(MailboxResource::getUrl())->assertDontSee('window.filamentMailboxNotifications', false);
    }

    public function test_vapid_tokens_are_valid_es256_signatures(): void
    {
        $keys = VapidKeys::generate();
        $jwt = VapidKeys::jwt('https://fcm.googleapis.com', 'mailto:admin@example.com', $keys['public'], $keys['private']);

        [$header, $claims, $signature] = explode('.', $jwt);
        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(VapidKeys::base64UrlDecode($header), true));
        $this->assertSame('https://fcm.googleapis.com', json_decode(VapidKeys::base64UrlDecode($claims), true)['aud']);

        $raw = VapidKeys::base64UrlDecode($signature);
        $this->assertSame(64, strlen($raw));

        // Verify with the public key.
        $public = VapidKeys::base64UrlDecode($keys['public']);
        $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00".$public;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $toDer = function (string $raw): string {
            $int = function (string $part): string {
                $part = ltrim($part, "\0");
                $part = (ord($part[0] ?? "\0") & 0x80) ? "\0".$part : $part;

                return "\x02".chr(strlen($part)).$part;
            };
            $body = $int(substr($raw, 0, 32)).$int(substr($raw, 32));

            return "\x30".chr(strlen($body)).$body;
        };

        $this->assertSame(1, openssl_verify("{$header}.{$claims}", $toDer($raw), $pem, OPENSSL_ALGO_SHA256));
        $this->artisan('mailbox:vapid-keys')->expectsOutputToContain('MAILBOX_VAPID_PUBLIC_KEY=')->assertSuccessful();
    }

    public function test_web_push_subscriptions_and_payload_less_pushes(): void
    {
        $keys = VapidKeys::generate();
        config([
            'filament-mailbox.pwa.enabled' => true,
            'filament-mailbox.notifications.web_push.enabled' => true,
            'filament-mailbox.notifications.web_push.public_key' => $keys['public'],
            'filament-mailbox.notifications.web_push.private_key' => $keys['private'],
            'filament-mailbox.notifications.web_push.subject' => 'mailto:admin@example.com',
        ]);

        $this->postJson('/admin/filament-mailbox/push-subscriptions', ['endpoint' => 'https://evil.example.com/steal'])->assertStatus(422);
        $this->postJson('/admin/filament-mailbox/push-subscriptions', ['endpoint' => 'http://fcm.googleapis.com/fcm/send/x'])->assertStatus(422);
        $this->postJson('/admin/filament-mailbox/push-subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])->assertNoContent();
        $this->postJson('/admin/filament-mailbox/push-subscriptions', ['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/gone'])->assertNoContent();
        $this->assertSame(2, MailboxPushSubscription::query()->where('user_id', $this->user->id)->count());

        Http::fake([
            'fcm.googleapis.com/*' => Http::response('', 201),
            'updates.push.services.mozilla.com/*' => Http::response('', 410),
        ]);

        $this->sync();
        $this->incoming(1);
        $this->sync();

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://fcm.googleapis.com/')
            && $request->body() === ''
            && str_starts_with($request->header('Authorization')[0], 'vapid t=')
            && str_ends_with($request->header('Authorization')[0], ', k='.$keys['public']));

        // Expired subscriptions are removed.
        $this->assertSame(1, MailboxPushSubscription::query()->count());

        $this->deleteJson('/admin/filament-mailbox/push-subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])->assertNoContent();
        $this->assertSame(0, MailboxPushSubscription::query()->count());

        $this->get('/admin/filament-mailbox-sw.js')->assertOk()->assertSee("addEventListener('push'", false);
        $this->assertTrue(WebPushSender::isAllowedEndpoint('https://abc.notify.windows.com/w/?token=1'));
    }
}
