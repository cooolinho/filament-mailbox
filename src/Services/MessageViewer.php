<?php

namespace Cooolinho\FilamentMailbox\Services;

use Cooolinho\FilamentMailbox\Enums\ProviderCapability;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Support\CredentialRedactor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Opens messages for reading (message page and preview pane): always resolved
 * through the mailbox and authorised with the message policy.
 */
class MessageViewer
{
    public function __construct(
        protected MessageService $messages,
    ) {}

    /**
     * The message of this mailbox if the user may view it, otherwise null.
     */
    public function find(Mailbox $mailbox, int|string|null $messageId, ?Authenticatable $user = null): ?MailboxMessage
    {
        if (! is_numeric($messageId)) {
            return null;
        }

        $user ??= auth()->user();
        $message = $mailbox->messages()->with(['folder', 'mailbox'])->find((int) $messageId);

        return $message && $user && Gate::forUser($user)->allows('view', $message) ? $message : null;
    }

    /**
     * Like find(), and marks the message as read.
     */
    public function open(Mailbox $mailbox, int|string|null $messageId, ?Authenticatable $user = null): ?MailboxMessage
    {
        $message = $this->find($mailbox, $messageId, $user);

        if ($message) {
            $this->markRead($message);
        }

        return $message;
    }

    public function markRead(MailboxMessage $message): void
    {
        if ($message->is_read || ! $message->mailbox->supports(ProviderCapability::Flags)) {
            return;
        }

        try {
            $this->messages->markRead($message);
        } catch (Throwable $exception) {
            // Reading the local copy must not depend on the remote server.
            Log::channel(config('filament-mailbox.sync.log_channel'))->warning('Could not mark message as read.', [
                'mailbox_id' => $message->mailbox_id,
                'message_id' => $message->getKey(),
                'error' => CredentialRedactor::redact($exception->getMessage(), $message->mailbox),
            ]);
        }
    }
}
