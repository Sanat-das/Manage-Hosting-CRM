---
description: Audit a diff or deliverable against repo guardrails; findings by severity
---

# Workflow: Review

Audit, do not rewrite. Review the diff or deliverable and return findings with evidence.

## Steps

1. **Scope**: identify exactly what to review (changed files, diff, or named artifact). State what is out of scope.
2. **Review against the repo guardrails**:
   - Architecture: thin controllers, logic in services/actions, no business logic in views.
   - Security: authorization on every route/action, input validation, output escaping, CSRF, rate limits, no internal-note/staff-data leaks, no secrets in code or logs.
   - Data: indexes and foreign keys for new queries, no N+1, migrations reversible and additive.
   - Tests: changed behavior is covered in the right suite; permissions/seeders changes hit `SeederIntegrityTest`.
   - UI (when in scope): AdminLTE consistency, 8px spacing rhythm, typography, dark + light modes, accessibility, no oversized/childish elements.
3. **Verify claims** where code exists: run `vendor/bin/pint --test` and the relevant `php artisan test` scope — report actual output, not expectations.
4. **Findings by severity**: Blocker / Major / Minor / Nit. Each finding: `file:line`, what is wrong, and a concrete fix. Persist the findings in `.opencode/reports/<YYYY-MM-DD>-<slug>/review.md`.
5. **Close** with up to 3 verification questions when the conclusions are actionable (apply `discernment-nudge`, at most once per conversation).

## Rules

- Never edit during review; hand fixes to `workflow-feature`/`workflow-bugfix`.
- No finding without a location; no "looks fine" hand-waving — say what was checked and what was not.
- If the review touches UI and design judgment is required, load the `frontend-design` skill for the critique lens.
- Do not review generated/vendor files unless the change is there.
