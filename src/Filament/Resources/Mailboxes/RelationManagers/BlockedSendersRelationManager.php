<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\RelationManagers;

use Closure;
use Cooolinho\FilamentMailbox\Services\BlockedSenderMatcher;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Senders whose new inbox messages are moved to spam.
 */
class BlockedSendersRelationManager extends RelationManager
{
    protected static string $relationship = 'blockedSenders';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return config('filament-mailbox.spam.block_senders', true) && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('filament-mailbox::mailbox.spam.blocked_senders.plural_label');
    }

    public static function getModelLabel(): string
    {
        return __('filament-mailbox::mailbox.spam.blocked_senders.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-mailbox::mailbox.spam.blocked_senders.plural_label');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('pattern')
                ->label(__('filament-mailbox::mailbox.spam.blocked_senders.pattern'))
                ->helperText(__('filament-mailbox::mailbox.spam.blocked_senders.pattern_help'))
                ->required()
                ->maxLength(255)
                ->dehydrateStateUsing(fn (string $state): string => BlockedSenderMatcher::normalize($state))
                ->rule(fn (?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    if (! BlockedSenderMatcher::isValid((string) $value)) {
                        $fail(__('filament-mailbox::mailbox.spam.blocked_senders.pattern_invalid'));

                        return;
                    }

                    // Patterns are stored normalised, so duplicates are checked the same way.
                    $exists = $this->getOwnerRecord()->blockedSenders()
                        ->where('pattern', BlockedSenderMatcher::normalize((string) $value))
                        ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                        ->exists();

                    if ($exists) {
                        $fail(__('validation.unique', ['attribute' => __('filament-mailbox::mailbox.spam.blocked_senders.pattern')]));
                    }
                }),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('pattern')
            ->columns([
                TextColumn::make('pattern')
                    ->label(__('filament-mailbox::mailbox.spam.blocked_senders.pattern'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('filament-mailbox::mailbox.messages.fields.date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'created_by' => auth()->id()]),
            ])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
