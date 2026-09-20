<?php

namespace Cooolinho\FilamentMailbox\Statistics;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Snapshot figures that are not historised (unanswered and unread messages,
 * storage), calculated from indexes and cached for five minutes.
 */
class LiveStatsProvider
{
    public const BUCKETS = ['0_4h' => [0, 4], '4_24h' => [4, 24], '1_3d' => [24, 72], 'over_3d' => [72, null]];

    /**
     * @param  array<int, int>  $mailboxIds
     * @return array{unanswered: array<string, int>, unread: int, storage_bytes: int}
     */
    public function get(array $mailboxIds): array
    {
        sort($mailboxIds);

        return Cache::remember(
            'filament-mailbox:statistics:live:'.md5(implode(',', $mailboxIds)),
            max(0, (int) config('filament-mailbox.statistics.live_cache_seconds', 300)),
            fn (): array => $this->compute($mailboxIds),
        );
    }

    /**
     * @param  array<int, int>  $mailboxIds
     * @return array{unanswered: array<string, int>, unread: int, storage_bytes: int}
     */
    public function compute(array $mailboxIds): array
    {
        $ids = $mailboxIds === [] ? [0] : $mailboxIds;

        $unanswered = [];

        foreach (self::BUCKETS as $key => [$minHours, $maxHours]) {
            $unanswered[$key] = $this->unansweredQuery($ids)
                ->where('m.received_at', '<=', now()->subHours($minHours))
                ->when($maxHours !== null, fn ($query) => $query->where('m.received_at', '>', now()->subHours($maxHours)))
                ->count();
        }

        $unread = DB::table('mailbox_messages as m')
            ->join('mailbox_folders as f', 'f.id', '=', 'm.folder_id')
            ->whereIn('m.mailbox_id', $ids)
            ->where('f.special_use', SpecialUse::Inbox->value)
            ->whereNull('m.deleted_at')
            ->where('m.is_read', false)
            ->count();

        $bodies = (int) DB::table('mailbox_messages')
            ->whereIn('mailbox_id', $ids)
            ->whereNull('deleted_at')
            ->selectRaw('coalesce(sum(coalesce(length(text_body), 0) + coalesce(length(html_body), 0)), 0) as bytes')
            ->value('bytes');

        $attachments = (int) DB::table('mailbox_attachments as a')
            ->join('mailbox_messages as m', 'm.id', '=', 'a.message_id')
            ->whereIn('m.mailbox_id', $ids)
            ->whereNull('m.deleted_at')
            ->sum('a.size');

        return [
            'unanswered' => $unanswered,
            'unread' => $unread,
            'storage_bytes' => $bodies + $attachments,
        ];
    }

    /**
     * Human inbound messages in the inbox without a linked reply and not flagged as answered.
     *
     * @param  array<int, int>  $mailboxIds
     */
    public function unansweredQuery(array $mailboxIds): \Illuminate\Database\Query\Builder
    {
        return DB::table('mailbox_messages as m')
            ->join('mailbox_folders as f', 'f.id', '=', 'm.folder_id')
            ->whereIn('m.mailbox_id', $mailboxIds === [] ? [0] : $mailboxIds)
            ->where('f.special_use', SpecialUse::Inbox->value)
            ->whereNull('m.deleted_at')
            ->where('m.is_auto_generated', false)
            ->where('m.is_answered', false)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('mailbox_reply_links as l')->whereColumn('l.inbound_message_id', 'm.id'));
    }
}
