<?php

namespace Cooolinho\FilamentMailbox\Statistics;

use Carbon\CarbonInterface;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxFolder;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Called for every newly imported message: links replies and marks
 * already aggregated days for recalculation.
 */
class StatisticsRecorder
{
    /** @var array<int, string> */
    protected array $timezones = [];

    public function __construct(
        protected ReplyLinker $linker,
    ) {}

    public function messageImported(MailboxMessage $message, MailboxFolder $folder): void
    {
        if (! StatisticsSettings::enabled() || $folder->special_use === SpecialUse::Drafts) {
            return;
        }

        try {
            if ($folder->special_use === SpecialUse::Sent) {
                $this->markDirty($message->mailbox_id, $message->sent_at ?? $message->received_at);

                foreach ($this->linker->linkReply($message) as $inbound) {
                    $this->markDirty($message->mailbox_id, $inbound->received_at);
                }

                return;
            }

            $this->markDirty($message->mailbox_id, $message->received_at);
            $this->linker->linkInbound($message);
        } catch (Throwable $exception) {
            // Statistics must never break a synchronisation.
            report($exception);
        }
    }

    /**
     * Days before today (in the mailbox time zone) are recalculated by the next aggregation.
     */
    public function markDirty(int $mailboxId, ?CarbonInterface $at): void
    {
        if (! $at) {
            return;
        }

        $timezone = $this->timezones[$mailboxId] ??= BusinessHours::timezoneFor(Mailbox::query()->findOrNew($mailboxId));
        $date = $at->copy()->setTimezone($timezone)->format('Y-m-d');

        if ($date >= now($timezone)->format('Y-m-d')) {
            return;
        }

        DB::table('mailbox_stats_dirty_days')->insertOrIgnore(['mailbox_id' => $mailboxId, 'date' => $date]);
    }
}
