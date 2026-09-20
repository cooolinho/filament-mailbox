<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas;

use Closure;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Services\InlineImageProcessor;
use Cooolinho\FilamentMailbox\Services\SignatureRenderer;
use Cooolinho\FilamentMailbox\Support\ComposeContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;

/**
 * Form and columns shared by mailbox and personal signatures.
 */
class SignatureForm
{
    /**
     * @param  ?Closure(): array<int|string, string>  $mailboxes  Selectable mailboxes (personal signatures)
     * @return array<int, Component>
     */
    public static function components(?Closure $mailboxes = null): array
    {
        return [
            ...($mailboxes ? [
                Select::make('mailbox_id')
                    ->label(__('filament-mailbox::mailbox.signatures.fields.mailbox'))
                    ->options($mailboxes)
                    ->required(),
            ] : []),
            TextInput::make('name')
                ->label(__('filament-mailbox::mailbox.fields.name'))
                ->required()
                ->maxLength(255),
            Text::make(__('filament-mailbox::mailbox.signatures.placeholders', [
                'placeholders' => implode(', ', array_map(fn (string $placeholder): string => '{'.$placeholder.'}', SignatureRenderer::PLACEHOLDERS)),
            ])),
            ComposeMessageForm::richEditor('body_html', attachments: true, directory: InlineImageProcessor::directory('signatures'))
                ->label(__('filament-mailbox::mailbox.signatures.fields.body_html'))
                ->helperText(__('filament-mailbox::mailbox.signatures.fields.body_html_help')),
            Textarea::make('body_text')
                ->label(__('filament-mailbox::mailbox.signatures.fields.body_text'))
                ->helperText(__('filament-mailbox::mailbox.signatures.fields.body_text_help'))
                ->rows(5)
                ->maxLength(10_000)
                ->required(fn (Get $get): bool => ComposeMessageForm::isEmptyHtml(ComposeMessageForm::html($get('body_html')))),
            Fieldset::make(__('filament-mailbox::mailbox.signatures.fields.defaults'))
                ->columns(3)
                ->schema(array_map(
                    fn (ComposeContext $context): Toggle => Toggle::make($context->defaultColumn())->label($context->getLabel()),
                    ComposeContext::cases(),
                )),
        ];
    }

    /**
     * @return array<int, TextColumn|IconColumn>
     */
    public static function columns(bool $withMailbox = false): array
    {
        return [
            TextColumn::make('name')
                ->label(__('filament-mailbox::mailbox.fields.name'))
                ->searchable()
                ->sortable(),
            ...($withMailbox ? [
                TextColumn::make('mailbox.name')
                    ->label(__('filament-mailbox::mailbox.signatures.fields.mailbox'))
                    ->sortable(),
            ] : []),
            ...array_map(
                fn (ComposeContext $context): IconColumn => IconColumn::make($context->defaultColumn())
                    ->label(__('filament-mailbox::mailbox.signatures.fields.default_for', ['context' => $context->getLabel()]))
                    ->boolean(),
                ComposeContext::cases(),
            ),
        ];
    }

    /**
     * Mailboxes the user is assigned to.
     *
     * @return array<int|string, string>
     */
    public static function assignedMailboxes(mixed $user): array
    {
        return $user ? Mailbox::query()->assignedTo($user)->orderBy('name')->pluck('name', 'id')->all() : [];
    }
}
