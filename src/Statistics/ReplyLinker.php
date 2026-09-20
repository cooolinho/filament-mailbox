<?php

namespace Cooolinho\FilamentMailbox\Statistics;

use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Links sent replies to the inbound messages they answer (In-Reply-To, or
 * the last References entry), within the same mailbox only.
 */
class ReplyLinker
{
    /** Replies dated slightly before the original (clock skew) still count. */
    protected const TOLERANCE_MINUTES = 5;

    public function __construct(
        protected BusinessHoursCalculator $calculator,
    ) {}

    /**
     * @return array<int, MailboxMessage> newly linked inbound messages
     */
    public function linkReply(MailboxMessage $reply): array
    {
        $references = array_values(array_unique(array_filter([
            $reply->in_reply_to,
            $this->lastReference($reply->references),
        ])));

        if ($references === []) {
            return [];
        }

        $linked = [];

        $inbound = $this->inboundQuery($reply->mailbox_id)
            ->whereIn('message_id', $references)
            ->whereKeyNot($reply->getKey())
            ->get();

        foreach ($inbound as $message) {
            if ($this->link($message, $reply)) {
                $linked[] = $message;
            }
        }

        return $linked;
    }

    /**
     * Link replies imported before the message they answer.
     *
     * @return array<int, MailboxMessage> newly linked replies
     */
    public function linkInbound(MailboxMessage $inbound): array
    {
        if (blank($inbound->message_id)) {
            return [];
        }

        $linked = [];

        $replies = MailboxMessage::withTrashed()
            ->where('mailbox_id', $inbound->mailbox_id)
            ->where('in_reply_to', $inbound->message_id)
            ->whereHas('folder', fn (Builder $query) => $query->where('special_use', SpecialUse::Sent))
            ->get();

        foreach ($replies as $reply) {
            if ($this->link($inbound, $reply)) {
                $linked[] = $reply;
            }
        }

        return $linked;
    }

    /**
     * Inbound messages of a mailbox: everything outside the sent and drafts folders.
     */
    public function inboundQuery(int $mailboxId): Builder
    {
        return MailboxMessage::withTrashed()
            ->where('mailbox_id', $mailboxId)
            ->whereHas('folder', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNull('special_use')
                ->orWhereNotIn('special_use', [SpecialUse::Sent, SpecialUse::Drafts])));
    }

    public function link(MailboxMessage $inbound, MailboxMessage $reply): bool
    {
        $receivedAt = $inbound->received_at ?? $inbound->sent_at;
        $repliedAt = $reply->sent_at ?? $reply->received_at;

        if (! $receivedAt || ! $repliedAt || $repliedAt->lt($receivedAt->copy()->subMinutes(self::TOLERANCE_MINUTES))) {
            return false;
        }

        $mailbox = $inbound->relationLoaded('mailbox') ? $inbound->mailbox : Mailbox::query()->find($inbound->mailbox_id);
        $hours = $mailbox ? BusinessHours::forMailbox($mailbox) : null;
        $repliedAt = Carbon::instance($repliedAt)->max($receivedAt);

        return DB::table('mailbox_reply_links')->insertOrIgnore([
            'inbound_message_id' => $inbound->getKey(),
            'reply_message_id' => $reply->getKey(),
            'reply_minutes' => (int) floor($receivedAt->diffInSeconds($repliedAt, true) / 60),
            'reply_business_minutes' => $hours ? $this->calculator->minutes($receivedAt, $repliedAt, $hours) : null,
        ]) > 0;
    }

    protected function lastReference(?string $references): ?string
    {
        $parts = preg_split('/\s+/', trim((string) $references)) ?: [];

        return $parts === [] || end($parts) === '' ? null : end($parts);
    }
}
