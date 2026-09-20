<?php

namespace Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Actions\TagActions;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Services\AttachmentService;
use Cooolinho\FilamentMailbox\Services\HtmlBodySanitizer;
use Cooolinho\FilamentMailbox\Models\MailboxReceipt;
use Cooolinho\FilamentMailbox\Services\MessageService;
use Cooolinho\FilamentMailbox\Services\Receipts\ReadReceiptService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Throwable;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;
use Illuminate\Support\HtmlString;
use Livewire\Component;

class MessageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make(__('filament-mailbox::mailbox.spam.callout.heading'))
                ->description(__('filament-mailbox::mailbox.spam.callout.description'))
                ->warning()
                ->visible(fn (MailboxMessage $record): bool => static::isStrict($record)),

            // Never answered automatically (RFC 8098): the user decides.
            Callout::make(__('filament-mailbox::mailbox.read_receipts.callout.heading'))
                ->key('readReceiptRequest')
                ->description(fn (MailboxMessage $record): string => $record->mdn_status === ReadReceiptService::UNSAFE
                    ? __('filament-mailbox::mailbox.read_receipts.callout.unsafe', ['address' => $record->mdn_requested_to])
                    : __('filament-mailbox::mailbox.read_receipts.callout.description', ['address' => $record->mdn_requested_to]))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color(fn (MailboxMessage $record): string => $record->mdn_status === ReadReceiptService::UNSAFE ? 'warning' : 'info')
                ->visible(fn (MailboxMessage $record): bool => app(ReadReceiptService::class)->isOpenRequest($record))
                ->actions([
                    Action::make('sendReadReceipt')
                        ->label(__('filament-mailbox::mailbox.read_receipts.actions.send'))
                        ->size('sm')
                        ->authorize(fn (MailboxMessage $record): bool => Gate::allows('reply', $record))
                        ->visible(fn (MailboxMessage $record): bool => app(ReadReceiptService::class)->canRespond($record))
                        ->action(function (MailboxMessage $record, ReadReceiptService $receipts): void {
                            try {
                                $receipts->send($record);
                            } catch (Throwable $exception) {
                                report($exception);
                                Notification::make()->danger()->title(__('filament-mailbox::mailbox.read_receipts.failed'))->send();

                                return;
                            }

                            Notification::make()->success()->title(__('filament-mailbox::mailbox.read_receipts.sent'))->send();
                        }),
                    Action::make('ignoreReadReceipt')
                        ->label(__('filament-mailbox::mailbox.read_receipts.actions.ignore'))
                        ->size('sm')
                        ->color('gray')
                        ->authorize(fn (MailboxMessage $record): bool => Gate::allows('update', $record))
                        ->action(fn (MailboxMessage $record, ReadReceiptService $receipts) => $receipts->ignore($record)),
                ]),

            Section::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('from')
                        ->label(__('filament-mailbox::mailbox.messages.fields.from'))
                        ->state(fn (MailboxMessage $record): ?string => static::formatAddress([
                            'address' => $record->from_address,
                            'name' => $record->from_name,
                        ])),
                    TextEntry::make('received_at')
                        ->label(__('filament-mailbox::mailbox.messages.fields.date'))
                        ->dateTime(),
                    TextEntry::make('to')
                        ->label(__('filament-mailbox::mailbox.messages.fields.to'))
                        ->state(fn (MailboxMessage $record): array => static::formatAddresses($record->to))
                        ->listWithLineBreaks()
                        ->placeholder('—'),
                    TextEntry::make('cc')
                        ->label(__('filament-mailbox::mailbox.messages.fields.cc'))
                        ->state(fn (MailboxMessage $record): array => static::formatAddresses($record->cc))
                        ->listWithLineBreaks()
                        ->hidden(fn (MailboxMessage $record): bool => blank($record->cc)),
                    TextEntry::make('subject')
                        ->label(__('filament-mailbox::mailbox.messages.fields.subject'))
                        ->placeholder(__('filament-mailbox::mailbox.messages.no_subject'))
                        ->columnSpanFull(),
                    TextEntry::make('tags')
                        ->label(__('filament-mailbox::mailbox.tags.plural_label'))
                        ->state(fn (MailboxMessage $record): array => $record->tags()->ordered()->pluck('name')->all())
                        ->badge()
                        ->color(fn (string $state, MailboxMessage $record): array|string => TagActions::color($record->tags->firstWhere('name', $state)))
                        ->hidden(fn (MailboxMessage $record): bool => $record->tags->isEmpty())
                        ->columnSpanFull(),
                ]),

            Section::make(__('filament-mailbox::mailbox.messages.fields.attachments'))
                ->icon(Heroicon::OutlinedPaperClip)
                ->visible(fn (MailboxMessage $record): bool => $record->attachments->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('attachments')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('filename')
                                ->label(__('filament-mailbox::mailbox.attachments.fields.filename'))
                                ->icon(Heroicon::OutlinedArrowDownTray)
                                ->color('primary')
                                ->url(fn (MailboxAttachment $record): ?string => static::isStrict($record->message) ? null : app(AttachmentService::class)->downloadUrl($record))
                                // Attachments of spam are only downloaded after a warning.
                                ->action(
                                    Action::make('downloadAttachment')
                                        ->requiresConfirmation()
                                        ->modalIcon(Heroicon::OutlinedExclamationTriangle)
                                        ->modalHeading(__('filament-mailbox::mailbox.spam.download.heading'))
                                        ->modalDescription(__('filament-mailbox::mailbox.spam.download.description'))
                                        ->color('warning')
                                        ->action(fn (MailboxAttachment $record, Component $livewire) => $livewire->redirect(app(AttachmentService::class)->downloadUrl($record))),
                                ),
                            TextEntry::make('mime_type')
                                ->label(__('filament-mailbox::mailbox.attachments.fields.mime_type'))
                                ->placeholder('—'),
                            TextEntry::make('size')
                                ->label(__('filament-mailbox::mailbox.attachments.fields.size'))
                                ->formatStateUsing(fn (int $state): string => Number::fileSize($state)),
                        ]),
                ]),

            Section::make(__('filament-mailbox::mailbox.read_receipts.section'))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->collapsible()
                ->visible(fn (MailboxMessage $record): bool => static::receipts($record)->isNotEmpty())
                ->schema([
                    TextEntry::make('read_receipts')
                        ->hiddenLabel()
                        ->state(fn (MailboxMessage $record): array => static::receiptLines(static::receipts($record)))
                        ->listWithLineBreaks(),
                ]),

            Section::make(__('filament-mailbox::mailbox.messages.fields.body'))
                ->schema([
                    ViewEntry::make('html_body')
                        ->hiddenLabel()
                        ->view('filament-mailbox::infolists.message-body')
                        ->state(fn (MailboxMessage $record): string => app(HtmlBodySanitizer::class)->document($record->html_body, strict: static::isStrict($record)))
                        ->visible(fn (MailboxMessage $record): bool => filled($record->html_body)),
                    TextEntry::make('text_body')
                        ->hiddenLabel()
                        // Escaped first; only the line breaks are markup.
                        ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString(nl2br(e($state))))
                        ->placeholder('—')
                        ->visible(fn (MailboxMessage $record): bool => blank($record->html_body)),
                ]),
        ]);
    }

    /**
     * Read receipts of a sent message, matched by its Message-ID.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, MailboxReceipt>
     */
    public static function receipts(MailboxMessage $message): \Illuminate\Database\Eloquent\Collection
    {
        return ReadReceiptService::enabled() && filled($message->message_id)
            ? app(ReadReceiptService::class)->receiptsFor($message)
            : new \Illuminate\Database\Eloquent\Collection;
    }

    /**
     * @param  iterable<MailboxReceipt>  $receipts
     * @return array<int, string>
     */
    public static function receiptLines(iterable $receipts): array
    {
        $lines = [];

        foreach ($receipts as $receipt) {
            $key = 'filament-mailbox::mailbox.read_receipts.dispositions.'.$receipt->disposition;

            $lines[] = __('filament-mailbox::mailbox.read_receipts.line', [
                'recipient' => $receipt->recipient,
                'disposition' => \Illuminate\Support\Facades\Lang::has($key) ? __($key) : $receipt->disposition,
                'date' => $receipt->reported_at->copy()->setTimezone(\Filament\Support\Facades\FilamentTimezone::get())->translatedFormat('D, j. M Y, H:i'),
            ]);
        }

        return $lines;
    }

    /**
     * Messages in the spam folder are rendered without links.
     */
    public static function isStrict(?MailboxMessage $message): bool
    {
        return $message !== null
            && config('filament-mailbox.spam.strict_rendering', true)
            && app(MessageService::class)->isSpam($message);
    }

    /**
     * @param  array{address: ?string, name: ?string}  $address
     */
    public static function formatAddress(array $address): ?string
    {
        if (blank($address['address'] ?? null)) {
            return null;
        }

        return filled($address['name'] ?? null)
            ? "{$address['name']} <{$address['address']}>"
            : $address['address'];
    }

    /**
     * @param  ?array<int, array{address: ?string, name: ?string}>  $addresses
     * @return array<int, string>
     */
    public static function formatAddresses(?array $addresses): array
    {
        return array_values(array_filter(array_map(static::formatAddress(...), $addresses ?? [])));
    }
}
