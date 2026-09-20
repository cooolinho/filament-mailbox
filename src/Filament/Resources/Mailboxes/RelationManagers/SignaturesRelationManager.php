<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\RelationManagers;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\SignatureForm;
use Cooolinho\FilamentMailbox\Services\SignatureResolver;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Signatures of the mailbox (personal signatures are managed by their owners).
 */
class SignaturesRelationManager extends RelationManager
{
    protected static string $relationship = 'signatures';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Filament::auth()->user();

        return SignatureResolver::enabled()
            && $user !== null
            && app(MailboxAuthorization::class)->canManage($user);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('filament-mailbox::mailbox.signatures.plural_label');
    }

    public static function getModelLabel(): string
    {
        return __('filament-mailbox::mailbox.signatures.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-mailbox::mailbox.signatures.plural_label');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(SignatureForm::components())->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('user_id'))
            ->defaultSort('name')
            ->columns(SignatureForm::columns())
            ->headerActions([
                CreateAction::make()
                    ->modalWidth('4xl')
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'user_id' => null]),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth('4xl'),
                DeleteAction::make(),
            ]);
    }
}
