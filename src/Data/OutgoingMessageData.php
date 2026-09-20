<?php

namespace Cooolinho\FilamentMailbox\Data;

final readonly class OutgoingMessageData
{
    /**
     * @param  array<int, string>  $to
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     * @param  array<int, AttachmentData>  $attachments
     * @param  array<int, string>  $references  Message-IDs without angle brackets
     * @param  ?string  $providerThreadId  Thread of the provider to reply in (Gmail threadId)
     * @param  ?string  $forwardedMessageId  Message-ID of a forwarded message, without angle brackets
     * @param  string  $body  Plain text body (the text alternative of an HTML message)
     * @param  ?string  $bodyHtml  Sanitised HTML body; inline images are attachments with "inline" and a content ID
     * @param  ?string  $messageId  Message-ID without angle brackets (default: generated)
     * @param  array<string, string>  $headers  Additional text headers, e.g. for drafts
     * @param  ?ReportData  $report  Sends a multipart/report (read receipts) instead of text/HTML
     * @param  bool  $requestDeliveryReceipt  Ask for a success report too (failures and delays are always reported)
     * @param  ?DsnOptions  $dsn  DSN parameters for SMTP transports, set by the outbox
     */
    public function __construct(
        public array $to,
        public string $subject,
        public string $body,
        public array $cc = [],
        public array $bcc = [],
        public array $attachments = [],
        public ?string $inReplyTo = null,
        public array $references = [],
        public ?string $providerThreadId = null,
        public ?string $forwardedMessageId = null,
        public ?string $bodyHtml = null,
        public ?string $messageId = null,
        public array $headers = [],
        public ?ReportData $report = null,
        public bool $requestDeliveryReceipt = false,
        public ?DsnOptions $dsn = null,
    ) {}

    public function isHtml(): bool
    {
        return filled($this->bodyHtml);
    }

    /**
     * Message-IDs for the References header. A forward references the
     * original message without being a reply to it.
     *
     * @return array<int, string>
     */
    public function referencedMessageIds(): array
    {
        return array_values(array_unique(array_filter([...$this->references, $this->forwardedMessageId])));
    }
}
