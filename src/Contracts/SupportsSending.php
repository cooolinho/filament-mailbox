<?php

namespace Cooolinho\FilamentMailbox\Contracts;

use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;

/**
 * Providers that deliver mail through their own API (ProviderCapability::ServerSideSend).
 * The provider stores the message in its sent folder itself.
 */
interface SupportsSending
{
    /**
     * @param  string  $rawMime  Complete MIME message including Bcc
     */
    public function send(string $rawMime, OutgoingMessageData $data): void;
}
