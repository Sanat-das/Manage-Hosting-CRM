---
description: Read-only exploration of the codebase before deciding what to do
---

# Workflow: Explore

Read-only understanding. The deliverable is a map, not a change. Never edit files, never run migrations or seeders.

## Steps

1. **CodeGraph first.** When the session directory is this repo (`.codegraph/` exists), start with `codegraph explore "<symbol names or question>"` or the `codegraph_explore` MCP tool. It answers most code questions in one call, including dynamic-dispatch hops grep cannot follow.
2. Dispatch the `explore` subagent with one precise question (thoroughness: medium by default, very thorough for cross-cutting questions). Parallel `explore` calls are allowed — they are read-only.
3. Confirm findings with targeted `read`/`grep` only where the answer must be exact (file locations, line numbers, test names).
4. Follow the trail through the relevant layers: routes (`routes/`) → controllers/middleware → actions/services → models/migrations → Blade views/assets → tests.
5. Summarize.

## Output

- **Entry points**: routes/controllers/middleware touched by the question.
- **Flow**: data path through services/actions/models.
- **Tests**: existing coverage (suite + test names) and gaps.
- **Risks**: authz, migrations, N+1, UI regressions, anything surprising.
- **Recommended next workflow**: `feature` / `bugfix` / `review`, with the reason in one line.

## Rules

- Read-only: no `edit`/`write` calls, no `artisan migrate`, no seeders, no dependency installs.
- Cite real paths and symbols; never invent file names.
- Keep the summary tight — findings, not narration.
