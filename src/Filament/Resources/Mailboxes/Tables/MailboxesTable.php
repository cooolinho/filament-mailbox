<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Tables;

use Cooolinho\FilamentMailbox\Enums\MailboxHealth;
use Cooolinho\FilamentMailbox\Filament\Pages\MailboxHealth as MailboxHealthPage;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Health\MailboxHealthEvaluator;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MailboxesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('oauthConnection'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament-mailbox::mailbox.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('filament-mailbox::mailbox.fields.email'))
                    ->searchable(),
                TextColumn::make('host')
                    ->label(__('filament-mailbox::mailbox.fields.host'))
                    ->toggleable(),
                TextColumn::make('oauth_status')
                    ->label(__('filament-mailbox::mailbox.fields.auth_mode'))
                    ->state(fn (Mailbox $record): ?string => $record->usesOAuth() && $record->oauthConnection?->isActive() === false
                        ? __('filament-mailbox::mailbox.oauth.needs_reconnect')
                        : null)
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),
                TextColumn::make('health')
                    ->label(__('filament-mailbox::mailbox.health.fields.status'))
                    ->state(fn (Mailbox $record): MailboxHealth => app(MailboxHealthEvaluator::class)->current($record))
                    ->badge()
                    ->visible(fn (): bool => MailboxHealthPage::canAccess()),
                IconColumn::make('is_active')
                    ->label(__('filament-mailbox::mailbox.fields.is_active'))
                    ->boolean(),
                TextColumn::make('last_synced_at')
                    ->label(__('filament-mailbox::mailbox.fields.last_synced_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->recordUrl(fn (Mailbox $record): ?string => match (true) {
                MailboxResource::canView($record) => MailboxResource::getUrl('browse', ['record' => $record]),
                MailboxResource::canEdit($record) => MailboxResource::getUrl('edit', ['record' => $record]),
                default => null,
            })
            ->recordActions([
                Action::make('open')
                    ->label(__('filament-mailbox::mailbox.actions.open.label'))
                    ->icon(Heroicon::OutlinedInbox)
                    ->authorize('view')
                    ->url(fn (Mailbox $record): string => MailboxResource::getUrl('browse', ['record' => $record])),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
