<?php

namespace Cooolinho\FilamentMailbox\Providers\Imap;

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Support\Str;

/**
 * ImapEngine connection with the commands ImapEngine does not expose.
 */
class ImapClientConnection extends ImapConnection
{
    /**
     * Subscribed folders (LSUB).
     */
    public function lsub(string $reference = '', string $folder = '*'): ResponseCollection
    {
        $this->send('LSUB', Str::literal([$reference, $folder]), $tag);

        $this->assertTaggedResponse($tag);

        return $this->result->responses()->untagged()->filter(
            fn (UntaggedResponse $response) => $response->type()->is('LSUB')
        );
    }
}
