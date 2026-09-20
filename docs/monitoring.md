# Monitoring

Every synchronisation is recorded as a **sync run**, failures are classified, and alerts notify the people who
manage mailboxes before users report "missing e-mails". Monitoring data never contains message contents,
subjects or addresses; error texts are redacted.

## Scheduler

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('mailbox:sync')->everyFiveMinutes();
Schedule::command('mailbox:monitor')->everyFiveMinutes();
Schedule::command('mailbox:prune-monitoring')->daily();
```

`monitoring.expected_sync_interval_minutes` must match the `mailbox:sync` interval.

## Sync runs (`mailbox_sync_runs`)

| Column | Content |
|---|---|
| `uuid` | Correlation id, also added as `sync_run_id` to every sync log entry |
| `trigger` | `schedule` (jobs from `mailbox:sync`), `command` (`--now`), `manual` (panel), `webhook` (Graph/Gmail push), `folder_change`, `retry` |
| `status` | `running`, `succeeded`, `partial` (some folders failed), `failed` |
| `queued_at`, `queue_wait_ms` | When the job was dispatched and how long it waited for a worker |
| `started_at`, `finished_at`, `duration_ms` | |
| `folders`, `imported`, `updated`, `deleted` | Counts of the run |
| `error_type`, `error` | Classified (`connection`, `authentication`, `timeout`, `protocol`, `rate_limited`, `storage`, `unknown`) and redacted |
| `folder_stats` | Per folder: duration, imported, updated, deleted, error type and error |
| `provider_stats` | Per provider operation (`folders`, `changes`, `fetchMessages`, `mailboxChanges`, `labels`, …): calls, errors, total and max milliseconds |

`SyncRunRecorder` records the run around `SyncService` (jobs and `mailbox:sync --now`); the service reports
folders and provider latencies through the `Contracts\SyncObserver` interface. Events: `SyncRunStarted`,
`SyncRunFinished`.

The **Synchronisation history** relation manager on the mailbox edit page lists the runs with a detail view
(folders, provider calls).

## Alerts (`mailbox_alerts`)

| Rule | Opened | Resolved |
|---|---|---|
| `authentication` (critical) | Immediately when a run fails with an authentication error | Next successful run |
| `consecutive_failures` (critical) | The last `alerts.consecutive_failures` runs failed | Next successful run |
| `stale` (warning) | `mailbox:monitor`: no successful synchronisation for `expected_sync_interval_minutes × alerts.stale_factor` | `mailbox:monitor` once synchronised again, or the mailbox is deactivated |

An open alert of the same rule and mailbox is never opened twice. Opening and resolving send a
`MailboxAlertNotification` through `alerts.channels`:

- `database` / `mail` — to every user who may manage mailboxes (the user model needs Laravel's `Notifiable`;
  enable `->databaseNotifications()` on the panel to see them in Filament),
- `mail` additionally to `alerts.mail_to`,
- `slack` — to the incoming webhook `alerts.slack_webhook` (no extra package needed).

**Mailbox alerts** in the navigation (managers only, badge = open alerts) lists open and resolved alerts;
*Acknowledge* closes an alert manually. `mailbox:monitor` also closes runs that are still `running` after
`run_timeout_minutes` as failed (`timeout`), e.g. after a worker crash.

`mailbox:prune-monitoring [--days=]` deletes runs and resolved alerts older than `retention_days`. With many
mailboxes the table grows quickly (1,000 mailboxes every 5 minutes ≈ 290,000 runs per day) — keep the retention short.

## Health dashboard

The page **Mailbox health** rates every mailbox from the recorded runs and runs system checks (queue worker,
scheduler, attachment disk, search, OAuth connections, failed jobs), see [Health dashboard](health.md).

## Prometheus

Disabled by default. Enable `monitoring.prometheus.enabled`, set `MAILBOX_METRICS_TOKEN` and scrape
`GET /filament-mailbox/metrics` with `Authorization: Bearer <token>`:

```text
filament_mailbox_sync_runs{status="succeeded"} 12
filament_mailbox_sync_duration_seconds_bucket{le="5"} 10
filament_mailbox_sync_duration_seconds_sum 42.5
filament_mailbox_provider_errors{type="connection"} 1
filament_mailbox_open_alerts 1
filament_mailbox_active_mailboxes 20
filament_mailbox_last_success_timestamp{mailbox="3"} 1789632000   # only with mailbox_labels
```

Run values cover the last `window_minutes`. Mailbox series use the id only and are off by default to limit
label cardinality.

## Laravel Pulse

With [laravel/pulse](https://pulse.laravel.com) installed (`monitoring.pulse`, default on), every finished run is
recorded:

| Type | Key | Value | Aggregates |
|---|---|---|---|
| `mailbox_sync` | mailbox id | duration in ms | avg, max, count |
| `mailbox_sync_failure` | error type | – | count |

Add the cards to the published Pulse dashboard (`php artisan vendor:publish --tag=pulse-dashboard`):

```blade
<livewire:filament-mailbox.pulse.syncs cols="6" />
<livewire:filament-mailbox.pulse.sync-failures cols="6" />
```

*Mailbox synchronisations* lists runs, average and slowest duration per mailbox (sortable), *Mailbox sync
failures* the failed and partial runs by error type. Pulse itself decides who may open the dashboard
(`viewPulse` gate).

## OpenTelemetry

With `open-telemetry/sdk` (and an exporter, e.g. `open-telemetry/exporter-otlp`) installed and
`MAILBOX_OPENTELEMETRY=true`, sync runs create spans through the global tracer provider
(`OpenTelemetry\API\Globals`, configured by the SDK autoloader):

```dotenv
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_SERVICE_NAME=my-app
OTEL_TRACES_EXPORTER=otlp
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318
MAILBOX_OPENTELEMETRY=true
```

| Span | Parent | Attributes |
|---|---|---|
| `mailbox.sync` | current context | `mailbox.id`, `mailbox.provider`, `mailbox.sync.trigger`, `.run_id`, `.status`, `.folders`, `.imported`, `.updated`, `.deleted`, `.queue_wait_ms`, `.error_type` |
| `mailbox.sync.folder` | `mailbox.sync` | `mailbox.folder.hash` (first 12 characters of the SHA-1 of the path), counts, `mailbox.sync.error_type` |
| `mailbox.provider.<operation>` (client) | folder or run span | `mailbox.provider.operation` |

Failures set the span status to error with the error type and `exception.type`; exception messages, mailbox names,
folder names and addresses are never exported. Sync log entries get the `trace_id`. Bind your own
`Monitoring\OpenTelemetry\TracingSyncObserver` to use another tracer provider.

## Analysing sync errors

1. **Mailbox alerts** or the mailbox's **Synchronisation history** shows the failing runs and their error type.
2. Open a run: `folder_stats` tells whether one folder or the whole mailbox fails, `provider_stats` shows slow or
   failing provider operations.
3. Search the sync log channel (`sync.log_channel`) for the run id (`sync_run_id`).
4. By error type:
   - `authentication` — wrong password, revoked OAuth consent or expired app secret; reconnect the account in
     the mailbox settings.
   - `connection` / `timeout` — host, port, firewall or TLS settings; *Test connection* on the edit page.
   - `rate_limited` — Graph/Gmail throttling; lower `sync.chunk_size` or the scheduler frequency.
   - `protocol` — a server response the provider could not handle; check the log entry.
   - `storage` — attachment disk or database errors.
5. Large `queue_wait_ms` values mean too few queue workers.
