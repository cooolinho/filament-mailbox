<?php

namespace Cooolinho\FilamentMailbox\Search;

use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Support\HtmlToText;
use Illuminate\Support\Facades\DB;

/**
 * Keeps mailbox_search_documents (and the SQLite FTS table) in step with messages.
 */
class SearchDocumentWriter
{
    /** Columns that change without touching the text. */
    public const STATE_COLUMNS = ['folder_id', 'is_read', 'is_flagged', 'received_at'];

    public function write(MailboxMessage $message): void
    {
        if ($message->trashed()) {
            $this->delete([(int) $message->getKey()]);

            return;
        }

        $attachments = $message->attachments()->get(['filename', 'extracted_text']);
        $maxBody = max(1000, (int) config('filament-mailbox.search.max_body_length', 100_000));
        $maxAttachments = max(1000, (int) config('filament-mailbox.search.attachments.max_text_length', 200_000));

        $document = [
            'message_id' => $message->getKey(),
            'mailbox_id' => $message->mailbox_id,
            'folder_id' => $message->folder_id,
            'received_at' => $message->received_at ?? $message->sent_at,
            'is_read' => $message->is_read,
            'is_flagged' => $message->is_flagged,
            'has_attachments' => $message->has_attachments,
            'from_text' => static::normalize(trim($message->from_name.' '.$message->from_address)),
            'to_text' => static::addresses($message->to),
            'cc_text' => static::addresses($message->cc),
            'subject' => mb_substr((string) static::normalize($message->subject), 0, 500),
            'body_text' => static::normalize(mb_substr(HtmlToText::body($message->text_body, $message->html_body), 0, $maxBody)),
            'attachment_names' => static::normalize($attachments->pluck('filename')->implode(' ')),
            'attachment_text' => static::normalize(mb_substr($attachments->pluck('extracted_text')->filter()->implode("\n"), 0, $maxAttachments)),
        ];

        DB::transaction(function () use ($document): void {
            DB::table('mailbox_search_documents')->upsert([$document], ['message_id'], array_keys($document));

            if ($this->usesFts()) {
                DB::table('mailbox_search_fts')->where('rowid', $document['message_id'])->delete();
                DB::table('mailbox_search_fts')->insert([
                    'rowid' => $document['message_id'],
                    ...array_intersect_key($document, array_flip(['subject', 'from_text', 'to_text', 'cc_text', 'body_text', 'attachment_names', 'attachment_text'])),
                ]);
            }
        });
    }

    /**
     * Flags and folder only; writes the full document when it does not exist yet.
     */
    public function updateState(MailboxMessage $message): void
    {
        $updated = DB::table('mailbox_search_documents')
            ->where('message_id', $message->getKey())
            ->update([
                'folder_id' => $message->folder_id,
                'is_read' => $message->is_read,
                'is_flagged' => $message->is_flagged,
                'received_at' => $message->received_at ?? $message->sent_at,
            ]);

        if ($updated === 0 && ! DB::table('mailbox_search_documents')->where('message_id', $message->getKey())->exists()) {
            $this->write($message);
        }
    }

    /**
     * @param  array<int, int>  $messageIds
     */
    public function delete(array $messageIds): void
    {
        foreach (array_chunk($messageIds, 500) as $chunk) {
            DB::table('mailbox_search_documents')->whereIn('message_id', $chunk)->delete();

            if ($this->usesFts()) {
                DB::table('mailbox_search_fts')->whereIn('rowid', $chunk)->delete();
            }
        }
    }

    public function usesFts(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    public static function normalize(?string $text): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', mb_strtolower((string) $text)) ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * @param  ?array<int, array{address: ?string, name: ?string}>  $addresses
     */
    protected static function addresses(?array $addresses): ?string
    {
        return static::normalize(collect($addresses ?? [])->map(fn (array $address): string => trim(($address['name'] ?? '').' '.($address['address'] ?? '')))->implode(' '));
    }
}
