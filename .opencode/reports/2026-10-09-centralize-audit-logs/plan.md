# Plan: Centralize Audit/Activity + Email logs

Date: 2026-10-09 · Executed by: build (orchestrator) + 6 general slices (2 waves) + verify

## Goal

One recording layer for every DB-backed log stream, hardened schemas, a unified admin Logs
section, and config-driven per-stream retention. Scope chosen by user: Recorder + hardened
streams + Logs hub; hardened per-message email + resend; per-stream retention defaults.

Acceptance: `vendor/bin/pint --dirty` clean; `php artisan test` green; recorder always
stamps `created_at` + `request_id`; every activity/audit writer routes through the recorder;
emails rows carry `log_key`/`attempts`/`payload`; `logs:prune` prunes per `config/audit.php`;
new Logs pages render with correct permission gating (SeederIntegrityTest green).

## Frozen contracts (do not deviate)

- `App\Support\Audit\AuditEvent` (string enum) — 36 canonical activity keys, values are the
  EXACT legacy strings (order_created, hosting.created, settings.updated, user_created, …).
- `App\Support\Audit\AuditRecorder`:
  - `activity(AuditEvent|string $event, ?Customer $customer = null, array $metadata = [], ?string $description = null, ?Model $subject = null, ?int $actorId = null): void`
  - `entity(string $action, Model $entity, array $details = [], ?int $actorId = null): void`
  - `entityRef(string $action, string $entityType, int|string|null $entityId, array $details = [], ?int $actorId = null): void` (deleted-record trails — chat)
  - `module(?int $moduleId, string $event, ?int $serviceInstanceId = null, string $status = 'info', ?string $error = null): void`
  - `domainSync(string $provider, string $operation, string $status, ?array $payload = null, ?string $error = null): void`
  - Always: `created_at` explicitly set; `request_id` stamped from `RequestContext`;
    metadata/details/payload/error redacted via `SecretRedactor`; write failure →
    `AppLog::security()->error` (never silent). String `$event` is the escape hatch for
    dynamically-composed actions (hosting module verbs); fixed vocabulary uses the enum.
- Models keep their fillable sets; recorder sets non-fillable columns (`created_at`,
  `event`, `subject_*`, `user_agent`, `request_id`, `properties` not used) via property
  assignment before `save()`.
- `config/audit.php` retention keys: activity_log 180 · audit_log 365 · emails 90 ·
  module_log 90 · domain_sync_log 90 · domain_search_logs 30 · invoice_pdf_log 365 ·
  marketing_consent_log null (never). cron_task_runs stays with RecordScheduledTaskRun.
- Migrations (mine, additive/reversible, one per table):
  `add_request_id_to_activity_log`, `add_request_id_to_audit_log`, `harden_emails_table`
  (log_key string36 indexed · attempts tinyint · from_email · payload json · index
  (status, created_at) · status default 'sent'→'queued').
- Email (SendEmail.php): `log_key` = `(string) Str::uuid()` generated in constructor;
  resolveLog matches by log_key first (fallback to legacy match for retried OLD jobs);
  attempts incremented on each resend/retry attempt; error appended, not overwritten;
  `template_name`/`customer_id`/`from_email`/`payload` (constructor args for resend) stored;
  logged body always passes SecretRedactor.
- Resend: `POST admin/email-logs/{emailLog}/resend` gated `email.manage`, re-dispatches
  `SendEmail` from stored `payload` (fidelity as long as attachment files exist).
- UI (Logs section, `can` = route `permission` on every new item):
  new pages `admin/audit-log` (audit.view) · `admin/module-logs` (module_logs.view) ·
  `admin/domain-logs` (domain_logs.view, two tabs: sync + searches) · `admin/consent-log`
  (consent.view); SYSTEM menu gets a Logs submenu holding Email Logs, Activity Log, Audit
  Trail, Module Logs, Domain Logs, Consent Log; email index rows link to show; email show
  renders template/customer/from/cc/bcc/attempts/error + resend; flash-alert added to
  email views.
- New permissions added to `database/seeders/AdminLteRbacSeeder.php` inventory ONLY:
  audit.view, module_logs.view, domain_logs.view, consent.view.

## Slices (disjoint ownership)

Wave 1:
- S1 services writers: OrderActivityLogger, HostingService, LogCustomerLifecycle,
  AddOnService, UpgradeRequestService, ChatService → recorder.
- S2 controllers/raw writers: Admin+Api CustomerController? (CustomerController helper),
  Admin/UserController (+reader fix user_id fallback), Api/UserController,
  ProductOptionLinkController, ImpersonationController, SettingsController:529,
  UpdateService:2694/3173 (audit row only), ServiceInstanceController:156.
- S3 module/domain writers + retention: ModuleContext, RunModuleCapability, ModuleManager,
  ModuleMigrationRunner, SyncDomainPricingCommand; PruneLogs command (`logs:prune`,
  config-driven), CleanupCommand delegates (activity/emails via config; --days stays as
  manual override), schedule: replace `app:cleanup --days=90` weekly with `logs:prune`.
- S4 email: SendEmail hardening + EmailLogController resend + email blades (index links to
  show; show detail fields + resend button; flash-alert) + routes/admin/email.php.

Wave 2:
- S5 Logs hub pages: routes (routes/admin/settings.php + new pages), controllers, blades,
  menu submenu, seeder permissions, feature tests.
- S6 (merge into S5 if needed): UI tests for permission gating.

## Verification

1. Pint (explicit paths per slice; final `pint --dirty`).
2. Tests: new recorder unit (created_at/request_id/redaction/failure escalation), retention
   command test, email hardening tests (log_key dedup, attempts, payload, resend), UI feature
   tests; update affected existing tests (SeederIntegrityTest auto, QueueSchedulerTest
   schedule string, any created_at workarounds).
3. Full `php artisan test`; local `php artisan migrate` + live smoke rows inspected via
   database-query (created_at + request_id present).
4. Adversarial verify pass; fix findings; re-verify.

## Deliberate light-touch (documented)

- Historical NULL created_at rows: left as-is (small, inert; new writes guaranteed).
- `automation_log`, `cron_logs`, `email_queue`: dead tables kept (zero risk), documented.
- PdfLogService / DomainSearchLog / consent writers: already timestamp-correct; stay
  direct (no recorder churn) — surfaced in UI instead.
- Vendor AdminLTE auth rows (login/logout/failed): vendor writes correctly; reader/UI
  unifies display (action ?? event) rather than fighting the vendor.
