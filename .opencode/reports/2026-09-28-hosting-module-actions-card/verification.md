# Verification — Module actions card UX pass

Date: 2026-09-28 · Plan: `plan.md` · Scope: admin hosting detail, "Module actions" card

## Gate ladder — observed output

| Gate | Command | Result |
| --- | --- | --- |
| Format | `vendor/bin/pint --dirty` | PASS, 11 files |
| Presenter tests | `php artisan test --filter=VmStatusPresenter` | 8 passed (39 assertions) |
| Card UI | `php artisan test --filter=ProxmoxComputeCardUiTest` | 26 passed (190 assertions) |
| Admin page text | `php artisan test --filter=AdminModuleUiTest` | 13 passed (113 assertions) |
| Hyper-V UI (untouched) | `php artisan test --filter=HypervActionsUiTest` | 10 passed (109 assertions) |
| Hyper-V matrix | `php artisan test --filter=HypervVmProgressTest` | 26 passed (153 assertions) |
| Stranded recovery | `php artisan test --filter=HypervStrandedBuildRecoveryTest` | 7 passed (57 assertions) |
| Proxmox lifecycle | `php artisan test --filter=ProxmoxVmLifecycleTest` | 9 passed (29 assertions) |
| Guest reset | `php artisan test --filter=ProxmoxGuestPasswordResetTest` | 14 passed (67 assertions) |
| Client parity | `php artisan test --filter=ClientHostingVmActionsTest` | 27 passed (186 assertions) |
| **Integrated whole** | `php artisan test --testsuite=Feature` | **2548 passed (12846 assertions), 0 failed** |
| Build | `npm run build` | ✓ built (adminlte css rebuilt) |

## Independent adversarial pass (`verify`, F1-F7)

Verdict: **all seven confirmed**; two minor findings, both adjudicated below.

- **F1 confirmed** — terminated denies `create` in all four sub-cases; falsified in the reverse direction too: walked `pending`/`suspended`/`active`/`terminated` × VM-absent/present against `ManualProvisioner.php:124-129` and the `HostingController` guards. No direction disagrees. The `$rebuildingStale || !$hasActivePanel` rebuild path requires a non-terminated status, so it is untouched.
- **F2 confirmed** — `severity === null` iff `text === null`; precedence matches `permissions()`; no `success` variant; value set closed.
- **F3 confirmed** — non-terminated reason strings byte-identical; the frozen strings in `HypervVmProgressTest.php:212-256` still pass unmodified.
- **F4 confirmed** — all frozen DOM hooks present; `applyStatus()` cannot re-enable an action the presenter denies; the confirm/credential panels remain reachable.
- **F5 confirmed** — strip is truthful, absent when healthy, and survives a `$vmStatus` payload with no `notice` key.
- **F6 confirmed** — `ProxmoxComputeCardUiTest.php` diff is `218+/0`: purely additive, no assertion relaxed or deleted. Two writers touched that file concurrently; no duplicate methods, no lost test, `php -l` clean.
- **F7 confirmed** — new CSS is token-only, `.mh-module-*`-namespaced, dark-mode aware; no scope creep in the owned files.

### Findings adjudicated

1. **`data-compute-hint` now renders only inside the strip** (absent on a healthy VM). Nothing reads it unconditionally; the JS recreates it. Accepted — strict reading of B2 only.
2. **Terminated + running VM advised "Stop the VM first"** for delete — an impossible action, since stop is refused on a terminated account. **Fixed** in `VmStatusPresenter.php:365`: the reason now matches the other terminated reasons. This was the same defect class as D2, so it was closed rather than deferred. Presenter suites re-run green after the change.

## Rendered evidence (harness, not assertion)

Fresh server render of `admin/hosting/23` (`?v=fresh3`, terminated account, no VM), DOM read + capture:

- `Create VM [DISABLED]`, `Start [DISABLED]`, `Stop [DISABLED]`, `Restart [DISABLED]`, `Delete [DISABLED]`, `Reset password [DISABLED]`, `VM Console [DISABLED]`; `Credentials` the only enabled control.
- Status strip: `This service is terminated — VM actions are refused. Restore the account to re-enable them.` with `Last action failed: Hosting account #23 is not pending (status: terminated).` on a subordinate muted line.
- Group labels `LIFECYCLE` / `ACCESS` / `DANGER` on one baseline; every row button `h=31` at 1440px and 1152px; console-gateway reason retained under `ACCESS`; footnote trimmed.
- Captures: `.openchamber/screenshots/card-after-layout-fix-2026-09-28T06-58-24-649.jpg` (1440), `.openchamber/screenshots/card-at-1152-2026-09-28T06-58-51-344.jpg` (1152).

**Note on a misleading intermediate reading:** a geometry snapshot taken immediately after `browser.resize` reported the groups stacked (`y=461/526/640`); the capture taken moments later showed a single aligned row. The stacked reading was reflow mid-flight — the settled capture is authoritative. Recorded because it is exactly the kind of artefact that would otherwise be reported as a defect.

## Not verified

- The poll path's *presentation* after a live action (strip text and button variants are re-rendered client-side and drift from a fresh load — see plan residuals).
- A real Proxmox host round-trip; all coverage uses fakes/probes, as before this change.
- Dark mode was checked by reading the token remap, not by rendering.

## Working-tree note

`ProvisioningEventRecorder.php`, both dispatchers, `ServerController.php`, `ci.yml`, `routes/console.php`, `ProvisioningEventRecorderTest.php`, `ProxmoxTemplateCatalogTest.php`, `ProxmoxUnrecordedVmDestroyTest.php` and `tests/Fixtures/Modules/*` were already modified before this task started (mtime baseline) — another author's in-flight work. Not touched, not claimed, and green in the full-suite run above.
