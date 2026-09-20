<?php

namespace Cooolinho\FilamentMailbox\Tests\Fixtures;

use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;

/**
 * In-memory SMTP server for transport tests: records commands and answers them.
 */
class FakeSmtpStream extends AbstractStream
{
    /** @var array<int, string> */
    public array $commands = [];

    /** @var array<int, string> */
    protected array $responses = [];

    protected bool $inData = false;

    /**
     * @param  array<int, string>  $extensions  EHLO keywords, e.g. ['DSN']
     */
    public function __construct(
        public array $extensions = ['DSN'],
    ) {}

    public function initialize(): void
    {
        $this->commands = [];
        $this->responses = ["220 fake ESMTP\r\n"];
    }

    public function write(string $bytes, bool $debug = true): void
    {
        if ($this->inData) {
            if (str_ends_with($bytes, "\r\n.\r\n")) {
                $this->inData = false;
                $this->responses[] = "250 2.0.0 Ok: queued as FAKE1\r\n";
            }

            return;
        }

        $this->commands[] = rtrim($bytes, "\r\n");
        $verb = strtoupper(substr($bytes, 0, 4));

        match ($verb) {
            'EHLO' => array_push($this->responses, ...array_map(
                fn (string $line, int $index, int $count): string => '250'.($index < $count - 1 ? '-' : ' ').$line."\r\n",
                $lines = ['fake', ...$this->extensions],
                array_keys($lines),
                array_fill(0, count($lines), count($lines)),
            )),
            'DATA' => (function (): void {
                $this->inData = true;
                $this->responses[] = "354 End data with <CR><LF>.<CR><LF>\r\n";
            })(),
            'QUIT' => $this->responses[] = "221 Bye\r\n",
            default => $this->responses[] = "250 Ok\r\n",
        };
    }

    public function flush(): void {}

    // Called by EsmtpTransport like on a SocketStream.
    public function isTLS(): bool
    {
        return false;
    }

    public function disableTls(): static
    {
        return $this;
    }

    public function setHost(string $host): static
    {
        return $this;
    }

    public function setPort(int $port): static
    {
        return $this;
    }

    public function readLine(): string
    {
        return array_shift($this->responses) ?? '';
    }

    public function hasPendingData(): bool
    {
        return false;
    }

    public function terminate(): void {}

    protected function getReadConnectionDescription(): string
    {
        return 'fake';
    }
}
