<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Filament\Resources\Mailboxes\Schemas\ComposeMessageForm;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Support\HtmlToText;

/**
 * Prepares the compose form state for replies.
 */
class ReplyBuilder
{
    /**
     * @return array{to: array<int, string>, cc: array<int, string>, subject: string, format: string, body: string, body_html: ?string, quoted: string, quoted_html: ?string}
     */
    public function formData(MailboxMessage $message, bool $all = false, ?string $format = null): array
    {
        $to = $this->replyRecipients($message);
        $format ??= ComposeMessageForm::defaultFormat($message->mailbox);
        $html = ComposeMessageForm::isHtmlFormat($format);

        return [
            'to' => $to,
            'cc' => $all ? $this->replyAllCc($message, $to) : [],
            'subject' => $this->subject($message->subject),
            'format' => $format,
            'body' => '',
            // Empty rich editor states are null, the editor cannot parse an empty string.
            'body_html' => null,
            'quoted' => $html ? '' : $this->quote($message),
            'quoted_html' => $html ? $this->quoteHtml($message) : null,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function replyRecipients(MailboxMessage $message): array
    {
        $replyTo = array_column($message->reply_to ?? [], 'address');

        return $this->unique($replyTo !== [] ? $replyTo : array_filter([$message->from_address]));
    }

    /**
     * @param  array<int, string>  $to
     * @return array<int, string>
     */
    public function replyAllCc(MailboxMessage $message, array $to): array
    {
        $excluded = array_map('mb_strtolower', [...$to, $message->mailbox->email, $message->mailbox->username]);

        $candidates = [
            ...array_column($message->to ?? [], 'address'),
            ...array_column($message->cc ?? [], 'address'),
        ];

        return $this->unique(array_filter(
            $candidates,
            fn (string $address): bool => ! in_array(mb_strtolower($address), $excluded, true),
        ));
    }

    public function subject(?string $subject): string
    {
        $subject = trim((string) $subject);

        // Do not stack reply prefixes ("Re: Re: …"), including common localized ones.
        if (preg_match('/^(re|aw|antw|sv|vs|wg)\s*:/iu', $subject)) {
            return $subject;
        }

        return trim('Re: '.$subject);
    }

    public function quote(MailboxMessage $message): string
    {
        $original = HtmlToText::body($message->text_body, $message->html_body);

        $lines = preg_split('/\R/u', rtrim($original)) ?: [];

        return $this->quoteHeader($message)."\n".implode("\n", array_map(fn (string $line): string => '> '.$line, $lines));
    }

    /**
     * The original as <blockquote>: sanitised HTML without images (inline
     * parts of the original are not sent along), or the text as paragraphs.
     */
    public function quoteHtml(MailboxMessage $message): string
    {
        return '<p>'.e($this->quoteHeader($message)).'</p><blockquote type="cite">'.static::originalHtml($message).'</blockquote>';
    }

    /**
     * Sanitised HTML of an original message for quoting and forwarding.
     */
    public static function originalHtml(MailboxMessage $message): string
    {
        if (blank($message->html_body)) {
            return ComposeMessageForm::textToHtml($message->text_body);
        }

        $html = app(HtmlBodySanitizer::class)->outgoing($message->html_body);

        // No allowed directories: every image is removed.
        return app(InlineImageProcessor::class)->process($html, [])->html;
    }

    protected function quoteHeader(MailboxMessage $message): string
    {
        return __('filament-mailbox::mailbox.reply.quote_header', [
            'date' => $message->sent_at?->toDayDateTimeString() ?? $message->received_at?->toDayDateTimeString() ?? '',
            'sender' => $message->fromLabel(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function references(MailboxMessage $message): array
    {
        $references = preg_split('/\s+/', trim((string) $message->references)) ?: [];

        return array_values(array_unique(array_filter([
            ...array_map(fn (string $id): string => trim($id, '<>'), $references),
            $message->message_id ? trim($message->message_id, '<>') : null,
        ])));
    }

    /**
     * @param  array<int, string>  $addresses
     * @return array<int, string>
     */
    protected function unique(array $addresses): array
    {
        $unique = [];

        foreach ($addresses as $address) {
            $unique[mb_strtolower($address)] ??= $address;
        }

        return array_values($unique);
    }
}
