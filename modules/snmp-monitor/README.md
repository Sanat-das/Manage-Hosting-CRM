# SNMP Monitor Module

Standalone SNMP-based monitoring for hosting accounts with centralized
collector, time-series storage and admin dashboard.

## Architecture

```
                          Windows Task Scheduler
                          (every 1 minute)
                                |
                                v
+-------------------+    +------------------+    +-------------------+
|  Dashboard        |    |  Laravel Scheduler|    |  Queue Worker     |
|  (Blade views)    |    |  schedule:run     |    |  queue:work        |
|                   |    |                   |    |  --queue=snmp-poll |
|  /admin/snmp-*    |    | snmp-poll-        |    |                   |
|                   |    |   dispatch-due    |--->| PollHostBatch     |
|  SnmpMetric-      |    | snmp-rollup-      |    |   -> SnmpCollector|
|    Repository     |    |   hourly          |    |   -> SNMP v2c/v3  |
|                   |    | snmp:maintain-    |    |   -> snmp_host_   |
|                   |    |   partitions      |    |      samples       |
+--------+----------+    +------------------+    |   -> snmp_if_     |
         |                                       |      samples       |
         |         +------------------+          +--------+----------+
         +-------->|  managehosting_  |<--------------------+
                   |  monitoring DB   |
                   |                  |
                   | snmp_targets     |
                   | snmp_host_samples|  <-- RANGE partitions (monthly)
                   | snmp_if_samples  |
                   | snmp_latest      |
                   | snmp_metric_     |
                   |   hourly         |  <-- pre-aggregated rollups
                   +------------------+
```

Two dedicated connections share `managehosting_monitoring` as a separate
database (configured via `MONITORING_DB_DATABASE` in `.env`):

- The **web** connection reads `snmp_latest`, `snmp_host_samples`,
  `snmp_if_samples` and `snmp_metric_hourly` for the dashboard.
- The **queue worker** connection writes new samples and the latest
  snapshot after each successful poll.

## Scheduler requirements

The polling pipeline requires **two** runtime services on the host:

### 1. Scheduler tick (every minute)

`php artisan schedule:run` must execute once per minute. On Windows use
**Task Scheduler**:

```
Action:  Start a program
Program: C:\path\to\php.exe
Arguments: artisan schedule:run
Start in: C:\path\to\app
Trigger: Every 1 minute
```

> **This is a manual operator setup step.** It is NOT verified by QA
> automated tests — no Task Scheduler registration is asserted.

The scheduler dispatches:

| Schedule name            | Frequency       | Command / Closure                          |
|--------------------------|-----------------|--------------------------------------------|
| `snmp-poll-dispatch-due` | Every minute    | `PollHostBatch::dispatchDue()`             |
| `snmp-rollup-hourly`     | Hourly at :05   | `RollupHourlyAggregates::dispatch()`       |
| *(unnamed)*              | Daily at 00:10  | `snmp:maintain-partitions`                 |

### 2. Queue worker

A persistent queue worker processes polling jobs:

```
php artisan queue:work --queue=snmp-poll --tries=1 --timeout=120 --max-jobs=500 --max-time=3600
```

Register as a Windows service with NSSM or WinSW:

```powershell
# NSSM example (documentation only — not asserted by QA)
nssm install snmp-poll-worker "C:\path\to\php.exe" "artisan queue:work --queue=snmp-poll --tries=1 --timeout=120 --max-jobs=500 --max-time=3600"
nssm set snmp-poll-worker AppDirectory "C:\path\to\app"
nssm start snmp-poll-worker
```

The `--max-jobs=500 --max-time=3600` flags cause the worker to restart
after 500 jobs or 1 hour, preventing memory leaks on long-running PHP
processes.

## Retention and partition maintenance

### `snmp:maintain-partitions`

```
php artisan snmp:maintain-partitions
```

Idempotent command that:

1. **Pre-creates** the next monthly partition by reorganizing the
   `p_future` catch-all when its start falls within 45 days of today.
2. **Drops** monthly partitions whose rows are entirely older than the
   retention window, keeping at least one partition per table.

Operates on two tables: `snmp_host_samples` and `snmp_if_samples`.

On non-MySQL drivers (e.g. SQLite under tests) this is a no-op returning
exit 0. The command is scheduled daily at 00:10 by the Laravel scheduler.

### Environment variables

| Variable                 | Default                 | Description                                          |
|--------------------------|-------------------------|------------------------------------------------------|
| `MONITORING_DB_DATABASE` | `managehosting_monitoring` | Database name for the dedicated monitoring connection. |
| `MONITORING_RETENTION_DAYS` | `35`                | Days of per-host samples retained before partition drop. |

Both are set in the project root `.env` file. `MONITORING_RETENTION_DAYS`
defaults to 35 days if unset; the command enforces a minimum of 1 day.

## Product config field reference

The snmp-monitor module exposes 15 fields on the hosting product
configuration form (`SnmpMonitor::configSchema()`). These are stored as
JSON in the product's `module_config` column.

### Connection section

| Field             | Type     | Default | Description                                    |
|-------------------|----------|---------|------------------------------------------------|
| `snmp_version`    | select   | `v3`    | Protocol version (`v3` or `v2c`).              |
| `snmp_community`  | text     | —       | Community string for SNMPv2c (shown only when `snmp_version = v2c`). |
| `snmp_port`       | number   | `161`   | UDP port of the SNMP agent.                    |
| `snmp_timeout`    | number   | `2`     | Request timeout in seconds.                    |

### SNMPv3 Auth section

| Field                | Type     | Default | Description                                    |
|----------------------|----------|---------|------------------------------------------------|
| `snmp_username`      | text     | —       | SNMPv3 username (shown only when `snmp_version = v3`). |
| `snmp_auth_password` | password | —       | Authentication passphrase (encrypted, v3 only). |
| `snmp_auth_protocol` | select   | `SHA`   | Hash algorithm: `SHA` or `MD5` (v3 only).     |

### Privacy section

| Field                | Type     | Default | Description                                    |
|----------------------|----------|---------|------------------------------------------------|
| `snmp_priv_password` | password | —       | Privacy passphrase; empty = authNoPriv (v3 only). |
| `snmp_priv_protocol` | select   | `AES`   | Encryption protocol: `AES` or `DES` (v3 only). |

### Metrics section

| Field               | Type     | Default | Description                                    |
|---------------------|----------|---------|------------------------------------------------|
| `collect_cpu`       | checkbox | `true`  | Collect CPU load via `hrProcessorLoad`.        |
| `collect_memory`    | checkbox | `true`  | Collect memory via `hrMemorySize` / `hrStorageTable`. |
| `collect_disks`     | checkbox | `true`  | Collect disk usage from `hrStorageTable` (fixed disks only). |
| `collect_network`   | checkbox | `false` | Collect network interfaces via IF-MIB (higher SNMP walk cost). |
| `collect_processes` | checkbox | `false` | Collect running processes via `hrSWRunTable` (highest SNMP walk cost). |

### Polling section

| Field           | Type   | Default | Description                                                |
|-----------------|--------|---------|-------------------------------------------------------------|
| `poll_interval` | select | `300`   | Product-level default poll cadence in seconds (60s–1h). Individual hosts may override this on their hosting-account page; that override always wins. Values below 60s are floored to 60s (the scheduler's own tick). |

## Dashboard filters guide

### Index page (`/admin/snmp-monitor`)

Server-rendered filter form with three controls:

| Filter    | Control | Parameter | Description                              |
|-----------|---------|-----------|------------------------------------------|
| Account   | select  | `account` | Filter by hosting account ID.            |
| Status    | select  | `status`  | Filter by target status (e.g. `active`, `error`). |
| Hostname  | text    | `q`       | Fuzzy search across hostnames.           |

Results are paginated (25 per page). The URL carries all filter
parameters for bookmarkable/shareable views.

### Host page (`/admin/snmp-monitor/accounts/{account}`)

Per-host chart page with two filter controls:

| Filter    | Control      | Parameter  | Description                              |
|-----------|--------------|------------|------------------------------------------|
| Metrics   | multi-select | `metrics[]`| One or more of: `cpu_pct`, `cpu_load1`, `mem_used_mb`, `storage_pct`, `response_ms`, `proc_count`, `in_bps`, `out_bps`. |
| Range     | select       | `range`    | Time window: `1h`, `24h`, `7d`, `30d`.  |

The page fetches chart data from the `/series` JSON endpoint. Chart.js
renders the data with per-bucket avg/min/max lines.

### TSDB graduation note

Ranges **strictly beyond 30 days** (> 2,592,000 seconds) read from the
pre-aggregated `snmp_metric_hourly` table instead of scanning raw
partitioned samples. The hourly rollup job (`snmp-rollup-hourly`) runs
every hour at :05 and aggregates the preceding hour's raw samples into
hourly buckets. This tier is transparent to the dashboard — the same
`/series` endpoint serves both raw and hourly data, selecting the tier
automatically based on the requested range.

Current range options are capped at `30d`. To view data older than 30
days, query `snmp_metric_hourly` directly or extend the `RANGES` constant
in `SnmpMetricRepository`.

## Manual poll trigger

The hosting account show page includes a **Refresh** button that issues
`POST /admin/hosting/{account}/snmp-monitor/poll`, enqueuing an immediate
`PollHostBatch` job. This requires the `permission:hosting.manage` gate.
