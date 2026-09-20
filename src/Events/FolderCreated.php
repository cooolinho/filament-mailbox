<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Illuminate\Foundation\Events\Dispatchable;

class FolderCreated
{
    use Dispatchable;

    public function __construct(
        public MailboxFolder $folder,
    ) {}
}
