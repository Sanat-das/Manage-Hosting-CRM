---
description: Execute independent work slices in parallel via subagents, contracts first
---

# Workflow: Parallel

Use only when the work decomposes into genuinely independent slices. Contracts first, integration last.

## Steps

1. **Freeze contracts.** Write down the interfaces before any dispatch: routes, DTOs/payloads, service signatures, events, error shapes. Slices may not change them unilaterally.
2. **Slice.** 2–4 work units with **exclusive file ownership** (no two subagents touch the same file). Assign each slice its own tests.
3. **Dispatch** each slice to a `general` subagent with: the frozen contract, its owned files, its test scope, and the required report format (files changed, tests run, blockers).
4. **Integrate** in `build` once all slices return. Resolve conflicts at the contract level; never edit a sibling's files mid-flight.
5. **Verify once, fully**, after integration: `vendor/bin/pint --test`, `php artisan test`, plus `npm run build` / seed-smoke / Dusk gates when the slice types require them.
6. **Evidence**: slice map, contracts, and integration results in `.opencode/reports/<YYYY-MM-DD>-<slug>/`.
7. **Report**: slice map, contract, per-slice results, integration notes.

## Hard rules

- **Max 4 concurrent subagents.**
- **Never run migrations on the same table in parallel.** Schema work is sequential, one migration per table.
- No overlapping file ownership. No slice depends on another slice's unmerged output.
- Every slice has a test; a slice without a test is incomplete.
- A contract change invalidates every sibling slice: stop all of them, re-plan, re-dispatch. Do not patch around it.
- If the problem is not truly parallelizable, fall back to `workflow-feature` and say so.
