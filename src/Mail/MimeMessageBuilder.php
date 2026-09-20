<?php

namespace Cooolinho\FilamentMailbox\Mail;

use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Symfony\Component\Mime\Address;

/**
 * Renders the OutgoingMessage mailable to MIME, exactly like the SMTP path
 * (threading headers, attachments, text and HTML part).
 */
class MimeMessageBuilder
{
    public function __construct(
        protected Factory $views,
        protected Dispatcher $events,
    ) {}

    public function build(Mailbox $mailbox, OutgoingMessageData $data): string
    {
        $transport = new ArrayTransport;

        (new Mailer('filament-mailbox-mime', $this->views, $transport))
            ->send(new OutgoingMessage($mailbox, $data));

        $sent = $transport->messages()->sole();
        $raw = $sent->toString();

        // Rendered messages drop the Bcc header; API providers read the recipients from the MIME headers.
        $bcc = $sent->getOriginalMessage()->getBcc();

        if ($bcc === []) {
            return $raw;
        }

        return 'Bcc: '.implode(', ', array_map(fn (Address $address): string => $address->toString(), $bcc))."\r\n".$raw;
    }
}
