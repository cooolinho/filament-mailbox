<?php

namespace Cooolinho\FilamentMailbox\Enums;

/**
 * Optional features a mailbox provider may support.
 *
 * The UI only asks for capabilities, never for the provider type.
 */
enum ProviderCapability: string
{
    /** Hierarchical folders (POP3: no). */
    case Folders = 'folders';

    case FolderManagement = 'folder_management';

    /** IMAP SUBSCRIBE / UNSUBSCRIBE. */
    case FolderSubscriptions = 'folder_subscriptions';

    case MoveMessages = 'move_messages';

    /** Server-side \Seen state (POP3: no). */
    case Flags = 'flags';

    /** Star / \Flagged. */
    case Flagged = 'flagged';

    /** IMAP keywords / Graph categories. */
    case Keywords = 'keywords';

    /** Gmail labels (several "folders" per message). */
    case Labels = 'labels';

    /** Store messages in a folder, e.g. drafts or sent mail. */
    case AppendMessages = 'append';

    /** Send through the provider API instead of SMTP. */
    case ServerSideSend = 'send';

    /** Webhooks / IDLE. */
    case PushNotifications = 'push';

    case DeltaSync = 'delta_sync';

    /** One change feed for the whole mailbox (Gmail history). */
    case MailboxWideSync = 'mailbox_wide_sync';

    /** Messages in the trash can be deleted permanently (Gmail API: no). */
    case PermanentDelete = 'permanent_delete';
}
