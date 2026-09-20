<?php

namespace Cooolinho\FilamentMailbox\Mail\Transports;

use Cooolinho\FilamentMailbox\Contracts\OutgoingTransport;
use Cooolinho\FilamentMailbox\Contracts\ReportsDsnSupport;
use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Mail\OutgoingMessage;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\OAuth\OAuthProviders;
use Cooolinho\FilamentMailbox\OAuth\OAuthTokenManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory;
use Illuminate\Mail\Mailer;
use Symfony\Component\Mailer\Transport\TransportInterface;
use InvalidArgumentException;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;

/**
 * SMTP with the mailbox's own identity: XOAUTH2 for OAuth mailboxes,
 * username/password for password mailboxes with an SMTP host.
 */
class SmtpMailboxTransport implements OutgoingTransport, ReportsDsnSupport
{
    protected ?bool $dsnSupported = null;

    public function __construct(
        protected OAuthTokenManager $tokens,
        protected OAuthProviders $providers,
        protected Factory $views,
        protected Dispatcher $events,
    ) {}

    public function send(Mailbox $mailbox, OutgoingMessageData $data): void
    {
        $transport = $this->transport($mailbox);
        $this->dsnSupported = null;

        if ($transport instanceof DsnEsmtpTransport) {
            $transport->setDsn($data->dsn);
        }

        (new Mailer('filament-mailbox', $this->views, $transport, $this->events))
            ->send(new OutgoingMessage($mailbox, $data));

        $this->dsnSupported = $transport instanceof DsnEsmtpTransport ? $transport->dsnSupported() : null;
    }

    public function dsnSupported(): ?bool
    {
        return $this->dsnSupported;
    }

    public function transport(Mailbox $mailbox): TransportInterface
    {
        [$host, $port] = $this->server($mailbox);

        // Port 465 uses implicit TLS, other ports upgrade with STARTTLS when offered.
        $transport = new DsnEsmtpTransport($host, $port, $port === 465 ? true : null);

        if ($mailbox->usesOAuth()) {
            $transport->setUsername($mailbox->username ?: $mailbox->email);
            $transport->setPassword($this->tokens->accessToken($mailbox->oauthConnection));
            $transport->setAuthenticators([new XOAuth2Authenticator]);
        } else {
            $transport->setUsername((string) $mailbox->username);
            $transport->setPassword((string) $mailbox->password);
        }

        return $transport;
    }

    /**
     * @return array{string, int}
     */
    protected function server(Mailbox $mailbox): array
    {
        $defaults = $mailbox->usesOAuth() && $mailbox->oauthConnection
            ? $this->providers->servers($mailbox->oauthConnection->application->provider)['smtp']
            : [];

        $host = $mailbox->smtp_host ?: ($defaults['host'] ?? null);

        if (! $host) {
            throw new InvalidArgumentException('No SMTP host configured for the mailbox.');
        }

        return [$host, (int) ($mailbox->smtp_port ?: ($defaults['port'] ?? 587))];
    }
}
