<?php

namespace Cooolinho\FilamentMailbox\Http\Controllers;

use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Services\AttachmentService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadAttachmentController
{
    public function __invoke(int $attachment, AttachmentService $attachments): StreamedResponse
    {
        // The panel's auth middleware already requires a login; never rely on it alone.
        abort_unless(Filament::auth()->check(), 403);

        $attachment = MailboxAttachment::with('message.mailbox')->findOrFail($attachment);

        // Respond with 404 for foreign attachments to not disclose their existence.
        abort_unless($attachment->message && Gate::forUser(Filament::auth()->user())->allows('view', $attachment->message), 404);

        return $attachments->download($attachment);
    }
}
