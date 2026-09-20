<?php

namespace Cooolinho\FilamentMailbox\Search;

use Cooolinho\FilamentMailbox\Jobs\ExtractAttachmentTextJob;
use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Search\Extraction\AttachmentTextExtractor;
use Throwable;

/**
 * Keeps the index of engines with an own index up to date: every import, flag
 * change, move, deletion and restore. Index errors never break mail operations.
 */
class SearchIndexObserver
{
    public function __construct(
        protected SearchManager $search,
    ) {}

    public function messageSaved(MailboxMessage $message): void
    {
        if (! $this->search->indexes()) {
            return;
        }

        $this->guard(function () use ($message): void {
            $engine = $this->search->engine();

            // Flags and folder only: the database engine updates them without rebuilding the text.
            if (! $message->wasRecentlyCreated && $engine instanceof Engines\DatabaseSearchEngine && array_diff(array_keys($message->getChanges()), [...SearchDocumentWriter::STATE_COLUMNS, 'updated_at', 'keywords', 'is_answered', 'pending_move_to', 'sort_at', 'snoozed_until', 'snoozed_by', 'unsnoozed_at', 'mdn_status']) === []) {
                app(SearchDocumentWriter::class)->updateState($message);

                return;
            }

            $engine->index($message);
        });
    }

    public function messageDeleted(MailboxMessage $message): void
    {
        if ($this->search->indexes()) {
            $this->guard(fn () => $this->search->engine()->remove([(int) $message->getKey()]));
        }
    }

    public function attachmentCreated(MailboxAttachment $attachment): void
    {
        if (AttachmentTextExtractor::enabled() && app(AttachmentTextExtractor::class)->supports($attachment)) {
            ExtractAttachmentTextJob::dispatch((int) $attachment->getKey())->afterCommit();
        }

        if ($this->search->indexes() && ($message = $attachment->message)) {
            $this->guard(fn () => $this->search->engine()->index($message));
        }
    }

    protected function guard(\Closure $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
