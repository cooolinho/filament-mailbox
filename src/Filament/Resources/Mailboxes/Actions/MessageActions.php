<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions;

use Closure;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\BrowseMailbox;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Support\FolderPath;
use Filament\Forms\Components\Select;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\MessageService;
use Cooolinho\FilamentMailbox\Services\SnoozeService;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class MessageActions
{
    public static function markAsRead(): Action
    {
        return Action::make('markAsRead')
            ->label(__('filament-mailbox::mailbox.actions.mark_read.label'))
            ->icon(Heroicon::OutlinedEnvelopeOpen)
            ->authorize('update')
            ->visible(fn (MailboxMessage $record): bool => ! $record->is_read && $record->mailbox->supports(ProviderCapability::Flags))
            ->action(fn (MailboxMessage $record, MessageService $messages) => static::run(
                fn () => $messages->markRead($record),
                __('filament-mailbox::mailbox.actions.mark_read.success'),
            ));
    }

    public static function markAsUnread(): Action
    {
        return Action::make('markAsUnread')
            ->label(__('filament-mailbox::mailbox.actions.mark_unread.label'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->authorize('update')
            ->visible(fn (MailboxMessage $record): bool => $record->is_read && $record->mailbox->supports(ProviderCapability::Flags))
            ->action(fn (MailboxMessage $record, MessageService $messages) => static::run(
                fn () => $messages->markUnread($record),
                __('filament-mailbox::mailbox.actions.mark_unread.success'),
            ));
    }

    /**
     * Toggles the star of a message, e.g. from the table column.
     */
    public static function toggleStar(): Action
    {
        return Action::make('toggleStar')
            ->authorize('update')
            ->action(fn (MailboxMessage $record, MessageService $messages) => static::run(
                fn () => $messages->setFlagged($record, ! $record->is_flagged),
                $record->is_flagged
                    ? __('filament-mailbox::mailbox.actions.unstar.success')
                    : __('filament-mailbox::mailbox.actions.star.success'),
            ));
    }

    public static function bulkStar(): BulkAction
    {
        return static::bulkSetFlagged('star', true);
    }

    public static function bulkUnstar(): BulkAction
    {
        return static::bulkSetFlagged('unstar', false);
    }

    protected static function bulkSetFlagged(string $key, bool $flagged): BulkAction
    {
        return BulkAction::make($key)
            ->label(__("filament-mailbox::mailbox.actions.{$key}.label"))
            ->authorizeIndividualRecords('update')
            ->icon($flagged ? Heroicon::OutlinedStar : Heroicon::OutlinedMinusCircle)
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, MessageService $messages) => static::run(
                fn () => $messages->setFlagged($records, $flagged),
                __("filament-mailbox::mailbox.actions.{$key}.success"),
            ));
    }

    public static function archive(): Action
    {
        return Action::make('archive')
            ->label(__('filament-mailbox::mailbox.actions.archive.label'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->authorize('update')
            ->visible(fn (MailboxMessage $record): bool => static::archivableFolder($record))
            ->disabled(fn (MailboxMessage $record): bool => ! app(MessageService::class)->canArchive($record))
            ->tooltip(fn (MailboxMessage $record, MessageService $messages): ?string => static::archiveHint($record, $messages))
            ->action(fn (MailboxMessage $record, MessageService $messages) => static::archiveAndNotify($messages, $record));
    }

    public static function bulkArchive(): BulkAction
    {
        return BulkAction::make('archive')
            ->label(__('filament-mailbox::mailbox.actions.archive.label'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->authorizeIndividualRecords('update')
            ->visible(fn (BrowseMailbox $livewire, MessageService $messages): bool => ($livewire->getRecord()->archiveFolder() !== null || $messages->canCreateArchiveFolder($livewire->getRecord()))
                && ($livewire->spansFolders() || ! in_array($livewire->getActiveFolder()?->special_use, MessageService::NOT_ARCHIVABLE, true)))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, MessageService $messages) => static::archiveAndNotify($messages, $records));
    }

    public static function spam(): Action
    {
        return Action::make('markAsSpam')
            ->label(__('filament-mailbox::mailbox.actions.spam.label'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->authorize('update')
            ->visible(fn (MailboxMessage $record, MessageService $messages): bool => $messages->canMarkAsSpam($record))
            ->action(fn (MailboxMessage $record, MessageService $messages) => static::moveAndNotify(
                fn () => $messages->markAsSpam($record),
                __('filament-mailbox::mailbox.actions.spam.success'),
            ));
    }

    public static function notSpam(): Action
    {
        return Action::make('markAsNotSpam')
            ->label(__('filament-mailbox::mailbox.actions.not_spam.label'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->authorize('update')
            ->visible(fn (MailboxMessage $record, MessageService $messages): bool => $messages->canMarkAsNotSpam($record))
            ->action(fn (MailboxMessage $record, MessageService $messages) => static::moveAndNotify(
                fn () => $messages->markAsNotSpam($record),
                __('filament-mailbox::mailbox.actions.not_spam.success'),
            ));
    }

    public static function bulkSpam(): BulkAction
    {
        return BulkAction::make('markAsSpam')
            ->label(__('filament-mailbox::mailbox.actions.spam.label'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->authorizeIndividualRecords('update')
            ->visible(fn (BrowseMailbox $livewire): bool => $livewire->getRecord()->spamFolder() !== null && ! $livewire->isSpamFolder())
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, MessageService $messages) => static::moveAndNotify(
                fn () => $messages->markAsSpam($records),
                __('filament-mailbox::mailbox.actions.spam.success'),
            ));
    }

    public static function bulkNotSpam(): BulkAction
    {
        return BulkAction::make('markAsNotSpam')
            ->label(__('filament-mailbox::mailbox.actions.not_spam.label'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->authorizeIndividualRecords('update')
            ->visible(fn (BrowseMailbox $livewire): bool => $livewire->isSpamFolder())
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, MessageService $messages) => static::moveAndNotify(
                fn () => $messages->markAsNotSpam($records),
                __('filament-mailbox::mailbox.actions.not_spam.success'),
            ));
    }

    public static function snooze(): Action
    {
        return Action::make('snooze')
            ->label(__('filament-mailbox::mailbox.snooze.actions.snooze'))
            ->icon(Heroicon::OutlinedClock)
            ->authorize('update')
            ->visible(fn (MailboxMessage $record): bool => static::canSnooze($record))
            ->modalHeading(__('filament-mailbox::mailbox.snooze.modal.heading'))
            ->modalSubmitActionLabel(__('filament-mailbox::mailbox.snooze.actions.snooze'))
            ->modalWidth('md')
            ->schema(fn (): array => static::snoozeSchema())
            ->action(fn (MailboxMessage $record, array $data, SnoozeService $snooze): bool => static::snoozeAndNotify($snooze, $record, $data));
    }

    public static function bulkSnooze(): BulkAction
    {
        return BulkAction::make('snooze')
            ->label(__('filament-mailbox::mailbox.snooze.actions.snooze'))
            ->icon(Heroicon::OutlinedClock)
            ->authorizeIndividualRecords('update')
            ->visible(fn (BrowseMailbox $livewire): bool => SnoozeService::enabled() && ! $livewire->isSnoozedView())
            ->modalHeading(__('filament-mailbox::mailbox.snooze.modal.heading'))
            ->modalSubmitActionLabel(__('filament-mailbox::mailbox.snooze.actions.snooze'))
            ->modalWidth('md')
            ->schema(fn (): array => static::snoozeSchema())
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data, SnoozeService $snooze): bool => static::snoozeAndNotify($snooze, $records, $data));
    }

    public static function unsnooze(): Action
    {
        return Action::make('unsnooze')
            ->label(__('filament-mailbox::mailbox.snooze.actions.unsnooze'))
            ->icon(Heroicon::OutlinedBellSlash)
            ->authorize('update')
            ->visible(fn (MailboxMessage $record): bool => SnoozeService::enabled() && $record->isSnoozed())
            ->action(fn (MailboxMessage $record, SnoozeService $snooze): bool => static::run(
                fn () => $snooze->unsnooze($record),
                __('filament-mailbox::mailbox.snooze.unsnoozed'),
            ));
    }

    public static function bulkUnsnooze(): BulkAction
    {
        return BulkAction::make('unsnooze')
            ->label(__('filament-mailbox::mailbox.snooze.actions.unsnooze'))
            ->icon(Heroicon::OutlinedBellSlash)
            ->authorizeIndividualRecords('update')
            ->visible(fn (BrowseMailbox $livewire): bool => SnoozeService::enabled() && $livewire->isSnoozedView())
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, SnoozeService $snooze): bool => static::run(
                fn () => $snooze->unsnooze($records),
                __('filament-mailbox::mailbox.snooze.unsnoozed'),
            ));
    }

    public static function canSnooze(MailboxMessage $record): bool
    {
        return SnoozeService::enabled()
            && ! $record->isSnoozed()
            && ! $record->trashed()
            && ! in_array($record->folder?->special_use, [SpecialUse::Drafts, SpecialUse::Trash], true);
    }

    /**
     * Presets (calculated in the time zone of the panel) and a custom time.
     *
     * @return array<int, Radio|DateTimePicker>
     */
    public static function snoozeSchema(): array
    {
        $snooze = app(SnoozeService::class);
        $timezone = FilamentTimezone::get();
        $options = [];
        $descriptions = [];

        foreach (array_keys($snooze->presets()) as $preset) {
            if (! ($until = $snooze->resolvePreset($preset, $timezone)) || ! $until->isFuture()) {
                continue;
            }

            $key = "filament-mailbox::mailbox.snooze.presets.{$preset}";
            $options[$preset] = Lang::has($key) ? __($key) : Str::headline($preset);
            $descriptions[$preset] = $until->translatedFormat('D, j. M Y, H:i');
        }

        $options['custom'] = __('filament-mailbox::mailbox.snooze.presets.custom');

        return [
            Radio::make('preset')
                ->label(__('filament-mailbox::mailbox.snooze.modal.until'))
                ->options($options)
                ->descriptions($descriptions)
                ->default(array_key_first($options))
                ->required()
                ->live(),
            DateTimePicker::make('until')
                ->label(__('filament-mailbox::mailbox.snooze.modal.custom'))
                ->seconds(false)
                ->timezone($timezone)
                ->minDate(now())
                ->maxDate(now()->addDays(SnoozeService::MAX_DAYS))
                ->visible(fn (Get $get): bool => $get('preset') === 'custom')
                ->required(fn (Get $get): bool => $get('preset') === 'custom'),
        ];
    }

    /**
     * The snooze time chosen in the form, in the application time zone.
     *
     * @param  array<string, mixed>  $data
     */
    public static function snoozeUntil(array $data, SnoozeService $snooze): ?CarbonInterface
    {
        $preset = (string) ($data['preset'] ?? '');

        if ($preset !== 'custom') {
            return $snooze->resolvePreset($preset, FilamentTimezone::get());
        }

        // The picker converts the user's input from the panel time zone.
        return blank($data['until'] ?? null) ? null : CarbonImmutable::parse($data['until'], config('app.timezone'));
    }

    /**
     * @param  MailboxMessage|Collection<int, MailboxMessage>  $messages
     * @param  array<string, mixed>  $data
     */
    public static function snoozeAndNotify(SnoozeService $snooze, MailboxMessage|Collection $messages, array $data): bool
    {
        $until = static::snoozeUntil($data, $snooze);

        try {
            $until ? $snooze->validateUntil($until) : throw new InvalidArgumentException;
        } catch (InvalidArgumentException) {
            Notification::make()->danger()->title(__('filament-mailbox::mailbox.snooze.invalid'))->send();

            return false;
        }

        $user = auth()->user();

        return static::run(
            fn () => $snooze->snooze($messages, $until, $user),
            __('filament-mailbox::mailbox.snooze.snoozed', [
                'until' => $until->copy()->setTimezone(FilamentTimezone::get())->translatedFormat('D, j. M Y, H:i'),
            ]),
        );
    }

    /**
     * Move a message to any other active folder of its mailbox.
     */
    public static function moveTo(): Action
    {
        return Action::make('moveTo')
            ->label(__('filament-mailbox::mailbox.actions.move.label'))
            ->icon(Heroicon::OutlinedArrowRightCircle)
            ->authorize('update')
            ->visible(fn (MailboxMessage $record): bool => $record->mailbox->supports(ProviderCapability::MoveMessages))
            ->modalWidth('md')
            ->schema(fn (MailboxMessage $record): array => [static::targetFolderSelect($record->mailbox, $record->folder_id)])
            ->action(fn (MailboxMessage $record, array $data, MessageService $messages) => static::moveToAndNotify($messages, $record, $data['folder']));
    }

    public static function bulkMoveTo(): BulkAction
    {
        return BulkAction::make('moveTo')
            ->label(__('filament-mailbox::mailbox.actions.move.label'))
            ->icon(Heroicon::OutlinedArrowRightCircle)
            ->authorizeIndividualRecords('update')
            ->visible(fn (BrowseMailbox $livewire): bool => $livewire->getRecord()->supports(ProviderCapability::MoveMessages))
            ->modalWidth('md')
            ->schema(fn (BrowseMailbox $livewire): array => [static::targetFolderSelect($livewire->getRecord(), $livewire->spansFolders() ? null : $livewire->getActiveFolder()?->getKey())])
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data, MessageService $messages) => static::moveToAndNotify($messages, $records, $data['folder']));
    }

    public static function targetFolderSelect(Mailbox $mailbox, ?int $currentFolderId): Select
    {
        return Select::make('folder')
            ->label(__('filament-mailbox::mailbox.actions.move.folder'))
            ->options(fn (): array => $mailbox->folders()
                ->where('is_active', true)
                ->when($currentFolderId, fn ($query) => $query->whereKeyNot($currentFolderId))
                ->orderBy('full_name')
                ->get()
                ->mapWithKeys(fn (MailboxFolder $folder): array => [$folder->getKey() => $folder->special_use?->getLabel() ?? FolderPath::decode($folder->full_name)])
                ->all())
            ->searchable()
            ->required();
    }

    /**
     * @param  MailboxMessage|Collection<int, MailboxMessage>  $messages
     */
    public static function moveToAndNotify(MessageService $service, MailboxMessage|Collection $messages, mixed $folderId): bool
    {
        $records = Collection::make($messages instanceof MailboxMessage ? [$messages] : $messages->all());

        return static::moveAndNotify(function () use ($service, $records, $folderId): array {
            // The target is resolved through the mailbox of the messages, never taken from the form as is.
            $target = $records->first()?->mailbox->folders()->where('is_active', true)->findOrFail($folderId);
            $moves = $records
                ->filter(fn (MailboxMessage $message): bool => $message->mailbox_id === $target->mailbox_id && $message->folder_id !== $target->getKey())
                ->mapWithKeys(fn (MailboxMessage $message): array => [$message->getKey() => $message->folder_id])
                ->all();

            $service->move($records->whereIn('id', array_keys($moves)), $target);

            return $moves;
        }, __('filament-mailbox::mailbox.actions.move.success'));
    }

    /**
     * Hint for a disabled archive action.
     */
    public static function archiveHint(MailboxMessage $record, MessageService $messages): ?string
    {
        return $record->mailbox->archiveFolder() || $messages->canCreateArchiveFolder($record->mailbox)
            ? null
            : __('filament-mailbox::mailbox.actions.archive.no_folder');
    }

    /**
     * The archive action is only offered outside the archive, trash and drafts.
     */
    public static function archivableFolder(MailboxMessage $record): bool
    {
        return $record->folder !== null && ! in_array($record->folder->special_use, MessageService::NOT_ARCHIVABLE, true);
    }

    /**
     * @param  MailboxMessage|Collection<int, MailboxMessage>  $messages
     */
    public static function archiveAndNotify(MessageService $service, MailboxMessage|Collection $messages): bool
    {
        return static::moveAndNotify(fn (): array => $service->archive($messages), __('filament-mailbox::mailbox.actions.archive.success'));
    }

    /**
     * Run a move operation and offer to undo it.
     *
     * @param  Closure(): array<int, int>  $operation  Returns the original folder ids keyed by message id
     */
    public static function moveAndNotify(Closure $operation, string $successTitle): bool
    {
        $moves = [];

        return static::run(
            function () use ($operation, &$moves): void {
                $moves = $operation();
            },
            $successTitle,
            function () use (&$moves): array {
                return $moves === [] ? [] : [static::undoMoveAction($moves)];
            },
        );
    }

    /**
     * Notification action that moves messages back to their original folders.
     * The listener authorises every message again (BrowseMailbox::undoMove()).
     *
     * @param  array<int, int>  $moves  Original folder ids keyed by message id
     */
    public static function undoMoveAction(array $moves): Action
    {
        return Action::make('undo')
            ->label(__('filament-mailbox::mailbox.actions.undo.label'))
            ->link()
            ->dispatch(BrowseMailbox::UNDO_MOVE_EVENT, ['moves' => $moves])
            ->close();
    }

    public static function delete(): Action
    {
        return Action::make('deleteMessage')
            ->label(fn (MailboxMessage $record): string => static::deleteLabel($record))
            ->authorize('delete')
            ->visible(fn (MailboxMessage $record): bool => static::canDelete($record))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('filament-mailbox::mailbox.actions.delete.heading'))
            ->modalDescription(__('filament-mailbox::mailbox.actions.delete.description'))
            ->action(fn (MailboxMessage $record, MessageService $messages) => static::run(
                fn () => $messages->delete($record),
                __('filament-mailbox::mailbox.actions.delete.success'),
            ));
    }

    public static function bulkMarkAsRead(): BulkAction
    {
        return BulkAction::make('markAsRead')
            ->label(__('filament-mailbox::mailbox.actions.mark_read.label'))
            ->authorizeIndividualRecords('update')
            ->icon(Heroicon::OutlinedEnvelopeOpen)
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, MessageService $messages) => static::run(
                fn () => $messages->markRead($records),
                __('filament-mailbox::mailbox.actions.mark_read.success'),
            ));
    }

    public static function bulkMarkAsUnread(): BulkAction
    {
        return BulkAction::make('markAsUnread')
            ->label(__('filament-mailbox::mailbox.actions.mark_unread.label'))
            ->authorizeIndividualRecords('update')
            ->icon(Heroicon::OutlinedEnvelope)
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, MessageService $messages) => static::run(
                fn () => $messages->markUnread($records),
                __('filament-mailbox::mailbox.actions.mark_unread.success'),
            ));
    }

    public static function bulkDelete(): BulkAction
    {
        return BulkAction::make('deleteMessages')
            ->label(__('filament-mailbox::mailbox.actions.delete.label'))
            ->authorize(fn (): bool => ($user = auth()->user()) && app(MailboxAuthorization::class)->canDelete($user))
            ->authorizeIndividualRecords('delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('filament-mailbox::mailbox.actions.delete.heading'))
            ->modalDescription(__('filament-mailbox::mailbox.actions.delete.description'))
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, MessageService $messages) => static::run(
                fn () => $messages->delete($records),
                __('filament-mailbox::mailbox.actions.delete.success'),
            ));
    }

    /**
     * Without permanent deletion (Gmail API) messages can only be moved to the trash.
     */
    public static function canDelete(MailboxMessage $record): bool
    {
        return $record->mailbox->supports(ProviderCapability::PermanentDelete)
            || $record->folder?->special_use !== SpecialUse::Trash;
    }

    public static function deleteLabel(MailboxMessage $record): string
    {
        return $record->mailbox->supports(ProviderCapability::PermanentDelete)
            ? __('filament-mailbox::mailbox.actions.delete.label')
            : __('filament-mailbox::mailbox.actions.delete.trash_label');
    }

    /**
     * Run a message operation and report the outcome as a notification.
     */
    /**
     * @param  ?Closure(): array<int, Action>  $successActions
     */
    public static function run(Closure $operation, string $successTitle, ?Closure $successActions = null): bool
    {
        try {
            $operation();
        } catch (UnsupportedOperation) {
            Notification::make()
                ->warning()
                ->title(__('filament-mailbox::mailbox.actions.unsupported'))
                ->send();

            return false;
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title(__('filament-mailbox::mailbox.actions.failed'))
                ->send();

            return false;
        }

        Notification::make()
            ->success()
            ->title($successTitle)
            ->actions($successActions ? $successActions() : [])
            ->send();

        return true;
    }
}
