<?php

namespace Cooolinho\FilamentMailbox\Mail\Transports;

use Cooolinho\FilamentMailbox\Contracts\OutgoingTransport;
use Cooolinho\FilamentMailbox\Contracts\ReportsDsnSupport;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Mail\OutgoingMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Illuminate\Support\Facades\Mail;

/**
 * The application's mail configuration (config/mail.php). Mailers with the
 * "mailbox-dsn" transport request delivery status notifications.
 */
class LaravelMailTransport implements OutgoingTransport, ReportsDsnSupport
{
    protected ?bool $dsnSupported = null;

    public function send(Mailbox $mailbox, OutgoingMessageData $data): void
    {
        $mailer = Mail::mailer(config('filament-mailbox.mail.mailer'));
        $transport = method_exists($mailer, 'getSymfonyTransport') ? $mailer->getSymfonyTransport() : null;
        $this->dsnSupported = null;

        if (! $transport instanceof DsnEsmtpTransport) {
            $mailer->send(new OutgoingMessage($mailbox, $data));

            return;
        }

        try {
            $transport->setDsn($data->dsn);
            $mailer->send(new OutgoingMessage($mailbox, $data));
            $this->dsnSupported = $transport->dsnSupported();
        } finally {
            // The mailer is shared: other mail never gets DSN parameters.
            $transport->setDsn(null);
        }
    }

    public function dsnSupported(): ?bool
    {
        return $this->dsnSupported;
    }
}
