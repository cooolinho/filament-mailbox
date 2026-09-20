<?php

namespace Cooolinho\FilamentMailbox\Data;

/**
 * Delivery Status Notification parameters for the SMTP envelope (RFC 3461).
 */
final readonly class DsnOptions
{
    public const NOTIFY_ALL = 'SUCCESS,FAILURE,DELAY';

    public const NOTIFY_PROBLEMS = 'FAILURE,DELAY';

    /**
     * @param  string  $envelopeId  ENVID, returned as Original-Envelope-Id in reports (the outbox UUID)
     * @param  string  $notify  NOTIFY value
     */
    public function __construct(
        public string $envelopeId,
        public string $notify = self::NOTIFY_PROBLEMS,
    ) {}
}
