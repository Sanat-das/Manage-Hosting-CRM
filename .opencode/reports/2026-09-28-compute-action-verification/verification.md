# Verification — admin compute actions, hosting #23 (Proxmox VE)

Date: 2026-09-28 · Surface: `/admin/hosting/23`, `#compute-panel-proxmox` · Environment: Local (Windows), MySQL `local`, queue `database`.

## Acceptance — verdict

| # | Acceptance | Verdict | Decisive evidence |
| --- | --- | --- | --- |
| 1 | Create VM dispatches a real build and records the true outcome | PASS | event #99/#100/#101 `failed` with `Hosting account #23 is not pending (status: terminated).` — pre-fix rows showed the bogus `Interrupted — …` verdict |
| 2 | Failed action stays readable in the card and survives reload | PASS | card alert text after poll and after reload; `pollOnce` no longer reloads on `failed` |
| 3 | Test suite + lint | PASS | adversarial full run 2695 passed (13444 assertions) exit 0; current-tree focused runs: recorder/destroy/reconcile/action-status 34 passed (197 assertions), compute card 26 passed (190 assertions), Hyper-V template 15 passed (63 assertions); `pint --dirty` PASS. A later full run showed 2 failures that were mid-edit artifacts of a concurrent workstream (see "Concurrent workstream" below) — both files' current versions pass |
| 4 | Browser evidence for every reachable control | PASS | Create ✅, Credentials ✅ (reveal, no error box; username `root`; password not captured), Start/Stop/Restart/Delete/Reset password disabled (opacity .65) for a VM-less terminated service, VM Console disabled with visible reason `The console gateway is not configured — set GUACAMOLE_SECRET.` |

## Root cause (proven, not inferred)

`ProvisioningEventRecorder::begin()` tracks the row in a per-process static list and a `register_shutdown_function` guard
marks every still-tracked row `failed` / `INTERRUPTED_MESSAGE` at PHP process end. Commit `b21df8e1` moved every mutating
VM action to a queued job that closes the event in another process, so the guard killed each freshly queued event at
web-request teardown — before any worker ran — and the job's safety net then refused to overwrite the failed row with the
real error. Live proof: in-process dispatch → row `running`, job pushed; seconds later (new process) → `failed`/Interrupted.

## Fixes (this session)

| File | Change |
| --- | --- |
| `app/Services/Provisioning/ProvisioningEventRecorder.php` | `handOff()` (untrack after queue push), `flushOpenEvents()` (testable guard seam) |
| `app/Services/Provisioning/VmBuildDispatcher.php:71` | `handOff` after `ProvisionComputeVm::dispatch` |
| `app/Services/Provisioning/VmOperationDispatcher.php:125,234` | `handOff` after `RunVmOperation::dispatch` (both entry points) |
| `app/Http/Controllers/Admin/ServerController.php:1735` | `handOff` after `RunUnrecordedVmDestroy::dispatch` |
| `resources/views/admin/hosting/partials/_compute-actions.blade.php` | poller keeps failures on screen (no reload), surfaces stale/interrupted verdicts, server pre-renders last failure into `[data-compute-feedback]` |
| `routes/console.php` | `queue-provisioning-cron`: per-minute, exit-when-idle drain for the `provisioning` queue |
| tests | `ProvisioningEventRecorderTest`, `ProxmoxUnrecordedVmDestroyTest`, `ProxmoxComputeCardUiTest` |

## Live evidence (browser: OpenChamber panel, user's authenticated session)

- Pre-fix create: event #97/#98 `failed` / `Interrupted — the process ended before the action finished.`; no visible feedback after the auto-reload.
- Post-fix create: click → 202 → worker ran the job (`stage` advanced `queued`→`checking`→`cloning`, `jobs` queue drained to 0) → card showed the real account-status message; progress widget closed; Create re-enabled; message persisted across a manual reload.
- Stale/interrupted path: event #102 frozen `running` + backdated 40 min (job pulled from the queue) → poller showed
  `The build was interrupted before it finished — retry the create.`, no reload, controls live.
- Queue drain: with no manual worker running, a pushed job was consumed by the scheduled drain (pids 9768 @ 11:23:07 and 11480 @ 11:24:08, exact args `--tries=1 --timeout=1800 --stop-when-empty`, exiting when idle).
- Credentials reveal (Get `vm-credentials`): success, error box hidden, username `root`; `reveal_credentials` audit row visible in the History tab.

## Adversarial verification (`verify` agent)

| Finding | Verdict | Disposition |
| --- | --- | --- |
| Queue-down: pushed-but-unconsumed job strands the event ~35 min | CONFIRMED | resolved by `queue-provisioning-cron` drain + live proof |
| Poller swallowed stale-running verdicts | CONFIRMED | resolved by explicit `status === 'running'` branch + live proof |
| `hideFeedback()` leaves `alert-danger` on the hidden pre-rendered alert | CONFIRMED | no action — `showFeedback` resets `className`; cosmetic only |
| Unrelated files modified in the working tree (`ci.yml`, `servers/edit.blade.php`, `ProxmoxTemplateCatalogTest.php`) | CONFIRMED | pre-existing before this session; left untouched |
| Missing `handOff` on some queued path / guard no longer catches orphans / Blade conflicts / weakened tests | REFUTED | fix holds; all 10 `begin()` sites traced, 4 queued ones hand off after the push |

## Not verified live (explicit scope boundaries)

1. **A successful create and the power verbs against a real VM** — would provision/alter resources on `DT-Proxmox`; covered by the suite (`ProxmoxVmLifecycleTest`, `ProxmoxProvisioningTest`, `HypervVmProgressTest`), not by a live click.
2. **Typed-confirm restart/delete** — unreachable on #23 (buttons disabled while no VM exists); server guards covered by tests.
3. **VM Console** — gateway unconfigured on this host (visible reason), so only the disabled state is verifiable.
4. **Browser JS via Playwright** — verified in the OpenChamber browser (DOM assertions) instead; no Playwright run.

## Open questions for the operator

1. A terminated service keeps an enabled **Create VM** button by design (`VmStatusPresenter::permissions()` says "Create
   re-provisions"), but `ManualProvisioner` refuses any account that is not pending/suspended — so that click always
   fails with `Hosting account #23 is not pending (status: terminated).` Which side should change?
2. Disabled Start/Stop/Restart carry no visible reason (server reasons are not rendered as titles), unlike
   Reset/Credentials/Console. Add titles?
3. `snmp-poll` has 141 stuck jobs, no consumer, and the `snmp_targets` table is missing (scheduler errors every minute).
   Unrelated to this task; needs its own decision.

## Concurrent workstream (tree collision — read before re-running tests)

While this verification ran, a parallel workstream (`.opencode/reports/2026-09-28-hosting-module-actions-card`) rewrote the
compute card presentation and its test file: `_compute-actions.blade.php` mtime 11:41, `ProxmoxComputeCardUiTest.php`
mtime 11:43, `git diff --numstat` 288/95 and 218/0. Their design replaces the `[data-compute-feedback]` pre-render with a
`[data-compute-notice]` status strip (`role="status"`, `aria-live="polite"`) that carries the failure verdict
(`Last action failed: …`, reusing this session's `$computeFailureText`) plus live presenter notices; the feedback hook
stays empty for the poller.

Consequences for this report:

- My PHP fixes and the queue drain are untouched by that workstream (recorder 32/3 mtime 10:43; dispatchers 2/0, 4/0;
  `ServerController` 5/1; `routes/console.php` 13/0 mtime 11:18); their tests pass on the current tree.
- My two card follow-ups (keep the failure on screen, surface the stale verdict) were **superseded** by that rewrite —
  the intent survives in their strip. This session made no further edits to the card file after that point.
- The final full-suite run (11:40–12:00) reported 2 failures (`ProxmoxComputeCardUiTest` and
  `ProductHypervTemplateRestrictionTest`) that were mid-edit artifacts of the concurrent saves; both files' current
  versions pass (26 and 15 tests). No failing gate remains attributable to this session's changes. A full-suite run at
  the current revision was not attempted — the revision is still in flux.

## Test-data disclosure

`provisioning_events` rows created during this verification: #97 (pre-fix click), #98 (CLI probe), #99 (post-fix click),
#100 (an additional create attempt at 11:10:38, admin id 1 — not initiated by this verification), #101, #102 (stale-path
test). All are `failed` with either the bogus `Interrupted` verdict (pre-fix) or the true account-status message (post-fix).
They were left in place for audit integrity. The manual `provisioning` worker was stopped for the stale-path test and is
running again at the end of the session.
