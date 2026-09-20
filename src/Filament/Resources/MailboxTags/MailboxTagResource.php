<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\MailboxTags;

use BackedEnum;
use Closure;
use Cooolinho\FilamentMailbox\Enums\LabelColor;
use Cooolinho\FilamentMailbox\FilamentMailboxPlugin;
use Cooolinho\FilamentMailbox\Filament\Resources\MailboxTags\Pages\ListMailboxTags;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxTag;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Catalogue of app-only tags, global or per mailbox.
 */
class MailboxTagResource extends Resource
{
    protected static ?string $model = MailboxTag::class;

    protected static ?string $slug = 'mailbox-tags';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    public static function getModelLabel(): string
    {
        return __('filament-mailbox::mailbox.tags.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-mailbox::mailbox.tags.plural_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return FilamentMailboxPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $sort = FilamentMailboxPlugin::get()->getNavigationSort();

        return $sort === null ? null : $sort + 2;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('filament-mailbox::mailbox.fields.name'))
                ->required()
                ->maxLength(64)
                ->rule(fn (Get $get, ?MailboxTag $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                    // The unique index does not cover global tags (NULL mailbox), so it is checked here.
                    $exists = MailboxTag::query()
                        ->where('name', trim((string) $value))
                        ->when(filled($get('mailbox_id')), fn ($query) => $query->where('mailbox_id', $get('mailbox_id')), fn ($query) => $query->whereNull('mailbox_id'))
                        ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                        ->exists();

                    if ($exists) {
                        $fail(__('validation.unique', ['attribute' => __('filament-mailbox::mailbox.fields.name')]));
                    }
                }),
            Select::make('color')
                ->label(__('filament-mailbox::mailbox.labels.fields.color'))
                ->options(LabelColor::class)
                ->default(LabelColor::Gray)
                ->required(),
            Select::make('mailbox_id')
                ->label(__('filament-mailbox::mailbox.tags.fields.mailbox'))
                ->helperText(__('filament-mailbox::mailbox.tags.fields.mailbox_help'))
                ->options(fn (): array => Mailbox::query()->orderBy('name')->pluck('name', 'id')->all())
                ->placeholder(__('filament-mailbox::mailbox.tags.global'))
                ->live()
                ->searchable(),
            TextInput::make('description')
                ->label(__('filament-mailbox::mailbox.tags.fields.description'))
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament-mailbox::mailbox.fields.name'))
                    ->badge()
                    ->color(fn (MailboxTag $record): array => $record->color->palette())
                    ->description(fn (MailboxTag $record): ?string => $record->description)
                    ->searchable(),
                TextColumn::make('mailbox.name')
                    ->label(__('filament-mailbox::mailbox.tags.fields.mailbox'))
                    ->placeholder(__('filament-mailbox::mailbox.tags.global')),
                TextColumn::make('messages_count')
                    ->label(__('filament-mailbox::mailbox.labels.fields.messages'))
                    ->counts('messages'),
            ])
            ->filters([
                SelectFilter::make('mailbox_id')
                    ->label(__('filament-mailbox::mailbox.tags.fields.mailbox'))
                    ->relationship('mailbox', 'name'),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMailboxTags::route('/'),
        ];
    }
}
