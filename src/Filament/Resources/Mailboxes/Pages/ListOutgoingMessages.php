<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages;

use Carbon\CarbonImmutable;
use Cooolinho\FilamentMailbox\Enums\OutgoingStatus;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\MailboxResource;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Pages\Concerns\CancelsOutgoingMessages;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxOutgoingMessage;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\MessageInfolist;
use Cooolinho\FilamentMailbox\Services\OutboxService;
use Cooolinho\FilamentMailbox\Services\Receipts\DeliveryReportService;
use Cooolinho\FilamentMailbox\Services\Receipts\ReadReceiptService;
use Cooolinho\FilamentMailbox\Support\MailboxAuthorization;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Outbox of a mailbox: the user's own messages, or all for users who may manage mailboxes.
 *
 * @property Mailbox $record
 */
class ListOutgoingMessages extends Page implements HasTable
{
    use CancelsOutgoingMessages;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = MailboxResource::class;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return __('filament-mailbox::mailbox.outbox.title', ['mailbox' => $this->getRecord()->name]);
    }

    public function getBreadcrumb(): ?string
    {
        return __('filament-mailbox::mailbox.outbox.label');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return Builder<MailboxOutgoingMessage>
     */
    public static function query(Mailbox $mailbox): Builder
    {
        $user = Filament::auth()->user();

        return $mailbox->outgoingMessages()
            ->getQuery()
            ->when(! $user || ! app(MailboxAuthorization::class)->canManage($user), fn (Builder $query) => $query->where('user_id', $user?->getAuthIdentifier() ?? 0));
    }

    /**
     * Scheduled and failed messages, for the badge in the mailbox header.
     */
    public static function attentionCount(Mailbox $mailbox): int
    {
        return static::query($mailbox)->whereIn('status', [OutgoingStatus::Scheduled, OutgoingStatus::Failed])->count();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::query($this->getRecord())->with('user')->withCount('attachments'))
            ->defaultSort('send_at', 'desc')
            ->poll('30s')
            ->columns([
                TextColumn::make('status')
                    ->label(__('filament-mailbox::mailbox.outbox.fields.status'))
                    ->badge()
                    ->tooltip(fn (MailboxOutgoingMessage $record): ?string => $record->last_error),
                TextColumn::make('subject')
                    ->label(__('filament-mailbox::mailbox.messages.fields.subject'))
                    ->description(fn (MailboxOutgoingMessage $record): string => implode(', ', [...$record->to ?? [], ...$record->cc ?? []]))
                    ->searchable()
                    ->limit(80),
                TextColumn::make('user.name')
                    ->label(__('filament-mailbox::mailbox.drafts.fields.author'))
                    ->visible(fn (): bool => app(MailboxAuthorization::class)->canManage(Filament::auth()->user())),
                TextColumn::make('delivery')
                    ->label(__('filament-mailbox::mailbox.delivery_receipts.column'))
                    ->state(fn (MailboxOutgoingMessage $record): ?string => app(DeliveryReportService::class)->statusOf($record))
                    ->formatStateUsing(fn (string $state): string => __('filament-mailbox::mailbox.delivery_receipts.statuses.'.$state))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        DeliveryReportService::STATUS_DELIVERED => 'success',
                        DeliveryReportService::STATUS_DELAYED => 'warning',
                        DeliveryReportService::STATUS_FAILED => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—')
                    ->visible(fn (): bool => DeliveryReportService::enabled()),
                TextColumn::make('send_at')
                    ->label(__('filament-mailbox::mailbox.outbox.fields.send_at'))
                    ->dateTime()
                    ->description(fn (MailboxOutgoingMessage $record): ?string => $record->status === OutgoingStatus::Scheduled ? $record->send_at->diffForHumans() : null)
                    ->sortable(),
                TextColumn::make('sent_at')
                    ->label(__('filament-mailbox::mailbox.outbox.fields.sent_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('attempts')
                    ->label(__('filament-mailbox::mailbox.outbox.fields.attempts'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('filament-mailbox::mailbox.outbox.fields.status'))
                    ->options(OutgoingStatus::class),
                \Filament\Tables\Filters\Filter::make('delivery_failed')
                    ->label(__('filament-mailbox::mailbox.delivery_receipts.filter'))
                    ->visible(fn (): bool => DeliveryReportService::enabled())
                    ->query(fn (Builder $query): Builder => $query->whereExists(fn ($receipts) => $receipts
                        ->selectRaw('1')
                        ->from('mailbox_receipts')
                        ->join('mailbox_receipt_requests', 'mailbox_receipt_requests.id', '=', 'mailbox_receipts.request_id')
                        ->whereColumn('mailbox_receipt_requests.outgoing_message_id', 'mailbox_outgoing_messages.id')
                        ->where('mailbox_receipt_requests.type', 'delivery')
                        ->where('mailbox_receipts.disposition', DeliveryReportService::STATUS_FAILED))),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('details')
                        ->label(__('filament-mailbox::mailbox.outbox.actions.details'))
                        ->icon(Heroicon::OutlinedEye)
                        ->authorize(fn (MailboxOutgoingMessage $record): bool => Gate::allows('view', $record))
                        ->modalSubmitAction(false)
                        ->schema([
                            TextEntry::make('to')->label(__('filament-mailbox::mailbox.messages.fields.to'))->state(fn (MailboxOutgoingMessage $record): string => implode(', ', $record->to)),
                            TextEntry::make('cc')->label(__('filament-mailbox::mailbox.messages.fields.cc'))->state(fn (MailboxOutgoingMessage $record): ?string => implode(', ', $record->cc ?? []) ?: null)->placeholder('—'),
                            TextEntry::make('bcc')->label(__('filament-mailbox::mailbox.messages.fields.bcc'))->state(fn (MailboxOutgoingMessage $record): ?string => implode(', ', $record->bcc ?? []) ?: null)->placeholder('—'),
                            TextEntry::make('message_id')->label('Message-ID')->copyable(),
                            TextEntry::make('read_receipts')
                                ->label(__('filament-mailbox::mailbox.read_receipts.section'))
                                ->state(fn (MailboxOutgoingMessage $record): array => MessageInfolist::receiptLines(app(ReadReceiptService::class)->receiptsFor($record)))
                                ->listWithLineBreaks()
                                ->placeholder('—')
                                ->visible(fn (MailboxOutgoingMessage $record): bool => collect($record->headers ?? [])->keys()->contains(fn (string $name): bool => strcasecmp($name, ReadReceiptService::HEADER) === 0)),
                            TextEntry::make('delivery_reports')
                                ->label(__('filament-mailbox::mailbox.delivery_receipts.section'))
                                ->state(fn (MailboxOutgoingMessage $record): array => static::deliveryLines($record))
                                ->listWithLineBreaks()
                                ->placeholder(fn (MailboxOutgoingMessage $record): string => $record->dsn_supported === false
                                    ? __('filament-mailbox::mailbox.delivery_receipts.statuses.unsupported')
                                    : '—')
                                ->visible(fn (MailboxOutgoingMessage $record): bool => $record->dsn_notify !== null),
                            TextEntry::make('last_error')->label(__('filament-mailbox::mailbox.outbox.fields.last_error'))->placeholder('—'),
                            TextEntry::make('body')->label(__('filament-mailbox::mailbox.messages.fields.body'))->state(fn (MailboxOutgoingMessage $record): string => $record->body)->extraAttributes(['class' => 'whitespace-pre-line']),
                        ]),
                    Action::make('edit')
                        ->label(__('filament-mailbox::mailbox.outbox.actions.edit'))
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->authorize(fn (MailboxOutgoingMessage $record): bool => Gate::allows('update', $record))
                        ->visible(fn (MailboxOutgoingMessage $record): bool => in_array($record->status, [OutgoingStatus::Scheduled, OutgoingStatus::Cancelled], true))
                        ->fillForm(fn (MailboxOutgoingMessage $record): array => [
                            'to' => $record->to,
                            'cc' => $record->cc ?? [],
                            'bcc' => $record->bcc ?? [],
                            'subject' => $record->subject,
                            'body' => $record->body,
                        ])
                        ->schema(fn (MailboxOutgoingMessage $record): array => [
                            TagsInput::make('to')->label(__('filament-mailbox::mailbox.messages.fields.to'))->nestedRecursiveRules(['email'])->required(),
                            TagsInput::make('cc')->label(__('filament-mailbox::mailbox.messages.fields.cc'))->nestedRecursiveRules(['email']),
                            TagsInput::make('bcc')->label(__('filament-mailbox::mailbox.messages.fields.bcc'))->nestedRecursiveRules(['email']),
                            TextInput::make('subject')->label(__('filament-mailbox::mailbox.messages.fields.subject'))->required()->maxLength(255),
                            Textarea::make('body')
                                ->label(__('filament-mailbox::mailbox.messages.fields.body'))
                                ->rows(10)
                                ->visible($record->body_html === null)
                                ->helperText(__('filament-mailbox::mailbox.outbox.edit_hint')),
                        ])
                        ->action(function (MailboxOutgoingMessage $record, array $data, OutboxService $outbox): void {
                            $this->notifyOutcome($outbox->update($record, $data), __('filament-mailbox::mailbox.outbox.updated'));
                        }),
                    Action::make('reschedule')
                        ->label(__('filament-mailbox::mailbox.outbox.actions.reschedule'))
                        ->icon(Heroicon::OutlinedClock)
                        ->authorize(fn (MailboxOutgoingMessage $record): bool => Gate::allows('update', $record))
                        ->visible(fn (MailboxOutgoingMessage $record): bool => OutboxService::schedulingEnabled() && in_array($record->status, [OutgoingStatus::Scheduled, OutgoingStatus::Cancelled], true))
                        ->modalWidth('md')
                        ->fillForm(fn (MailboxOutgoingMessage $record): array => ['send_at' => $record->send_at->isFuture() ? $record->send_at : null])
                        ->schema([
                            DateTimePicker::make('send_at')
                                ->label(__('filament-mailbox::mailbox.outbox.fields.send_at'))
                                ->seconds(false)
                                ->timezone(FilamentTimezone::get())
                                ->minDate(now()->startOfMinute())
                                ->maxDate(now()->addDays(OutboxService::MAX_SCHEDULE_DAYS))
                                ->required(),
                        ])
                        ->action(function (MailboxOutgoingMessage $record, array $data, OutboxService $outbox): void {
                            try {
                                $changed = $outbox->reschedule($record, CarbonImmutable::parse($data['send_at'], config('app.timezone')));
                            } catch (InvalidArgumentException) {
                                Notification::make()->danger()->title(__('filament-mailbox::mailbox.outbox.schedule.invalid'))->send();

                                return;
                            }

                            $this->notifyOutcome($changed, __('filament-mailbox::mailbox.outbox.rescheduled'));
                        }),
                    Action::make('sendNow')
                        ->label(__('filament-mailbox::mailbox.outbox.actions.send_now'))
                        ->icon(Heroicon::OutlinedPaperAirplane)
                        ->authorize(fn (MailboxOutgoingMessage $record): bool => Gate::allows('update', $record))
                        ->visible(fn (MailboxOutgoingMessage $record): bool => in_array($record->status, [OutgoingStatus::Scheduled, OutgoingStatus::Cancelled], true))
                        ->requiresConfirmation()
                        ->action(fn (MailboxOutgoingMessage $record, OutboxService $outbox) => $this->notifyOutcome($outbox->sendNow($record), __('filament-mailbox::mailbox.outbox.sending'))),
                    Action::make('cancel')
                        ->label(__('filament-mailbox::mailbox.outbox.actions.cancel'))
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('danger')
                        ->authorize(fn (MailboxOutgoingMessage $record): bool => Gate::allows('update', $record))
                        ->visible(fn (MailboxOutgoingMessage $record): bool => $record->status === OutgoingStatus::Scheduled)
                        ->requiresConfirmation()
                        ->action(fn (MailboxOutgoingMessage $record, OutboxService $outbox) => $this->notifyOutcome($outbox->cancel($record), __('filament-mailbox::mailbox.outbox.cancelled'))),
                    Action::make('retry')
                        ->label(__('filament-mailbox::mailbox.outbox.actions.retry'))
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->authorize(fn (MailboxOutgoingMessage $record): bool => Gate::allows('update', $record))
                        ->visible(fn (MailboxOutgoingMessage $record): bool => $record->status === OutgoingStatus::Failed)
                        ->action(fn (MailboxOutgoingMessage $record, OutboxService $outbox) => $this->notifyOutcome($outbox->retry($record), __('filament-mailbox::mailbox.outbox.retrying'))),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedPaperAirplane)
            ->emptyStateHeading(__('filament-mailbox::mailbox.outbox.empty'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('filament-mailbox::mailbox.actions.back.label'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => MailboxResource::getUrl('browse', ['record' => $this->getRecord()])),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function deliveryLines(MailboxOutgoingMessage $message): array
    {
        return app(DeliveryReportService::class)->receipts($message)
            ->map(fn (\Cooolinho\FilamentMailbox\Models\MailboxReceipt $receipt): string => __('filament-mailbox::mailbox.delivery_receipts.line', [
                'recipient' => $receipt->recipient,
                'status' => __('filament-mailbox::mailbox.delivery_receipts.statuses.'.$receipt->disposition),
                'date' => $receipt->reported_at->copy()->setTimezone(FilamentTimezone::get())->translatedFormat('D, j. M Y, H:i'),
            ]).collect([$receipt->status_code, $receipt->diagnostic])->filter()->map(fn (string $value): string => ' · '.$value)->implode('')
                .($receipt->source === \Cooolinho\FilamentMailbox\Models\MailboxReceipt::SOURCE_BOUNCE_HEURISTIC ? ' · '.__('filament-mailbox::mailbox.delivery_receipts.heuristic') : ''))
            ->all();
    }

    /**
     * The status may have changed meanwhile (e.g. sent by the worker).
     */
    protected function notifyOutcome(bool $changed, string $title): void
    {
        $changed
            ? Notification::make()->success()->title($title)->send()
            : Notification::make()->warning()->title(__('filament-mailbox::mailbox.outbox.too_late'))->send();
    }
}
