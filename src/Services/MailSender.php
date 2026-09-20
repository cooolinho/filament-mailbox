<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Contracts\OutgoingTransport;
use Cooolinho\FilamentMailbox\Contracts\ReportsDsnSupport;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Mail\Transports\LaravelMailTransport;
use Cooolinho\FilamentMailbox\Mail\Transports\ProviderTransport;
use Cooolinho\FilamentMailbox\Mail\Transports\SmtpMailboxTransport;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Illuminate\Contracts\Container\Container;

/**
 * Sends mail on behalf of a mailbox through the transport that fits it.
 */
class MailSender
{
    public function __construct(
        protected Container $container,
    ) {}

    protected ?bool $dsnSupported = null;

    public function send(Mailbox $mailbox, OutgoingMessageData $data): void
    {
        $transport = $this->transportFor($mailbox);
        $this->dsnSupported = null;

        $transport->send($mailbox, $data);

        $this->dsnSupported = $data->dsn !== null && $transport instanceof ReportsDsnSupport ? $transport->dsnSupported() : null;
    }

    /**
     * Whether the server accepted the DSN request of the last message (null: not requested or unknown).
     */
    public function dsnSupported(): ?bool
    {
        return $this->dsnSupported;
    }

    public function transportFor(Mailbox $mailbox): OutgoingTransport
    {
        return $this->container->make(match (true) {
            $mailbox->supports(ProviderCapability::ServerSideSend) => ProviderTransport::class,
            $mailbox->usesOAuth(), filled($mailbox->smtp_host) => SmtpMailboxTransport::class,
            default => LaravelMailTransport::class,
        });
    }
}
