<?php

namespace Cooolinho\FilamentMailbox\Services\Receipts;

use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Data\ReportData;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Events\ReadReceiptReceived;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxOutgoingMessage;
use Cooolinho\FilamentMailbox\Models\MailboxReceipt;
use Cooolinho\FilamentMailbox\Models\MailboxReceiptRequest;
use Cooolinho\FilamentMailbox\Notifications\ReadReceiptReceivedNotification;
use Cooolinho\FilamentMailbox\Services\MailSender;
use Cooolinho\FilamentMailbox\Services\MessageService;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Read receipts (Message Disposition Notifications, RFC 8098): requesting
 * them when sending, answering requests only after the user agreed, and
 * assigning incoming receipts to the sent message.
 */
class ReadReceiptService
{
    public const HEADER = 'Disposition-Notification-To';

    public const MDN_SENT_KEYWORD = '$MDNSent';

    public const PENDING = 'pending';

    /** The request does not come from the sender (Return-Path): the user is warned, no receipt can be sent. */
    public const UNSAFE = 'unsafe';

    public const SENT = 'sent';

    public const IGNORED = 'ignored';

    public const NOT_APPLICABLE = 'not_applicable';

    public function __construct(
        protected MailSender $sender,
        protected MessageService $messages,
        protected ReceiptParser $parser,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('filament-mailbox.read_receipts.enabled', true);
    }

    public static function requestByDefault(Mailbox $mailbox): bool
    {
        return static::enabled() && (bool) ($mailbox->request_read_receipts ?? config('filament-mailbox.read_receipts.request_by_default', false));
    }

    /**
     * @return array<string, string>
     */
    public static function requestHeaders(Mailbox $mailbox): array
    {
        return [self::HEADER => '<'.$mailbox->email.'>'];
    }

    /**
     * Remember a requested read receipt of a queued message, to assign incoming receipts.
     */
    public function recordRequest(MailboxOutgoingMessage $message): void
    {
        if (blank(collect($message->headers ?? [])->first(fn (string $value, string $name): bool => strcasecmp($name, self::HEADER) === 0))) {
            return;
        }

        MailboxReceiptRequest::query()->create([
            'mailbox_id' => $message->mailbox_id,
            'outgoing_message_id' => $message->getKey(),
            'message_id' => $message->message_id,
            'type' => MailboxReceiptRequest::TYPE_READ,
            'recipients' => $message->recipients(),
        ]);
    }

    /**
     * Receipt columns of an imported message.
     *
     * @return array{mdn_requested_to: ?string, mdn_status: ?string, is_receipt: bool}
     */
    public function importAttributes(MailboxFolder $folder, MessageData $data): array
    {
        $requestedTo = $data->header(self::HEADER);

        return [
            'mdn_requested_to' => $requestedTo === null ? null : mb_substr($requestedTo, 0, 255),
            'mdn_status' => $requestedTo === null || ! static::enabled() ? null : $this->evaluateRequest($folder, $data, $requestedTo),
            'is_receipt' => str_starts_with(strtolower((string) $data->header('Content-Type')), 'multipart/report'),
        ];
    }

    /**
     * RFC 8098 §2.1: no request for several addresses, own messages or spam;
     * a warning when the address differs from the Return-Path.
     */
    public function evaluateRequest(MailboxFolder $folder, MessageData $data, string $requestedTo): string
    {
        $addresses = static::addresses($requestedTo);
        $mailbox = $folder->mailbox;
        $returnPath = static::addresses((string) $data->header('Return-Path'));

        return match (true) {
            in_array(self::MDN_SENT_KEYWORD, $data->flags->keywords, true) => self::SENT,
            count($addresses) !== 1,
            in_array($folder->special_use, [SpecialUse::Sent, SpecialUse::Drafts, SpecialUse::Junk, SpecialUse::Trash], true),
            $folder->getKey() === $mailbox?->spam_folder_id,
            $data->from !== null && strcasecmp($data->from->address, (string) $mailbox?->email) === 0 => self::NOT_APPLICABLE,
            $returnPath !== [] && $returnPath[0] !== $addresses[0] => self::UNSAFE,
            default => self::PENDING,
        };
    }

    /**
     * "$MDNSent" set by another client: no further request.
     */
    public function applyKeywords(MailboxMessage $message): void
    {
        if (in_array($message->mdn_status, [self::PENDING, self::UNSAFE], true) && in_array(self::MDN_SENT_KEYWORD, $message->keywords ?? [], true)) {
            $message->mdn_status = self::SENT;
        }
    }

    /**
     * Assign an imported read receipt to the requested message. Only
     * receipts for Message-IDs requested from this mailbox are stored.
     */
    public function processReport(MailboxMessage $message, MessageData $data): ?MailboxReceipt
    {
        $content = $data->reportParts['message/disposition-notification'] ?? null;
        $report = $content === null ? null : $this->parser->dispositionNotification($content);

        if ($report === null) {
            return null;
        }

        $request = MailboxReceiptRequest::query()
            ->where('mailbox_id', $message->mailbox_id)
            ->where('message_id', $report['original_message_id'])
            ->where('type', MailboxReceiptRequest::TYPE_READ)
            ->first();

        if (! $request) {
            return null;
        }

        $receipt = MailboxReceipt::query()->firstOrCreate([
            'request_id' => $request->getKey(),
            'recipient' => $report['recipient'] ?? mb_strtolower((string) $message->from_address),
            'disposition' => mb_substr($report['disposition'], 0, 40),
        ], [
            'receipt_message_id' => $message->getKey(),
            'reported_at' => $data->sentAt ?? now(),
        ]);

        if ($receipt->wasRecentlyCreated) {
            ReadReceiptReceived::dispatch($receipt);

            if (config('filament-mailbox.read_receipts.notify_sender', true) && ($user = $request->outgoingMessage?->user()->first())) {
                rescue(fn () => app(ReadReceiptReceivedNotification::class)->send($user, $receipt));
            }
        }

        return $receipt;
    }

    /**
     * Whether the user can be asked to send a read receipt.
     */
    public function canRespond(MailboxMessage $message): bool
    {
        return static::enabled()
            && config('filament-mailbox.read_receipts.allow_sending', true)
            && $message->mdn_status === self::PENDING
            && ! $this->messages->isSpam($message);
    }

    /**
     * Whether a request is shown at all (pending or with a warning).
     */
    public function isOpenRequest(MailboxMessage $message): bool
    {
        return static::enabled()
            && in_array($message->mdn_status, [self::PENDING, self::UNSAFE], true)
            && ! $this->messages->isSpam($message);
    }

    /**
     * Send a read receipt after the user agreed ("manual-action/MDN-sent-manually").
     *
     * @throws InvalidArgumentException when no receipt may be sent
     */
    public function send(MailboxMessage $message): void
    {
        $to = static::addresses((string) $message->mdn_requested_to)[0] ?? null;

        if (! $this->canRespond($message) || $to === null) {
            throw new InvalidArgumentException('No read receipt can be sent for this message.');
        }

        $mailbox = $message->mailbox;
        $messageId = ReceiptParser::messageId($message->message_id);
        $subject = $message->subject ?: __('filament-mailbox::mailbox.messages.no_subject');
        $clean = fn (string $value): string => trim(preg_replace('/[^\x20-\x7E]/', '', $value) ?? '');

        $fields = array_filter([
            'Reporting-UA: '.($clean((string) config('app.name')) ?: 'Laravel').'; filament-mailbox',
            'Original-Recipient: rfc822;'.$clean((string) $mailbox->email),
            'Final-Recipient: rfc822;'.$clean((string) $mailbox->email),
            $messageId ? 'Original-Message-ID: <'.$clean($messageId).'>' : null,
            'Disposition: manual-action/MDN-sent-manually; displayed',
        ]);

        $this->sender->send($mailbox, new OutgoingMessageData(
            to: [$to],
            subject: Str::limit(__('filament-mailbox::mailbox.read_receipts.mdn.subject', ['subject' => $subject]), 250, ''),
            body: __('filament-mailbox::mailbox.read_receipts.mdn.body', [
                'subject' => $subject,
                'date' => ($message->sent_at ?? $message->received_at)?->toDayDateTimeString() ?? '',
                'displayed' => now()->toDayDateTimeString(),
            ]),
            inReplyTo: $messageId,
            references: array_filter([$messageId]),
            headers: ['Auto-Submitted' => 'auto-replied'],
            report: new ReportData('disposition-notification', implode("\r\n", $fields)."\r\n"),
        ));

        $this->handled($message, self::SENT);
    }

    /**
     * Do not send a read receipt and do not ask again.
     */
    public function ignore(MailboxMessage $message): void
    {
        if (! in_array($message->mdn_status, [self::PENDING, self::UNSAFE], true)) {
            return;
        }

        $this->handled($message, self::IGNORED);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, MailboxReceipt>
     */
    public function receiptsFor(MailboxMessage|MailboxOutgoingMessage $message, string $type = MailboxReceiptRequest::TYPE_READ): \Illuminate\Database\Eloquent\Collection
    {
        $messageId = ReceiptParser::messageId($message->message_id);

        return MailboxReceipt::query()
            ->whereHas('request', fn ($requests) => $requests
                ->where('mailbox_id', $message->mailbox_id)
                ->where('type', $type)
                ->where('message_id', $messageId ?? ''))
            ->orderBy('reported_at')
            ->get();
    }

    /**
     * Other clients see "$MDNSent" too (RFC 8098 §5).
     */
    protected function handled(MailboxMessage $message, string $status): void
    {
        try {
            $this->messages->addKeywords($message, [self::MDN_SENT_KEYWORD]);
        } catch (Throwable $exception) {
            report($exception);
        }

        $message->forceFill(['mdn_status' => $status])->save();
    }

    /**
     * @return array<int, string> Lower-case addresses
     */
    public static function addresses(string $value): array
    {
        preg_match_all('/[^\s<>,;:"()]+@[^\s<>,;:"()]+/', $value, $matches);

        return array_values(array_unique(array_filter(
            array_map(fn (string $address): string => mb_strtolower(trim($address, '.')), $matches[0]),
            fn (string $address): bool => (bool) filter_var($address, FILTER_VALIDATE_EMAIL),
        )));
    }
}
