<?php

namespace Cooolinho\FilamentMailbox;

use Cooolinho\FilamentMailbox\Commands\AggregateStatisticsCommand;
use Cooolinho\FilamentMailbox\Commands\BackfillStatisticsCommand;
use Cooolinho\FilamentMailbox\Commands\GenerateVapidKeysCommand;
use Cooolinho\FilamentMailbox\Commands\GmailWatchCommand;
use Cooolinho\FilamentMailbox\Commands\GraphSubscriptionsCommand;
use Cooolinho\FilamentMailbox\Commands\MonitorMailboxesCommand;
use Cooolinho\FilamentMailbox\Commands\PruneComposeUploadsCommand;
use Cooolinho\FilamentMailbox\Commands\PruneDraftsCommand;
use Cooolinho\FilamentMailbox\Commands\PruneMonitoringCommand;
use Cooolinho\FilamentMailbox\Commands\PruneTagAssignmentsCommand;
use Cooolinho\FilamentMailbox\Commands\PruneOutboxCommand;
use Cooolinho\FilamentMailbox\Commands\SendDueMessagesCommand;
use Cooolinho\FilamentMailbox\Commands\SearchExtractAttachmentsCommand;
use Cooolinho\FilamentMailbox\Commands\SearchReindexCommand;
use Cooolinho\FilamentMailbox\Commands\SearchTasksCheckCommand;
use Cooolinho\FilamentMailbox\Commands\SyncMailboxCommand;
use Cooolinho\FilamentMailbox\Commands\WakeSnoozedMessagesCommand;
use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Filament\Livewire\MessagePreview;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Events\OAuthConnectionRevoked;
use Cooolinho\FilamentMailbox\Events\SyncRunFinished;
use Cooolinho\FilamentMailbox\Http\Controllers\MetricsController;
use Cooolinho\FilamentMailbox\Listeners\EvaluateSyncRunAlerts;
use Cooolinho\FilamentMailbox\Listeners\UpdateMailboxHealth;
use Cooolinho\FilamentMailbox\Listeners\NotifyUsersAboutNewMessages;
use Cooolinho\FilamentMailbox\Events\MessagesImported;
use Cooolinho\FilamentMailbox\Monitoring\OpenTelemetry\TracingSyncObserver;
use Cooolinho\FilamentMailbox\Monitoring\Pulse\Cards\MailboxSyncFailures;
use Cooolinho\FilamentMailbox\Monitoring\Pulse\Cards\MailboxSyncs;
use Cooolinho\FilamentMailbox\Monitoring\Pulse\RecordSyncRunInPulse;
use Cooolinho\FilamentMailbox\Monitoring\SyncErrorClassifier;
use Cooolinho\FilamentMailbox\Http\Controllers\GmailPushController;
use Cooolinho\FilamentMailbox\Http\Controllers\GraphWebhookController;
use Cooolinho\FilamentMailbox\Listeners\HandleRevokedOAuthConnection;
use Cooolinho\FilamentMailbox\Models\MailboxAlert;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Search\SearchIndexObserver;
use Cooolinho\FilamentMailbox\Search\SearchEngineRegistry;
use Cooolinho\FilamentMailbox\Search\SearchManager;
use Cooolinho\FilamentMailbox\Models\MailboxDraft;
use Cooolinho\FilamentMailbox\Models\MailboxLabel;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxOutgoingMessage;
use Cooolinho\FilamentMailbox\Models\MailboxSignature;
use Cooolinho\FilamentMailbox\Models\MailboxTag;
use Cooolinho\FilamentMailbox\Models\MailboxTemplate;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Cooolinho\FilamentMailbox\Policies\MailboxAlertPolicy;
use Cooolinho\FilamentMailbox\Policies\MailboxDraftPolicy;
use Cooolinho\FilamentMailbox\Policies\MailboxLabelPolicy;
use Cooolinho\FilamentMailbox\Policies\MailboxMessagePolicy;
use Cooolinho\FilamentMailbox\Policies\MailboxOutgoingMessagePolicy;
use Cooolinho\FilamentMailbox\Policies\MailboxSignaturePolicy;
use Cooolinho\FilamentMailbox\Policies\MailboxPolicy;
use Cooolinho\FilamentMailbox\Policies\MailboxTagPolicy;
use Cooolinho\FilamentMailbox\Policies\MailboxTemplatePolicy;
use Cooolinho\FilamentMailbox\Policies\OAuthApplicationPolicy;
use Cooolinho\FilamentMailbox\Providers\DefaultMailboxProviderFactory;
use Cooolinho\FilamentMailbox\Support\MailboxAccess;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Cooolinho\FilamentMailbox\Support\ProviderCapabilities;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\Livewire\Card as PulseCard;
use Cooolinho\FilamentMailbox\Mail\Transports\DsnEsmtpTransport;
use Illuminate\Mail\MailManager;
use Livewire\Livewire;
use OpenTelemetry\API\Globals;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentMailboxServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-mailbox';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            ->hasCommands([SyncMailboxCommand::class, GraphSubscriptionsCommand::class, GmailWatchCommand::class, PruneTagAssignmentsCommand::class, MonitorMailboxesCommand::class, PruneMonitoringCommand::class, PruneComposeUploadsCommand::class, PruneDraftsCommand::class, AggregateStatisticsCommand::class, BackfillStatisticsCommand::class, GenerateVapidKeysCommand::class, WakeSnoozedMessagesCommand::class, SendDueMessagesCommand::class, PruneOutboxCommand::class, SearchReindexCommand::class, SearchExtractAttachmentsCommand::class, SearchTasksCheckCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->app->bindIf(MailboxProviderFactory::class, DefaultMailboxProviderFactory::class);
        $this->app->singletonIf(MailboxAuthorization::class);
        $this->app->scoped(MailboxAccess::class);
        $this->app->scoped(ProviderCapabilities::class);
        $this->app->scoped(SearchManager::class);
        $this->app->singleton(SearchEngineRegistry::class);

        // Uses the globally configured OpenTelemetry SDK (e.g. OTEL_PHP_AUTOLOAD_ENABLED).
        $this->app->bindIf(TracingSyncObserver::class, fn ($app) => new TracingSyncObserver(Globals::tracerProvider(), $app->make(SyncErrorClassifier::class)));
    }

    public function packageBooted(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Applications may register their own policies instead.
        if (! Gate::getPolicyFor(Mailbox::class)) {
            Gate::policy(Mailbox::class, MailboxPolicy::class);
        }

        if (! Gate::getPolicyFor(MailboxMessage::class)) {
            Gate::policy(MailboxMessage::class, MailboxMessagePolicy::class);
        }

        if (! Gate::getPolicyFor(MailboxLabel::class)) {
            Gate::policy(MailboxLabel::class, MailboxLabelPolicy::class);
        }

        if (! Gate::getPolicyFor(MailboxTag::class)) {
            Gate::policy(MailboxTag::class, MailboxTagPolicy::class);
        }

        if (! Gate::getPolicyFor(MailboxDraft::class)) {
            Gate::policy(MailboxDraft::class, MailboxDraftPolicy::class);
        }

        if (! Gate::getPolicyFor(MailboxOutgoingMessage::class)) {
            Gate::policy(MailboxOutgoingMessage::class, MailboxOutgoingMessagePolicy::class);
        }

        if (! Gate::getPolicyFor(MailboxTemplate::class)) {
            Gate::policy(MailboxTemplate::class, MailboxTemplatePolicy::class);
        }

        if (! Gate::getPolicyFor(MailboxSignature::class)) {
            Gate::policy(MailboxSignature::class, MailboxSignaturePolicy::class);
        }

        if (! Gate::getPolicyFor(MailboxAlert::class)) {
            Gate::policy(MailboxAlert::class, MailboxAlertPolicy::class);
        }

        if (! Gate::getPolicyFor(OAuthApplication::class)) {
            Gate::policy(OAuthApplication::class, OAuthApplicationPolicy::class);
        }

        Event::listen(OAuthConnectionRevoked::class, HandleRevokedOAuthConnection::class);
        Event::listen(SyncRunFinished::class, EvaluateSyncRunAlerts::class);
        // After the alerts: open critical alerts rate a mailbox as failing.
        Event::listen(SyncRunFinished::class, UpdateMailboxHealth::class);
        Event::listen(MessagesImported::class, NotifyUsersAboutNewMessages::class);

        // Search engines with an own index (database, Meilisearch, ...).
        MailboxMessage::saved(fn (MailboxMessage $message) => app(SearchIndexObserver::class)->messageSaved($message));
        MailboxMessage::deleted(fn (MailboxMessage $message) => app(SearchIndexObserver::class)->messageDeleted($message));
        MailboxAttachment::created(fn (MailboxAttachment $attachment) => app(SearchIndexObserver::class)->attachmentCreated($attachment));

        Livewire::component('filament-mailbox.message-preview', MessagePreview::class);

        // SMTP with delivery status notifications for the app's mailers: 'transport' => 'mailbox-dsn'.
        $this->callAfterResolving('mail.manager', function (MailManager $manager): void {
            $manager->extend('mailbox-dsn', fn (array $config): DsnEsmtpTransport => DsnEsmtpTransport::fromConfig($config));
        });

        $this->registerPulse();

        // Public endpoint for Microsoft Graph, outside the panel authentication.
        Route::post('filament-mailbox/webhooks/graph', GraphWebhookController::class)
            ->middleware('throttle:120,1')
            ->name('filament-mailbox.webhooks.graph');

        // Prometheus scraper endpoint; answers 404 unless enabled.
        Route::get('filament-mailbox/metrics', MetricsController::class)
            ->middleware('throttle:60,1')
            ->name('filament-mailbox.metrics');

        Route::post('filament-mailbox/webhooks/gmail', GmailPushController::class)
            ->middleware('throttle:120,1')
            ->name('filament-mailbox.webhooks.gmail');
    }

    /**
     * Laravel Pulse is optional: recorder and cards are only registered when it is installed.
     */
    protected function registerPulse(): void
    {
        if (! config('filament-mailbox.monitoring.pulse', true) || ! class_exists(Pulse::class) || ! class_exists(PulseCard::class)) {
            return;
        }

        Event::listen(SyncRunFinished::class, RecordSyncRunInPulse::class);

        Livewire::component('filament-mailbox.pulse.syncs', MailboxSyncs::class);
        Livewire::component('filament-mailbox.pulse.sync-failures', MailboxSyncFailures::class);
    }
}
