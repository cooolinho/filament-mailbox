<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Illuminate\Foundation\Events\Dispatchable;

class FolderSynced
{
    use Dispatchable;

    public function __construct(
        public MailboxFolder $folder,
        public int $imported,
    ) {}
}
