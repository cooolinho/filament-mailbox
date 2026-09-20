<?php

namespace Cooolinho\FilamentMailbox\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What started a synchronisation run.
 */
enum SyncTrigger: string implements HasLabel
{
    /** Jobs queued by "mailbox:sync", usually from the scheduler. */
    case Schedule = 'schedule';

    /** "mailbox:sync --now". */
    case Command = 'command';

    /** The "Synchronise" action in the panel. */
    case Manual = 'manual';

    /** Microsoft Graph change notification or Gmail push. */
    case Webhook = 'webhook';

    /** After a folder was renamed or moved. */
    case FolderChange = 'folder_change';

    /** A later attempt of a failed job. */
    case Retry = 'retry';

    public function getLabel(): string
    {
        return __('filament-mailbox::mailbox.monitoring.triggers.'.$this->value);
    }
}
