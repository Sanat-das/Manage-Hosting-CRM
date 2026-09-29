---
name: ManageHosting Workflows
description: Repo guardrails and verification recipes for the ManageHostingCRM Laravel app — use when implementing, fixing, or reviewing changes here.
---

# ManageHosting Workflows

Wiring: `AGENTIC_WORKFLOW_SYSTEM.md` · `.opencode/agentic-workflows.json` · commands in `.opencode/commands/`.

## Router

| Workflow | Command | Use when |
| --- | --- | --- |
| triage | `workflow-triage` | classify a request before committing to a workflow |
| explore | `workflow-explore` | read-only research: files, symbols, flows, tests |
| feature | `workflow-feature` | new capability; start with `plan` for >3 files / migration / auth surface |
| bugfix | `workflow-bugfix` | defect with a reproducible failure |
| parallel | `workflow-parallel` | 2–4 independent slices; contracts frozen first; max 4 subagents |
| review | `workflow-review` | audit a diff or deliverable |

## Guardrails

- Never edit `.env*` files (permission prompts first); never print secrets.
- Migrations: additive or reversible, one per table, never in parallel on the same table.
- New routes/actions need authorization coverage; client-facing output must not leak internal notes or staff data.
- Keep controllers thin; logic belongs in services/actions. Follow existing patterns; no new dependencies without approval.
- `.omo/`, `.codegraph/`, `.openchamber/` are context, not task targets.
- Evidence per task: `.opencode/reports/<YYYY-MM-DD>-<slug>/` (`plan.md`, `verification.md`).

## Verification recipes

| Change | Run |
| --- | --- |
| formatting | `vendor/bin/pint --dirty` |
| logic / behavior | `php artisan test --filter=<Test>` then `php artisan test` |
| permissions / seeders | `php artisan test --filter SeederIntegrityTest` |
| migrations / seeders | `bash scripts/seed-smoke.sh` |
| front-end entries | `npm run build` |
| browser / UI | `php artisan dusk --filter=<Test>` |
| workflow wiring | `node .opencode/scripts/validate-workflows.mjs` |
