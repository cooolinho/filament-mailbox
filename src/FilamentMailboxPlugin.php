<?php

namespace Cooolinho\FilamentMailbox;

use Closure;
use Cooolinho\FilamentMailbox\Enums\OAuthProviderType;
use Cooolinho\FilamentMailbox\Filament\Pages\MailboxHealth;
use Cooolinho\FilamentMailbox\Filament\Pages\MailboxSettings;
use Cooolinho\FilamentMailbox\Filament\Pages\OfflineAvailability;
use Cooolinho\FilamentMailbox\Http\Controllers\OfflineController;
use Cooolinho\FilamentMailbox\Services\Offline\OfflineDeviceService;
use Cooolinho\FilamentMailbox\Filament\Pages\MailboxStatistics;
use Cooolinho\FilamentMailbox\Filament\Pages\MySignatures;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Filament\Resources\MailboxAlerts\MailboxAlertResource;
use Cooolinho\FilamentMailbox\Filament\Resources\MailboxTags\MailboxTagResource;
use Cooolinho\FilamentMailbox\Filament\Resources\MailboxTemplates\MailboxTemplateResource;
use Cooolinho\FilamentMailbox\Filament\Resources\OAuthApplications\OAuthApplicationResource;
use Cooolinho\FilamentMailbox\Filament\Widgets\MailboxHealthStatsWidget;
use Cooolinho\FilamentMailbox\Http\Controllers\DownloadAttachmentController;
use Cooolinho\FilamentMailbox\Http\Controllers\MailNotificationController;
use Cooolinho\FilamentMailbox\Http\Controllers\OAuthCallbackController;
use Cooolinho\FilamentMailbox\Search\SearchEngineRegistry;
use Cooolinho\FilamentMailbox\Services\NotificationPreferences;
use Cooolinho\FilamentMailbox\Http\Controllers\PwaController;
use Cooolinho\FilamentMailbox\Pwa\Pwa;
use Cooolinho\FilamentMailbox\Pwa\PwaManifestFactory;
use Cooolinho\FilamentMailbox\OAuth\OAuthFlow;
use Cooolinho\FilamentMailbox\Services\AttachmentService;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Filament\Support\Concerns\EvaluatesClosures;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use UnitEnum;

class FilamentMailboxPlugin implements Plugin
{
    use EvaluatesClosures;

    public const ID = 'filament-mailbox';

    protected string|UnitEnum|Closure|null $navigationGroup = null;

    protected int|Closure|null $navigationSort = null;

    protected bool $healthWidgetsOnDashboard = false;

    protected bool|Closure|null $pwa = null;

    protected string|Closure|null $pwaName = null;

    /** @var array<string, string>|Closure|null */
    protected array|Closure|null $pwaIcons = null;

    protected string|Closure|null $pwaStartUrl = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static */
        return filament(static::ID);
    }

    public function getId(): string
    {
        return static::ID;
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                MailboxResource::class,
                OAuthApplicationResource::class,
                MailboxTagResource::class,
                MailboxTemplateResource::class,
                MailboxAlertResource::class,
            ])
            ->pages([
                MySignatures::class,
                MailboxHealth::class,
                MailboxStatistics::class,
                MailboxSettings::class,
                OfflineAvailability::class,
            ])
            ->userMenuItems([
                'mailbox-signatures' => Action::make('mailboxSignatures')
                    ->label(fn (): string => __('filament-mailbox::mailbox.signatures.personal_title'))
                    ->icon(Heroicon::OutlinedPencil)
                    ->url(fn (): string => MySignatures::getUrl())
                    ->visible(fn (): bool => MySignatures::canAccess()),
                'mailbox-offline' => Action::make('mailboxOffline')
                    ->label(fn (): string => __('filament-mailbox::mailbox.offline.title'))
                    ->icon(Heroicon::OutlinedCloudArrowDown)
                    ->url(fn (): string => OfflineAvailability::getUrl())
                    ->visible(fn (): bool => OfflineAvailability::canAccess()),
                'mailbox-settings' => Action::make('mailboxSettings')
                    ->label(fn (): string => __('filament-mailbox::mailbox.notifications.settings.title'))
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->url(fn (): string => MailboxSettings::getUrl())
                    ->visible(fn (): bool => MailboxSettings::canAccess()),
            ])
            ->widgets($this->healthWidgetsOnDashboard ? [MailboxHealthStatsWidget::class] : [])
            ->routes(function (): void {
                // Public: browsers load manifest and service worker without cookies.
                Route::get('filament-mailbox/manifest.webmanifest', [PwaController::class, 'manifest'])->name('filament-mailbox.pwa.manifest');
                Route::get('filament-mailbox-sw.js', [PwaController::class, 'serviceWorker'])->name('filament-mailbox.pwa.service-worker');
                Route::get('filament-mailbox/offline', [PwaController::class, 'offline'])->name('filament-mailbox.pwa.offline');
                // App shell of the offline copy, without personal data.
                Route::get('filament-mailbox/offline/app', [OfflineController::class, 'shell'])->name('filament-mailbox.offline.app');
                Route::get('filament-mailbox/pwa/{icon}.png', [PwaController::class, 'icon'])
                    ->whereIn('icon', Pwa::ICONS)
                    ->name('filament-mailbox.pwa.icon');
            })
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): ?View => $this->isPwaEnabled() ? view('filament-mailbox::pwa.head', [
                'manifestUrl' => Pwa::manifestUrl($panel),
                'themeColor' => app(PwaManifestFactory::class)->themeColor($panel),
                'name' => $this->getPwaName() ?? (string) (config('filament-mailbox.pwa.name') ?: config('app.name')),
                'appleTouchIcon' => Pwa::iconUrl($panel, 'apple-touch-icon'),
            ]) : null)
            ->renderHook(PanelsRenderHook::BODY_END, fn (): ?View => $this->isPwaEnabled() ? view('filament-mailbox::pwa.register', [
                'serviceWorkerUrl' => Pwa::serviceWorkerUrl($panel),
                'scope' => Pwa::scope($panel),
                'logoutUrl' => $panel->getLogoutUrl(),
            ]) : null)
            ->renderHook(PanelsRenderHook::BODY_END, fn (): ?View => NotificationPreferences::enabled() && config('filament-mailbox.notifications.browser', true) && Filament::auth()->check()
                ? view('filament-mailbox::notifications.browser', [
                    'endpoint' => Pwa::url($panel, 'filament-mailbox/notifications/latest'),
                    'icon' => Pwa::url($panel, 'filament-mailbox/pwa/icon-192.png'),
                    'interval' => max(10, (int) config('filament-mailbox.notifications.browser_poll_seconds', 30)),
                ])
                : null)
            ->renderHook(PanelsRenderHook::BODY_END, fn (): ?View => $this->isPwaEnabled() && OfflineDeviceService::enabled() && Filament::auth()->check()
                ? view('filament-mailbox::offline.sync', [
                    'baseUrl' => Pwa::url($panel, 'filament-mailbox/offline'),
                    'user' => OfflineAvailability::userHash(Filament::auth()->user()),
                    'interval' => max(1, (int) config('filament-mailbox.offline.sync_minutes', 5)),
                ])
                : null)
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): ?View => $this->isPwaEnabled() ? view('filament-mailbox::pwa.install-button') : null)
            ->authenticatedRoutes(function (): void {
                Route::get('filament-mailbox/notifications/latest', [MailNotificationController::class, 'latest'])
                    ->middleware('throttle:60,1')
                    ->name('filament-mailbox.notifications.latest');
                Route::post('filament-mailbox/push-subscriptions', [MailNotificationController::class, 'subscribe'])
                    ->middleware('throttle:20,1')
                    ->name('filament-mailbox.push-subscriptions.store');
                Route::delete('filament-mailbox/push-subscriptions', [MailNotificationController::class, 'unsubscribe'])
                    ->middleware('throttle:20,1')
                    ->name('filament-mailbox.push-subscriptions.destroy');

                Route::middleware('throttle:120,1')->group(function (): void {
                    Route::get('filament-mailbox/offline/manifest', [OfflineController::class, 'manifest'])->name('filament-mailbox.offline.manifest');
                    Route::get('filament-mailbox/offline/changes', [OfflineController::class, 'changes'])->name('filament-mailbox.offline.changes');
                    Route::get('filament-mailbox/offline/key', [OfflineController::class, 'key'])->name('filament-mailbox.offline.key');
                    Route::get('filament-mailbox/offline/attachments/{attachment}', [OfflineController::class, 'attachment'])
                        ->whereNumber('attachment')
                        ->name('filament-mailbox.offline.attachment');
                });

                Route::get('filament-mailbox/launch/{target}', [PwaController::class, 'launch'])
                    ->whereIn('target', ['inbox', 'compose'])
                    ->name('filament-mailbox.pwa.launch');

                Route::get('filament-mailbox/attachments/{attachment}', DownloadAttachmentController::class)
                    ->whereNumber('attachment')
                    ->name(AttachmentService::DOWNLOAD_ROUTE);

                Route::get('filament-mailbox/oauth/{provider}/callback', OAuthCallbackController::class)
                    ->whereIn('provider', array_column(OAuthProviderType::cases(), 'value'))
                    ->name(OAuthFlow::CALLBACK_ROUTE);
            });
    }

    public function boot(Panel $panel): void
    {
        //
    }

    /**
     * @param  Closure(Authenticatable): bool  $callback
     */
    public function canManageMailboxes(Closure $callback): static
    {
        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE, $callback);

        return $this;
    }

    /**
     * @param  Closure(Authenticatable): bool  $callback
     */
    public function canSendMessages(Closure $callback): static
    {
        app(MailboxAuthorization::class)->using(MailboxAuthorization::SEND, $callback);

        return $this;
    }

    /**
     * @param  Closure(Authenticatable): bool  $callback
     */
    public function canDeleteMessages(Closure $callback): static
    {
        app(MailboxAuthorization::class)->using(MailboxAuthorization::DELETE, $callback);

        return $this;
    }

    /**
     * Managing the tag catalogue (default: may manage mailboxes).
     *
     * @param  Closure(Authenticatable): bool  $callback
     */
    public function canManageTags(Closure $callback): static
    {
        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE_TAGS, $callback);

        return $this;
    }

    /**
     * Managing the template catalogue (default: may manage mailboxes).
     *
     * @param  Closure(Authenticatable): bool  $callback
     */
    public function canManageTemplates(Closure $callback): static
    {
        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE_TEMPLATES, $callback);

        return $this;
    }

    /**
     * Managing the folders of mailboxes (default: may manage mailboxes).
     *
     * @param  Closure(Authenticatable): bool  $callback
     */
    public function canManageFolders(Closure $callback): static
    {
        app(MailboxAuthorization::class)->using(MailboxAuthorization::MANAGE_FOLDERS, $callback);

        return $this;
    }

    /**
     * Tagging messages of assigned mailboxes (default: everyone assigned).
     *
     * @param  Closure(Authenticatable): bool  $callback
     */
    public function canTagMessages(Closure $callback): static
    {
        app(MailboxAuthorization::class)->using(MailboxAuthorization::TAG, $callback);

        return $this;
    }

    /**
     * Register an own search engine (search.engine = <name>).
     *
     * @param  Closure(\Illuminate\Contracts\Foundation\Application): \Cooolinho\FilamentMailbox\Search\Contracts\MessageSearchEngine  $factory
     */
    public function searchEngine(string $name, Closure $factory): static
    {
        app(SearchEngineRegistry::class)->register($name, $factory);

        return $this;
    }

    /**
     * Show the mailbox health key figures on the panel dashboard (managers only).
     */
    public function healthWidgetsOnDashboard(bool $condition = true): static
    {
        $this->healthWidgetsOnDashboard = $condition;

        return $this;
    }

    /**
     * Installable app (manifest, service worker for static assets, offline page, shortcuts).
     * Default: config "filament-mailbox.pwa.enabled".
     */
    public function pwa(bool|Closure $condition = true): static
    {
        $this->pwa = $condition;

        return $this;
    }

    public function isPwaEnabled(): bool
    {
        return (bool) ($this->evaluate($this->pwa) ?? config('filament-mailbox.pwa.enabled', false));
    }

    public function pwaName(string|Closure|null $name): static
    {
        $this->pwaName = $name;

        return $this;
    }

    public function getPwaName(): ?string
    {
        return $this->evaluate($this->pwaName);
    }

    /**
     * @param  array<string, string>|Closure|null  $icons  URLs keyed by "icon-192", "icon-512", "maskable-512", "apple-touch-icon"
     */
    public function pwaIcons(array|Closure|null $icons): static
    {
        $this->pwaIcons = $icons;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getPwaIcons(): array
    {
        return (array) $this->evaluate($this->pwaIcons);
    }

    public function pwaStartUrl(string|Closure|null $url): static
    {
        $this->pwaStartUrl = $url;

        return $this;
    }

    public function getPwaStartUrl(): ?string
    {
        return $this->evaluate($this->pwaStartUrl);
    }

    public function navigationGroup(string|UnitEnum|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): string|UnitEnum|null
    {
        return $this->evaluate($this->navigationGroup);
    }

    public function navigationSort(int|Closure|null $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        return $this->evaluate($this->navigationSort);
    }
}
