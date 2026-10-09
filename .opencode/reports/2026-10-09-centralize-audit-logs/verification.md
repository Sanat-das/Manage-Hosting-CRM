# Verification — Centralize Audit/Activity + Email logs

Date: 2026-10-09 · Owner: build (orchestrator) · 6 slices + infra + adversarial verify + fix round

## Gates

| Gate | Result |
| --- | --- |
| `vendor/bin/pint --dirty` (whole tree) | PASSED (re-run clean after fix round) |
| `php artisan test` full suite (integrated change) | 3259/3259 passed, 16,354 assertions (~25.5 min) |
| `php artisan test` full suite (post-fix re-run) | see footer |
| `tests/Feature/Audit/AuditRecorderTest` | 5/5 (created_at · request_id · redaction · failure→security-log · streams) |
| `tests/Feature/Audit/PruneLogsCommandTest` | passed (per-stream windows; consent never pruned) |
| `tests/Feature/EmailLogHardeningTest` | 6/6 (log_key dedup · attempts · distinct rows · resend · failure-append · body redaction) |
| `tests/Feature/Admin/LogsHubTest` | 9/9 (forbidden/allowed per page; blades render via HTTP) |
| Slice suites (S1 718 · S2 ~275 · S3 85 · S4 82 · S5 51) + fix batch 60 | all passed |
| `php artisan migrate` (local) | 4 migrations applied (request_id ×2, emails hardening, log prune indexes) |
| Live smoke via recorder | rows in all four streams with `created_at`; `request_id` set (HTTP) / null (CLI) |

## What changed

- **Recorder** (`app/Support/Audit/AuditRecorder.php` + `AuditEvent`, 41 canonical keys): guaranteed `created_at`, `request_id` correlation with the file-log pipeline, recursive SecretRedactor over metadata/details/payload/error, failure → `AppLog::security()->error`. Null-module diagnostics supported (`?int`).
- **Writers migrated** (zero direct `ActivityLog|AuditLog|ModuleLog|DomainSyncLog::create` remain outside the recorder): OrderActivityLogger · HostingService (entityRef `hosting_account` + activity mirror, public API unchanged) · LogCustomerLifecycle · AddOn/UpgradeRequest · ChatService (entityRef) · Admin+Api Customer/User/ProductOptionLink/Impersonation(Settings) · SettingsController · UpdateService (system.updated/rolledback) · ServiceInstanceController (entityRef `service_instance`) · ModuleContext/RunModuleCapability/ModuleManager/ModuleMigrationRunner · SyncDomainPricingCommand.
- **Entity compatibility**: `entityRef` with literal aliases — no morph map exists, so generic `entity()` would rewrite `entity_type` to FQCNs (documented trap).
- **Retention**: `logs:prune` reads `config/audit.php` (activity 180 · audit 365 · emails 90 · module 90 · domain-sync 90 · domain-search 30 · invoice-pdf 365 · consent never); scheduled weekly; `app:cleanup` kept manual (`--days` override). `created_at` indexes added on the previously unindexed prune targets.
- **Email**: log_key UUID (retry-stable) · attempts · payload (array cast) · from_email · template/customer persisted (invoice = real `$templateName`) · errors appended · schema default `'sent'`→`'queued'` · logged body redacted · resend action (`email.manage`) with faithful payload replay · UI row links + full detail + flash.
- **UI**: Logs submenu — gate-only `admin.panel` parent, children gated individually (Email Logs · Activity Log · Audit Trail · Module Logs · Domain Logs (sync+search tabs) · Consent Log); Activity page reads both writer schemes (`action ?? event` everywhere incl. filter/search/dropdown).

## Defects closed during integration

1. NULL `created_at` class (live-proven pre-fix: audit_log 129/129, activity_log 174/308) — recorder guarantees forward; legacy NULLs inert (documented).
2. ModuleManager null-module diagnostic would re-NULL — recorder widened to `?int`.
3. `EmailLog` fillable/payload-cast gap — closed.
4. Invoice template_name generic — now actual.
5. Logs menu parent hid Email Logs from `email.view`-only staff — gate-only parent now.
6. Activity page dual-scheme blindness — fixed.

## Adversarial pass (independent verify subagent)

**VERDICT: FALSIFIED-NONE.** Five minor findings, all reproduced; dispositions:

| Finding | Disposition |
| --- | --- |
| F1 Orphan hosting account lost customer id in the activity mirror | **FIXED** — detached-stub fallback preserves the id (`HostingService.php`); hosting suites green |
| F2 CLI `user_agent` stored `''` instead of NULL | **FIXED** — null-preserving in both recorder methods |
| F3 `logs:prune` range-deletes unindexed on 3 tables | **FIXED** — migration 000004 adds `created_at` indexes (applied) |
| F4 Email failure-append + body-redaction paths untested | **FIXED** — 2 tests added (single row · attempts=2 · `[attempt 2]` · redacted body/payload) |
| F5 Empty Logs group for permission-less roles | **ACCEPTED** — documented; optional menu-filter improvement later |

Not falsifiable from the pass: migration `down()` execution (read-verified; no rollback run), UserController history both-kinds live probe (MySQL JSON-path proven by query; code read), browser click-through of new pages/pagers (HTTP render-asserted only).

## Residual risks

- `entity()` remains an FQCN trap until a `Relation::morphMap` exists — use `entityRef` or register one.
- Resend replays the stored (redacted/plain) body; `htmlBody` never stored — welcome/rich mails resend degraded by design; **resend ≠ re-issue**.
- Redaction bounded by SecretRedactor's pattern list (URL userinfo + GitHub tokens) — documented in its docblock.
- Legacy NULL-`created_at` rows (303) remain un-pruneable and sort last — inert, documented.
- Smoke rows exist in the local dev DB (activity_log ids 375-376 etc.) — harmless local artifacts.
- `app:cleanup --days=` with an empty value casts to 0 (pre-existing behavior, not introduced here).

## Footer

Post-fix full-suite re-run: **PASSED — 3268/3268 tests, 16,397 assertions (~24 min)**, includes the two adversarial-fix tests. `pint --dirty` clean. Change size at close: 128 dirty files / 32 untracked across the two logging tasks (file logs + audit logs); today's set ≈ 30 files + migration 000004.
