<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas;

use Cooolinho\FilamentMailbox\Enums\AuthMode;
use Cooolinho\FilamentMailbox\Enums\Encryption;
use Cooolinho\FilamentMailbox\Enums\OAuthProviderType;
use Cooolinho\FilamentMailbox\Enums\ProviderType;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Cooolinho\FilamentMailbox\Models\OAuthConnection;
use Cooolinho\FilamentMailbox\OAuth\OAuthConnectionManager;
use Cooolinho\FilamentMailbox\OAuth\OAuthFlow;
use Cooolinho\FilamentMailbox\OAuth\OAuthProviders;
use Cooolinho\FilamentMailbox\Statistics\BusinessHours;
use Cooolinho\FilamentMailbox\Statistics\StatisticsSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;
use Throwable;

class MailboxForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('filament-mailbox::mailbox.form.sections.general'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('filament-mailbox::mailbox.fields.name'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label(__('filament-mailbox::mailbox.fields.email'))
                        ->email()
                        ->required()
                        ->maxLength(255),
                    Select::make('provider')
                        ->label(__('filament-mailbox::mailbox.fields.provider'))
                        ->options(ProviderType::class)
                        ->default(ProviderType::Imap)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, ProviderType|string|null $state): void {
                            if (static::providerType($state)->requiresOAuth()) {
                                $set('auth_mode', AuthMode::OAuth);
                            }
                        }),
                    Select::make('compose_format')
                        ->label(__('filament-mailbox::mailbox.fields.compose_format'))
                        ->options([
                            ComposeMessageForm::FORMAT_HTML => __('filament-mailbox::mailbox.compose.formats.html'),
                            ComposeMessageForm::FORMAT_TEXT => __('filament-mailbox::mailbox.compose.formats.text'),
                        ])
                        ->placeholder(fn (): string => __('filament-mailbox::mailbox.form.compose_format_default', [
                            'format' => __('filament-mailbox::mailbox.compose.formats.'.ComposeMessageForm::defaultFormat()),
                        ])),
                    Select::make('request_read_receipts')
                        ->label(__('filament-mailbox::mailbox.read_receipts.mailbox_default'))
                        ->boolean(trueLabel: __('filament-mailbox::mailbox.read_receipts.always'), falseLabel: __('filament-mailbox::mailbox.read_receipts.never'))
                        ->placeholder(fn (): string => __('filament-mailbox::mailbox.form.compose_format_default', [
                            'format' => config('filament-mailbox.read_receipts.request_by_default', false)
                                ? __('filament-mailbox::mailbox.read_receipts.always')
                                : __('filament-mailbox::mailbox.read_receipts.never'),
                        ]))
                        ->visible(fn (): bool => \Cooolinho\FilamentMailbox\Services\Receipts\ReadReceiptService::enabled()),
                    Toggle::make('is_active')
                        ->label(__('filament-mailbox::mailbox.fields.is_active'))
                        ->default(true),
                ]),

            static::authenticationSection(),

            Section::make(__('filament-mailbox::mailbox.form.sections.imap'))
                ->visible(fn (Get $get): bool => static::providerType($get('provider'))->usesServerSettings())
                ->columns(2)
                ->schema([
                    TextInput::make('host')
                        ->label(__('filament-mailbox::mailbox.fields.host'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('port')
                        ->label(__('filament-mailbox::mailbox.fields.port'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(65535)
                        ->default(Encryption::Ssl->defaultPort())
                        ->required(),
                    Select::make('encryption')
                        ->label(__('filament-mailbox::mailbox.fields.encryption'))
                        ->options(Encryption::class)
                        ->default(Encryption::Ssl)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, Encryption|string|null $state): void {
                            $encryption = $state instanceof Encryption ? $state : Encryption::tryFrom((string) $state);

                            if ($encryption) {
                                $set('port', $encryption->defaultPort());
                            }
                        }),
                    Toggle::make('validate_cert')
                        ->label(__('filament-mailbox::mailbox.fields.validate_cert'))
                        ->default(true),
                    TextInput::make('username')
                        ->label(__('filament-mailbox::mailbox.fields.username'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('password')
                        ->label(__('filament-mailbox::mailbox.fields.password'))
                        ->password()
                        ->revealable()
                        ->maxLength(1024)
                        ->visible(fn (Get $get): bool => ! static::usesOAuth($get))
                        ->required(fn (string $operation, ?Mailbox $record): bool => $operation === Operation::Create->value || blank($record?->password))
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(fn (string $operation, ?Mailbox $record): ?string => $operation === Operation::Edit->value && filled($record?->password)
                            ? __('filament-mailbox::mailbox.form.password_keep')
                            : null),
                ]),

            Section::make(__('filament-mailbox::mailbox.form.sections.smtp'))
                ->visible(fn (Get $get): bool => static::providerType($get('provider'))->usesServerSettings())
                ->description(__('filament-mailbox::mailbox.form.smtp_help'))
                ->columns(2)
                ->collapsible()
                ->collapsed(fn (?Mailbox $record): bool => blank($record?->smtp_host))
                ->schema([
                    TextInput::make('smtp_host')
                        ->label(__('filament-mailbox::mailbox.fields.smtp_host'))
                        ->maxLength(255),
                    TextInput::make('smtp_port')
                        ->label(__('filament-mailbox::mailbox.fields.smtp_port'))
                        ->integer()
                        ->minValue(1)
                        ->maxValue(65535),
                ]),

            Section::make(__('filament-mailbox::mailbox.form.sections.folders'))
                ->description(__('filament-mailbox::mailbox.form.folders_help'))
                ->visible(fn (string $operation): bool => $operation === Operation::Edit->value)
                ->columns(2)
                ->schema([
                    static::folderSelect('archive_folder_id', SpecialUse::Archive),
                    static::folderSelect('spam_folder_id', SpecialUse::Junk),
                ]),

            static::businessHoursSection(),

            Section::make(__('filament-mailbox::mailbox.form.sections.access'))
                ->schema([
                    Select::make('users')
                        ->label(__('filament-mailbox::mailbox.fields.users'))
                        ->relationship('users', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable(),
                ]),
        ]);
    }

    /**
     * Business hours and SLA for the statistics (reply times within business hours).
     */
    protected static function businessHoursSection(): Section
    {
        return Section::make(__('filament-mailbox::mailbox.statistics.form.section'))
            ->description(__('filament-mailbox::mailbox.statistics.form.help'))
            ->visible(fn (): bool => StatisticsSettings::enabled())
            ->collapsible()
            ->collapsed(fn (?Mailbox $record): bool => blank($record?->business_hours) && blank($record?->sla_minutes))
            ->columns(2)
            ->schema([
                Select::make('business_hours.timezone')
                    ->label(__('filament-mailbox::mailbox.statistics.form.timezone'))
                    ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                    ->placeholder(fn (): string => (string) (config('filament-mailbox.statistics.timezone') ?: config('app.timezone')))
                    ->in(timezone_identifiers_list())
                    ->searchable(),
                TextInput::make('sla_minutes')
                    ->label(__('filament-mailbox::mailbox.statistics.form.sla_minutes'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(525600)
                    ->suffix('min')
                    ->placeholder(fn (): string => (string) config('filament-mailbox.statistics.sla_default_minutes', 240)),
                Repeater::make('business_hours.hours')
                    ->label(__('filament-mailbox::mailbox.statistics.form.hours'))
                    ->columns(3)
                    ->columnSpanFull()
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->addActionLabel(__('filament-mailbox::mailbox.statistics.form.add_hours'))
                    ->schema([
                        Select::make('day')
                            ->label(__('filament-mailbox::mailbox.statistics.form.day'))
                            ->options(collect(BusinessHours::DAYS)->mapWithKeys(fn (string $day): array => [$day => __('filament-mailbox::mailbox.statistics.days.'.$day)])->all())
                            ->required(),
                        TimePicker::make('start')
                            ->label(__('filament-mailbox::mailbox.statistics.form.start'))
                            ->seconds(false)
                            ->required(),
                        TimePicker::make('end')
                            ->label(__('filament-mailbox::mailbox.statistics.form.end'))
                            ->seconds(false)
                            ->after('start')
                            ->required(),
                    ]),
                TagsInput::make('business_hours.holidays')
                    ->label(__('filament-mailbox::mailbox.statistics.form.holidays'))
                    ->placeholder('2026-12-24')
                    ->nestedRecursiveRules(['date_format:Y-m-d'])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Folder of the edited mailbox for a role the server did not report.
     */
    protected static function folderSelect(string $name, SpecialUse $specialUse): Select
    {
        return Select::make($name)
            ->label(__("filament-mailbox::mailbox.fields.{$name}"))
            ->options(fn (?Mailbox $record): array => $record?->folders()->where('is_active', true)->orderBy('full_name')->pluck('full_name', 'id')->all() ?? [])
            ->searchable()
            ->placeholder(fn (?Mailbox $record): string => ($detected = $record?->folderFor($specialUse))
                ? __('filament-mailbox::mailbox.form.detected_folder', ['folder' => $detected->full_name])
                : __('filament-mailbox::mailbox.form.no_folder'))
            // Only folders of this mailbox are accepted.
            ->rule(fn (?Mailbox $record) => Rule::exists('mailbox_folders', 'id')->where('mailbox_id', $record?->getKey() ?? 0));
    }

    protected static function authenticationSection(): Section
    {
        $oauth = fn (Get $get): bool => static::usesOAuth($get);

        return Section::make(__('filament-mailbox::mailbox.form.sections.authentication'))
            ->columns(2)
            ->schema([
                ToggleButtons::make('auth_mode')
                    ->label(__('filament-mailbox::mailbox.fields.auth_mode'))
                    ->options(AuthMode::class)
                    ->default(AuthMode::Password)
                    ->disableOptionWhen(fn (Get $get, string $value): bool => $value === AuthMode::Password->value && static::providerType($get('provider'))->requiresOAuth())
                    ->in(fn (Get $get): array => static::providerType($get('provider'))->requiresOAuth() ? [AuthMode::OAuth->value] : array_column(AuthMode::cases(), 'value'))
                    ->inline()
                    ->required()
                    ->live()
                    ->columnSpanFull(),
                Select::make('oauth_application_id')
                    ->label(__('filament-mailbox::mailbox.oauth.fields.application'))
                    ->options(fn (Get $get): array => OAuthApplication::query()
                        ->when(static::providerType($get('provider'))->oauthProvider(), fn ($query, OAuthProviderType $provider) => $query->where('provider', $provider))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->afterStateHydrated(function (Select $component, ?Mailbox $record): void {
                        if ($record?->oauthConnection) {
                            $component->state($record->oauthConnection->application_id);
                        }
                    })
                    ->visible($oauth)
                    ->live()
                    ->dehydrated(false),
                TextEntry::make('oauth_account')
                    ->label(__('filament-mailbox::mailbox.oauth.fields.account'))
                    ->state(fn (Get $get): ?string => static::connection($get)?->account_email)
                    ->placeholder(__('filament-mailbox::mailbox.oauth.not_connected'))
                    ->badge()
                    ->color(fn (Get $get): string => static::connection($get)?->status->getColor() ?? 'gray')
                    ->helperText(fn (Get $get): ?string => static::connection($get)?->status->getLabel())
                    ->visible($oauth),
                Hidden::make('oauth_connection_id')
                    ->required($oauth)
                    ->validationMessages(['required' => __('filament-mailbox::mailbox.oauth.connect_first')]),
                TextInput::make('initial_sync_days')
                    ->label(__('filament-mailbox::mailbox.fields.initial_sync_days'))
                    ->helperText(__('filament-mailbox::mailbox.form.initial_sync_days_help', ['days' => config('filament-mailbox.gmail.initial_sync_days', 90)]))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(3650)
                    ->visible(fn (Get $get): bool => static::providerType($get('provider')) === ProviderType::Gmail),
                TextInput::make('remote_user')
                    ->label(__('filament-mailbox::mailbox.fields.remote_user'))
                    ->helperText(__('filament-mailbox::mailbox.form.remote_user_help'))
                    ->email()
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $oauth($get) && static::providerType($get('provider')) === ProviderType::Graph),
                Callout::make(fn (Get $get): string => match (true) {
                    static::connection($get)?->grant_type?->value === 'service_account' => __('filament-mailbox::mailbox.form.service_account_hint'),
                    static::providerType($get('provider')) === ProviderType::Graph => __('filament-mailbox::mailbox.form.graph_permissions_hint'),
                    default => __('filament-mailbox::mailbox.oauth.application_permissions_hint'),
                })
                    ->warning()
                    ->visible(fn (Get $get): bool => static::usesOAuth($get) && in_array(static::connection($get)?->grant_type?->value, ['client_credentials', 'service_account'], true))
                    ->columnSpanFull(),
                Actions::make([
                    static::connectAction(),
                    static::connectApplicationAction(),
                    static::disconnectAction(),
                ])
                    ->key('oauthActions')
                    ->visible($oauth)
                    ->columnSpanFull(),
            ]);
    }

    protected static function connectAction(): Action
    {
        return Action::make('connectOAuth')
            ->label(fn (Get $get): string => static::connection($get)
                ? __('filament-mailbox::mailbox.oauth.actions.reconnect')
                : __('filament-mailbox::mailbox.oauth.actions.connect', ['provider' => static::application($get)?->provider->getLabel() ?? 'OAuth']))
            ->icon(Heroicon::OutlinedLink)
            ->disabled(fn (Get $get): bool => static::application($get) === null)
            ->action(function (Get $get, CreateRecord|EditRecord $livewire, OAuthFlow $flow): void {
                $application = static::application($get);

                if (! $application) {
                    return;
                }

                $record = $livewire instanceof EditRecord ? $livewire->getRecord() : null;
                $returnUrl = $record
                    ? MailboxResource::getUrl('edit', ['record' => $record])
                    : MailboxResource::getUrl('create');

                $livewire->redirect($flow->begin($application, $returnUrl, $record, static::providerType($get('provider'))));
            });
    }

    protected static function connectApplicationAction(): Action
    {
        return Action::make('connectOAuthApplication')
            ->label(fn (Get $get): string => static::application($get)?->provider === OAuthProviderType::Google
                ? __('filament-mailbox::mailbox.oauth.actions.connect_service_account')
                : __('filament-mailbox::mailbox.oauth.actions.connect_application'))
            ->icon(Heroicon::OutlinedServerStack)
            ->color('gray')
            ->visible(fn (Get $get): bool => static::application($get) !== null)
            ->requiresConfirmation()
            ->modalDescription(fn (Get $get): string => static::application($get)?->provider === OAuthProviderType::Google
                ? __('filament-mailbox::mailbox.form.service_account_hint')
                : __('filament-mailbox::mailbox.oauth.application_permissions_hint'))
            ->action(function (Get $get, Set $set, OAuthConnectionManager $connections): void {
                $application = static::application($get);

                if (! $application || blank($get('email'))) {
                    Notification::make()->danger()->title(__('filament-mailbox::mailbox.oauth.email_required'))->send();

                    return;
                }

                try {
                    $connection = $connections->connectApplication($application, (string) $get('email'), static::providerType($get('provider')));
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()->danger()->title(__('filament-mailbox::mailbox.oauth.failed'))->body($exception->getMessage())->send();

                    return;
                }

                $set('oauth_connection_id', $connection->getKey());
                static::fillServerDefaults($get, $set, $application, $connection);

                Notification::make()->success()->title(__('filament-mailbox::mailbox.oauth.connected', ['account' => $connection->account_email]))->send();
            });
    }

    protected static function disconnectAction(): Action
    {
        return Action::make('disconnectOAuth')
            ->label(__('filament-mailbox::mailbox.oauth.actions.disconnect'))
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->visible(fn (Get $get): bool => static::connection($get) !== null)
            ->requiresConfirmation()
            ->modalDescription(__('filament-mailbox::mailbox.oauth.disconnect_description'))
            ->action(function (Set $set, CreateRecord|EditRecord $livewire, OAuthConnectionManager $connections): void {
                if ($livewire instanceof EditRecord) {
                    /** @var Mailbox $record */
                    $record = $livewire->getRecord();
                    $connections->disconnect($record);
                }

                $set('oauth_connection_id', null);
                $set('auth_mode', AuthMode::Password);

                Notification::make()->success()->title(__('filament-mailbox::mailbox.oauth.disconnected'))->send();
            });
    }

    /**
     * Form state for a freshly connected account, e.g. after the OAuth callback.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function stateForConnection(OAuthConnection $connection, array $state = [], ProviderType $provider = ProviderType::Imap): array
    {
        $servers = app(OAuthProviders::class)->servers($connection->application->provider);

        $state = [
            ...$state,
            'provider' => $provider,
            'auth_mode' => AuthMode::OAuth,
            'oauth_application_id' => $connection->application_id,
            'oauth_connection_id' => $connection->getKey(),
            'name' => filled($state['name'] ?? null) ? $state['name'] : $connection->account_email,
            'email' => filled($state['email'] ?? null) ? $state['email'] : $connection->account_email,
            'username' => $connection->account_email,
            'host' => $servers['imap']['host'],
            'port' => $servers['imap']['port'],
            'encryption' => Encryption::from($servers['imap']['encryption']),
        ];

        return $provider->usesServerSettings()
            ? $state
            : [...$state, 'host' => null, 'port' => null, 'encryption' => null, 'username' => null];
    }

    protected static function fillServerDefaults(Get $get, Set $set, OAuthApplication $application, OAuthConnection $connection): void
    {
        if (! static::providerType($get('provider'))->usesServerSettings()) {
            return;
        }

        $servers = app(OAuthProviders::class)->servers($application->provider);

        $set('username', $connection->account_email);

        if (blank($get('host')) || $get('host') !== $servers['imap']['host']) {
            $set('host', $servers['imap']['host']);
            $set('port', $servers['imap']['port']);
            $set('encryption', Encryption::from($servers['imap']['encryption']));
        }
    }

    public static function providerType(ProviderType|string|null $state): ProviderType
    {
        return $state instanceof ProviderType ? $state : (ProviderType::tryFrom((string) $state) ?? ProviderType::Imap);
    }

    protected static function usesOAuth(Get $get): bool
    {
        $mode = $get('auth_mode');

        return ($mode instanceof AuthMode ? $mode : AuthMode::tryFrom((string) $mode)) === AuthMode::OAuth;
    }

    protected static function application(Get $get): ?OAuthApplication
    {
        return filled($id = $get('oauth_application_id')) ? OAuthApplication::find($id) : null;
    }

    protected static function connection(Get $get): ?OAuthConnection
    {
        return filled($id = $get('oauth_connection_id')) ? OAuthConnection::find($id) : null;
    }
}
