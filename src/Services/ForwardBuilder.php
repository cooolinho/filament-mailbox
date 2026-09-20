<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Data\AttachmentData;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\ComposeMessageForm;
use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\MessageInfolist;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Support\HtmlToText;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Prepares the compose form state and attachments for forwarding a message.
 */
class ForwardBuilder
{
    public const MODE_INLINE = 'inline';

    public const MODE_ATTACHMENT = 'attachment';

    public function __construct(
        protected AttachmentService $attachments,
        protected MessageService $messages,
    ) {}

    /**
     * @return array{as_attachment: bool, to: array<int, string>, cc: array<int, string>, bcc: array<int, string>, subject: string, format: string, body: string, body_html: ?string, quoted: string, quoted_html: ?string, original_attachments: array<int, int>}
     */
    public function formData(MailboxMessage $message, ?string $format = null): array
    {
        $asAttachment = config('filament-mailbox.forward.default_mode') === self::MODE_ATTACHMENT;
        $format ??= ComposeMessageForm::defaultFormat($message->mailbox);

        return [
            'as_attachment' => $asAttachment,
            'to' => [],
            'cc' => [],
            'bcc' => [],
            'subject' => $this->subject($message->subject),
            'format' => $format,
            'body' => '',
            'body_html' => null,
            ...$this->quoted($message, $format, $asAttachment),
            'original_attachments' => $message->attachments->modelKeys(),
        ];
    }

    /**
     * The quoted original for the form: empty when forwarding as attachment.
     *
     * @return array{quoted: string, quoted_html: ?string}
     */
    public function quoted(MailboxMessage $message, ?string $format, bool $asAttachment): array
    {
        $html = ComposeMessageForm::isHtmlFormat($format);

        return [
            'quoted' => $asAttachment || $html ? '' : $this->inlineBody($message),
            'quoted_html' => $asAttachment || ! $html ? null : $this->inlineBodyHtml($message),
        ];
    }

    public function subject(?string $subject): string
    {
        $subject = trim((string) $subject);

        // Do not stack forward prefixes ("Fwd: Fwd: …"), including common localized ones.
        if (preg_match('/^(fwd?|wg|wtr|tr)\s*:/iu', $subject)) {
            return $subject;
        }

        return trim(config('filament-mailbox.forward.subject_prefix', 'Fwd: ').$subject);
    }

    public function inlineBody(MailboxMessage $message): string
    {
        $lines = [
            __('filament-mailbox::mailbox.forward.header'),
            ...$this->headerLines($message),
            '',
            rtrim(HtmlToText::body($message->text_body, $message->html_body)),
        ];

        return implode("\n", $lines);
    }

    /**
     * Header block and the sanitised original HTML (without images).
     */
    public function inlineBodyHtml(MailboxMessage $message): string
    {
        $header = implode('<br>', array_map(e(...), [__('filament-mailbox::mailbox.forward.header'), ...$this->headerLines($message)]));

        return '<p>'.$header.'</p>'.ReplyBuilder::originalHtml($message);
    }

    /**
     * @return array<int, string>
     */
    protected function headerLines(MailboxMessage $message): array
    {
        $fields = array_filter([
            'from' => MessageInfolist::formatAddress(['address' => $message->from_address, 'name' => $message->from_name]),
            'date' => ($message->sent_at ?? $message->received_at)?->toDayDateTimeString(),
            'subject' => $message->subject,
            'to' => implode(', ', MessageInfolist::formatAddresses($message->to)),
            'cc' => implode(', ', MessageInfolist::formatAddresses($message->cc)),
        ], filled(...));

        return array_map(
            fn (string $field, string $value): string => __("filament-mailbox::mailbox.messages.fields.{$field}").': '.$value,
            array_keys($fields),
            $fields,
        );
    }

    /**
     * Original attachments selected in the form. Ids that do not belong to the
     * message are ignored.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, AttachmentData>
     */
    public function originalAttachments(MailboxMessage $message, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, MailboxAttachment> $selected */
        $selected = $message->attachments()->whereKey($ids)->get();

        return $selected
            ->map(fn (MailboxAttachment $attachment): AttachmentData => new AttachmentData(
                filename: $attachment->filename,
                mimeType: $attachment->mime_type ?: 'application/octet-stream',
                contents: $this->attachments->contents($attachment),
            ))
            ->values()
            ->all();
    }

    /**
     * The whole message as "message/rfc822" attachment. The original source is
     * used when the provider delivers it; otherwise the message is rebuilt
     * from the local copy.
     */
    public function messageAttachment(MailboxMessage $message): AttachmentData
    {
        try {
            $raw = $this->messages->rawMessage($message);
        } catch (Throwable) {
            $raw = null;
        }

        $name = Str::slug((string) $message->subject) ?: 'message';

        return new AttachmentData(
            filename: AttachmentService::sanitizeFilename(Str::limit($name, 200, '').'.eml'),
            mimeType: 'message/rfc822',
            contents: $raw ?? $this->reconstruct($message),
        );
    }

    /**
     * Rebuild a MIME message from the locally stored data.
     */
    public function reconstruct(MailboxMessage $message): string
    {
        $email = (new Email)->subject((string) $message->subject);

        if (filled($message->from_address)) {
            $email->from(new Address($message->from_address, (string) $message->from_name));
        }

        foreach (['to' => 'addTo', 'cc' => 'addCc', 'reply_to' => 'addReplyTo'] as $attribute => $method) {
            foreach ($message->{$attribute} ?? [] as $address) {
                rescue(fn () => $email->{$method}(new Address($address['address'], (string) ($address['name'] ?? ''))), report: false);
            }
        }

        if ($date = $message->sent_at ?? $message->received_at) {
            $email->date($date->toDateTimeImmutable());
        }

        if (filled($message->message_id)) {
            $email->getHeaders()->addIdHeader('Message-ID', trim($message->message_id, '<>'));
        }

        $email->text(HtmlToText::body($message->text_body, $message->html_body));

        if (filled($message->html_body)) {
            $email->html($message->html_body);
        }

        foreach ($message->attachments as $attachment) {
            $email->attach(
                $this->attachments->contents($attachment),
                AttachmentService::sanitizeFilename($attachment->filename),
                $attachment->mime_type ?: 'application/octet-stream',
            );
        }

        return $email->toString();
    }

    /**
     * @param  array<int, AttachmentData>  $attachments
     */
    public function exceedsSizeLimit(array $attachments): bool
    {
        $limit = (int) config('filament-mailbox.mail.max_attachment_size', 10240) * 1024;

        return array_sum(array_map(fn (AttachmentData $attachment): int => $attachment->size(), $attachments)) > $limit;
    }
}
