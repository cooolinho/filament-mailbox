<?php

namespace Cooolinho\FilamentMailbox\Contracts;

use Cooolinho\FilamentMailbox\Data\OutgoingMessageData;
use Cooolinho\FilamentMailbox\Models\Mailbox;

/**
 * Delivers an outgoing message on behalf of a mailbox.
 */
interface OutgoingTransport
{
    public function send(Mailbox $mailbox, OutgoingMessageData $data): void;
}
