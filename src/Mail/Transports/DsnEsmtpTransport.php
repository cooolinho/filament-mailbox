<?php

namespace Cooolinho\FilamentMailbox\Mail\Transports;

use Cooolinho\FilamentMailbox\Data\DsnOptions;
use Cooolinho\FilamentMailbox\Support\Xtext;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

/**
 * ESMTP with DSN parameters (RFC 3461): "MAIL FROM … RET=HDRS ENVID=…" and
 * "RCPT TO … NOTIFY=… ORCPT=rfc822;…", when the server offers the DSN
 * extension. Symfony builds these commands in private methods, so they are
 * extended in executeCommand().
 */
class DsnEsmtpTransport extends EsmtpTransport
{
    protected ?DsnOptions $dsn = null;

    protected ?bool $lastSupported = null;

    /**
     * Laravel mailer configuration like the "smtp" transport.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): static
    {
        $port = (int) ($config['port'] ?? 587);
        $scheme = $config['scheme'] ?? ($port === 465 ? 'smtps' : 'smtp');

        $transport = new static((string) ($config['host'] ?? 'localhost'), $port, $scheme === 'smtps' ? true : null);
        $transport->setUsername((string) ($config['username'] ?? ''));
        $transport->setPassword((string) ($config['password'] ?? ''));

        if (isset($config['local_domain'])) {
            $transport->setLocalDomain((string) $config['local_domain']);
        }

        return $transport;
    }

    public function setDsn(?DsnOptions $dsn): static
    {
        $this->dsn = $dsn;
        $this->lastSupported = null;

        return $this;
    }

    public function dsnSupported(): ?bool
    {
        return $this->lastSupported;
    }

    public function executeCommand(string $command, array $codes): string
    {
        if ($this->dsn !== null && (str_starts_with($command, 'MAIL FROM:<') || str_starts_with($command, 'RCPT TO:<'))) {
            $this->lastSupported = $this->serverSupportsDsn();

            if ($this->lastSupported) {
                $command = $this->withDsnParameters($command);
            }
        }

        return parent::executeCommand($command, $codes);
    }

    protected function withDsnParameters(string $command): string
    {
        $line = substr($command, 0, -2);

        if (str_starts_with($line, 'MAIL FROM:<')) {
            return $line.' RET=HDRS ENVID='.Xtext::encode($this->dsn->envelopeId)."\r\n";
        }

        $address = (string) preg_replace('/^RCPT TO:<([^>]*)>.*$/', '$1', $line);
        $notify = preg_match('/^(NEVER|(SUCCESS|FAILURE|DELAY)(,(SUCCESS|FAILURE|DELAY))*)$/', $this->dsn->notify) ? $this->dsn->notify : DsnOptions::NOTIFY_PROBLEMS;

        return $line.' NOTIFY='.$notify.' ORCPT=rfc822;'.Xtext::encode($address)."\r\n";
    }

    protected function serverSupportsDsn(): bool
    {
        try {
            return array_key_exists('DSN', $this->getCapabilities());
        } catch (Throwable) {
            // Capabilities are only known after EHLO.
            return false;
        }
    }
}
