<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Illuminate\Foundation\Events\Dispatchable;

class FolderSyncFailed
{
    use Dispatchable;

    public function __construct(
        public MailboxFolder $folder,
        public string $error,
    ) {}
}
