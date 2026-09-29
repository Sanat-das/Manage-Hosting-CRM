# Workflow Reconciliation Routine (idempotent)

Run when agents, skills, MCP servers, or OpenChamber settings change — monthly via the OpenChamber schedule, or manually from a session whose directory is this repo. Workflow configuration only: never edit app code, never commit, never print secrets.

## Steps

1. **MCP**: read `~/.config/opencode/opencode.jsonc` and this repo's `opencode.jsonc`; record every server (type, command, enabled). Use `list_mcp_resources` to classify prod vs demo (`demo://` resources are always demo). Never run `get-env` or surface its output.
2. **Skills**: list `~/.config/opencode/skills/*`; read the first lines of each `SKILL.md` (name + description). Note project skills under `.opencode/skills/`.
3. **Agents**: read `~/.config/opencode/agents/*.md`; record description, mode, model, and permissions.
4. **Runtime**: `projects.list`, `models.list`, `schedule.list` (this project), `session.list` (limit 20, withStatus).
5. **Repo facts**: confirm what the guardrails cite — `composer.json`, `package.json`, `.codegraph/`, `tests/`, `.github/workflows/`, routes layout.
6. **Reconcile**: update `.opencode/agentic-workflows.json`, `AGENTIC_WORKFLOW_SYSTEM.md`, and `.opencode/commands/workflow-*.md` so they reference only discovered names. Additive edits only; keep the complexity gate, router rules, and Laravel guardrails accurate.
7. **Verify**: run `node .opencode/scripts/validate-workflows.mjs`; confirm six `workflow-*.md` files exist; grep the artifacts for agent/skill/server names that are not in the discovery lists and fix hits (names explicitly marked "not installed" are allowed).
8. **Report**: discovery table, changed files, verification output, gaps and risks.
