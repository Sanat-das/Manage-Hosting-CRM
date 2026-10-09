# Plan: Centralize the logging system (full migration)

Date: 2026-10-08 · Executed by: build (orchestrator) + 5 general slices + verify

## Goal

Replace one unrotated 579 MB `laravel.log` fed by ~220 inline `Log::` calls with a
config-driven, domain-partitioned, redacted, correlation-enriched logging pipeline.

Acceptance: `vendor/bin/pint --dirty` clean; `php artisan test` green; each domain
channel writes `storage/logs/<domain>-YYYY-MM-DD.log`; redaction + request context
verified by tests; zero `Log::` calls remain outside `App\Support\Logging\AppLog`
and untouched exceptions.

## Frozen contracts (do not deviate)

- `App\Support\Logging\LogChannel` (enum): app | security | billing | provisioning | cron | ops | alert.
- `App\Support\Logging\AppLog::channel(LogChannel, array $context = []): LoggerInterface`
  plus shorthand `AppLog::app()|security()|billing()|provisioning()|cron()|ops()|alert()`.
  Call sites migrate `Log::level('msg', [...])` → `AppLog::<domain>()->level('msg', [...])`.
- `config/logging.php`: stock channels kept; default stack → `app` (`LOG_STACK=app`);
  domain channels = `monolog` driver + `RotatingFileHandler`, `maxFiles=LOG_RETENTION_DAYS`
  (default 30), processors `[PsrLogMessageProcessor, RequestContextProcessor, RedactingProcessor]`;
  `alert` = slack when `LOG_ALERT_WEBHOOK` set else rotating file. No new dependencies.
- `App\Http\Middleware\AssignRequestId` (global, registered in bootstrap/app.php)
  captures X-Request-Id/ULID into `App\Support\Logging\RequestContext` (singleton).
- `OpsFileWriter::append(path, entry)` — redacts + rotates update.log/rollback.log
  (10 MB, 3 archives); replaces bare `file_put_contents` in UpdateService/commands.
- Severity policy: error = needs human action; warning = degraded, self-recovered;
  info = lifecycle; debug = local diagnostics.

## Channel routing map (slice ownership, disjoint files)

| Slice | Domain | Files |
| --- | --- | --- |
| A | ops | UpdateService, AppInfoService, SystemController, RunSystemUpdateCommand, RunSystemRollbackCommand (+OpsFileWriter call sites) |
| B | provisioning | ProvisioningDispatcher, ManualProvisioner, VmBuildDispatcher, VmOperationDispatcher, ProvisioningEventRecorder, WelcomeMailer, VmGuestCredentialStore, AbstractPanelModule, AbstractComputeModule, ReconcileProvisioningOperationsCommand, jobs: RunOrderProvisioning/RunOrderLifecycleVerb/RunVmOperation/RunUnrecordedVmDestroy/ProvisionComputeVm |
| C | provisioning | Proxmox.php + Proxmox/Services, HyperV.php + HyperV/Services, Virtualizor, Cpanel, modules/snmp-monitor, modules/rdp-console, modules/ssh-console |
| D1 | mixed | Admin+Client HostingController, ServerController, ServiceInstanceController, CartController, Admin OrderController, StoreController, DomainController, RegisteredUserController(S), PaymentWebhookController(B), GlobalSearchService+AbstractSearchProvider(A), EncryptedOrPlaintext+EncryptedCast(S), ModuleManager(A), SyncDomainStatus(B) |
| D2 | mixed | OrderService(B), PaymentSettlementService(B), ApplyUpgradeOnInvoicePaid(B), AdvanceOrderOnPayment(B), Invoice/Upgrade/OrderEmailService(B), ChatService(A), ChatTranscriptEmailService(A), HostingService(P), PortDiscoveryService(P), MailboxConfig(C), TicketMailService/Parser(C), FetchTicketMailCommand(C), RecordScheduledTaskRun(C), routes/console.php(C) |

(A)=app, (S)=security, (B)=billing, (P)=provisioning, (C)=cron.

## Severity corrections (evidence-backed)

- ProvisioningEventRecorder:250,324 → error (event trail loss).
- Proxmox:582, HyperV:396,410 → error (credential store lost after rotation).
- ChatService:574 → error (audit insert failed).
- RunVmOperation:332,359,370 → error (state tracking lost); :144 failed() bare catch → error log.
- PollHostBatch warnings stay warning (per-poll retries).

## Silent-catch policy (listed sites only)

Intentional fallbacks get ONE `debug` line with `$e->getMessage()`; unexpected failures
get `error`. Sites: Virtualizor:242, Cpanel:102, ProxmoxClient:156,517,1007,1534,1578,1621,1663,1754,1831,1871,
HyperVClient:127,178,278,444,1416, snmp TargetService:54/SnmpCollector:763/SnmpMetricRepository:539/SnmpMonitor:281,
rdp VmConnectTargetResolver:104/PveVncTargetResolver:118, ssh SshConsole:102.

## Deliberate exceptions (do not touch)

- `modules/snmp-monitor/database/migrations/**` — historical migration (`Log::error` at :142), stays as-is.
- `database/migrations/2026_08_28_000009_audit_orphan_ticket_departments.php` — historical migration (`Log::info` ×3 at :48,125,131), stays as-is. Both migrations now flow into `app.log` through the default channel when run.
- `app/Providers/ModuleServiceProvider.php:60,111` — `error_log` retained ONLY as deepest guarded fallback when the file log itself fails; primary path is `AppLog::app()->warning` (per docs/iis-deployment.md:127-133).
- Branding fallback catches, SshConsoleController disconnect/Cache cleanup catches — presentational/cleanup, correctly silent.
- `SystemController` detached-launch stdout redirects (update-bg.log etc.) — proc-level, not PHP writers.
- DB audit subsystem (ActivityLog et al.) — separate concern, untouched.
- `tests/**` `Log::spy()` usage — intended test mocking, not production logging.

## Verification

1. `vendor/bin/pint --dirty` (and full `vendor/bin/pint --format agent` if needed).
2. New tests (owner: build): RedactingProcessor, RequestContextProcessor, channel config, OpsFileWriter, GlobalSearchServiceTest update.
3. `php artisan test` full suite.
4. Runtime smoke: log to each channel, confirm files + redaction + request header.
5. `grep -rn "Log::" app/ app/modules routes` → only AppLog internals + approved exceptions.
6. verify subagent adversarial pass over the integrated diff.
