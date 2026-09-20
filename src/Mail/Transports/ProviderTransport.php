<?php

namespace Cooolinho\FilamentMailbox\Mail\Transports;

use Cooolinho\FilamentMailbox\Contracts\MailboxProviderFactory;
use Cooolinho\FilamentMailbox\Contracts\OutgoingTransport;
use Cooolinho\FilamentMailbox\Contracts\SupportsSending;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Exceptions\UnsupportedOperation;
use Cooolinho\FilamentMailbox\Mail\MimeMessageBuilder;
use Cooolinho\FilamentMailbox\Models\Mailbox;

/**
 * Sends through the provider API, e.g. Microsoft Graph sendMail.
 */
class ProviderTransport implements OutgoingTransport
{
    public function __construct(
        protected MailboxProviderFactory $providers,
        protected MimeMessageBuilder $mime,
    ) {}

    public function send(Mailbox $mailbox, OutgoingMessageData $data): void
    {
        $provider = $this->providers->make($mailbox);

        try {
            if (! $provider instanceof SupportsSending || ! $provider->supports(ProviderCapability::ServerSideSend)) {
                throw UnsupportedOperation::for('send', ProviderCapability::ServerSideSend);
            }

            $provider->send($this->mime->build($mailbox, $data), $data);
        } finally {
            $provider->disconnect();
        }
    }
}
