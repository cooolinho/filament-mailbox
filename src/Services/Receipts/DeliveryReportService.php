<?php

namespace Cooolinho\FilamentMailbox\Services\Receipts;

use Cooolinho\FilamentMailbox\Data\DsnOptions;
use Cooolinho\FilamentMailbox\Data\MessageData;
use Cooolinho\FilamentMailbox\Events\DeliveryFailed;
use Cooolinho\FilamentMailbox\Events\DeliveryReportReceived;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxOutgoingMessage;
use Cooolinho\FilamentMailbox\Models\MailboxReceipt;
use Cooolinho\FilamentMailbox\Models\MailboxReceiptRequest;
use Cooolinho\FilamentMailbox\Notifications\DeliveryFailedNotification;
use Illuminate\Support\Collection;

/**
 * Delivery status notifications (RFC 3461/3464) and bounces: requested for
 * every message sent through the outbox, assigned to it per recipient.
 */
class DeliveryReportService
{
    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_DELAYED = 'delayed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_UNSUPPORTED = 'unsupported';

    public function __construct(
        protected ReceiptParser $parser,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('filament-mailbox.delivery_receipts.enabled', true);
    }

    public static function requestSuccessByDefault(): bool
    {
        return static::enabled() && (bool) config('filament-mailbox.delivery_receipts.request_success_by_default', false);
    }

    /**
     * The NOTIFY value of a message: failures and delays always, success on request.
     */
    public function notifyFor(bool $success): ?string
    {
        return static::enabled() ? ($success ? DsnOptions::NOTIFY_ALL : DsnOptions::NOTIFY_PROBLEMS) : null;
    }

    public function recordRequest(MailboxOutgoingMessage $message): void
    {
        if ($message->dsn_notify === null) {
            return;
        }

        MailboxReceiptRequest::query()->create([
            'mailbox_id' => $message->mailbox_id,
            'outgoing_message_id' => $message->getKey(),
            'message_id' => $message->message_id,
            'type' => MailboxReceiptRequest::TYPE_DELIVERY,
            'recipients' => $message->recipients(),
        ]);
    }

    public function optionsFor(MailboxOutgoingMessage $message): ?DsnOptions
    {
        return $message->dsn_notify === null ? null : new DsnOptions($message->uuid, $message->dsn_notify);
    }

    /**
     * Whether an imported message is a delivery report or looks like a bounce.
     */
    public function isReport(MessageData $data): bool
    {
        return isset($data->reportParts['message/delivery-status']) || isset($data->reportParts['message/global-delivery-status']) || $this->looksLikeBounce($data);
    }

    /**
     * Assign a delivery report or bounce to the outbox message it is about.
     *
     * @return Collection<int, MailboxReceipt> created or existing receipts
     */
    public function process(MailboxMessage $message, MessageData $data): Collection
    {
        if (! static::enabled()) {
            return collect();
        }

        $status = $data->reportParts['message/delivery-status'] ?? $data->reportParts['message/global-delivery-status'] ?? null;
        $report = $status === null ? null : $this->parser->deliveryStatus($status);

        if ($report !== null) {
            $request = $this->requestByEnvelopeId($message, $report['envelope_id']) ?? $this->requestByMessageIds($message, $this->returnedMessageIds($data));

            return $request ? $this->store($message, $data, $request, $report['recipients'], MailboxReceipt::SOURCE_DSN, $report['reporting_mta']) : collect();
        }

        if (! config('filament-mailbox.delivery_receipts.bounce_heuristics', true) || ! $this->looksLikeBounce($data)) {
            return collect();
        }

        $request = $this->requestByMessageIds($message, $this->returnedMessageIds($data));

        if (! $request) {
            return collect();
        }

        $failed = static::failedRecipients($data, $request);

        return $this->store($message, $data, $request, array_map(fn (string $recipient): array => [
            'recipient' => $recipient,
            'action' => self::STATUS_FAILED,
            'status' => null,
            'diagnostic' => null,
        ], $failed), MailboxReceipt::SOURCE_BOUNCE_HEURISTIC, null);
    }

    /**
     * Aggregated delivery status of an outbox message.
     */
    public function statusOf(MailboxOutgoingMessage $message): ?string
    {
        if ($message->dsn_notify === null || $message->sent_at === null) {
            return null;
        }

        $receipts = $this->receipts($message);
        $latest = $receipts->groupBy('recipient')->map(fn (Collection $items) => $items->sortBy('reported_at')->last()->disposition);

        return match (true) {
            $latest->contains(self::STATUS_FAILED) => self::STATUS_FAILED,
            $latest->contains(self::STATUS_DELAYED) => self::STATUS_DELAYED,
            $latest->isNotEmpty() && $latest->count() >= count($message->recipients()) => self::STATUS_DELIVERED,
            $message->dsn_supported === false => self::STATUS_UNSUPPORTED,
            $latest->isNotEmpty() => self::STATUS_DELIVERED,
            default => self::STATUS_UNKNOWN,
        };
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, MailboxReceipt>
     */
    public function receipts(MailboxOutgoingMessage $message): \Illuminate\Database\Eloquent\Collection
    {
        return MailboxReceipt::query()
            ->whereHas('request', fn ($requests) => $requests->where('outgoing_message_id', $message->getKey())->where('type', MailboxReceiptRequest::TYPE_DELIVERY))
            ->orderBy('reported_at')
            ->get();
    }

    public function looksLikeBounce(MessageData $data): bool
    {
        $from = strtolower((string) $data->from?->address);
        $local = strstr($from, '@', true) ?: $from;

        if (! in_array($local, ['mailer-daemon', 'postmaster', 'mail-daemon', 'mailerdaemon'], true) && blank($data->header('X-Failed-Recipients'))) {
            return false;
        }

        return (bool) preg_match('/undeliver|delivery status notification|delivery (has )?failed|failure notice|returned mail|mail delivery (failed|system)|could not be delivered|unzustellbar|nicht zustellbar|non remis|no entregado/i', (string) $data->subject)
            || filled($data->header('X-Failed-Recipients'));
    }

    protected function requestByEnvelopeId(MailboxMessage $message, ?string $envelopeId): ?MailboxReceiptRequest
    {
        if (blank($envelopeId)) {
            return null;
        }

        return MailboxReceiptRequest::query()
            ->where('mailbox_id', $message->mailbox_id)
            ->where('type', MailboxReceiptRequest::TYPE_DELIVERY)
            ->whereHas('outgoingMessage', fn ($outgoing) => $outgoing->where('uuid', $envelopeId))
            ->first();
    }

    /**
     * @param  array<int, string>  $messageIds
     */
    protected function requestByMessageIds(MailboxMessage $message, array $messageIds): ?MailboxReceiptRequest
    {
        if ($messageIds === []) {
            return null;
        }

        return MailboxReceiptRequest::query()
            ->where('mailbox_id', $message->mailbox_id)
            ->where('type', MailboxReceiptRequest::TYPE_DELIVERY)
            ->whereIn('message_id', $messageIds)
            ->first();
    }

    /**
     * Message-IDs of the returned message: from the returned headers (RET=HDRS), otherwise from the text.
     *
     * @return array<int, string>
     */
    protected function returnedMessageIds(MessageData $data): array
    {
        $headers = $data->reportParts['text/rfc822-headers'] ?? $data->reportParts['message/rfc822-headers'] ?? null;

        if ($headers !== null && preg_match('/^Message-ID:\s*(<[^>]+>)/mi', $headers, $matches)) {
            return array_filter([ReceiptParser::messageId($matches[1])]);
        }

        preg_match_all('/Message-ID:\s*<([^<>\s]+@[^<>\s]+)>/i', mb_substr((string) $data->textBody, 0, 200_000), $matches);

        return array_slice(array_values(array_unique($matches[1])), 0, 10);
    }

    /**
     * Recipients of the request named in X-Failed-Recipients or in the bounce text.
     *
     * @return array<int, string>
     */
    protected static function failedRecipients(MessageData $data, MailboxReceiptRequest $request): array
    {
        $recipients = array_map('mb_strtolower', $request->recipients);
        $named = ReadReceiptService::addresses((string) $data->header('X-Failed-Recipients'));

        if ($named === []) {
            $named = ReadReceiptService::addresses(mb_substr((string) $data->textBody, 0, 200_000));
        }

        $failed = array_values(array_intersect($recipients, $named));

        return $failed === [] && count($recipients) === 1 ? $recipients : $failed;
    }

    /**
     * @param  array<int, array{recipient: string, action: string, status: ?string, diagnostic: ?string}>  $entries
     * @return Collection<int, MailboxReceipt>
     */
    protected function store(MailboxMessage $message, MessageData $data, MailboxReceiptRequest $request, array $entries, string $source, ?string $reportingMta): Collection
    {
        $message->is_receipt || $message->forceFill(['is_receipt' => true])->save();

        $receipts = collect();
        $failed = collect();
        $allowed = array_map('mb_strtolower', $request->recipients);

        foreach ($entries as $entry) {
            $disposition = match ($entry['action']) {
                'failed' => self::STATUS_FAILED,
                'delayed' => self::STATUS_DELAYED,
                default => self::STATUS_DELIVERED, // delivered, relayed, expanded
            };

            // Reports are untrusted: only recipients of the sent message.
            if (! in_array($entry['recipient'], $allowed, true)) {
                continue;
            }

            $receipt = MailboxReceipt::query()->firstOrCreate([
                'request_id' => $request->getKey(),
                'recipient' => $entry['recipient'],
                'disposition' => $disposition,
            ], [
                'receipt_message_id' => $message->getKey(),
                'status_code' => $entry['status'],
                'diagnostic' => $entry['diagnostic'],
                'source' => $source,
                'reporting_mta' => $reportingMta,
                'reported_at' => $data->sentAt ?? now(),
            ]);

            if ($receipt->wasRecentlyCreated) {
                DeliveryReportReceived::dispatch($receipt);

                if ($disposition === self::STATUS_FAILED) {
                    DeliveryFailed::dispatch($receipt);
                    $failed->push($receipt);
                }
            }

            $receipts->push($receipt);
        }

        if ($failed->isNotEmpty() && ($user = $request->outgoingMessage?->user()->first())) {
            rescue(fn () => app(DeliveryFailedNotification::class)->send($user, $request, $failed));
        }

        return $receipts;
    }
}
