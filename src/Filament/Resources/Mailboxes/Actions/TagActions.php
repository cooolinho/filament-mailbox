<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxTag;
use Cooolinho\FilamentMailbox\Services\TagService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

class TagActions
{
    /**
     * Edit the tags of one message.
     */
    public static function edit(): Action
    {
        return Action::make('editTags')
            ->label(__('filament-mailbox::mailbox.tags.actions.edit'))
            ->icon(Heroicon::OutlinedHashtag)
            ->color('gray')
            ->authorize('tag')
            ->visible(fn (MailboxMessage $record): bool => static::options($record->mailbox) !== [])
            ->modalWidth('md')
            ->fillForm(fn (MailboxMessage $record): array => [
                'tags' => $record->tags()->pluck('mailbox_tags.id')->all(),
            ])
            ->schema(fn (MailboxMessage $record): array => [
                CheckboxList::make('tags')
                    ->label(__('filament-mailbox::mailbox.tags.plural_label'))
                    ->options(static::options($record->mailbox))
                    ->columns(2),
            ])
            ->action(fn (MailboxMessage $record, array $data, TagService $tags) => MessageActions::run(
                // Only tags available for the message's mailbox are accepted.
                fn () => $tags->sync($record, static::available($record->mailbox, $data['tags'] ?? []), auth()->user()),
                __('filament-mailbox::mailbox.tags.actions.saved'),
            ));
    }

    public static function bulkAttach(): BulkAction
    {
        return static::bulk('attachTag', attach: true);
    }

    public static function bulkDetach(): BulkAction
    {
        return static::bulk('detachTag', attach: false);
    }

    protected static function bulk(string $name, bool $attach): BulkAction
    {
        $key = $attach ? 'attach' : 'detach';

        return BulkAction::make($name)
            ->label(__("filament-mailbox::mailbox.tags.actions.{$key}"))
            ->icon($attach ? Heroicon::OutlinedHashtag : Heroicon::OutlinedXMark)
            ->authorizeIndividualRecords('tag')
            ->visible(fn (BrowseMailbox $livewire): bool => static::options($livewire->getRecord()) !== [])
            ->schema(fn (BrowseMailbox $livewire): array => [
                Select::make('tag')
                    ->label(__('filament-mailbox::mailbox.tags.label'))
                    ->options(static::options($livewire->getRecord()))
                    ->required(),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data, BrowseMailbox $livewire, TagService $tags) => MessageActions::run(function () use ($records, $data, $livewire, $tags, $attach): void {
                $tag = static::available($livewire->getRecord(), [$data['tag']])->firstOrFail();

                $attach ? $tags->attach($records, $tag, auth()->user()) : $tags->detach($records, $tag);
            }, __('filament-mailbox::mailbox.tags.actions.saved')));
    }

    /**
     * @return array<int, string>
     */
    public static function options(Mailbox $mailbox): array
    {
        return MailboxTag::query()->visibleFor($mailbox)->ordered()->pluck('name', 'id')->all();
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return Collection<int, MailboxTag>
     */
    public static function available(Mailbox $mailbox, array $ids): Collection
    {
        return MailboxTag::query()->visibleFor($mailbox)->whereKey($ids)->get();
    }

    /**
     * @return array<int, string>|string
     */
    public static function color(?MailboxTag $tag): array|string
    {
        return $tag?->color?->palette() ?? 'gray';
    }
}
