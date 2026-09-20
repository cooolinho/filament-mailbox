<?php

namespace Cooolinho\FilamentMailbox\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cooolinho\FilamentMailbox\Data\AttachmentData;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Enums\OutgoingStatus;
use Cooolinho\FilamentMailbox\Events\MessageForwarded;
use Cooolinho\FilamentMailbox\Exceptions\NotAllowedToSend;
use Cooolinho\FilamentMailbox\Events\OutgoingMessageFailed;
use Cooolinho\FilamentMailbox\Events\OutgoingMessageQueued;
use Cooolinho\FilamentMailbox\Events\OutgoingMessageSent;
use Cooolinho\FilamentMailbox\Jobs\SendOutgoingMessageJob;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Models\MailboxOutgoingAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxOutgoingMessage;
use Cooolinho\FilamentMailbox\Notifications\OutgoingMessageFailedNotification;
use Cooolinho\FilamentMailbox\Services\Receipts\DeliveryReportService;
use Cooolinho\FilamentMailbox\Services\Receipts\ReadReceiptService;
use Cooolinho\FilamentMailbox\Support\CredentialRedactor;
use Cooolinho\FilamentMailbox\Support\RelativeDate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Every message sent from the app goes through the outbox. A message is
 * sent right away (in the request, see "outbox.send_immediately_inline"),
 * after the undo window or at a scheduled time by SendOutgoingMessageJob
 * and "mailbox:send-due". Status transitions are conditional updates, so a
 * message is never sent twice by parallel workers.
 */
class OutboxService
{
    public const CANCEL_EVENT = 'filament-mailbox-cancel-outgoing';

    public const MAX_SCHEDULE_DAYS = 366;

    public function __construct(
        protected MailSender $sender,
        protected MessageService $messages,
    ) {}

    public static function schedulingEnabled(): bool
    {
        return (bool) config('filament-mailbox.outbox.scheduling', true);
    }

    public static function undoSeconds(): int
    {
        return max(0, (int) config('filament-mailbox.outbox.undo_seconds', 0));
    }

    /**
     * @return array<string, string> Relative date expressions keyed by preset
     */
    public function presets(): array
    {
        return array_filter((array) config('filament-mailbox.outbox.schedule_presets', []), fn (mixed $expression): bool => is_string($expression) && $expression !== '');
    }

    public function resolvePreset(string $preset, ?string $timezone = null): ?CarbonImmutable
    {
        $expression = $this->presets()[$preset] ?? null;

        return $expression === null ? null : RelativeDate::resolve($expression, $timezone);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function validateSendAt(CarbonInterface $sendAt): void
    {
        if ($sendAt->lessThan(now()->subMinute()) || $sendAt->greaterThan(now()->addDays(self::MAX_SCHEDULE_DAYS))) {
            throw new InvalidArgumentException('The send time must be in the future and within one year.');
        }
    }

    /**
     * Send or queue a message.
     *
     * Without a send time and undo window the message is sent in this request
     * (with "send_immediately_inline"); a failure is thrown and nothing stays
     * in the outbox, so the user can correct and send again.
     *
     * @param  array{reply_to?: ?MailboxMessage, forward_of?: ?MailboxMessage, as_attachment?: bool}  $context
     *
     * @throws InvalidArgumentException for an invalid send time
     * @throws Throwable when sending right away fails
     */
    public function send(Mailbox $mailbox, OutgoingMessageData $data, ?Authenticatable $user, ?CarbonInterface $sendAt = null, array $context = []): MailboxOutgoingMessage
    {
        if ($sendAt !== null) {
            $this->validateSendAt($sendAt);
        }

        $deferred = $sendAt !== null || static::undoSeconds() > 0 || ! config('filament-mailbox.outbox.send_immediately_inline', true);
        $sendAt = CarbonImmutable::instance($sendAt ?? now()->addSeconds(static::undoSeconds()))->setTimezone(config('app.timezone'));

        $message = $this->create($mailbox, $data, $user, $sendAt, $context, storeAttachments: $deferred);

        if (! $deferred) {
            try {
                $this->deliver($message, new OutgoingMessageData(...[...get_object_vars($data), 'messageId' => $message->message_id]), inline: true);
            } catch (Throwable $exception) {
                $this->purge($message);

                throw $exception;
            }

            return $message->refresh();
        }

        OutgoingMessageQueued::dispatch($message);
        $this->dispatchJob($message);

        return $message;
    }

    /**
     * Send a queued message whose time has come. Returns whether it was sent.
     *
     * @param  ?OutgoingMessageData  $data  The message as composed (inline sending), otherwise rebuilt from the outbox
     *
     * @throws Throwable only when sending inline
     */
    public function deliver(MailboxOutgoingMessage $message, ?OutgoingMessageData $data = null, bool $inline = false): bool
    {
        if (! $this->claim($message)) {
            return false;
        }

        $message->refresh()->loadMissing('mailbox');

        try {
            $this->ensureMaySend($message);

            $dsn = app(DeliveryReportService::class)->optionsFor($message);

            $this->sender->send($message->mailbox, $data === null
                ? $this->toData($message)
                : new OutgoingMessageData(...[...get_object_vars($data), 'dsn' => $dsn]));
        } catch (Throwable $exception) {
            if ($inline) {
                throw $exception;
            }

            report($exception);
            $this->failed($message, $exception);

            return false;
        }

        $this->transition($message, OutgoingStatus::Sending, [
            'status' => OutgoingStatus::Sent,
            'sent_at' => now(),
            'last_error' => null,
            'dsn_supported' => $this->sender->dsnSupported(),
        ]);

        $this->deleteAttachments($message);
        $this->afterSent($message);

        OutgoingMessageSent::dispatch($message);

        return true;
    }

    /**
     * Rebuild the message to send from the outbox.
     */
    public function toData(MailboxOutgoingMessage $message): OutgoingMessageData
    {
        return new OutgoingMessageData(
            to: $message->to,
            subject: $message->subject,
            body: $message->body,
            cc: $message->cc ?? [],
            bcc: $message->bcc ?? [],
            attachments: $message->attachments()->orderBy('id')->get()->map(fn (MailboxOutgoingAttachment $attachment): AttachmentData => $attachment->toData())->all(),
            inReplyTo: $message->in_reply_to,
            references: $message->references ?? [],
            providerThreadId: $message->provider_thread_id,
            forwardedMessageId: $message->forwarded_message_id,
            bodyHtml: $message->body_html,
            messageId: $message->message_id,
            headers: $message->headers ?? [],
            dsn: app(DeliveryReportService::class)->optionsFor($message),
        );
    }

    public function cancel(MailboxOutgoingMessage $message): bool
    {
        return $this->transition($message, OutgoingStatus::Scheduled, ['status' => OutgoingStatus::Cancelled]);
    }

    /**
     * Send a scheduled message now (or a cancelled one after all).
     */
    public function sendNow(MailboxOutgoingMessage $message): bool
    {
        return $this->reschedule($message, now());
    }

    /**
     * Change the send time of a scheduled or cancelled message.
     *
     * @throws InvalidArgumentException
     */
    public function reschedule(MailboxOutgoingMessage $message, CarbonInterface $sendAt): bool
    {
        $this->validateSendAt($sendAt);

        $changed = $this->transition($message, [OutgoingStatus::Scheduled, OutgoingStatus::Cancelled], [
            'status' => OutgoingStatus::Scheduled,
            'send_at' => CarbonImmutable::instance($sendAt)->setTimezone(config('app.timezone')),
        ]);

        if ($changed) {
            $this->dispatchJob($message);
        }

        return $changed;
    }

    /**
     * Try a failed message again, with fresh attempts.
     */
    public function retry(MailboxOutgoingMessage $message): bool
    {
        $changed = $this->transition($message, OutgoingStatus::Failed, [
            'status' => OutgoingStatus::Scheduled,
            'send_at' => now(),
            'attempts' => 0,
        ]);

        if ($changed) {
            $this->dispatchJob($message);
        }

        return $changed;
    }

    /**
     * Change recipients, subject or the plain text body of a message that was not sent yet.
     *
     * @param  array{to?: array<int, string>, cc?: array<int, string>, bcc?: array<int, string>, subject?: string, body?: string}  $changes
     */
    public function update(MailboxOutgoingMessage $message, array $changes): bool
    {
        $attributes = array_filter([
            'to' => isset($changes['to']) ? array_values($changes['to']) : null,
            'cc' => array_key_exists('cc', $changes) ? array_values($changes['cc'] ?? []) : null,
            'bcc' => array_key_exists('bcc', $changes) ? array_values($changes['bcc'] ?? []) : null,
            'subject' => isset($changes['subject']) ? mb_substr((string) $changes['subject'], 0, 255) : null,
            // HTML messages keep their body: inline images are referenced by content id.
            'body' => isset($changes['body']) && $message->body_html === null ? (string) $changes['body'] : null,
        ], fn (mixed $value): bool => $value !== null);

        if (($attributes['to'] ?? $message->to) === []) {
            throw new InvalidArgumentException('A message needs at least one recipient.');
        }

        // Encrypted and JSON casts are applied by the model, so the conditional update runs on a fresh instance.
        return DB::transaction(function () use ($message, $attributes): bool {
            $locked = MailboxOutgoingMessage::query()->whereKey($message->getKey())->lockForUpdate()->first();

            if (! $locked || ! in_array($locked->status, [OutgoingStatus::Scheduled, OutgoingStatus::Cancelled], true)) {
                return false;
            }

            $locked->forceFill($attributes)->save();
            $message->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Queue due messages (fallback for lost or undelayed jobs) and fail
     * messages that hang in "sending" - they are not sent again, as the
     * server may have accepted them.
     *
     * @return int Number of dispatched messages
     */
    public function dispatchDue(): int
    {
        $stuckAfter = max(1, (int) config('filament-mailbox.outbox.stuck_after_minutes', 15));

        MailboxOutgoingMessage::query()
            ->where('status', OutgoingStatus::Sending)
            ->where('updated_at', '<', now()->subMinutes($stuckAfter))
            ->each(function (MailboxOutgoingMessage $message): void {
                if ($this->transition($message, OutgoingStatus::Sending, ['status' => OutgoingStatus::Failed, 'last_error' => __('filament-mailbox::mailbox.outbox.errors.interrupted')])) {
                    $this->notifyFailure($message);
                }
            });

        $count = 0;

        MailboxOutgoingMessage::query()
            ->where('status', OutgoingStatus::Scheduled)
            ->where('send_at', '<=', now())
            ->select('id')
            ->chunkById(100, function ($messages) use (&$count): void {
                foreach ($messages as $message) {
                    SendOutgoingMessageJob::dispatch($message->getKey());
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Delete sent, failed and cancelled messages older than the given days.
     */
    public function prune(int $days): int
    {
        $count = 0;

        MailboxOutgoingMessage::query()
            ->whereIn('status', [OutgoingStatus::Sent, OutgoingStatus::Failed, OutgoingStatus::Cancelled])
            ->where('updated_at', '<', now()->subDays($days))
            ->chunkById(100, function ($messages) use (&$count): void {
                foreach ($messages as $message) {
                    $this->purge($message);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * @param  array{reply_to?: ?MailboxMessage, forward_of?: ?MailboxMessage, as_attachment?: bool}  $context
     */
    protected function create(Mailbox $mailbox, OutgoingMessageData $data, ?Authenticatable $user, CarbonInterface $sendAt, array $context, bool $storeAttachments): MailboxOutgoingMessage
    {
        if ($data->to === [] && $data->cc === [] && $data->bcc === []) {
            throw new InvalidArgumentException('A message needs at least one recipient.');
        }

        return DB::transaction(function () use ($mailbox, $data, $user, $sendAt, $context, $storeAttachments): MailboxOutgoingMessage {
            $message = $mailbox->outgoingMessages()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user?->getAuthIdentifier(),
                'status' => OutgoingStatus::Scheduled,
                'to' => array_values($data->to),
                'cc' => array_values($data->cc),
                'bcc' => array_values($data->bcc),
                'subject' => mb_substr($data->subject, 0, 255),
                'body' => $data->body,
                'body_html' => $data->bodyHtml,
                // Fixed before the first attempt: a retry after an unclear timeout keeps the Message-ID.
                'message_id' => $data->messageId ?? static::messageId($mailbox),
                'in_reply_to' => $data->inReplyTo,
                'references' => $data->references === [] ? null : $data->references,
                'provider_thread_id' => $data->providerThreadId,
                'forwarded_message_id' => $data->forwardedMessageId,
                'headers' => $data->headers === [] ? null : $data->headers,
                'reply_to_message_id' => ($context['reply_to'] ?? null)?->getKey(),
                'forward_of_message_id' => ($context['forward_of'] ?? null)?->getKey(),
                'forward_as_attachment' => (bool) ($context['as_attachment'] ?? false),
                'dsn_notify' => app(DeliveryReportService::class)->notifyFor($data->requestDeliveryReceipt),
                'send_at' => $sendAt,
            ]);

            app(ReadReceiptService::class)->recordRequest($message);
            app(DeliveryReportService::class)->recordRequest($message);

            if ($storeAttachments) {
                $disk = (string) config('filament-mailbox.attachments.disk');

                foreach (array_values($data->attachments) as $index => $attachment) {
                    $path = $message->directory().'/'.$index.'-'.AttachmentService::sanitizeFilename($attachment->filename);

                    Storage::disk($disk)->put($path, $attachment->contents);

                    $message->attachments()->create([
                        'filename' => AttachmentService::sanitizeFilename($attachment->filename),
                        'mime_type' => mb_substr($attachment->mimeType, 0, 255),
                        'size' => $attachment->size(),
                        'content_id' => $attachment->contentId,
                        'inline' => $attachment->inline,
                        'disk' => $disk,
                        'storage_path' => $path,
                    ]);
                }
            }

            return $message;
        });
    }

    public static function messageId(Mailbox $mailbox): string
    {
        $domain = Str::afterLast((string) $mailbox->email, '@');

        return Str::uuid().'@'.(filled($domain) && ! str_contains($domain, ' ') ? $domain : 'filament-mailbox.local');
    }

    /**
     * scheduled → sending, only when due. Counts the attempt.
     */
    protected function claim(MailboxOutgoingMessage $message): bool
    {
        return MailboxOutgoingMessage::query()
            ->whereKey($message->getKey())
            ->where('status', OutgoingStatus::Scheduled)
            ->where('send_at', '<=', now())
            ->update([
                'status' => OutgoingStatus::Sending,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Conditional status change.
     *
     * @param  OutgoingStatus|array<int, OutgoingStatus>  $from
     * @param  array<string, mixed>  $attributes
     */
    protected function transition(MailboxOutgoingMessage $message, OutgoingStatus|array $from, array $attributes): bool
    {
        $values = array_map(fn (mixed $value): mixed => $value instanceof OutgoingStatus ? $value->value : $value, $attributes);

        $changed = MailboxOutgoingMessage::query()
            ->whereKey($message->getKey())
            ->whereIn('status', array_map(fn (OutgoingStatus $status): string => $status->value, is_array($from) ? $from : [$from]))
            ->update([...$values, 'updated_at' => now()]) === 1;

        if ($changed) {
            $message->forceFill($attributes)->syncOriginal();
        }

        return $changed;
    }

    /**
     * The permission to send is checked again: it may have been revoked since the message was queued.
     */
    protected function ensureMaySend(MailboxOutgoingMessage $message): void
    {
        if ($message->user_id === null) {
            return;
        }

        $model = config('filament-mailbox.user_model');
        /** @var ?User $user */
        $user = class_exists($model) ? $model::query()->find($message->user_id) : null;

        if (! $user || Gate::forUser($user)->denies('send', $message->mailbox)) {
            throw new NotAllowedToSend(__('filament-mailbox::mailbox.outbox.errors.not_allowed'));
        }
    }

    protected function failed(MailboxOutgoingMessage $message, Throwable $exception): void
    {
        $error = mb_substr(CredentialRedactor::redact($exception->getMessage(), $message->mailbox), 0, 1000);
        $backoff = array_values((array) config('filament-mailbox.outbox.retry_backoff_minutes', [1, 5, 15]));
        $final = $exception instanceof NotAllowedToSend || $message->attempts >= max(1, (int) config('filament-mailbox.outbox.max_attempts', 3));

        if ($final) {
            $this->transition($message, OutgoingStatus::Sending, ['status' => OutgoingStatus::Failed, 'last_error' => $error]);
            $this->notifyFailure($message);

            OutgoingMessageFailed::dispatch($message);

            return;
        }

        $minutes = (int) ($backoff[$message->attempts - 1] ?? end($backoff) ?: 1);

        $this->transition($message, OutgoingStatus::Sending, [
            'status' => OutgoingStatus::Scheduled,
            'send_at' => now()->addMinutes($minutes),
            'last_error' => $error,
        ]);

        $this->dispatchJob($message);
    }

    protected function notifyFailure(MailboxOutgoingMessage $message): void
    {
        try {
            if ($user = $message->user()->first()) {
                app(OutgoingMessageFailedNotification::class)->send($user, $message);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Mark a forwarded original once the forward is really sent.
     */
    protected function afterSent(MailboxOutgoingMessage $message): void
    {
        $original = $message->forwardOf()->with('mailbox')->first();

        if (! $original) {
            return;
        }

        try {
            $this->messages->markForwarded($original);
        } catch (Throwable $exception) {
            // The message is sent; the marker is not worth failing for.
            report($exception);
        }

        MessageForwarded::dispatch($original, $message->recipients(), $message->forward_as_attachment);
    }

    protected function dispatchJob(MailboxOutgoingMessage $message): void
    {
        $job = SendOutgoingMessageJob::dispatch($message->getKey());

        if ($message->send_at->isFuture()) {
            $job->delay($message->send_at);
        }
    }

    protected function deleteAttachments(MailboxOutgoingMessage $message): void
    {
        $message->attachments()->get()->each->delete();

        rescue(fn () => Storage::disk((string) config('filament-mailbox.attachments.disk'))->deleteDirectory($message->directory()), report: false);
    }

    protected function purge(MailboxOutgoingMessage $message): void
    {
        $this->deleteAttachments($message);
        $message->delete();
    }
}
