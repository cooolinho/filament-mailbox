<?php

namespace Cooolinho\FilamentMailbox\Mail;

use Cooolinho\FilamentMailbox\Data\AttachmentData;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Services\AttachmentService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Symfony\Component\Mime\Email;
use Cooolinho\FilamentMailbox\Mail\Parts\MessageReportContentPart;
use Cooolinho\FilamentMailbox\Mail\Parts\ReportPart;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\TextPart;

class OutgoingMessage extends Mailable
{
    public function __construct(
        public Mailbox $mailbox,
        public OutgoingMessageData $data,
    ) {}

    /**
     * Inline images become "related" parts with their content ID.
     */
    public function build(): void
    {
        if ($report = $this->data->report) {
            $this->withSymfonyMessage(function (Email $message) use ($report): void {
                $message->setBody(new ReportPart(
                    $report->type,
                    new TextPart($this->data->body),
                    new MessageReportContentPart($report->type, $report->content),
                ));
            });

            return;
        }

        $inline = $this->inlineAttachments();

        if ($inline === []) {
            return;
        }

        $this->withSymfonyMessage(function (Email $message) use ($inline): void {
            foreach ($inline as $attachment) {
                $message->addPart(
                    (new DataPart($attachment->contents, AttachmentService::sanitizeFilename($attachment->filename), $attachment->mimeType))
                        ->asInline()
                        ->setContentId((string) $attachment->contentId),
                );
            }
        });
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->mailbox->email, $this->mailbox->name),
            to: $this->data->to,
            cc: $this->data->cc,
            bcc: $this->data->bcc,
            subject: $this->data->subject,
        );
    }

    public function content(): Content
    {
        if ($this->data->isHtml()) {
            return new Content(
                text: 'filament-mailbox::mail.message-text',
                htmlString: app(HtmlMailRenderer::class)->render((string) $this->data->bodyHtml),
                with: ['body' => $this->data->body],
            );
        }

        return new Content(
            view: 'filament-mailbox::mail.message',
            text: 'filament-mailbox::mail.message-text',
            with: ['body' => $this->data->body],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->data->messageId,
            references: $this->data->referencedMessageIds(),
            text: array_filter([
                'In-Reply-To' => $this->data->inReplyTo ? '<'.$this->data->inReplyTo.'>' : null,
                ...$this->data->headers,
            ]),
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (AttachmentData $attachment): Attachment => Attachment::fromData(
                fn (): string => $attachment->contents,
                AttachmentService::sanitizeFilename($attachment->filename),
            )->withMime($attachment->mimeType),
            array_values(array_filter($this->data->attachments, fn (AttachmentData $attachment): bool => ! $this->isInline($attachment))),
        );
    }

    /**
     * @return array<int, AttachmentData>
     */
    public function inlineAttachments(): array
    {
        return array_values(array_filter($this->data->attachments, $this->isInline(...)));
    }

    protected function isInline(AttachmentData $attachment): bool
    {
        return $attachment->inline && filled($attachment->contentId) && $this->data->isHtml();
    }
}
