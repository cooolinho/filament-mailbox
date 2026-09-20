<?php

namespace Cooolinho\FilamentMailbox\Jobs;

use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Search\Extraction\AttachmentTextExtractor;
use Cooolinho\FilamentMailbox\Search\SearchManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ExtractAttachmentTextJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $attachmentId,
    ) {
        $this->onConnection(config('filament-mailbox.search.queue_connection') ?? config('filament-mailbox.sync.queue_connection'));
        $this->onQueue(config('filament-mailbox.search.queue') ?? config('filament-mailbox.sync.queue'));
    }

    public function handle(AttachmentTextExtractor $extractor, SearchManager $search): void
    {
        $attachment = MailboxAttachment::query()->with('message')->find($this->attachmentId);

        if (! $attachment || ! $extractor->extract($attachment) || ! $attachment->message) {
            return;
        }

        // The text becomes part of the search document.
        if ($search->indexes()) {
            $search->engine()->index($attachment->message);
        }
    }
}
