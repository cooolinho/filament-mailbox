<?php

namespace Cooolinho\FilamentMailbox\Contracts;

/**
 * A transport that can request delivery status notifications.
 */
interface ReportsDsnSupport
{
    /**
     * Whether the server accepted DSN parameters for the last message
     * (null: no DSN was requested or the transport could not tell).
     */
    public function dsnSupported(): ?bool;
}
