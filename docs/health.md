# Health dashboard

**Mailbox health** (navigation, users who may manage mailboxes) shows at a glance which mailboxes synchronise
correctly, which are stale or failing, and whether queue worker, scheduler, storage and search work. Problems
can be fixed from the page with quick actions. The page needs [monitoring](monitoring.md) (sync runs).

## Scheduler and worker

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('mailbox:sync')->everyFiveMinutes();
Schedule::command('mailbox:monitor')->everyFiveMinutes();
```

`mailbox:monitor` rates all mailboxes, runs and caches the system checks, writes the **scheduler heartbeat** and
dispatches `MailboxHeartbeatJob` on the sync queue (`sync.queue_connection` / `sync.queue`). A worker processing
that job writes the **queue heartbeat**. Without a scheduler or worker the corresponding check fails — which is
its purpose. The heartbeats are stored in the application cache; use a cache store shared by web, scheduler and
workers (not `array`).

## Health rating

Every finished sync run updates denormalised fields on the mailbox (`UpdateMailboxHealth` listener on
`SyncRunFinished`), so the table needs no query per row:

| Column | Content |
|---|---|
| `health` | Stored rating (below) |
| `consecutive_failures` | Failed runs in a row; reset by a succeeded or partial run |
| `last_success_at` | End of the last succeeded or partial run |
| `last_run_status`, `last_run_duration_ms` | Status and duration of the last run |
| `last_error_type` | Classified error of the last run |
| `failing_folders` | Folders with errors in the last run |

`MailboxHealthEvaluator` applies the first matching rule:

| Status | Rule |
|---|---|
| `inactive` | The mailbox is deactivated |
| `reconnect` | The OAuth connection is not active, or the last run failed with an authentication error |
| `failing` | `monitoring.alerts.consecutive_failures` (3) failed runs in a row, or an open critical alert |
| `degraded` | The last run was partial, or one or two runs failed |
| `unknown` | Never synchronised |
| `stale` | Last success older than `expected_sync_interval_minutes × alerts.stale_factor` |
| `healthy` | Otherwise |

`stale` depends on the time: the table applies it while rendering, `mailbox:monitor` stores it.

## Page

- **Key figures** (`MailboxHealthStatsWidget`): active mailboxes, healthy, warnings (degraded, stale), errors
  (failing, reconnect), open alerts, sync success rate of the last 24 hours with an hourly sparkline, p95 sync
  duration and jobs waiting in the sync queue. Cached for `health.statistics_cache_seconds`.
- **System checks** (`SystemChecksWidget`): status, message and time of the last check; *Run checks* runs them
  immediately.
- **Mailboxes**: health, last success, last run with duration, last error with type, failures in a row and failing
  folders. Worst status first, filter by status and active flag.
- **Actions** per mailbox: *Test connection*, *Synchronise*, *Synchronisation history* (edit page, history tab) and
  *Edit*; bulk *Synchronise* for active mailboxes (unique jobs, already queued mailboxes are skipped).
- Page, widgets and table refresh every `health.polling` (30 s).
- The navigation badge counts `failing` and `reconnect` mailboxes.

The **Mailboxes** table shows the health badge to managers as well.

Show the key figures on the panel dashboard:

```php
FilamentMailboxPlugin::make()->healthWidgetsOnDashboard();
```

## System checks

| Check | Key | Run | Result |
|---|---|---|---|
| `QueueHeartbeatCheck` | `queue` | Live (cache read) | Age of the queue heartbeat: warning after 10, failed after 20 minutes (`health.queue_heartbeat`) |
| `SchedulerHeartbeatCheck` | `scheduler` | Live (cache read) | Age of the scheduler heartbeat (`health.scheduler_heartbeat`) |
| `AttachmentDiskCheck` | `attachment_disk` | `mailbox:monitor` | Writes, reads and deletes `<attachments.path>/.health/<uuid>.txt` |
| `SearchEngineCheck` | `search` (Meilisearch: also a health request to the instance) | `mailbox:monitor` | Runs a search query on the messages, warning above `health.search_warning_ms` |
| `OAuthConnectionsCheck` | `oauth` | `mailbox:monitor` | OAuth connections of active mailboxes that need a reconnect or were revoked |
| `FailedJobsCheck` | `failed_jobs` | `mailbox:monitor` | Failed jobs of the package in `failed_jobs` in the last 24 hours (skipped without the table) |

Expensive checks are never run while rendering: the page shows the cached results and their time. Before the first
`mailbox:monitor` they show "not checked yet". Exceptions of a check are reported and shown with the exception class
only, never with the message (it may contain credentials or paths).

### Own checks

```php
use Cooolinho\FilamentMailbox\Health\HealthCheck;
use Cooolinho\FilamentMailbox\Health\HealthCheckResult;

class ImapProxyCheck implements HealthCheck
{
    public function key(): string { return 'imap_proxy'; }

    public function label(): string { return 'IMAP proxy'; }

    public function isLive(): bool { return false; }

    public function run(): HealthCheckResult
    {
        return fsockopen('proxy.internal', 993, timeout: 5)
            ? HealthCheckResult::ok('Reachable')
            : HealthCheckResult::failed('Not reachable');
    }
}
```

Add the class to `health.checks` in the published configuration.

## Performance

The table reads the denormalised columns (indexed `health`), key figures are aggregated and cached, and expensive
checks run in the scheduler — the page stays fast with 1,000 mailboxes and many open admin tabs.
