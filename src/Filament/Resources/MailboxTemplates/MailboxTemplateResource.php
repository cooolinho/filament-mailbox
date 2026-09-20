<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\MailboxTemplates;

use BackedEnum;
use Cooolinho\FilamentMailbox\FilamentMailboxPlugin;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\ComposeMessageForm;
use Cooolinho\FilamentMailbox\Filament\Resources\MailboxTemplates\Pages\ListMailboxTemplates;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxTemplate;
use Cooolinho\FilamentMailbox\Services\HtmlBodySanitizer;
use Cooolinho\FilamentMailbox\Services\InlineImageProcessor;
use Cooolinho\FilamentMailbox\Services\TemplateRenderer;
use Cooolinho\FilamentMailbox\Services\TemplateRepository;
use Cooolinho\FilamentMailbox\Support\ComposeContext;
use Cooolinho\FilamentMailbox\Support\PlaceholderRenderer;
use Cooolinho\FilamentMailbox\Support\TemplateContext;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Catalogue of templates for composing, global or per mailbox.
 */
class MailboxTemplateResource extends Resource
{
    protected static ?string $model = MailboxTemplate::class;

    protected static ?string $slug = 'mailbox-templates';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    public static function getModelLabel(): string
    {
        return __('filament-mailbox::mailbox.templates.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-mailbox::mailbox.templates.plural_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return FilamentMailboxPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $sort = FilamentMailboxPlugin::get()->getNavigationSort();

        return $sort === null ? null : $sort + 3;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')
                ->label(__('filament-mailbox::mailbox.fields.name'))
                ->required()
                ->maxLength(255),
            TextInput::make('category')
                ->label(__('filament-mailbox::mailbox.templates.fields.category'))
                ->datalist(fn (): array => MailboxTemplate::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all())
                ->maxLength(255),
            Select::make('mailbox_id')
                ->label(__('filament-mailbox::mailbox.tags.fields.mailbox'))
                ->helperText(__('filament-mailbox::mailbox.tags.fields.mailbox_help'))
                ->options(fn (): array => Mailbox::query()->orderBy('name')->pluck('name', 'id')->all())
                ->placeholder(__('filament-mailbox::mailbox.tags.global'))
                ->searchable(),
            CheckboxList::make('contexts')
                ->label(__('filament-mailbox::mailbox.templates.fields.contexts'))
                ->options(ComposeContext::options())
                ->default(array_column(ComposeContext::cases(), 'value'))
                ->required()
                ->columns(3),
            Callout::make(__('filament-mailbox::mailbox.templates.placeholders_heading'))
                ->description(__('filament-mailbox::mailbox.templates.placeholders', [
                    'placeholders' => implode(', ', array_map(fn (string $placeholder): string => '{'.$placeholder.'}', TemplateContext::PLACEHOLDERS)),
                ]))
                ->info()
                ->columnSpanFull(),
            TextInput::make('subject')
                ->label(__('filament-mailbox::mailbox.messages.fields.subject'))
                ->helperText(__('filament-mailbox::mailbox.templates.fields.subject_help'))
                ->maxLength(255)
                ->columnSpanFull(),
            ComposeMessageForm::richEditor('body_html', attachments: true, directory: InlineImageProcessor::directory('templates'))
                ->label(__('filament-mailbox::mailbox.templates.fields.body_html'))
                ->columnSpanFull(),
            Textarea::make('body_text')
                ->label(__('filament-mailbox::mailbox.templates.fields.body_text'))
                ->helperText(__('filament-mailbox::mailbox.templates.fields.body_text_help'))
                ->rows(6)
                ->maxLength(100_000)
                ->required(fn (Get $get): bool => ComposeMessageForm::isEmptyHtml(ComposeMessageForm::html($get('body_html'))))
                ->columnSpanFull(),
            FileUpload::make('attachment_paths')
                ->label(__('filament-mailbox::mailbox.templates.attachments'))
                ->multiple()
                ->maxFiles(10)
                ->disk(fn (): string => (string) config('filament-mailbox.attachments.disk'))
                ->directory(fn (): string => InlineImageProcessor::directory('templates/attachments'))
                ->visibility('private')
                ->storeFileNamesIn('attachment_names')
                ->maxSize((int) config('filament-mailbox.templates.max_attachment_size', 10240))
                // Existing paths must belong to the edited template.
                ->preventFilePathTampering(allowFilePathUsing: fn (string $file, ?MailboxTemplate $record): bool => $record?->attachments()->where('storage_path', $file)->exists() ?? false)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament-mailbox::mailbox.fields.name'))
                    ->description(fn (MailboxTemplate $record): ?string => $record->subject)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->label(__('filament-mailbox::mailbox.templates.fields.category'))
                    ->badge()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('mailbox.name')
                    ->label(__('filament-mailbox::mailbox.tags.fields.mailbox'))
                    ->placeholder(__('filament-mailbox::mailbox.tags.global')),
                TextColumn::make('contexts')
                    ->label(__('filament-mailbox::mailbox.templates.fields.contexts'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ComposeContext::tryFrom($state)?->getLabel() ?? $state),
                TextColumn::make('attachments_count')
                    ->label(__('filament-mailbox::mailbox.messages.fields.attachments'))
                    ->counts('attachments')
                    ->numeric(),
                TextColumn::make('usage_count')
                    ->label(__('filament-mailbox::mailbox.templates.fields.usage_count'))
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('mailbox_id')
                    ->label(__('filament-mailbox::mailbox.tags.fields.mailbox'))
                    ->relationship('mailbox', 'name'),
                SelectFilter::make('category')
                    ->label(__('filament-mailbox::mailbox.templates.fields.category'))
                    ->options(fn (): array => MailboxTemplate::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category', 'category')->all()),
            ])
            ->defaultSort('name')
            ->recordActions([
                static::previewAction(),
                EditAction::make()
                    ->modalWidth('4xl')
                    ->mutateRecordDataUsing(fn (array $data, MailboxTemplate $record): array => [
                        ...$data,
                        'attachment_paths' => $record->attachments->pluck('storage_path')->all(),
                        'attachment_names' => $record->attachments->pluck('filename', 'storage_path')->all(),
                    ])
                    ->using(function (MailboxTemplate $record, array $data, TemplateRepository $templates): MailboxTemplate {
                        $record->update(static::attributes($data));

                        $templates->syncAttachments($record, (array) ($data['attachment_paths'] ?? []), (array) ($data['attachment_names'] ?? []));

                        return $record;
                    }),
                DeleteAction::make(),
            ]);
    }

    /**
     * Model attributes of the form data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function attributes(array $data): array
    {
        return collect($data)->only(['mailbox_id', 'name', 'category', 'subject', 'body_text', 'body_html', 'contexts'])->all();
    }

    /**
     * Rendered with example values; unknown placeholders are highlighted.
     */
    public static function previewAction(): Action
    {
        return Action::make('preview')
            ->label(__('filament-mailbox::mailbox.compose.preview'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->modalHeading(fn (MailboxTemplate $record): string => $record->name)
            ->modalWidth('3xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('filament-mailbox::mailbox.compose.close'))
            ->modalContent(fn (MailboxTemplate $record): View => view('filament-mailbox::templates.preview', static::preview($record)));
    }

    /**
     * @return array{subject: ?string, html: HtmlString, text: string, unknown: array<int, string>}
     */
    public static function preview(MailboxTemplate $template): array
    {
        $renderer = app(TemplateRenderer::class);
        $variables = TemplateContext::example($template->mailbox, Filament::auth()->user());
        $html = $renderer->html($template, $variables);
        $text = $renderer->text($template, $variables);

        $marked = preg_replace_callback(
            PlaceholderRenderer::PATTERN,
            fn (array $match): string => '<mark>'.$match[0].'</mark>',
            app(HtmlBodySanitizer::class)->outgoing($html->content),
        );

        return [
            'subject' => filled($template->subject) ? $renderer->subject($template, $variables) : null,
            'html' => new HtmlString((string) $marked),
            'text' => $text->content,
            'unknown' => array_values(array_unique([...$html->unknown, ...$text->unknown])),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMailboxTemplates::route('/'),
        ];
    }
}
