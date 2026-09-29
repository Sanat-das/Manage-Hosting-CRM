# Plan — Module actions card UX pass (admin hosting detail)

Page: `http://managehosting.local/admin/hosting/23` → `resources/views/admin/hosting/show.blade.php`, "Module actions" card.

## Acceptance (whole task)

1. `npm run build` succeeds.
2. `php artisan test --filter=ProxmoxComputeCardUiTest`, `--filter=AdminModuleUiTest`, `--filter=HypervActionsUiTest`, `--filter=ProxmoxVmLifecycleTest`, `--filter=HypervVmProgressTest`, `--filter=HypervStrandedBuildRecoveryTest`, `--filter=ClientHostingVmActionsTest` all green.
3. Rendered card for the reported scenario (terminated account, no VM): Create VM is **not** clickable, and exactly one prominent, correctly-styled reason explains why.
4. No poll/action wiring broken: `data-compute-*` hooks, `compute-panel-{slug}` ids, credentials/reset panels still function.

## Scope

In: `resources/views/admin/hosting/partials/_compute-actions.blade.php`, the card shell in `resources/views/admin/hosting/show.blade.php:93-210`, presentation-support CSS in `resources/css/adminlte.css`, `app/Services/Provisioning/VmStatusPresenter.php`, and the assertions those two slices intentionally invalidate.

Out: `_hyperv-actions.blade.php` + `_hyperv-actions-js.blade.php` markup (untouched — known divergence, reported as follow-up), page header card, metric tiles, tab strip, `ManualProvisioner`, controllers, routes, client portal.

## Defects this pass fixes

| # | Defect | Evidence |
| --- | --- | --- |
| D1 | `can.create = true` on a terminated account while the server always refuses | `VmStatusPresenter.php:349` vs `ManualProvisioner.php:124-129` |
| D2 | Reason text claims "Create re-provisions" — false | `VmStatusPresenter.php:346` |
| D3 | Refusal surfaces only after a doomed queued job (spinner 00:30 → error bar) | `_compute-actions.blade.php:164-174,179-184`, `RunVmOperation` |
| D4 | Three competing notice surfaces at equal weight (muted span / alert-danger / prose) | `_compute-actions.blade.php:153`, `:181`, `show.blade.php:204` |
| D5 | Disabled reason lives only in `title=` — unreachable on touch, invisible in print | `_compute-actions.blade.php:134-135,138-139,151` |
| D6 | No state answer above the fold; 8 buttons of near-equal weight, no risk grouping | card as rendered |

## Frozen contract

**A → B (presenter → view)**

- A1. Terminated account ⇒ `can['create'] === false` (all six `can` keys false when terminated + VM absent).
- A2. Every `can[action] === false` has a non-empty `reasons[action]`; `reasons` never contains a key whose `can` is true (existing invariant, kept).
- A3. Non-terminated reason strings are **unchanged** — `HypervVmProgressTest.php:212-256` asserts them verbatim.
- A4. `build()` gains exactly one additive key:
  `notice => ['severity' => 'danger'|'warning'|'info'|null, 'text' => ?string]`
  Precedence = `permissions()` order: running action > probe failure > terminated > VM absent > state unknown > VM running > VM stopped > null. `severity === null` iff `text === null`. No `success` variant.
- A5. No existing key removed or renamed: `can`, `reasons`, `vm`, `credentials`, `action`.

**B → markup/JS**

- B1. `notice` may be **absent** (the card is rendered standalone in `ProxmoxComputeCardUiTest.php:700-724` with a crafted `$vmStatus`). Read it as `$vmStatus['notice']['text'] ?? null`; derive nothing from it that cannot be derived from `can`/`reasons`/`vm`.
- B2. Preserve every existing hook the poll JS and tests depend on: `id="compute-panel-{{ $slug }}"`, `data-compute-action`, `data-compute-view`, `data-compute-hint`, `data-compute-progress`, `data-compute-form-action="create"`, `data-compute-feedback`, ids `vm-credentials` and `reset-vm-password`, the `••••••••` credential bullets, the form POST to `admin.hosting.module-action` with `module_slug`/`action`/`template`/`start_after_create`.
- B3. `applyStatus()` (`_compute-actions.blade.php:449-465`) stays the single client-side gate and mirrors `can`/`reasons`; it must not re-enable an action the presenter denies.
- B4. Reuse only existing primitives and tokens: `<x-adminlte.partials.status-badge>`, Bootstrap `btn-*`/`alert-*`/`badge`/`nav`/`card` classes, `bi-*` icons, `resources/css/tokens.css` values via `var()`. No new dependency, no Alpine/Livewire/jQuery, no hardcoded hex, dark mode must work.
- B5. Any new CSS goes in one delimited block at the end of `resources/css/adminlte.css`.
- B6. Nothing the card currently communicates may be dropped — every reason, the console-gateway reason, the failure text, and the operator guidance survive, restructured.

## Task list

- [x] T1 Map hosting-show view tree — explore → `show.blade.php:43/97/105/114/421/430`, include graph, tests
- [x] T2 Map module-action gating + guards — explore → button matrix, guards, all strings
- [x] T3 Map design system, primitives, UI tests — explore → tokens/Bootstrap/AdminLTE, reusable components
- [x] T4 Freeze contract, write this artifact — build
- [x] T5 Presenter: mirror server guards + `notice` key + tests — general — 8 tests / 39 assertions, `VmStatusPresenter.php:353-376,425-460`
- [x] T6 Card markup/JS redesign — general — `_compute-actions.blade.php`, `show.blade.php:204`, `adminlte.css` tail, `ProxmoxComputeCardUiTest.php` +5 tests
- [x] T7 Integrate + acceptance suite on the merged tree — build — `php artisan test --testsuite=Feature` → 2548 passed, 0 failed
- [x] T8 Adversarial verification (F1-F7 all confirmed, 2 minor findings) — verify
- [x] T9 Rendered-page evidence — build — fresh render + captures at 1440px and 1152px
- [x] T10 Fix group-row layout regression found in T9 (misaligned DANGER column, mixed button heights, solid disabled fills) — general — rebuilt, focused suites green
- [x] T11 Close T8 finding: terminated + running VM no longer advises the impossible "Stop the VM first" — build (solo, one string) — presenter suites green

## Outcome

Shipped. D1-D6 all addressed and confirmed by an independent `verify` pass (F1-F7). D1's fix was additionally falsified against the server side in both directions — no remaining UI/server disagreement, and the `$rebuildingStale || !$hasActivePanel` rebuild path is untouched.

Evidence: `.opencode/reports/2026-09-28-hosting-module-actions-card/verification.md`.

## Residual (reported, not fixed)

- **Delete sits 9px below the action-row baseline** — its danger box carries extra top padding. Cosmetic; the row's button heights are uniform (`h=31`).
- **Poll-path presentation drift** — `applyStatus()` is frozen, so after a live poll the strip reverts to the single-line concatenated form and poll-disabled buttons keep their server-rendered variant classes until reload. Functional, visually inconsistent with a fresh load.
- **Hyper-V card untouched** — `_hyperv-actions.blade.php` inherits the corrected `can` values from the presenter but not the presentation pass. Same defect class still present there.
- **A stale page can still POST a doomed create** — `HostingController::moduleAction` dispatches before `ManualProvisioner` refuses. Pre-existing, out of scope by decision (mirror, don't extend the server).

## Design spec (B)

Top-to-bottom inside the existing card, for a Proxmox module:

1. **Module identity** — name, slug, mode chip, and a state chip (`status-badge`) that answers "what is this VM doing" at a glance.
2. **Status strip** — one row, one icon, one severity colour, text from `notice.text`. `role="status"` + `aria-live="polite"`. Replaces the muted span, the `alert-danger` bar and the prose paragraph as the place a refusal is explained. Severity → `alert-danger` / `alert-warning` / `alert-info` styling from tokens. Absent entirely when `notice.text` is null.
3. **Action row, grouped by risk** — `Lifecycle` (Create VM, Start, Stop, Restart) · `Access` (Credentials, Reset password, VM Console) · `Danger` (Delete, last). Uppercase micro-header per group, `btn-sm`, icon + label, consistent height. Disabled buttons keep `disabled` + `title` **and** gain `aria-describedby` pointing at the status strip; when a group is entirely unavailable the strip is what explains it.
4. **Primary action section** — when `$computeCanCreate`, the template select + "Start VM after creation" + submit render as a real, immediately visible section (this is also the empty state: no VM exists yet), with the progress block adjacent to it so "Creating the VM on the host 00:30" belongs to something. When create is unavailable, the section is absent, not greyed.
5. **Footnote** — one muted line retaining the operator guidance (calls the module directly; state-checked on the host; restart/delete need the account name typed), trimmed to a single sentence plus a `bi-info-circle`.

Copy rules: active voice, sentence case, name what the operator controls, no apology, no vague "an error occurred". The terminated reason must state the actual consequence and the exit (`Delete` cleans up; restoring the account re-enables provisioning) without promising a flow that does not exist.

## Decisions

- **Mirror, don't extend the server.** D1 is fixed in the presenter, not by adding a new controller guard; `ManualProvisioner` is the authority and stays untouched. A doomed queued job is therefore still *possible* via a stale page — reported as a residual risk, not silently patched here.
- **One notice producer.** Precedence lives in `VmStatusPresenter`, not in Blade, so the client portal card can adopt it later without duplicating the matrix.
- **Hyper-V card untouched.** Same defect class exists in `_hyperv-actions.blade.php`, but it was excluded by scope; the presenter fix still corrects its `can` values.
- **One owner for the card shell and the partial**, because the two files disagree about which one renders the helper prose (T1 and T2 map `show.blade.php:116-207` differently). The owner resolves it by reading both.

## Risks

- R1. `notice` is additive; any exact-array assertion on `build()` would break. Mitigated by the slice grepping for it and stopping if found on a terminated-path assertion.
- R2. Updating `ProxmoxComputeCardUiTest` assertions is the same agent that changes the markup — weakens the gate. Mitigated by T8, which must diff every changed assertion against this plan.
- R3. Poll JS drift: blade-rendered gating and `applyStatus()` must not diverge (B3).
- R4. Other pages include `adminlte.css`; the new CSS block must be additive and namespaced.
