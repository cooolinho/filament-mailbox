# Statistics

The page **Statistics** gives team leads and administrators key figures of their mailboxes: incoming and
outgoing volume, busy hours and weekdays, first-reply times (median, p90, within business hours), SLA share,
unanswered messages by age, unread messages, top sender domains, attachment volume and storage. The page reads
daily aggregates only, so charts stay fast on large message tables.

Statistics are **disabled by default**:

```dotenv
MAILBOX_STATISTICS=true
```

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('mailbox:stats-aggregate')->hourly();
```

After enabling, link the replies of already imported messages and calculate past days once:

```bash
php artisan mailbox:stats-backfill --days=90
```

## Who sees what

| `statistics.visible_to` | Users who may manage mailboxes | Other users |
|---|---|---|
| `managers` (default) | All mailboxes | No access |
| `assigned_users` | All mailboxes | Their assigned mailboxes |

Filter values (mailbox ids) and the CSV export are always limited to these mailboxes on the server; a manipulated
filter with a foreign mailbox id shows nothing of it. Managers do not need to be assigned to a mailbox to see its
aggregates — statistics never contain subjects, bodies or complete addresses.

## Data protection (employee monitoring)

Reply times per person are a performance and behaviour control of employees (in Germany subject to co-determination,
§ 87 (1) no. 6 BetrVG; GDPR / § 26 BDSG). The package therefore calculates **mailbox aggregates only**: it does not
record who replied, and there is no option for per-person figures. Sender statistics use **domains** instead of
addresses (data minimisation). Aggregates are deleted after `statistics.retention_days` (730).

Discuss enabling statistics with your works council / data protection officer, especially together with
`assigned_users`.

## Figures

| Figure | Source |
|---|---|
| Received | Messages outside the sent, drafts and spam folders, by `received_at` in the mailbox time zone; the same `Message-ID` in several folders counts once, deleted messages still count |
| Sent | Messages in the sent folder, by `sent_at` |
| Automatic messages | `Auto-Submitted` (not `no`), `Precedence: bulk/junk/list/auto_reply`, `List-Id`, `List-Unsubscribe`, `X-Autoreply`, `X-Autorespond`; counted as received, but excluded from reply times, SLA, domains and unanswered |
| First reply | Earliest sent message whose `In-Reply-To` (or last `References` entry) is the `Message-ID` of the received message, same mailbox; minutes of wall-clock time and within business hours |
| SLA | Share of answered messages whose first reply took at most `sla_minutes` (per mailbox, default `sla_default_minutes`) — within business hours when configured, otherwise wall-clock time |
| Unanswered now | Received messages in the inbox without linked reply and without the answered flag, by age (0–4 h, 4–24 h, 1–3 days, > 3 days) |
| Unread, storage | Inbox messages not read; bodies plus attachments of the mailboxes |
| Top sender domains | Top `top_domains` domains per day, summed for the period |

Reply times and replied counts belong to the day the original message was **received**. Unanswered, unread and
storage are live figures (cached `live_cache_seconds`, 300), not history.

### Limits

- Replies are only found when the sent folder is synchronised (IMAP servers that store sent mail, Microsoft Graph,
  Gmail). Messages sent from the app through SMTP without a copy in the sent folder are missing.
- Clients that send replies without `In-Reply-To`/`References` are not linked (no subject matching).
- The top domains of a period are the sum of the daily top lists and may miss domains that were never in a daily top list.

## Business hours and SLA

Mailbox form, section **Business hours & SLA** (visible when statistics are enabled):

- **Time zone** — day boundaries and hour buckets of the statistics (default `statistics.timezone`, then `app.timezone`),
- **SLA (first reply)** in minutes,
- **Business hours** — weekday with start and end, several intervals per day possible,
- **Holidays** — dates (`YYYY-MM-DD`) without business hours.

Stored as `mailboxes.business_hours` (`{"timezone": "...", "hours": [{"day": "mon", "start": "08:00", "end": "17:00"}], "holidays": ["2026-12-24"]}`)
and `mailboxes.sla_minutes`. Business minutes are calculated with local wall-clock intervals, so daylight saving
changes are respected. After changing business hours, recalculate: `php artisan mailbox:stats-backfill --relink`.

## Aggregation

| Table | Content |
|---|---|
| `mailbox_stats_daily` | Per mailbox and day: counts, `inbound_by_hour`/`outbound_by_hour` (24 values), replied count, reply minutes p50/p90 (wall clock and business), SLA met, unique sender domains, attachment bytes in/out |
| `mailbox_stats_domains_daily` | Top domains per mailbox and day |
| `mailbox_reply_links` | Received message, reply, minutes, business minutes |
| `mailbox_stats_dirty_days` | Finished days that got late imports |

- The synchronisation links replies (in both import orders) and marks finished days with new messages as dirty
  (`StatisticsRecorder`, never breaks a synchronisation).
- `mailbox:stats-aggregate` recalculates today, yesterday and dirty days (idempotent) and deletes aggregates older
  than `retention_days`. Options: `--mailbox=*`, `--date=Y-m-d`, `--from=Y-m-d --to=Y-m-d`.
- `mailbox:stats-backfill [--days=90] [--relink] [--mailbox=*]` links replies of existing sent messages and aggregates past days.

## Page

- Filters: period (7, 30, 90 days, custom), mailboxes; stored in the URL and session.
- Key figures with comparison to the previous period of the same length.
- Charts: messages per day (received/sent), by hour, by weekday, first-reply time distribution.
- Tables: unanswered by age per mailbox (links to the inbox for assigned users), top sender domains.
- **Export CSV**: daily aggregates of the filtered mailboxes (`date`, `mailbox_id`, `mailbox`, counts, percentiles,
  hourly values separated by `|`).

## Configuration

```php
'statistics' => [
    'enabled' => false,
    'visible_to' => 'managers',           // managers | assigned_users
    'timezone' => null,                   // default time zone of mailboxes without one (null = app.timezone)
    'top_domains' => 10,
    'sla_default_minutes' => 240,
    'live_cache_seconds' => 300,
    'retention_days' => 730,
],
```
