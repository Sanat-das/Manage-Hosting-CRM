# Agentic Workflow System — ManageHostingCRM

Version 2 · Reconciled 2026-09-27 · Repo: `C:\Users\Administrator\Local Sites\managehosting\app`
Machine-readable twin: [`.opencode/agentic-workflows.json`](.opencode/agentic-workflows.json) — that file is the source of truth for wiring; this document explains it.

Scope rules (apply to every workflow):

- This system configures **workflow wiring only**. It never edits app code (`app/`, `routes/`, `database/`, `resources/`, `tests/`, `modules/`, `config/`, `public/`), secrets (`.env*`), or user work (`.omo/`, `.codegraph/`, `.openchamber/`, `docs/`).
- Changes to workflow artifacts are additive and idempotent. Nothing here is committed automatically.
- Every claim in this document was verified against the live system on 2026-09-27; if reality changes, re-run the reconciliation prompt and regenerate.

## 1. Agent Registry

| Agent | Mode | Role | Definition | Model | Tools |
|---|---|---|---|---|---|
| `build` | primary | Orchestrator + implementer; runs workflows end-to-end. Default entry point. | `~/.config/opencode/agents/build.md` | `opencode-go/deepseek-v4.1-flash#max` | OpenCode defaults (full toolset) |
| `plan` | primary | Planner for the complexity gate. Read-and-plan only; built-in plan semantics disable file edits. | `~/.config/opencode/agents/plan.md` | `opencode-go/deepseek-v4.1-flash#max` | Built-in plan semantics (no edits) |
| `explore` | subagent | Fast read-only code search; returns file/symbol maps and call paths. | `~/.config/opencode/agents/explore.md` | `opencode-go/muse-spark-1.3-contributor#xhigh` | Read-only (glob/grep/read) |
| `general` | subagent | Multi-step work units; used for parallel slices. | `~/.config/opencode/agents/general.md` | `opencode-go/muse-spark-1.3-contributor#xhigh` | OpenCode defaults |

Notes:

- Hardened 2026-09-27: all four agents carry `description:` frontmatter; `explore` denies `edit` outright; `plan` relies on OpenCode's built-in plan semantics (no edits to normal project files). Entry/child rules follow `mode`, permissions, and built-in semantics.
- OpenChamber's default agent for new sessions is the `Sisyphus - ultraworker` (oh-my-openagent) definition; the workflow commands assume `build`.
- `explore` and `general` must never be session entry points — they are reachable only through the subagent tool.
- OpenChamber project: `ManageHostingCRM` (`C:\Users\Administrator\Local Sites\managehosting\app`). One session active at reconciliation time, model `opencode-go/deepseek-v4.1-flash#max`; no scheduled tasks configured.

## 2. Router

Entry points: `build` (default), `plan` (planning-only sessions). The project config sets `default_agent: "build"` so sessions in this repo start inside the workflow system.

Routing rules, in order:

1. `build` is the default entry for every request; `plan` is a first-class entry when the user asks for planning only.
2. **Complexity gate** — start with `plan` when the task is likely to touch **>3 files**, needs a **migration/schema change**, or touches **auth, billing, permissions, or security**. Everything else may go straight to `build` with the matching workflow command.
3. Route to a workflow command: `workflow-triage` (classify), `workflow-explore` (understand before deciding), `workflow-feature` (new capability), `workflow-bugfix` (defect), `workflow-parallel` (independent slices), `workflow-review` (audit a diff or deliverable).
4. Subagents only through the subagent tool: `explore` for read-only search, `general` for parallel work units. Max 4 concurrent `general` subagents.
5. Skills are hooks, not defaults: load a skill only when its trigger matches (see §7).
6. MCP: `codegraph` first for code questions when the session runs in this repo; `everything` only for explicit `demo://` references.
7. Verification is part of routing: no workflow completes without the gates in §6.
8. Parallel work requires contracts first; migrations never run in parallel on the same table.

## 3. Workflows

| Workflow | Command | Purpose | Entry | Key gates |
|---|---|---|---|---|
| Triage | `.opencode/commands/workflow-triage.md` | Classify a request, emit `Workflow \| Agents \| Skills \| MCP \| First step` | `build` | Complexity gate applied before routing |
| Explore | `.opencode/commands/workflow-explore.md` | Read-only understanding; map files, symbols, tests, risks | `build` + `explore` | No edits, no migrations/seeders |
| Feature | `.opencode/commands/workflow-feature.md` | New capability, plan → implement → verify | `plan` → `build` | Complexity gate; test + pint + build gates |
| Bugfix | `.opencode/commands/workflow-bugfix.md` | Defect: reproduce → fix → regression test | `build` (+ `explore`) | Failing test first; passing test as proof |
| Parallel | `.opencode/commands/workflow-parallel.md` | Independent slices via subagents | `build` + `general` | Contracts-first; max 4; disjoint files |
| Review | `.opencode/commands/workflow-review.md` | Audit a diff/deliverable against guardrails | `build` | Findings by severity; verification commands run |

## 4. Repo Guardrails (discovered stack)

| Area | Reality |
|---|---|
| Runtime | PHP `^8.3`, Laravel `^13.8` |
| UI | Blade (355 views), AdminLTE 4.1, Bootstrap 5.3, Tailwind CSS 4, Sass, Vite 8 |
| Auth | Fortify (2FA), Sanctum (API), policies + granular permissions |
| Realtime | Laravel Reverb + Echo (`laravel-echo`, `pusher-js`) |
| Data | 132 migrations; `spatie/laravel-settings` typed settings; seeders with a smoke gate |
| Domain modules | `app/Modules` (Cpanel, DirectAdmin, HyperV, Plesk, Proxmox, Virtualizor) + `modules/` packages (rdp-console, snmp-monitor, ssh-console) |
| Tests | PHPUnit 12 (`tests/Unit`, `tests/Feature`), Dusk (`tests/Dusk`, `tests/Browser`), shared `tests/Concerns` fixtures |
| Tooling | Pint (formatting), Pail, codegraph index (`.codegraph/`); CI = `php artisan test` + `SeederIntegrityTest` + `scripts/seed-smoke.sh` |

Rules:

- Minimal diffs; follow existing patterns; no new dependencies without explicit approval.
- Migrations are additive or reversible, include `down()`, and never run in parallel on the same table.
- Every new route/action needs authorization coverage (policy/permission); client-facing responses must not leak internal notes or staff data.
- Every behavioral change ships with a test in the right suite; browser behavior is verified with Dusk or the `webapp-testing` skill when the local environment allows.
- Blade/asset changes that touch Vite entries must pass `npm run build`.
- Never print or commit secrets; `.env*` is off-limits; reports must contain no secret output.
- `.omo/`, `.codegraph/`, `.openchamber/`, `docs/` are context, not task targets, unless the user explicitly says so.
- Project permissions guard the sharp edges: edits to `.env*` require confirmation (`.env.example` exempt), and `migrate:fresh` / `db:wipe` / `migrate:reset` shell commands ask first.

## 5. Parallelism Rules

- **Contracts first**: interfaces, DTOs, routes, and service boundaries are frozen before dispatch; each slice gets exclusive file ownership.
- Max **4** concurrent `general` subagents; slices must not share files.
- Migrations on the same table are **never** parallel; prefer one migration per table, sequential.
- Every slice carries its own tests and is Pint-clean; integration happens in `build`; full verification runs once after integration.
- A contract change invalidates sibling slices: stop, re-plan, re-dispatch.

## 6. Verification Gates

| Change type | Gate |
|---|---|
| Any code | `vendor/bin/pint --dirty`, then `vendor/bin/pint --test` |
| Logic / behavior | Targeted `php artisan test --filter=<Test>`, then full `php artisan test` |
| Permissions / seeders | `php artisan test --filter SeederIntegrityTest` |
| Migrations / seeders | `bash scripts/seed-smoke.sh` |
| Front-end entries | `npm run build` |
| Browser/UI behavior | `php artisan dusk --filter=<Test>` or `webapp-testing` skill patterns |
| API surface | Targeted `Api*` feature tests incl. authz tests |
| Workflow artifacts | `node .opencode/scripts/validate-workflows.mjs` |

No workflow reports "done" without the relevant command output. CI parity target: Unit + Feature tests, seeder integrity, seed smoke, workflow wiring validation.

Plan output and gate evidence land in `.opencode/reports/<YYYY-MM-DD>-<slug>/` (`plan.md`, `verification.md`, `review.md`).

## 7. Skills & MCP Wiring

### MCP inventory (discovered 2026-09-27)

| Server | Scope | Config | Class | Observed | Use |
|---|---|---|---|---|---|
| `codegraph` | project | `app/opencode.jsonc` (`codegraph serve --mcp`, enabled) | prod | `codegraph_explore` tool + `codegraph explore` shell; `.codegraph/` index present | First stop for code questions in this repo. Loads only when a session runs in the repo directory (verified unavailable from chat-directory sessions). |
| `everything` | global | `~/.config/opencode/opencode.jsonc` | demo | 7 static docs + 2 dynamic templates, all `demo://resource/...` | **Smoke-test only, skip unless a `demo://` URI is explicitly referenced.** Never surface `get-env` output in logs or reports. |

Notes: no other MCP servers are configured. `laravel/mcp ^1.0` is an app-side dependency (the app can expose MCP servers), not agent tooling. `.playwright-mcp/` in the repo is stale evidence from earlier runs — not a configured server.

### Skill catalog (installed)

| Skill | Class | Trigger |
|---|---|---|
| `frontend-design` | core | UI/Blade/AdminLTE design or redesign work |
| `webapp-testing` | core | verifying a local web UI with Playwright-style automation |
| `mcp-builder` | conditional | building or changing an MCP server/endpoints |
| `web-artifacts-builder` | conditional | standalone multi-component HTML artifacts (demos, not app UI) |
| `theme-factory` | conditional | styling docs/slides/report artifacts |
| `brand-guidelines` | rare | Anthropic-branded artifacts only |
| `canvas-design` | rare | posters/static art files |
| `algorithmic-art` | rare | generative art requests |
| `discernment-nudge` | meta | at most once per conversation, on actionable conclusions (triage/review) |
| `managehosting-workflows` | core (project) | implementation/fix/review work in this repo — guardrails + verification recipes |

Not installed (spec names that do not exist here): `doc-coauthoring` — substitute inline docs in existing `docs/` style, and `theme-factory` only for styled artifacts; `skill-creator` — create new skills by hand following the existing `SKILL.md` format.

### Per-workflow wiring

| Workflow | Agents | Skills (when triggered) | MCP |
|---|---|---|---|
| triage | `build` | `discernment-nudge` (actionable triage) | `codegraph` |
| explore | `build` + `explore` | — | `codegraph` |
| feature | `plan` → `build` (+ `general` for slices) | `frontend-design` (UI), `webapp-testing` (UI verify), `mcp-builder` (MCP work) | `codegraph` |
| bugfix | `build` (+ `explore` for isolation) | `webapp-testing` (browser repro), `frontend-design` (visual fix) | `codegraph` |
| parallel | `build` + `general` ×≤4 | per slice | `codegraph` |
| review | `build` | `discernment-nudge` (conclusions), `frontend-design` (UI checks) | `codegraph` |

Skill usage rule: hooks load only when the trigger matches the request — never "just in case".

Project skill: `managehosting-workflows` (`.opencode/skills/managehosting-workflows/SKILL.md`) is advertised automatically in this repo and carries the guardrails + verification recipes.

## 8. Maintenance

- Reconcile: `.opencode/RECONCILE.md` — idempotent re-sync of this document, the JSON twin, and the workflow commands after agents/skills/MCP/OpenChamber change.
- Validate: `node .opencode/scripts/validate-workflows.mjs` — parses the JSON, checks workflow command files exist, and rejects unknown agent/skill/server references. Wired into CI (`.github/workflows/ci.yml`).
- Schedule: OpenChamber runs the reconciliation monthly (cron `0 9 1 * *`, Asia/Kolkata).
- Enforcement is declarative: project `permissions` in `opencode.jsonc` plus agent permissions — no plugin code to maintain.
- Additive changes only; this document and `.opencode/agentic-workflows.json` are updated together.
