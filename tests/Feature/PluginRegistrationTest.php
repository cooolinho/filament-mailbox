<?php

namespace Cooolinho\FilamentMailbox\Tests\Feature;

use Cooolinho\FilamentMailbox\FilamentMailboxPlugin;
use Cooolinho\FilamentMailbox\Tests\TestCase;
use Filament\Facades\Filament;

class PluginRegistrationTest extends TestCase
{
    public function test_plugin_is_registered_on_panel(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->hasPlugin(FilamentMailboxPlugin::ID));
        $this->assertInstanceOf(FilamentMailboxPlugin::class, $panel->getPlugin(FilamentMailboxPlugin::ID));
    }

    public function test_plugin_can_be_resolved_from_current_panel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertInstanceOf(FilamentMailboxPlugin::class, FilamentMailboxPlugin::get());
    }

    public function test_health_widgets_can_be_added_to_the_dashboard(): void
    {
        $panel = \Filament\Panel::make()->id('health');
        FilamentMailboxPlugin::make()->register($panel);
        $this->assertNotContains(\Cooolinho\FilamentMailbox\Filament\Widgets\MailboxHealthStatsWidget::class, $panel->getWidgets());

        $panel = \Filament\Panel::make()->id('health-dashboard');
        (new FilamentMailboxPlugin)->healthWidgetsOnDashboard()->register($panel);
        $this->assertContains(\Cooolinho\FilamentMailbox\Filament\Widgets\MailboxHealthStatsWidget::class, $panel->getWidgets());
    }

    public function test_config_is_merged(): void
    {
        $this->assertSame('local', config('filament-mailbox.attachments.disk'));
        $this->assertSame(50, config('filament-mailbox.sync.chunk_size'));
    }
}
