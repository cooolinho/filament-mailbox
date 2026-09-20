<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\OAuthApplications;

use BackedEnum;
use Cooolinho\FilamentMailbox\Enums\OAuthProviderType;
use Cooolinho\FilamentMailbox\FilamentMailboxPlugin;
use Cooolinho\FilamentMailbox\Filament\Resources\OAuthApplications\Pages\CreateOAuthApplication;
use Cooolinho\FilamentMailbox\Filament\Resources\OAuthApplications\Pages\EditOAuthApplication;
use Cooolinho\FilamentMailbox\Filament\Resources\OAuthApplications\Pages\ListOAuthApplications;
use Cooolinho\FilamentMailbox\Models\OAuthApplication;
use Cooolinho\FilamentMailbox\OAuth\OAuthFlow;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class OAuthApplicationResource extends Resource
{
    protected static ?string $model = OAuthApplication::class;

    protected static ?string $slug = 'mailbox-oauth-applications';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    public static function getModelLabel(): string
    {
        return __('filament-mailbox::mailbox.oauth.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-mailbox::mailbox.oauth.resource.plural_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return FilamentMailboxPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $sort = FilamentMailboxPlugin::get()->getNavigationSort();

        return $sort === null ? null : $sort + 1;
    }

    public static function form(Schema $schema): Schema
    {
        $isMicrosoft = fn (Get $get): bool => static::provider($get) === OAuthProviderType::Microsoft;

        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label(__('filament-mailbox::mailbox.fields.name'))
                        ->required()
                        ->maxLength(255),
                    Select::make('provider')
                        ->label(__('filament-mailbox::mailbox.oauth.fields.provider'))
                        ->options(OAuthProviderType::class)
                        ->default(OAuthProviderType::Microsoft)
                        ->required()
                        ->live(),
                    TextInput::make('tenant')
                        ->label(__('filament-mailbox::mailbox.oauth.fields.tenant'))
                        ->helperText(__('filament-mailbox::mailbox.oauth.fields.tenant_help'))
                        ->visible($isMicrosoft)
                        ->maxLength(255),
                    TextInput::make('client_id')
                        ->label(__('filament-mailbox::mailbox.oauth.fields.client_id'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('client_secret')
                        ->label(__('filament-mailbox::mailbox.oauth.fields.client_secret'))
                        ->password()
                        ->revealable()
                        ->maxLength(2048)
                        ->required(fn (string $operation, Get $get): bool => $operation === Operation::Create->value && blank($get('certificate')) && $isMicrosoft($get))
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(fn (string $operation): ?string => $operation === Operation::Edit->value
                            ? __('filament-mailbox::mailbox.oauth.fields.secret_keep')
                            : null),
                    Textarea::make('certificate')
                        ->label(fn (Get $get): string => $isMicrosoft($get)
                            ? __('filament-mailbox::mailbox.oauth.fields.certificate')
                            : __('filament-mailbox::mailbox.oauth.fields.service_account_key'))
                        ->helperText(fn (Get $get): string => $isMicrosoft($get)
                            ? __('filament-mailbox::mailbox.oauth.fields.certificate_help')
                            : __('filament-mailbox::mailbox.oauth.fields.service_account_key_help'))
                        ->rule(fn (Get $get): ?\Closure => $isMicrosoft($get) ? null : function (string $attribute, mixed $value, \Closure $fail): void {
                            $key = json_decode((string) $value, true);

                            if (filled($value) && (! is_array($key) || ! isset($key['private_key'], $key['client_email']))) {
                                $fail(__('filament-mailbox::mailbox.oauth.fields.service_account_key_invalid'));
                            }
                        })
                        ->rows(4)
                        ->maxLength(20_000)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->columnSpanFull(),
                    TextInput::make('redirect_uri')
                        ->label(__('filament-mailbox::mailbox.oauth.fields.redirect_uri'))
                        ->helperText(__('filament-mailbox::mailbox.oauth.fields.redirect_uri_help'))
                        ->formatStateUsing(fn (Get $get): ?string => ($provider = static::provider($get))
                            ? app(OAuthFlow::class)->redirectUri(new OAuthApplication(['provider' => $provider]))
                            : null)
                        ->readOnly()
                        ->copyable()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament-mailbox::mailbox.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('provider')
                    ->label(__('filament-mailbox::mailbox.oauth.fields.provider'))
                    ->badge(),
                TextColumn::make('client_id')
                    ->label(__('filament-mailbox::mailbox.oauth.fields.client_id'))
                    ->toggleable(),
                TextColumn::make('connections_count')
                    ->label(__('filament-mailbox::mailbox.oauth.fields.connections'))
                    ->counts('connections'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOAuthApplications::route('/'),
            'create' => CreateOAuthApplication::route('/create'),
            'edit' => EditOAuthApplication::route('/{record}/edit'),
        ];
    }

    protected static function provider(Get $get): ?OAuthProviderType
    {
        $state = $get('provider');

        return $state instanceof OAuthProviderType ? $state : OAuthProviderType::tryFrom((string) $state);
    }
}
