<?php

namespace Cooolinho\FilamentMailbox\Mail\Parts;

use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Part\AbstractMultipartPart;
use Symfony\Component\Mime\Part\AbstractPart;

/**
 * multipart/report; report-type=… (RFC 6522).
 */
class ReportPart extends AbstractMultipartPart
{
    public function __construct(
        protected string $reportType,
        AbstractPart ...$parts,
    ) {
        parent::__construct(...$parts);
    }

    public function getMediaSubtype(): string
    {
        return 'report';
    }

    public function getPreparedHeaders(): Headers
    {
        $headers = parent::getPreparedHeaders();
        $headers->setHeaderParameter('Content-Type', 'report-type', $this->reportType);

        return $headers;
    }
}
