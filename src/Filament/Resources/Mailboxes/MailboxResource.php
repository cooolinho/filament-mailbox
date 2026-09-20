<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes;

use BackedEnum;
use Cooolinho\FilamentMailbox\FilamentMailboxPlugin;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\CreateMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\EditDraft;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\EditMailbox;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ListDrafts;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ListOutgoingMessages;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ListMailboxes;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ManageFolders;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\ViewMessage;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\RelationManagers\BlockedSendersRelationManager;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\RelationManagers\LabelsRelationManager;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\RelationManagers\SignaturesRelationManager;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\RelationManagers\SyncRunsRelationManager;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\MailboxForm;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Tables\MailboxesTable;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class MailboxResource extends Resource
{
    protected static ?string $model = Mailbox::class;

    protected static ?string $slug = 'mailboxes';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    public static function getModelLabel(): string
    {
        return __('filament-mailbox::mailbox.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-mailbox::mailbox.resource.plural_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return FilamentMailboxPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return FilamentMailboxPlugin::get()->getNavigationSort();
    }

    /**
     * Managers see every mailbox, all other users only their assigned ones.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return app(MailboxAuthorization::class)->canManage($user)
            ? $query
            : $query->assignedTo($user);
    }

    public static function form(Schema $schema): Schema
    {
        return MailboxForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MailboxesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SignaturesRelationManager::class,
            LabelsRelationManager::class,
            BlockedSendersRelationManager::class,
            SyncRunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMailboxes::route('/'),
            'create' => CreateMailbox::route('/create'),
            'edit' => EditMailbox::route('/{record}/edit'),
            'folders' => ManageFolders::route('/{record}/folders'),
            'drafts' => ListDrafts::route('/{record}/drafts'),
            'outbox' => ListOutgoingMessages::route('/{record}/outbox'),
            'draft' => EditDraft::route('/{record}/drafts/{draft}'),
            'browse' => BrowseMailbox::route('/{record}'),
            'message' => ViewMessage::route('/{record}/messages/{message}'),
        ];
    }
}
