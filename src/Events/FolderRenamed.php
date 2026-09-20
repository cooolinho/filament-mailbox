<?php

namespace Cooolinho\FilamentMailbox\Events;

use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A folder was renamed or moved (with its descendants).
 */
class FolderRenamed
{
    use Dispatchable;

    public function __construct(
        public MailboxFolder $folder,
        public string $oldFullName,
    ) {}
}
