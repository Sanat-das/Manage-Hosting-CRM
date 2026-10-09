# Verification — Centralize the logging system

Date: 2026-10-08 · Owner: build (orchestrator) · Change: ~75 modified + 13 new files (`git status` on `app/`; `git diff --stat`: ~641 insertions, ~393 deletions)

## Gates

| Gate | Result |
| --- | --- |
| `vendor/bin/pint --dirty --format agent` (whole-tree) | PASSED (re-run clean after every edit batch) |
| `php artisan test` — full suite, integrated change | 3236/3236 passed, 16275 assertions (~25 min) |
| `php artisan test` — post-fix re-run (F1–F3) | see footer |
| `php artisan test tests/Feature/Logging` | 18/18 passed (includes the 3 adversarial-fix tests) |
| `php artisan test tests/Unit/OrderServiceTest.php tests/Feature/OrderLifecycleProvisioningTest.php` | 18/18 passed |
| Slice-targeted suites (A/B/C/D1/D2) | 112 + 172 + 462 + 260 + 222 tests passed |
| `php -l` every new/changed file | no syntax errors |

## Runtime evidence

- Tinker smoke through `AppLog`: `security-*.log`, `provisioning-*.log`, `alerts-*.log` created; `{tag}` placeholder substituted; `ghp_*` → `***`; nested URL userinfo redacted; `{"cli":"tinker"}` extra present.
- **Live-stack probe** (real web server, not test kernel): `GET http://managehosting.local/up` → 200 with generated `X-Request-Id: 01M4DM1SNMJV9847VPTK6SVEQ2`; inbound `probe-e2e-1` echoed back.
- Repo audit: zero bare `Log::` severity calls in `app/`, `routes/`, `scripts/`; `modules/` → only the approved snmp migration; `database/` → only the approved orphan-audit migration (×3); `error_log` → only the guarded `ModuleServiceProvider` fallbacks; raw `file_put_contents` to log paths → zero (all `OpsFileWriter`).

## Defects caught during smoke (fixed before close)

1. Processors duck-typed → Laravel's `is_a(..., true)` validation failed → `LogManager::get()` silently downgraded channels to the emergency logger (laravel.log). Fixed (implements `ProcessorInterface`); `LoggingPipelineTest` asserts real handler class+path per channel so this cannot return silently.
2. Monolog 3.12 `LogRecord` readonly — processors use `$record->with(...)`.
3. Test expectation for rotated filename corrected (Monolog inserts the date before the extension).

## Adversarial pass (independent verify subagent)

Findings raised: 6. Disposition:

| # | Severity | Finding | Disposition |
| --- | --- | --- | --- |
| F1 | major | Alert→Slack branch (`driver => slack`) silently dropped all processors — unredacted records to a third party | **FIXED**: alert channel always `monolog` driver with conditional handler (`SlackWebhookHandler` vs `RotatingFileHandler`, config/logging.php:175-195); processors kept in both branches; 2 regression tests added |
| F2 | major | Whole-`$e` context objects bypassed redaction (8 billing call sites) | **FIXED**: `RedactingProcessor` rewrites `Throwable` to redacted `[class, message, file]`; test `test_throwables_in_context_are_redacted_not_bypassed` |
| F3 | minor | `OrderService` auto-provisioning failures on `billing` channel, contradicting domain routing | **FIXED**: 3 lines → `provisioning`; `Order left in provisioning…` stays billing by design |
| F4 | minor | `static::` → `self::` + import/style churn | **CONFIRMED AS PINT-STANDARD**: laravel preset `self_static_accessor => true` (extracted from `vendor/laravel/pint` phar); kept |
| F5 | minor | `SecretRedactor` covers only URL-userinfo + GitHub token formats | **DOCUMENTED**: explicit NOT-covered list added to its docblock (narrowness intentional) |
| F6 | minor | `OpsFileWriter` check-then-rotate not atomic across processes | **DOCUMENTED**: single-writer contract note added to its docblock |

Re-verification (same verifier session, read-only): **all four actionable items FIXED, no new findings** — independent probes of both alert branches (full production key set, no emergency fallback), Throwable redaction live probe, channel diff review; logging suite 18/18.

Not falsifiable from the pass (stated, not silently dropped): genuine 500-path correlation header (reasonable equivalence to the proven 404 path — Laravel's Pipeline renders exceptions and returns the response through middlewares); full keep-3 archive eviction chain (code-proven, single rotation executed); cross-process rotation race (documented single-writer contract).

## Approved exceptions

- `modules/snmp-monitor/database/migrations/…:142`, `database/migrations/2026_08_28_000009_…:48,125,131` — historical migrations, untouched; flow into `app.log` via the default channel.
- `app/Providers/ModuleServiceProvider.php:60,111` — `error_log` as deepest guarded fallback only (docs/iis-deployment.md:127-133).
- `tests/**` `Log::spy()` — intended mocking.

## Residual risks

- Context Throwables keep `file:line` but not a stack trace — parity with the previous formatter output (no trace was rendered before either); documented in the processor.
- Redaction is bounded by `SecretRedactor`'s pattern list — now explicitly documented.
- Production `.env` not visible from here: deployed instances need `LOG_STACK=app` + `LOG_RETENTION_DAYS=30` (or rely on the config defaults, which now read `app`/30 when unset).
- The legacy 579 MB `laravel.log` remains on disk, inert (no longer written); archive or delete manually at leisure.
- `update.log`/`rollback.log` gain their first-ever size cap (10 MB, 3 archives) — existing tails are unaffected until cap.

## Footer

Post-fix full-suite re-run: **PASSED — 3239/3239 tests, 16,286 assertions (~23 min)** — includes the three adversarial-fix regression tests. Change size at close: 76 files changed (652 insertions, 393 deletions) + 12 new files.
