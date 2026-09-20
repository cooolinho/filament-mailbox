<?php

namespace Cooolinho\FilamentMailbox\Commands;

use Carbon\CarbonImmutable;
use Cooolinho\FilamentMailbox\Enums\SpecialUse;
use Cooolinho\FilamentMailbox\Models\Mailbox;
use Cooolinho\FilamentMailbox\Models\MailboxMessage;
use Cooolinho\FilamentMailbox\Statistics\BusinessHours;
use Cooolinho\FilamentMailbox\Statistics\DailyStatsAggregator;
use Cooolinho\FilamentMailbox\Statistics\ReplyLinker;
use Cooolinho\FilamentMailbox\Statistics\StatisticsSettings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class BackfillStatisticsCommand extends Command
{
    protected $signature = 'mailbox:stats-backfill
        {--mailbox=* : IDs of the mailboxes (default: all)}
        {--days=90 : Number of past days to aggregate}
        {--relink : Recalculate existing reply links (e.g. after changing business hours)}';

    protected $description = 'Link replies of already imported messages and aggregate past days';

    public function handle(ReplyLinker $linker, DailyStatsAggregator $aggregator): int
    {
        if (! StatisticsSettings::enabled()) {
            $this->components->warn('Mailbox statistics are disabled.');

            return self::SUCCESS;
        }

        $ids = array_filter((array) $this->option('mailbox'), 'is_numeric');
        $days = max(1, (int) $this->option('days'));

        Mailbox::query()->when($ids !== [], fn ($query) => $query->whereKey($ids))->orderBy('id')->each(function (Mailbox $mailbox) use ($linker, $aggregator, $days): void {
            $since = now()->subDays($days + 1);

            if ($this->option('relink')) {
                DB::table('mailbox_reply_links')
                    ->whereIn('inbound_message_id', MailboxMessage::withTrashed()->where('mailbox_id', $mailbox->id)->select('id'))
                    ->delete();
            }

            $links = 0;

            MailboxMessage::withTrashed()
                ->with('mailbox')
                ->where('mailbox_id', $mailbox->id)
                ->whereHas('folder', fn (Builder $query) => $query->where('special_use', SpecialUse::Sent))
                ->where(fn (Builder $query) => $query->whereNotNull('in_reply_to')->orWhereNotNull('references'))
                ->where(fn (Builder $query) => $query->where('sent_at', '>=', $since)->orWhere('received_at', '>=', $since))
                ->chunkById(500, function ($replies) use ($linker, &$links): void {
                    foreach ($replies as $reply) {
                        $links += count($linker->linkReply($reply));
                    }
                });

            $today = CarbonImmutable::now(BusinessHours::timezoneFor($mailbox));

            for ($offset = $days; $offset >= 0; $offset--) {
                $aggregator->aggregate($mailbox, $today->subDays($offset)->format('Y-m-d'));
            }

            $this->components->info("Mailbox [{$mailbox->name}]: {$links} reply links, ".($days + 1).' days aggregated.');
        });

        return self::SUCCESS;
    }
}
