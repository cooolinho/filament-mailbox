<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Illuminate\Foundation\Events\Dispatchable;

class FolderDeleted
{
    use Dispatchable;

    public function __construct(
        public MailboxFolder $folder,
    ) {}
}
