<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Cooolinho\FilamentMailbox\Jobs\ExtractAttachmentTextJob;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Search\Extraction\AttachmentTextExtractor;
use Illuminate\Console\Command;

class SearchExtractAttachmentsCommand extends Command
{
    protected $signature = 'mailbox:search-extract-attachments
        {--mailbox=* : IDs of the mailboxes (default: all)}
        {--force : Extract attachments again that were already processed}
        {--now : Extract in this process instead of dispatching queue jobs}';

    protected $description = 'Extract the text of attachments (PDF, DOCX, ODT, text) for the search';

    public function handle(AttachmentTextExtractor $extractor): int
    {
        if (! AttachmentTextExtractor::enabled()) {
            $this->components->info('Attachment text extraction is disabled (search.attachments.extract_text).');

            return self::SUCCESS;
        }

        $count = 0;

        MailboxAttachment::query()
            ->with('message')
            ->when(! $this->option('force'), fn ($query) => $query->whereNull('extracted_at'))
            ->when($this->option('mailbox') !== [], fn ($query) => $query->whereHas('message', fn ($messages) => $messages->whereIn('mailbox_id', $this->option('mailbox'))))
            ->chunkById(200, function ($attachments) use ($extractor, &$count): void {
                foreach ($attachments->filter(fn (MailboxAttachment $attachment): bool => $extractor->supports($attachment)) as $attachment) {
                    $job = new ExtractAttachmentTextJob((int) $attachment->getKey());
                    $this->option('now') ? app()->call([$job, 'handle']) : dispatch($job);
                    $count++;
                }
            });

        $this->components->info(($this->option('now') ? 'Processed' : 'Queued')." {$count} attachments.");

        return self::SUCCESS;
    }
}
