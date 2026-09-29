# Proxmox VE module — complete UI test

Date: 2026-09-27 · App: `http://managehosting.local` (Laravel Herd, PHP 8.4.16, MySQL `local`)
Live target: PVE 9.1-9.1.4 cluster at `10.100.1.30` (nodes Pve1-Twin04-Node01, Pve2-Twin04-Node04, Pve3-Twin05-node01), server #6 `DT-Proxmox`, API-token auth, TLS verification off.
Throwaway instance: order **ORD-2026-00008** (#22) → hosting account **#22** (`host-i0syymhi`) → **VMID 114**. Existing services #20/#21 (VMID 112) untouched and verified unchanged.

## Result

Full module lifecycle executed through the admin UI against the live cluster: server config → template curation → product restriction → order → activation → VM create → restart → stop → start → suspend → unsuspend → delete/terminate. Three real defects were found on the live cluster (all fixed, with a regression test); after the fixes every step passed.

## Automated gates

| Gate | Command | Result |
| --- | --- | --- |
| Module suite | `php artisan test --filter=Proxmox` | **121 passed** (375 assertions) |
| Full suite | `php artisan test` | **2576 passed** (12623 assertions), 19.7 min, 0 failures |
| Formatting | `vendor/bin/pint --dirty` | clean |

## UI test matrix

| # | Surface | Action | Result |
| --- | --- | --- | --- |
| 1 | `/admin/servers` | list shows DT-Proxmox + Proxmox-test2 `Connected` | pass |
| 2 | `/admin/servers/6` | PVE 9.1-9.1.4, cluster DiademPVE, 90 ms, 11 VMs remote; VM 112 correlated to ORD-2026-00007; "On host, not provisioned (10)" | pass |
| 3 | server show | Re-test Connection | pass ("Last checked 3 seconds ago") |
| 4 | server edit | Clone templates: 4 discovered (103/109/110/113, 2 nodes), curated checked, default 110 | pass |
| 5 | product #2 → Modules | restriction card: restrict mode, 110+103; toggle 109 → save → persists; restore | pass (DB `updated_at 15:06:49`) |
| 6 | order create | product autofills ₹499.00/month | pass |
| 7 | order #22 | Activate → flash "is now active", hosting #22 created | pass |
| 8 | hosting #22 | card `Manual` / `Not created`; picker restricted to 103/110, default 110 | pass |
| 9 | hosting #22 | Create VM → queued on `provisioning` → VMID 114 on Pve2-Twin04-Node04 | pass (see defect A) |
| 10 | server show | VM row: `host-i0syymhi`, VMID 114, ORD-2026-00008, running, 2 vCPU / 2 GB | pass |
| 11 | hosting #22 | Restart (typed confirm) → PVE `qmreboot:114` OK | pass (after fix) |
| 12 | hosting #22 | Stop → `qmshutdown:114` OK, card `Off` | pass (after fix) |
| 13 | hosting #22 | Start → `qmstart:114` OK, card `Running` | pass (after fix) |
| 14 | hosting #22 edit | Suspend → flash "suspended (module synced)", VM `Off` | pass (after fix) |
| 15 | hosting #22 edit | Reactivate → flash "reactivated (module synced)", VM `Running` | pass (after fix) |
| 16 | hosting #22 | Delete (typed confirm) → card `Not created`, hosting `terminated`, PVE `qmdestroy:114` OK | pass (after fix) |

Final PVE task history for VMID 114: `qmreboot` OK, `qmstart` ×4 OK, `qmshutdown` ×4 OK, `qmdestroy` OK. `vmExists(114)` → false.

## Defect A — write bodies vs PVE's API server (fixed)

**Symptom.** Provision recorded `VM created but failed to start: .../status/start returned HTTP 500`; the UI Start failed 3/3 while an identical form-encoded curl succeeded 3/3. Delete failed 2/2 with `.../qemu/114 returned HTTP 501`.

**Root cause.** Laravel's HTTP client defaults to JSON bodies; PVE 9.1's API server is form-oriented:
- parameter-less POST (`start`, `reboot`, `stop` fallback) sends body `[]` → `HTTP 500 Not a HASH reference at PVE/APIServer/AnyEvent.pm line 978`;
- DELETE with **any** body (JSON or form) → `HTTP 501 Unexpected content for method 'DELETE'`; query-string parameters are accepted.

**Fix** (`app/Modules/Proxmox/Services/ProxmoxClient.php`): parameter-less POST/PUT use `asForm()`; DELETE carries its parameters in the query string and sends no body.

**Regression test** `tests/Feature/ProxmoxVmLifecycleTest.php::test_write_verbs_send_form_encoded_bodies` — asserts POST bodies are form-encoded (not `[]`) and the DELETE has an empty body with `purge=1` in the query. Existing DELETE fakes/assertions in `ProxmoxProvisioningTest` and `ProxmoxUnrecordedVmDestroyTest` were updated to the query-string contract (they previously asserted the body parameter).

## Defect B — long waits killed by `max_execution_time` (fixed)

**Symptom.** Suspend/stop left `provisioning_events.status = running` with `completed_at = null` although the PVE task finished; the compute card showed `Unknown` with all controls disabled for `RUNNING_STALE_AFTER_SECONDS` (2100 s).

**Root cause.** PVE graceful shutdown ran 64 s; the web SAPI has `max_execution_time = 30`, killing the request inside `ProxmoxClient::waitForTask()`. The module's documented waits are 90 s (stop) and 180 s (destroy).

**Fix** (`ProxmoxClient::waitForTask()`): `@set_time_limit(0)` — the loop is bounded by `$timeoutSeconds`, so the request cannot hang; CLI runs unaffected. Live proof: suspend/unsuspend/terminate complete with `(module synced)` flashes and completed event rows (80, 81, 85).

## Observations (not defects)

- **10 s VM-state probe cache** (`VmStatusPresenter::probeVm`): after a power action the card reloads immediately and can render the pre-action state for up to ~10 s; a refresh corrects it. Automated assertions had to wait out the TTL twice.
- **Delete gating**: the card disables Delete while the VM is running (PVE refuses to destroy running VMs) — Stop first is the intended flow.
- **Restriction save dialog**: the product template save is guarded by a native `window.confirm()`; scripted saves need dialog handling.
- Service #22 remains `active/provisioned` with `external_id 114` while its hosting account is `terminated` — same shape as existing service #21, i.e. established behavior of the module-action terminate path, not a regression.
- `Proxmox-test2` (#5) has no curated templates and was not exercised beyond the server list.
- The client-area hosting page was not driven in a browser (admin UI covered; presenter covered by feature tests).

## Files changed

| File | Change |
| --- | --- |
| `app/Modules/Proxmox/Services/ProxmoxClient.php` | defect A + defect B fixes |
| `tests/Feature/ProxmoxVmLifecycleTest.php` | regression test + import |
| `tests/Feature/ProxmoxProvisioningTest.php` | DELETE fakes/assertions → query-string contract |
| `tests/Feature/ProxmoxUnrecordedVmDestroyTest.php` | DELETE fakes/assertions → query-string contract |

Pre-existing uncommitted work (`ServerController.php`, `servers/edit.blade.php`, `ProxmoxTemplateCatalogTest.php`, `ci.yml`) was left as-is; `pint --dirty` fixed one style issue in `ServerController.php` (no logic change).

## Follow-up fixes (post-test hardening)

Applied after the live test, per review of the two failure modes it exposed:

- **Interrupted-event shutdown guard** (`ProvisioningEventRecorder`): `begin()` tracks the open event id; a process-wide `register_shutdown_function` (registered once) flips any row still `running` to `failed` with `Interrupted — the process ended before the action finished.` `complete()`/`fail()` untrack. Hard kills (SIGKILL) still fall back to `isStaleRunning()`. Verified end-to-end: a subprocess that called `begin()` and exited with code 1 left event 86 `failed` with the interrupted verdict (probe row deleted afterwards).
- **Post-action cache invalidation** (`VmStatusPresenter::forgetVmState()`): the presenter's 10 s live-state probe cache is dropped after every module action — admin `moduleAction` (`Admin\HostingController:1177`), the shared lifecycle sync (`:882`), and the client power actions (`Client\HostingController:526,538`). This removes the stale badge window that defeated UI assertions during the test.
- Tests: `test_interrupted_guard_fails_a_still_running_row`, `test_interrupted_guard_never_touches_a_terminal_row` (recorder), `test_module_action_forgets_the_cached_vm_state` (Proxmox lifecycle).

**Independent review (verify subagent) and its findings, all resolved:**
1. *Major* — admin `moduleAction`'s driver-throw path skipped cache invalidation (the call sat after the try/catch, so only the normal-return path ran). Fixed by mirroring the client controller's catch-path call. Note: for the built-in Proxmox driver this path is defensive — `AbstractComputeModule::lifecycle()` catches all throwables and returns a failed result — but plugin modules can throw.
2. *Minor* — `complete()`/`fail()` untracked the event before saving the verdict, leaving a window where a failed save would strand the row with no guard. Untrack moved after `save()`.
3. *Minor* — `markInterrupted()` never untracked, so a daemon worker would accumulate ids until process exit. It now untracks after a successful flip.
4. *Minor (accepted)* — `@set_time_limit(0)` lifts the cap for the remainder of the request, not only the bounded wait. Same pattern as `UpdateService`; the wait itself is deadline-bounded.
5. *Minor (pre-existing, noted only)* — admin `begin()` sits outside try/catch (client wraps it). Unchanged by this work.

**Committed as `0399b105`** — `fix(proxmox): send PVE writes in the shapes its API accepts` (9 files, +357/−44). `pint --dirty` also normalized pre-existing fully-qualified references and brace placement inside the two HostingControllers it touched — style-only, no logic change. The user's unrelated in-progress files (`ServerController.php`, `servers/edit.blade.php`, `ProxmoxTemplateCatalogTest.php`, `ci.yml`) were deliberately left unstaged.

**Final gates:** `php artisan test` → 2579 passed (12636 assertions), 0 failures, 17.4 min; `pint --dirty` clean.

Environment note: local PHP has `disable_functions` empty and `set_time_limit` available; the production FPM pool (`request_terminate_timeout`) still needs a one-time check — if it caps requests below the module's waits, the shutdown guard is what keeps the card from locking.

## Test artifacts / cleanup

- Temp automation admin `proxmox-e2e-admin@example.test` created and deleted; password kept out of the repo.
- A `queue:work --queue=provisioning --max-time=7200` worker was started to replace the expiring original (it stops on its own).
- Order #22 and its terminated account/service rows remain as audit records (same as #20/#21).

## Evidence index (`.opencode/reports/2026-09-27-proxmox-ui-e2e/`)

- `run.log` / `run-log.json` — phase 1: order → activation → create (pre-fix; defect A caught here)
- `resume.log`, `phase2.log`, `phase3*.log` + `*-log.json` — lifecycle runs, pre- and post-fix
- `phase3d.log` — final successful delete
- `full-suite.log` — full `php artisan test` output
- `report.md` — this file
- `*.png` — numbered screenshots (dashboard, order form, order pending/active, hosting before/after, create form, VM running, delete confirm, deleted, terminated)
