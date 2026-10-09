# Verification — Log Retention settings tab

Date: 2026-10-09 · Feature: log-stream retention editable in Settings → Log Retention

## Gates

| Gate | Result |
| --- | --- |
| `tests/Feature/LogRetentionSettingsTest` (round-trip · validation · daysFor) | 4/4 passed |
| `tests/Feature/Audit/PruneLogsCommandTest` (+ settings-override window test) | passed |
| `TypedSettingsTest` · `SettingsAuditTest` · `SettingsConcurrencyTest` · `SeederIntegrityTest` | passed (33 total in the batch) |
| `AdminSettingsInventoryTest` (inventory guard re-baselined for +8 fields / +3 section groups / 18th typed group) | 5/5 passed |
| `pint` (explicit files) | clean |
| `php artisan migrate --force` | `2026_10_09_000006_seed_log_retention_settings` DONE |
| `pint --dirty` + full suite | see footer |

## Browser evidence (live admin session, real HTTP)

- `GET /admin/settings?tab=log_retention` renders the tab (Automation group) with all 8 fields and defaults 180 / 365 / 90 / 90 / 90 / 30 / 365 / 0, help text per field, no console errors.
- **Save round-trip driven for real**: changed Activity Log 180 → 179 → "All settings saved." → audit footer `Settings updated (log_retention): changed activity_retention_days` → reverted to 180 → saved again. Database confirms: `settings_properties` row payload `"180"`; `activity_log` rows 379/380 carry the two `settings.updated` audit entries for section `log_retention` (with causer + changes).
- Mobile (390×844) render checked — fields and values intact.
- Screenshot: captured in the browser panel (`log-retention-tab`).

## Deliberate decisions

- `config/audit.php` remains the DEFAULTS source; settings override at runtime via `LogRetentionSettings::daysFor()` (single resolution point for both commands).
- Consent uses `0 = keep forever` (blank keeps the current value, per the app's Option A save semantics); all other fields are min 1 / max 3650.
- New property rows are seeded by a migration (`SettingsPropertySeeder`) — upgrades get the tab without re-seeding; consistent with the project's house pattern.

## Adversarial pass

**VERDICT: FALSIFIED (3 minors)** — all closed with regression tests:

| Finding | Disposition |
| --- | --- |
| A stray `0` written around validation into a non-consent property would prune that entire stream (`subDays(0)`) | **FIXED** — non-positive windows on non-consent streams now fall back to the config default; `test_a_stray_zero_in_a_non_consent_property_falls_back_to_config` |
| `catch (Throwable)` in `daysFor()` was silent and over-broad (a future property-name typo would be masked) | **FIXED** — `report($e)` added, matching the `SettingsController::loadAll` convention |
| `app:cleanup --days=abc` (or `0`/negative) cast to 0 and would wipe both streams | **FIXED** — rejected with `FAILURE` before any delete; `test_cleanup_command_rejects_a_non_positive_days_override_without_deleting`; HTTP rejection of `0` on days fields also now tested |

Verifier could not execute (stated, not dropped): settings-table-missing fallback (corrupt-payload path proven via transactional probe), the CleanupCommand per-stream null "kept forever" execution (code-read only), full suite (ran separately).

Integration gate additionally caught **4 `AdminSettingsInventoryTest` failures** the slice missed — the page's hardcoded inventory guard (182 fields / 13 groups / 21-query budget). Re-baselined to 190 fields, 16 groups, 22 queries with comments updated; guard now green.

## Footer

Post-fix full suite: **PASSED — 3280/3280 tests, 16,460 assertions (~23 min)**, `pint --dirty` clean. Final feature delta vs. the pre-feature suite: +8 settings fields, +1 settings class, +3 regression tests after the adversarial round.

- Existing `cron_log_cleanup_days` setting remains unread (pre-existing; unrelated to these streams) — noted, not touched.
- Numeric fields rely on the page's error summary + tab auto-switch (project convention — no per-field @error blocks on numerics).
