<?php

namespace Cooolinho\FilamentMailbox\Data;

/**
 * A multipart/report message (RFC 6522): the text body of the message is the
 * human-readable part, followed by the machine-readable report.
 */
final readonly class ReportData
{
    /**
     * @param  string  $type  Report type, e.g. "disposition-notification"
     * @param  string  $content  The machine-readable part (message/{type})
     */
    public function __construct(
        public string $type,
        public string $content,
    ) {}
}
