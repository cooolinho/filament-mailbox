<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\RelationManagers;

use Cooolinho\FilamentMailbox\Enums\LabelColor;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\LabelActions;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxLabel;
use Cooolinho\FilamentMailbox\Services\LabelService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Label catalogue of a mailbox. Changes go through LabelService, so the
 * server is updated where the provider supports it.
 */
class LabelsRelationManager extends RelationManager
{
    protected static string $relationship = 'labels';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Mailbox && $ownerRecord->supportsLabels() && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('filament-mailbox::mailbox.labels.plural_label');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('filament-mailbox::mailbox.fields.name'))
                ->required()
                ->maxLength(64),
            Select::make('color')
                ->label(__('filament-mailbox::mailbox.labels.fields.color'))
                ->options(LabelColor::class),
            Toggle::make('is_hidden')
                ->label(__('filament-mailbox::mailbox.labels.fields.is_hidden'))
                ->hiddenOn('create'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament-mailbox::mailbox.fields.name'))
                    ->badge()
                    ->color(fn (MailboxLabel $record): array|string => LabelActions::color($record))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('remote_key')
                    ->label(__('filament-mailbox::mailbox.labels.fields.remote_key'))
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_hidden')
                    ->label(__('filament-mailbox::mailbox.labels.fields.is_hidden'))
                    ->boolean(),
                TextColumn::make('messages_count')
                    ->label(__('filament-mailbox::mailbox.labels.fields.messages'))
                    ->counts('messages'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data, CreateAction $action) => $this->attempt(
                        fn () => app(LabelService::class)->create($this->getOwnerRecord(), $data['name'], static::color($data['color'] ?? null)),
                        $action,
                    )),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (MailboxLabel $record, array $data, EditAction $action) => $this->attempt(
                        fn () => app(LabelService::class)->update($record, $data['name'], static::color($data['color'] ?? null), (bool) ($data['is_hidden'] ?? false)),
                        $action,
                    )),
                DeleteAction::make()
                    ->using(fn (MailboxLabel $record, DeleteAction $action) => $this->attempt(
                        fn () => app(LabelService::class)->delete($record) ?? true,
                        $action,
                    )),
            ]);
    }

    protected static function color(mixed $state): ?LabelColor
    {
        return $state instanceof LabelColor ? $state : LabelColor::tryFrom((string) $state);
    }

    protected function attempt(callable $operation, Action $action): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title(__('filament-mailbox::mailbox.actions.failed'))
                ->send();

            $action->halt();
        }

        return null;
    }
}
