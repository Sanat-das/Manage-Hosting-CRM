---
description: Plan and implement a feature end-to-end with verification gates
---

# Workflow: Feature

New capability or changed behavior: plan → implement → verify. Never skip the gate.

## Steps

1. **Gate.** If the work will touch **>3 files**, needs a **migration/schema change**, or touches **auth, billing, permissions, or security** — start with `plan` (planning-only) and produce the plan before any edit. Otherwise `build` may proceed directly.
2. **Skills (when triggered).** UI/Blade/AdminLTE work ⇒ load `frontend-design` before designing. MCP/endpoint work ⇒ load `mcp-builder`. Styled docs/artifacts ⇒ `theme-factory`. Do not load skills that do not match.
3. **Plan contents**: files to touch, contracts (routes/DTOs/service signatures), migration list, test list, risks, and explicit non-goals. Keep it under one page.
4. **Implement** in `build` with a minimal diff, following existing patterns (thin controllers, services/actions for logic). Parallel slices only under `workflow-parallel` rules: contracts frozen, max 4 `general` subagents, disjoint file ownership, no parallel migrations on the same table.
5. **Tests** ship with the change: Unit for pure logic, Feature for behavior/API/authz, Dusk for browser flows. Reproduce real edge cases, not just the happy path.
6. **Verify** (run and report the output):
   - `vendor/bin/pint --dirty`
   - `php artisan test --filter=<new tests>`
   - `php artisan test`
   - `npm run build` if front-end entries changed
   - `bash scripts/seed-smoke.sh` if migrations/seeders changed
   - `php artisan test --filter SeederIntegrityTest` if permissions/seeders changed
   - `php artisan dusk --filter=<Test>` (or `webapp-testing` patterns) if browser behavior changed
7. **Evidence**: write `plan.md` and `verification.md` into `.opencode/reports/<YYYY-MM-DD>-<slug>/`.
8. **Report**: change summary, verification commands with results, residual risks, follow-ups.

## Rules

- No new dependencies without explicit approval.
- Migrations are additive or reversible, include `down()`, and never target a table another migration is touching in parallel.
- Every new route/action has authorization coverage; client-facing output must not leak internal notes or staff data.
- Never touch `.env*`, never print secrets, never edit `.omo/`, `.codegraph/`, or `.openchamber/`.
- Do not report success without command output proving it.
