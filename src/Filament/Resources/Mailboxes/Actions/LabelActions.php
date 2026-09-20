<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxLabel;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\LabelService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

class LabelActions
{
    /**
     * Edit the labels of one message.
     */
    public static function edit(): Action
    {
        return Action::make('editLabels')
            ->label(__('filament-mailbox::mailbox.labels.actions.edit'))
            ->icon(Heroicon::OutlinedTag)
            ->authorize('update')
            ->visible(fn (MailboxMessage $record): bool => $record->mailbox->supportsLabels())
            ->modalWidth('md')
            ->fillForm(fn (MailboxMessage $record): array => [
                'labels' => $record->labels()->pluck('mailbox_labels.id')->all(),
            ])
            ->schema(fn (MailboxMessage $record): array => [
                CheckboxList::make('labels')
                    ->label(__('filament-mailbox::mailbox.labels.plural_label'))
                    ->options(static::options($record->mailbox))
                    ->columns(2),
                TextInput::make('new_label')
                    ->label(__('filament-mailbox::mailbox.labels.fields.new'))
                    ->maxLength(64),
            ])
            ->action(fn (MailboxMessage $record, array $data, LabelService $labels) => MessageActions::run(function () use ($record, $data, $labels): void {
                // Only labels of the message's own mailbox are accepted.
                $selected = $record->mailbox->labels()->visible()->whereKey($data['labels'] ?? [])->get();

                if (filled($data['new_label'] ?? null)) {
                    $selected->push($labels->create($record->mailbox, trim($data['new_label'])));
                }

                $hidden = $record->labels()->where('is_hidden', true)->get();

                $labels->sync($record, $selected->merge($hidden));
            }, __('filament-mailbox::mailbox.labels.actions.saved')));
    }

    public static function bulkAttach(): BulkAction
    {
        return static::bulk('attachLabel', attach: true);
    }

    public static function bulkDetach(): BulkAction
    {
        return static::bulk('detachLabel', attach: false);
    }

    protected static function bulk(string $name, bool $attach): BulkAction
    {
        $key = $attach ? 'attach' : 'detach';

        return BulkAction::make($name)
            ->label(__("filament-mailbox::mailbox.labels.actions.{$key}"))
            ->icon($attach ? Heroicon::OutlinedTag : Heroicon::OutlinedXCircle)
            ->authorizeIndividualRecords('update')
            ->visible(fn (BrowseMailbox $livewire): bool => $livewire->getRecord()->supportsLabels())
            ->schema(fn (BrowseMailbox $livewire): array => [
                Select::make('label')
                    ->label(__('filament-mailbox::mailbox.labels.label'))
                    ->options(static::options($livewire->getRecord()))
                    ->required(),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data, BrowseMailbox $livewire, LabelService $labels) => MessageActions::run(function () use ($records, $data, $livewire, $labels, $attach): void {
                $label = $livewire->getRecord()->labels()->findOrFail($data['label']);

                $attach ? $labels->attach($records, $label) : $labels->detach($records, $label);
            }, __('filament-mailbox::mailbox.labels.actions.saved')));
    }

    /**
     * @return array<int, string>
     */
    public static function options(Mailbox $mailbox): array
    {
        return $mailbox->labels()->visible()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>|string
     */
    public static function color(?MailboxLabel $label): array|string
    {
        return $label?->color?->palette() ?? 'gray';
    }
}
