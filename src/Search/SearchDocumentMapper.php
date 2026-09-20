<?php

namespace Cooolinho\FilamentMailbox\Search;

use Cooolinho\FilamentMailbox\Models\MailboxAttachment;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Support\HtmlToText;
use Illuminate\Support\Str;

/**
 * A message as a document for external search engines (Meilisearch, Scout).
 * Field operators (from:, subject:, …) become token arrays, because such
 * engines search all attributes at once.
 */
class SearchDocumentMapper
{
    /**
     * @return array<string, mixed>
     */
    public function map(MailboxMessage $message): array
    {
        $attachments = $message->attachments()->get(['filename', 'extracted_text']);
        $to = $this->addresses($message->to);
        $cc = $this->addresses($message->cc);
        $from = trim($message->from_name.' '.$message->from_address);
        $subject = (string) $message->subject;
        $names = $attachments->pluck('filename')->implode(' ');

        return [
            'message_id' => (int) $message->getKey(),
            'mailbox_id' => (int) $message->mailbox_id,
            'folder_id' => (int) $message->folder_id,
            'subject' => $subject,
            'from' => $from,
            'recipients' => trim($to.' '.$cc),
            'body' => mb_substr(HtmlToText::body($message->text_body, $message->html_body), 0, max(1000, (int) config('filament-mailbox.search.max_body_length', 100_000))),
            'attachment_names' => $names,
            'attachment_text' => mb_substr($attachments->pluck('extracted_text')->filter()->implode("\n"), 0, max(1000, (int) config('filament-mailbox.search.attachments.max_text_length', 200_000))),
            'from_tokens' => static::tokens($from),
            'to_tokens' => static::tokens($to),
            'cc_tokens' => static::tokens($cc),
            'subject_tokens' => static::tokens($subject),
            'attachment_name_tokens' => static::tokens($names),
            'from_domain' => Str::lower(Str::afterLast((string) $message->from_address, '@')) ?: null,
            'is_read' => (bool) $message->is_read,
            'is_flagged' => (bool) $message->is_flagged,
            'has_attachments' => (bool) $message->has_attachments,
            'received_at_ts' => (int) (($message->received_at ?? $message->sent_at)?->getTimestamp() ?? 0),
            'labels' => $message->labels()->pluck('name')->map(fn (string $name): string => mb_strtolower($name))->all(),
            'tags' => $message->tags()->pluck('name')->map(fn (string $name): string => mb_strtolower($name))->all(),
        ];
    }

    /**
     * Lower-case words of a text, e.g. the parts of an address.
     *
     * @return array<int, string>
     */
    public static function tokens(?string $text): array
    {
        return array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $text)) ?: [], fn (string $token): bool => $token !== '')));
    }

    /**
     * @param  ?array<int, array{address: ?string, name: ?string}>  $addresses
     */
    protected function addresses(?array $addresses): string
    {
        return trim(collect($addresses ?? [])->map(fn (array $address): string => trim(($address['name'] ?? '').' '.($address['address'] ?? '')))->implode(' '));
    }
}
