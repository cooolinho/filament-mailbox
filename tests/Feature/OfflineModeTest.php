<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Filament\Pages\OfflineAvailability;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxOfflineDevice;
use Cooolinho\FilamentMailbox\Services\Offline\OfflineDeviceService;
use Cooolinho\FilamentMailbox\Support\MailboxAccess;
use Cooolinho\FilamentMailbox\Tests\Fixtures\User;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class OfflineModeTest extends TestCase
{
    protected Mailbox $mailbox;

    protected MailboxFolder $inbox;

    protected MailboxFolder $junk;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filament-mailbox.pwa.enabled', true);
        $app['config']->set('filament-mailbox.offline.enabled', true);
        $app['config']->set('app.url', 'http://localhost');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailbox = Mailbox::factory()->create(['name' => 'Support']);
        $this->mailbox->users()->attach($this->user);
        $this->inbox = MailboxFolder::factory()->for($this->mailbox)->inbox()->create();
        $this->junk = MailboxFolder::factory()->for($this->mailbox)->specialUse(SpecialUse::Junk)->create(['name' => 'Junk', 'full_name' => 'Junk']);
    }

    public function test_everything_answers_404_while_disabled(): void
    {
        config(['filament-mailbox.offline.enabled' => false]);
        $device = $this->device();

        $this->get('/admin/filament-mailbox/offline/app')->assertNotFound();
        $this->getJson('/admin/filament-mailbox/offline/manifest', $this->headers($device))->assertNotFound();
        $this->get(MailboxResource::getUrl())->assertOk()->assertDontSee('filament-mailbox-offline-sync', false);
        $this->assertFalse(OfflineAvailability::canAccess());
    }

    public function test_the_shell_is_public_and_contains_no_personal_data(): void
    {
        MailboxMessage::factory()->for($this->inbox, 'folder')->create(['subject' => 'Secret subject']);
        auth()->logout();

        $this->get('/admin/filament-mailbox/offline/app')
            ->assertOk()
            ->assertHeader('Content-Security-Policy')
            ->assertSee('FilamentMailboxOffline', false)
            ->assertDontSee('Secret subject')
            ->assertDontSee('Support');

        $this->get('/admin/filament-mailbox-sw.js')->assertOk()->assertSee('http:\/\/localhost\/admin\/filament-mailbox\/offline\/app', false);
        $this->get('/admin/filament-mailbox/offline')->assertOk()->assertSee('http://localhost/admin/filament-mailbox/offline/app', false);
    }

    public function test_panel_pages_load_the_sync_script_and_logout_removes_the_copy(): void
    {
        $this->get(MailboxResource::getUrl())
            ->assertOk()
            ->assertSee('filament-mailbox-offline-sync', false)
            ->assertSee(OfflineAvailability::userHash($this->user), false)
            ->assertSee('FilamentMailboxOffline?.wipe()', false);
    }

    public function test_devices_are_registered_on_the_page(): void
    {
        $result = Livewire::test(OfflineAvailability::class)
            ->fillForm(['folder_ids' => [$this->inbox->id], 'days' => 7, 'max_messages' => 50, 'attachments' => true])
            ->call('enableDevice', 'Firefox on Linux')
            ->assertHasNoFormErrors()
            ->assertNotified(__('filament-mailbox::mailbox.offline.enabled'));

        $device = MailboxOfflineDevice::query()->sole();
        $this->assertSame(['uuid' => $device->device_uuid, 'user' => OfflineAvailability::userHash($this->user)], $result->effects['returns'][0] ?? null);
        $this->assertSame(['folder_ids' => [$this->inbox->id], 'days' => 7, 'max_messages' => 50, 'attachments' => true], $device->selection);
        $this->assertSame('Firefox on Linux', $device->label);
        $this->assertSame(44, strlen($device->key));
        $this->assertNotSame($device->key, DB::table('mailbox_offline_devices')->value('key'));

        Livewire::test(OfflineAvailability::class)
            ->fillForm(['folder_ids' => [MailboxFolder::factory()->create()->id]])
            ->call('enableDevice', 'Other')
            ->assertHasFormErrors(['folder_ids.0']);
    }

    public function test_the_selection_is_normalised_on_the_server(): void
    {
        $foreignFolder = MailboxFolder::factory()->create();

        $selection = app(OfflineDeviceService::class)->normalizeSelection($this->user, [
            'folder_ids' => [$this->inbox->id, $foreignFolder->id, 'x'],
            'days' => 9999,
            'max_messages' => -5,
            'attachments' => true,
        ]);

        $this->assertSame(['folder_ids' => [$this->inbox->id], 'days' => 30, 'max_messages' => 1, 'attachments' => true], $selection);
    }

    public function test_manifest_and_key_only_for_the_own_active_device(): void
    {
        $device = $this->device();

        $this->getJson('/admin/filament-mailbox/offline/manifest', $this->headers($device))
            ->assertOk()
            ->assertJsonPath('mailboxes.0.name', 'Support')
            ->assertJsonPath('mailboxes.0.folders.0.id', $this->inbox->id)
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->getJson('/admin/filament-mailbox/offline/key', $this->headers($device))->assertOk()->assertJsonPath('key', $device->key);

        $this->actingAs(User::make('Other'));
        $this->getJson('/admin/filament-mailbox/offline/key', $this->headers($device))->assertForbidden()->assertJsonPath('revoked', true);

        $this->actingAs($this->user);
        app(OfflineDeviceService::class)->revoke($device);
        $this->getJson('/admin/filament-mailbox/offline/manifest', $this->headers($device))->assertForbidden()->assertJsonPath('revoked', true);
        $this->getJson('/admin/filament-mailbox/offline/manifest')->assertForbidden();
    }

    public function test_changes_are_limited_sanitised_and_incremental(): void
    {
        $device = $this->device(['max_messages' => 2, 'days' => 7]);
        $old = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['received_at' => now()->subDays(10)]);
        $first = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['received_at' => now()->subDays(3), 'html_body' => '<p>Hi</p><script>alert(1)</script><img src="https://tracker.example/p.gif">']);
        $second = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['received_at' => now()->subDays(2)]);
        $third = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['received_at' => now()->subDay()]);
        $this->travel(1)->minutes();

        $response = $this->getJson('/admin/filament-mailbox/offline/changes?folder='.$this->inbox->id, $this->headers($device))->assertOk();

        $this->assertSame([$third->id, $second->id], $response->json('ids'));
        $this->assertEqualsCanonicalizing([$third->id, $second->id], array_column($response->json('messages'), 'id'));

        $this->travel(1)->minutes();
        $first->touch();
        $second->forceFill(['is_read' => true])->save();

        $next = $this->getJson('/admin/filament-mailbox/offline/changes?folder='.$this->inbox->id.'&since='.urlencode($response->json('cursor')), $this->headers($device))->assertOk();

        $this->assertSame([$second->id], array_column($next->json('messages'), 'id'));
        $this->assertTrue($next->json('messages.0.is_read'));
        $this->assertNotContains($old->id, $next->json('ids'));

        // HTML is sanitised on the server.
        $device->forceFill(['selection' => [...$device->selection, 'max_messages' => 10]])->save();
        $html = collect($this->getJson('/admin/filament-mailbox/offline/changes?folder='.$this->inbox->id, $this->headers($device))->json('messages'))->firstWhere('id', $first->id)['html'];
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('tracker.example', $html);
        $this->assertNotNull($device->refresh()->last_seen_at);
    }

    public function test_folders_outside_the_selection_or_without_access_are_not_delivered(): void
    {
        $device = $this->device();

        $this->getJson('/admin/filament-mailbox/offline/changes?folder='.$this->junk->id, $this->headers($device))->assertNotFound();

        $this->mailbox->users()->detach($this->user);
        app(MailboxAccess::class)->flush();

        $this->getJson('/admin/filament-mailbox/offline/manifest', $this->headers($device))->assertOk()->assertJsonPath('mailboxes', []);
        $this->getJson('/admin/filament-mailbox/offline/changes?folder='.$this->inbox->id, $this->headers($device))->assertNotFound();
    }

    public function test_attachments_only_within_the_limit_and_selection(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('mailbox/a.pdf', '%PDF');
        Storage::disk('local')->put('mailbox/b.pdf', '%PDF');

        $message = MailboxMessage::factory()->for($this->inbox, 'folder')->create(['has_attachments' => true]);
        $small = MailboxAttachment::factory()->for($message, 'message')->create(['size' => 1000, 'disk' => 'local', 'storage_path' => 'mailbox/a.pdf']);
        $large = MailboxAttachment::factory()->for($message, 'message')->create(['size' => 5 * 1024 * 1024, 'disk' => 'local', 'storage_path' => 'mailbox/b.pdf']);

        $without = $this->device(['attachments' => false]);
        $with = $this->device(['attachments' => true]);

        $this->get('/admin/filament-mailbox/offline/attachments/'.$small->id, $this->headers($without))->assertNotFound();
        $this->get('/admin/filament-mailbox/offline/attachments/'.$small->id, $this->headers($with))->assertOk();
        $this->get('/admin/filament-mailbox/offline/attachments/'.$large->id, $this->headers($with))->assertNotFound();

        $attachments = $this->getJson('/admin/filament-mailbox/offline/changes?folder='.$this->inbox->id, $this->headers($with))->json('messages.0.attachments');
        $this->assertSame([true, false], array_column($attachments, 'offline'));
    }

    public function test_devices_can_be_revoked_on_the_page(): void
    {
        $device = $this->device();
        $foreign = app(OfflineDeviceService::class)->register(User::make('Other'), []);

        Livewire::test(OfflineAvailability::class)
            ->assertSee($device->label)
            ->call('revokeDevice', $foreign->id)
            ->call('revokeDevice', $device->id)
            ->assertNotified(__('filament-mailbox::mailbox.offline.revoked'));

        $this->assertTrue($device->refresh()->isRevoked());
        $this->assertFalse($foreign->refresh()->isRevoked());
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    protected function device(array $selection = []): MailboxOfflineDevice
    {
        return app(OfflineDeviceService::class)->register($this->user, ['folder_ids' => [$this->inbox->id], ...$selection], 'Test browser');
    }

    /**
     * @return array<string, string>
     */
    protected function headers(MailboxOfflineDevice $device): array
    {
        return ['X-Mailbox-Offline-Device' => $device->device_uuid];
    }
}
