---
description: Classify a request and route it to the right workflow (Workflow | Agents | Skills | MCP | First step)
---

# Workflow: Triage

Classify the request. Do not edit files. This is a router, not an implementer.

## Steps

1. Restate the request in one line.
2. Inspect only what is needed to classify. Use the `explore` subagent or `codegraph` (when the session directory is this repo) for code context.
3. Pick exactly one workflow:
   - `explore` — understanding is the deliverable.
   - `feature` — new capability or changed behavior.
   - `bugfix` — defect with a reproducible failure.
   - `parallel` — ≥2 genuinely independent slices; contracts can be frozen before dispatch.
   - `review` — audit of an existing diff or deliverable.
4. Apply the complexity gate: **>3 files OR a migration/schema change OR auth/billing/permission/security surface ⇒ the chosen workflow must start with `plan`**.
5. List skill hooks only when their trigger matches (UI ⇒ `frontend-design`; UI verification ⇒ `webapp-testing`; MCP work ⇒ `mcp-builder`; docs/artifacts ⇒ `theme-factory`; review/triage conclusions ⇒ `discernment-nudge`). Never load a skill as a default.
6. List MCP only when applicable: `codegraph` for repo code questions. Never list `everything` unless the request explicitly references a `demo://` URI.
7. End with the single most useful next action.

## Output

One table, then at most three bullet notes:

| Workflow | Agents | Skills | MCP | First step |
| -------- | ------ | ------ | --- | ---------- |
| <explore\|feature\|bugfix\|parallel\|review> | <e.g. plan → build, explore subagent> | <triggered hooks or —> | <codegraph or —> | <one concrete command or action> |

## Rules

- Name only agents, skills, and servers defined in `AGENTIC_WORKFLOW_SYSTEM.md` §1 and §7 (machine-readable: `.opencode/agentic-workflows.json`). Invented helpers are invalid.
- Do not start implementation work from triage; hand off by naming the workflow command.
- If the request is unclear, say what is missing instead of guessing.
