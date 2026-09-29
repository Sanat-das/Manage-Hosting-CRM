# Plan: admin compute actions — verify + fix (hosting #23)

Date: 2026-09-28 · Surface: `/admin/hosting/23` compute panel (`#compute-panel-proxmox`), admin UI.
Session goal: verify the Create VM flow and every other action/error path through the admin UI, and resolve what is broken.

## Acceptance

1. Clicking Create VM in the card dispatches a real queued build; the job runs and the event records the true outcome
   (not "Interrupted — the process ended before the action finished.").
2. A failed action's message stays readable in the card and survives a page reload.
3. `php artisan test` green for the touched areas; `vendor/bin/pint --dirty` clean.
4. Browser evidence for every reachable control on /admin/hosting/23.

## Root cause found (proven live, not inferred)

`ProvisioningEventRecorder::begin()` tracked the event in a per-process static list and registered a shutdown guard that
marks every still-tracked event `failed` / `INTERRUPTED_MESSAGE` at PHP process end. Commit `b21df8e1` ("queued process
handling for every VM operation") moved every mutating action to a queued job that closes the event **in another
process** — so the guard killed each freshly queued event at web-request teardown, before any worker ran, and the job's
safety net then refused to overwrite the failed row with the real error.

Live proof: in-process dispatch → event `running`, job pushed to `provisioning`; seconds later (new process) → event
`failed` / Interrupted. Queue consumer (`queue:work --queue=provisioning`) was running the whole time (PID 6460).

## Contracts frozen before parallel work

- `ProvisioningEventRecorder::handOff(ProvisioningEvent $event): void` — idempotent, never throws; stops tracking the id
  so the shutdown guard leaves the row alone. Call **immediately after** a successful queue push.
- `ProvisioningEventRecorder::flushOpenEvents(): void` — public static form of the shutdown-guard body (testable seam).
- Exactly 4 queue-push sites need the hand-off: `VmBuildDispatcher` (after `ProvisionComputeVm::dispatch`),
  `VmOperationDispatcher::dispatch()` and `::dispatchForService()` (after `RunVmOperation::dispatch`),
  `Admin\ServerController::destroyVm()` (after `RunUnrecordedVmDestroy::dispatch`). All other `begin()` sites close
  in-process (audited).
- Card: on terminal `failed`, `pollOnce()` must not reload; the server must pre-render the latest terminal failure into
  `[data-compute-feedback]` (presenter already exposes `action.error`/`action.message`).

## Task list (as executed)

- [x] T1 Capture live panel state on /admin/hosting/23 (buttons, hints, console reason) — browser
- [x] T2 Exercise Create VM (pre-fix) — confirmed bogus "Interrupted" outcome, no visible feedback after reload
- [x] T3 Power actions: verified intentionally disabled for a VM-less terminated service; guards covered by tests
- [x] T4 Credentials reveal verified live; VM Console + Reset password verified disabled with visible reason
- [x] T5 Fix shutdown-guard hand-off (recorder API + 4 sites) + tests — slice `general` #1
- [x] T6 Fix card failure feedback (no reload on failure; persist last failure) + tests — slice `general` #2
- [x] T7 Browser re-verify: real error message shown and persisted; focused suites green per slice
- [ ] T8 Adversarial verification of the integrated whole (`verify`) — full suite + falsification

## Decisions

- Terminated service keeps an enabled Create VM button: `VmStatusPresenter::permissions()` line 345-350 says so
  explicitly ("Create re-provisions, Delete cleans up"). Left as designed; the copy/server-rule mismatch is reported, not changed.
- Did not create a real VM on `DT-Proxmox` to test success/power paths — would provision real resources. Success paths
  are covered by the existing suite; stated as a scope boundary in the report.
- My test rows in `provisioning_events` (#97, #98, #99) are left in place (audit trail integrity), disclosed in the report.

## Open questions (for the operator)

1. Terminated + not-created: presenter offers Create ("re-provisions") but `ManualProvisioner` refuses any non
   pending/suspended account (and the terminated order is not active). Which side should change?
2. Disabled Start/Stop/Restart on the card carry no visible reason (server reasons are not rendered as titles, unlike
   Reset/Credentials/Console). Add titles?
