<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\FilamentMailboxPlugin;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Pwa\Pwa;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Facades\Filament;
use Livewire\Livewire;

class PwaTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filament-mailbox.pwa.enabled', true);
        $app['config']->set('filament-mailbox.pwa.name', 'Team Mail');
        $app['config']->set('app.url', 'http://localhost');
    }

    public function test_the_manifest_describes_the_installable_panel(): void
    {
        auth()->logout();

        $response = $this->get('/admin/filament-mailbox/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json');

        $manifest = $response->json();

        $this->assertSame('Team Mail', $manifest['name']);
        $this->assertSame('Mail', $manifest['short_name']);
        $this->assertSame('/admin/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('http://localhost/admin/filament-mailbox/launch/inbox', $manifest['start_url']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $manifest['theme_color']);
        $this->assertSame(['192x192', '512x512', '512x512'], array_column($manifest['icons'], 'sizes'));
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
        $this->assertSame(['http://localhost/admin/filament-mailbox/launch/compose', 'http://localhost/admin/filament-mailbox/launch/inbox'], array_column($manifest['shortcuts'], 'url'));
    }

    public function test_plugin_options_override_name_icons_and_start_url(): void
    {
        FilamentMailboxPlugin::get()
            ->pwaName('Support Desk')
            ->pwaIcons(['icon-192' => 'https://cdn.example.com/192.png'])
            ->pwaStartUrl('https://mail.example.com/admin/mailboxes/1');

        $manifest = $this->get('/admin/filament-mailbox/manifest.webmanifest')->json();

        $this->assertSame('Support Desk', $manifest['name']);
        $this->assertSame('https://cdn.example.com/192.png', $manifest['icons'][0]['src']);
        $this->assertSame('https://mail.example.com/admin/mailboxes/1', $manifest['start_url']);
    }

    public function test_the_service_worker_is_scoped_uncached_and_only_caches_static_assets(): void
    {
        auth()->logout();

        $response = $this->get('/admin/filament-mailbox-sw.js')
            ->assertOk()
            ->assertHeader('Service-Worker-Allowed', '/admin/')
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');

        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));

        $script = $response->getContent();
        $this->assertStringContainsString('"fm-static-" + VERSION', str_replace("'", '"', $script));
        $this->assertStringContainsString(Pwa::version(), $script);
        $this->assertStringContainsString("request.mode === 'navigate'", $script);
        $this->assertStringContainsString("! url.pathname.includes('/livewire')", $script);
        $this->assertStringContainsString('http:\/\/localhost\/admin\/filament-mailbox\/offline', $script);
    }

    public function test_offline_page_and_icons_are_public(): void
    {
        auth()->logout();

        $this->get('/admin/filament-mailbox/offline')->assertOk()->assertSee(__('filament-mailbox::mailbox.pwa.offline.title'));

        $this->get('/admin/filament-mailbox/pwa/icon-192.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/admin/filament-mailbox/pwa/maskable-512.png')->assertOk();
        $this->get('/admin/filament-mailbox/pwa/secret.png')->assertNotFound();
    }

    public function test_the_endpoints_answer_404_when_disabled(): void
    {
        config(['filament-mailbox.pwa.enabled' => false]);

        $this->get('/admin/filament-mailbox/manifest.webmanifest')->assertNotFound();
        $this->get('/admin/filament-mailbox-sw.js')->assertNotFound();
        $this->get('/admin/filament-mailbox/offline')->assertNotFound();

        $this->get(MailboxResource::getUrl())->assertOk()->assertDontSee('manifest.webmanifest');
    }

    public function test_panel_pages_link_the_manifest_and_register_the_service_worker(): void
    {
        $this->get(MailboxResource::getUrl())
            ->assertOk()
            ->assertSee('<link rel="manifest" href="http://localhost/admin/filament-mailbox/manifest.webmanifest">', false)
            ->assertSee('name="theme-color"', false)
            ->assertSee('navigator.serviceWorker.register', false)
            ->assertSee('filament-mailbox-pwa-installable', false)
            ->assertSee("'".str_replace('/', '\\/', Filament::getPanel('admin')->getLogoutUrl())."'", false);
    }

    public function test_launch_opens_the_inbox_of_the_first_assigned_mailbox(): void
    {
        $this->get('/admin/filament-mailbox/launch/inbox')->assertRedirect(MailboxResource::getUrl());

        $mailbox = Mailbox::factory()->create(['name' => 'B Support']);
        $mailbox->users()->attach($this->user);
        $inbox = MailboxFolder::factory()->for($mailbox)->inbox()->create();
        Mailbox::factory()->create(['name' => 'A Not assigned']);

        $this->get('/admin/filament-mailbox/launch/inbox')
            ->assertRedirect(MailboxResource::getUrl('browse', ['record' => $mailbox, 'folder' => $inbox->id]));

        $this->get('/admin/filament-mailbox/launch/compose')
            ->assertRedirect(MailboxResource::getUrl('browse', ['record' => $mailbox, 'folder' => $inbox->id, 'action' => 'compose']));

        $this->get('/admin/filament-mailbox/launch/other')->assertNotFound();

        auth()->logout();
        $this->get('/admin/filament-mailbox/launch/inbox')->assertRedirect();
        $this->assertGuest();
    }

    public function test_the_compose_shortcut_opens_the_compose_form(): void
    {
        $mailbox = Mailbox::factory()->create();
        $mailbox->users()->attach($this->user);
        MailboxFolder::factory()->for($mailbox)->inbox()->create();

        Livewire::withQueryParams(['action' => 'compose'])
            ->test(BrowseMailbox::class, ['record' => $mailbox->getRouteKey()])
            // Filament mounts the default action when the page is initialised in the browser.
            ->assertSet('defaultAction', 'compose')
            ->assertSeeHtml('wire:init="mountAction(')
            ->assertActionVisible('compose');
    }
}
