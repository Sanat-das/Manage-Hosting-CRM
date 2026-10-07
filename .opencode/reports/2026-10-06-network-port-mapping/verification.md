# Verification report: Network device port mapping & cable management (v1)

- Date: 2026-10-06 · Plan: `.opencode/reports/2026-10-06-network-port-mapping/plan.md` (corrected copy — authoritative)
- Verdict: **Verified.** All gates green; one display defect found during the browser pass, fixed, and re-verified live. Accepted residuals listed in §4.

## 1. Gate ladder (all re-run on the frozen final tree by the orchestrator)

| Gate | Command | Result |
| --- | --- | --- |
| Slice A | `php artisan test --compact tests/Feature/DevicePortSchemaTest.php tests/Feature/SeederIntegrityTest.php` | 14 passed / 101 assertions |
| Slice B1 | `php artisan test --compact tests/Feature/DevicePortCrudTest.php tests/Feature/DevicePortSchemaTest.php tests/Feature/SeederIntegrityTest.php` | 27 passed / 158 assertions |
| Slice C | `php artisan test --compact tests/Feature/SnmpPortImportTest.php tests/Feature/SnmpPollingPipelineTest.php tests/Feature/SnmpInventoryDiscoveryTest.php` | 32 passed / 314 assertions |
| Slice B2 | `php artisan test --compact tests/Feature/PortConnectionTest.php tests/Feature/DevicePortCrudTest.php` | 25 passed / 110 assertions |
| Adversarial (verify agent, Slice D) | full ladder incl. `seed-smoke.sh`, `route:list`, falsification attempts | verified-with-findings → 2 coverage gaps closed (see §3.2) |
| Final integrated | `php artisan test --compact tests/Feature/DevicePortSchemaTest.php tests/Feature/DevicePortCrudTest.php tests/Feature/PortConnectionTest.php tests/Feature/SnmpPortImportTest.php tests/Feature/SnmpPollingPipelineTest.php tests/Feature/SnmpInventoryDiscoveryTest.php tests/Feature/SeederIntegrityTest.php` | **75 passed / 552 assertions** |
| Lint | `php vendor/bin/pint --dirty --format agent` | clean |
| Seed chain | `bash scripts/seed-smoke.sh` (scratch sqlite, never MySQL) | **ALL PASS** — 4 new migrations in the fresh chain; admin holds 108/108 permissions; re-seed idempotent |
| Routes | `php artisan route:list --path=admin/port(s)` + verifier `--name` runs | 9 routes; methods, names, middleware match plan §4.1 |
| Dev DB | `php artisan migrate` (MySQL `local`) | 4 migrations applied — the running app 500-ed before this (see §3.1) |

## 2. Browser pass (live app `http://managehosting.local`, admin session — driven, not code-only)

- Inventory index: **Connections** tools link present next to Dependency Tree/Relationships; sidebar **Connections** entry present.
- `DEMO-SWT-0001` (switch) show page: **Ports** card renders — badge, empty state, Add Port modal with full field set.
- Added `Gi1/0/1` and `Gi1/0/2`; connected them via the peer-port picker: debounced search returned only the free peer (source port excluded), **submit stayed disabled until a peer was chosen**, flash "Ports connected."; **Recent cable changes** card shows the `connected` event with actor.
- `/admin/port-connections`: cable row renders with both endpoints; search filter applied → real empty state ("No port connections found."); Reset; Export CSV clicked (download request issued; CSV content asserted by the dedicated filtered-export test — not visually inspected).
- Mobile viewport (390×844): layout holds (stacked filters, horizontally scrollable table); no console errors on final pages.

### 2.1 Defect found & fixed during the browser pass

- **Symptom**: a same-asset cable (`Gi1/0/1 ↔ Gi1/0/2` on one switch) rendered only the A-side row as connected; the B-side row showed `—` and offered "Connect" instead of "Disconnect"; badge read "1 connected".
- **Cause**: `app/Http/Controllers/Admin/InventoryAssetController.php:346-356` wrote one connection-map entry per cable, keyed only by the A-side local port.
- **Fix**: the loop now writes an entry per local side (`:346-362`); cross-asset cables still yield exactly one entry.
- **Regression tests**: `tests/Feature/PortConnectionTest.php` — both sides of a same-asset cable render peer + Disconnect; cross-asset single-side rendering asserted.
- **Re-verified live**: badge "2 ports · 2 connected"; both rows show peer, cable, Disconnect.

## 3. Notes / deviations

1. **Dev DB behind**: first browser attempt returned HTTP 500 (`Table 'local.device_ports' doesn't exist`) because the tests migrate a fresh sqlite while the running MySQL dev DB had not run the new migrations. Applied with `php artisan migrate` (additive only). Any deployment must run migrations as usual.
2. **Coverage gaps closed** (found by the adversarial pass, tests added): filtered CSV export end-to-end; SNMP ifIndex renumbering heal (the R1 mitigation) — both green.
3. **Plan corrections applied pre-implementation** (part of the authoritative plan): citation fixes; §6.2 refresh-on-fallback semantics; ownership fixes — `PortConnectionConflictException` → slice A, `show.blade.php` include → B1, inventory `index.blade.php` link → B2.

## 4. Accepted residuals (documented, non-blocking)

- **R3 cross-column raw-SQL bypass**: one-cable invariant is enforced at model/service layer for all Eloquent writers; DB uniques cover the same-column case; raw SQL cross-column insert bypasses (documented in plan §9). Attempted and confirmed as the sole bypass.
- `snmp:sync-ports` (like the pre-existing `snmp:maintain-partitions`) is not listed by bare `php artisan list` in this env — module registration mirrors the existing pattern; `schedule:list` shows `10 4 * * *` and tests prove the command works.
- Connections index keeps the Actions header for view-only users (buttons hidden) — matches the index-page convention; cosmetic drift from §5.2 wording only.
- N+1 freedom on show/index is inspection-verified (eager loads + PHP-side maps), not query-count-asserted.
- Concurrent double-connect serialization is lock-then-check reasoned, not load-tested.
- **Browser-pass demo data left in the dev DB**: ports `Gi1/0/1`, `Gi1/0/2` + cable `browser-check-cable` on DEMO-SWT-0001 (+ one ledger event). Disconnect/delete in the UI to remove.

## 5. Evidence files

- `.openchamber/screenshots/port-connections-index-2026-10-06T12-36-11-995.jpg`
- `.openchamber/screenshots/ports-card-connected-2026-10-06T12-38-19-253.jpg`
- (project root: `C:\Users\Administrator\Local Sites\managehosting\`)

## 6. Addendum — 2026-10-07: picker shows connected ports with status

User feedback on the live UI: the peer-port picker only listed free ports, so occupied ports appeared not to exist. Contract amended in plan §4.3/§5.1 (same date):

- `admin.ports.search` with `include_connected=1` now returns connected ports too, free-first (stable name order within each group), each carrying `"disabled": true` and meta appended `connected to <peer asset tag> <peer port name>` (bare `connected` when the peer is unavailable). `disabled` is always present (false for free ports). Default mode (no flag) is unchanged.
- The connect modal sends `include_connected=1`; the picker renders disabled entries muted (opacity .75, half-transparent text) and skips them in both click and keyboard navigation. Occupied ports are informational — the one-cable-per-port invariant means they cannot be chosen; re-cabling = disconnect first.

Evidence:
- `php artisan test --compact tests/Feature/DevicePortCrudTest.php tests/Feature/PortConnectionTest.php` → 30 passed / 141 assertions; `pint --dirty` clean.
- Live (asset DEMO-SRV-0001, port E1): searching "Gi1/0" lists both occupied switch ports with their `connected to …` annotations; clicking a disabled entry is rejected by the browser ("Element is disabled"); the Connect submit stays gated.
- Screenshot: `.openchamber/screenshots/picker-connected-ports-2026-10-07T05-37-53-751.jpg`

Noted follow-up (not a v1 defect): the endpoint `limit 20` applies before the free-first partition, so with >20 matches on a large device free ports could in theory be crowded out of the result window; the picker renders at most 8 entries anyway, so narrowing the query is the intended flow. Revisit if a broad 48-port search proves annoying.
