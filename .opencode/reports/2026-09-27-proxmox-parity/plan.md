# Plan: Proxmox VE parity — guest credentials, password reset, VM console

Goal: on the admin hosting page a Proxmox service exposes the same affordances Hyper-V has —
credential reveal, guest password reset — plus a VM console. Client area keeps its reset
button for Proxmox too.

Acceptance (whole): `php artisan test --filter=Proxmox` green, `--filter=Hyperv` green
(no regression), new tests pin reveal/reset/console; `pint --dirty` clean; full suite green.

## Recon summary (evidence: three explore passes, 2026-09-27)

| Affordance | Hyper-V (reference) | Proxmox today |
| --- | --- | --- |
| Credential store | `VmGuestCredentialStore` on `panel_accounts.guest_username/guest_password_encrypted`; written by base provision path | Storage EXISTS via base path; reveal endpoint hard-filters `panel=hyperv` |
| Reveal | `GET admin.hosting.vm-credentials` + `_hyperv-actions` modal | Missing (endpoint + UI) |
| Password reset | `HyperV::resetGuestAdminPassword` via PowerShell Direct; presenter gates `slug==='hyperv'`; admin + client endpoints | Missing (driver method, presenter gate, both controllers) |
| VM console | `admin.rdp-console.vmConsole` (VMConnect, Guacamole) gated on route+manage+host creds+vmId | Missing (no console client method, route, or card link) |

## Frozen contracts

1. `ProxmoxClient::setGuestPassword(string $node, int $vmid, string $username, string $password): void`
   → `POST /nodes/{node}/qemu/{vmid}/agent/set-user-password` `{username,password,crypted:0}`; throws `PanelException`.
2. `Proxmox::resetGuestAdminPassword(ServiceInstance $service, string $newPassword, ?string $username = null, ?string $currentPassword = null): ProvisioningResult`
   - Resolve `PanelAccount` (panel `proxmox`) → node via `recordedNode()`, vmid `external_id`; none → fail loudly.
   - Require running state (parity with Hyper-V); not running → fail "Start the VM before resetting the password."
   - Username: `$username ?: stored guest_username ?: 'root'`; `$currentPassword` accepted for interface parity, ignored (host-authoritative).
   - Primary: `setGuestPassword` → success → `VmGuestCredentialStore::store($account, $username, $newPassword)`.
   - Fallback: agent throws **and** `hasCloudInitDrive()` → `applyCloudInitCredentials()` + success message stating it applies on next boot.
   - Neither → fail with the agent error verbatim.
3. `VmStatusPresenter`: reset gate becomes capability-based (`method_exists(driver,'resetGuestAdminPassword')` via `ComputeDriver::resolve`), reason text unchanged; `credentials.username` default stays hyperv→Administrator / else root.
4. Admin `HostingController::vmCredentials()` / `resetVmPassword()`: resolve the account's compute slug generically (first enabled module link with a resolvable compute driver, fallback server type); panel lookup by that slug (lookup-only, never creates a row). Current-password requirement only for `hyperv`; JSON/redirect shapes unchanged.
5. Client `HostingController::resetVmPassword()`: same generic resolution; no current-password requirement for non-hyperv; no password echo; throttle unchanged.
6. UI (admin `_compute-actions.blade.php`, single writer): toolbar triggers `[data-compute-action="reset_password"|"credentials"]`, views `[data-compute-view=...]`, form `[data-compute-form-action="reset_password"]`, ids `#compute-reset-username-{slug}`, `#compute-reset-new-password-{slug}`, `#compute-reset-password-confirm-{slug}`, `[data-compute-generate-target]`, `#compute-reset-result-password`, `#compute-reset-copy`, `#compute-credentials-{username,password,show,copy,feedback,error}-{slug}`; password never server-rendered. Client blade reset gate `$isHyperv` → presenter `can.reset_password`.
7. Console: contract pending console-feasibility recon (slice C).

## Slices

- [x] S1 backend — driver + client method + presenter + controllers + backend tests | owner: general
- [x] S2 UI — admin compute card (+JS) + client blade + rendering tests | owner: general
- [x] S3 recon — console feasibility (Guacamole VNC vs PVE noVNC) | owner: explore
- [ ] S4 console implementation — BLOCKED on deployment decision | owner: general
- [x] S5 integrate + gates (`--filter=Proxmox`, `--filter=Hyperv`, pint) | owner: build
- [x] S6 independent verify | owner: verify
- [x] S7 apply the five review findings | owner: general
- [x] S8 focused verify of the fixes | owner: verify
- [ ] S9 full suite + commit | owner: build


## S4 console — live findings (2026-09-27, this environment)

- `POST /nodes/{node}/qemu/{vmid}/vncproxy` returns `{port, ticket (PVEVNC:…), user, upid, cert}`; works on a stopped VM.
- The VNC proxy port is **not reachable from the app host** (`10.100.1.30:5900` refused; node names do not resolve) — PVE binds it internally.
- `GET /nodes/{node}/qemu/{vmid}/vncwebsocket?port&vncticket` with an `Authorization: PVEAPIToken=…` header returns **HTTP 101 Switching Protocols** — the websocket path is the supported console transport, and API tokens authenticate it.
- Local install has **no console gateway at all**: no Docker/guacd (`:4822`, `:8080` closed), `rdp-console.secret` empty. The Hyper-V VM Console is likewise non-functional here.
- Consequence: a Proxmox console needs a **relay** (the RFB port cannot be dialled by guacd, and PHP-FPM cannot hold websockets). Options:
  - A1: extend the in-repo Guacamole sidecar to bridge guacd ↔ PVE `/vncwebsocket` (reuses the vendored Guacamole canvas; new sidecar protocol + deploy docs).
  - A2: vendor noVNC + a websocket relay in the sidecar (new frontend asset, no guacd dependency).
  - A3: interim — enable the existing `ssh-console` module for Proxmox Linux guests (works today, guest-level, not host-level).
  - A4: link out to the PVE web UI (rejected: token auth cannot log into the PVE UI; would share cluster logins).
- Recommendation: A1 (reuse), A3 as the immediate interim. Do not ship an app-side button that cannot connect.
- **Decision (2026-09-27): A1 chosen by the user.** Slices for the next workstream:
  - A1a — `GuacamoleLiteDriver` VNC mode: `RdpConnectionMode::ProxmoxVnc`, `RdpConnectionContext::pveVnc()`, driver `connection.type` switch + `vncSettings()`; unit tests on the minted payload.
  - A1b — app side: `ProxmoxClient::createVncProxy()` (POST `vncproxy`, `websocket=0`), a `PveVncTargetResolver` mirroring `VmConnectTargetResolver`, `pveConsole` + `pveConsoleToken` routes/controller in `rdp-console`, a console page reusing `_guacamole-canvas`; feature tests with faked PVE (no live guacd).
  - A1c — sidecar relay (the riskiest piece): `modules/rdp-console/guacamole-sidecar/server.js` opens the PVE `/vncwebsocket` upstream (Authorization token + vncticket) and bridges it to a per-session loopback TCP listener that guacd dials as its VNC target; deploy-doc update; sidecar unit test if the harness allows.
  - A1d — compute-card button + gating (mirror the Hyper-V predicates, including "gateway not configured" disabled-with-reason) + rendering tests.
  - A1e — focused verify + full suite + commit.
  - Prerequisite for any live check: a deployed sidecar + guacd (absent on this machine: no Docker, `rdp-console.secret` empty).

## A1 execution (2026-09-27)

- A1a driver VNC mode + A1b app side: `RdpConnectionMode::ProxmoxVnc`, `RdpConnectionContext::pveVnc()`, driver `mint()` emits `type: vnc` + top-level `pve` block; `ProxmoxClient::createVncProxy()/apiHost()`; `PveVncTargetResolver` (+ `PveVncUnavailableException`); manage-gated `pveConsole`/`pveConsoleToken` + page. Tests: RdpConsole 43 passed (345), GuacamoleLiteDriver 17 (89).
- A1c sidecar relay: `pve-vnc-relay.js` (single-claim loopback bridge, idempotent teardown, 15s claim timeout) + `server.js` PVE branch (rewrites the guacd settings, drops `pve`, non-PVE passthrough) + `ws` declared + DEPLOYMENT.md §8. Node test: 6/6 assertions, no dependencies required.
- A1d card button: VM Console link with the Hyper-V-style presentation gate and five disabled-with-reason fallbacks; Proxmox 159 passed (585), UI 19 (133).
- Verify: six falsification verdicts all held (seam contract, relay resources, resolver, gating, card predicates, RDP/VMConnect regression). Two minors closed (card gateway predicate now ≥16 chars like the driver; page render no longer mints a discarded vncproxy ticket). One minor accepted: the card checks the account's server while the resolver prefers the panel's server — UX-only, both directions fail closed.
- Staging checks before trusting the console live: (1) sidecar host can dial `{apiHost}:{apiPort}` and reach the node addresses; (2) guacd↔relay RFB handshake with `password=vncTicket` (or none) succeeds; (3) PVE `/vncwebsocket` upgrade with the token from the sidecar host; (4) ticket lifetime vs. the 90s token TTL and the 15s claim window under rapid opens.



## Ledger (append-only)

- 2026-09-27 task-created S1..S7 — plan written, contracts frozen
- 2026-09-27 task-completed S1 — Proxmox reset/credentials backend; Proxmox 145 passed, Hyperv 172 passed, ClientHostingVmActions 22, Recorder 8; pint clean
- 2026-09-27 task-completed S2 — compute card UI (reset + reveal) + client gate; 7 new UI tests; existing suites green
- 2026-09-27 task-completed S3 — console recon; Option A refuted live (port unreachable), websocket path proven (101)
- 2026-09-27 task-blocked S4 — needs a relay (sidecar) + deployment decision; options A1–A4 recorded above
- 2026-09-27 task-completed S5 — integrated gates green: Proxmox 145, Hyperv 172, ClientHostingVmActions 22, Recorder 8, pint PASS
- 2026-09-27 task-completed S6 — independent verify: 5 minor findings, no blocker (slug width, duplicate ids, fallback masking, presenter hot-path resolve, test gaps)
- 2026-09-27 task-completed S7 — five fixes applied: slug parity with the client helper, slug-suffixed reset-result ids, agent-substring fallback gate, presenter capability memo, three new/strengthened tests; Proxmox 149 passed, others unchanged, pint PASS
- 2026-09-27 task-completed S8 — focused verify of the fixes: all five hold, no code changes required; two deny-side/forward-compat notes recorded (memo has no invalidation path; `agent` substring is message-sniffing)
- 2026-09-27 task-completed A1a–A1e — console endpoint + sidecar relay + card button; verify verdicts all held; commit `2f87c598` (20 files, +1962/−14); full suite 2631 passed (12 958 assertions)
- 2026-09-27 task-created staging-verify — console end-to-end with a deployed guacd + sidecar (see staging checks above); pending deployment

## Process-handling workstream (2026-09-28)

Review outcome: only the VM build was queued; every other verb ran inline in a web request (clone <=900s in the payment request; power/lifecycle verbs 90-390s), several paths wrote no event, the `provisioning` queue had no committed consumer, and no sweeper covered interrupted operations.

Implemented:
- `VmOperationDispatcher` + `RunVmOperation` (+ `VmOperationConflictException`): durable running event, stage progress, per-verb timeouts, tries=1, failOnTimeout, `failed()` flip, row-lock double-dispatch guard, ciphertext options, `power_only` host-only rule for client power verbs.
- Converted: admin card verbs, admin lifecycle forms (per link), order-less services, unrecorded destroy (`RunUnrecordedVmDestroy`), client power/reset, order paths (`RunOrderProvisioning`, `RunOrderLifecycleVerb`).
- Recovery: `provisioning:reconcile` (stale events + stranded orders, `--dry-run`, every five minutes) and `deploy/systemd/managehosting-queue-provisioning.service` + docs.
- Proxmox rename parity: `Proxmox::rename()` + `ProxmoxClient::renameVm()` + per-module rename sync on host_name change.
- Essential Information parity: bounded Proxmox telemetry (6s info / 10s test-connection deadlines), `_essential-proxmox` supplement (cluster/nodes/storage/templates), transport strip, VM-list node column.
- Customer UI: standalone action progress panel, running locks, state hint, proxmox reset disabled-with-reason, dead markup removed; `started` handling in all three action UIs.

Verification: two adversarial passes. Pass 1 found 4 majors + 2 minors (double-run window, plaintext password in queue payload, stranded orders, unbounded telemetry) - all fixed. Pass 2 found 2 majors + 1 minor (unbounded pre-telemetry info calls, service-path lock scope, sweep ordering) - all fixed; encryption/order-sweep/deadlock/import checks held. Gates: 516+ targeted tests green, full suite on the final tree pending.
- 2026-09-28 task-completed process workstream — commit `b21df8e1` (39 files, +5744/-496); full suite 2687 passed (13 409 assertions), 0 failures. Deployment follow-up: the committed systemd provisioning worker must be installed on the server (the queue previously had no consumer).





