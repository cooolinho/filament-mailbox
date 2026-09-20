<?php

namespace Cooolinho\FilamentMailbox\Mail\Parts;

use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Part\AbstractPart;

/**
 * The machine-readable part of a report, e.g. message/disposition-notification.
 * Its content is plain ASCII field lines.
 */
class MessageReportContentPart extends AbstractPart
{
    public function __construct(
        protected string $subtype,
        protected string $content,
    ) {
        parent::__construct();
    }

    public function bodyToString(): string
    {
        return $this->content;
    }

    public function bodyToIterable(): iterable
    {
        yield $this->content;
    }

    public function getMediaType(): string
    {
        return 'message';
    }

    public function getMediaSubtype(): string
    {
        return $this->subtype;
    }

    public function getPreparedHeaders(): Headers
    {
        $headers = parent::getPreparedHeaders();
        $headers->addTextHeader('Content-Transfer-Encoding', '7bit');

        return $headers;
    }
}
