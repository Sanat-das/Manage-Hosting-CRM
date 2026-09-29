---
description: Reproduce, fix, and lock in a regression test for a defect
---

# Workflow: Bugfix

A defect is only fixed when a failing test becomes a passing test.

## Steps

1. **Reproduce.** Write a failing test first: Feature/Unit for backend behavior; for UI bugs use `php artisan dusk` or the `webapp-testing` skill patterns. If a test is impossible, capture exact reproduction steps and evidence, and say why the test could not be written.
2. **Isolate.** Use `codegraph`/the `explore` subagent to find the root cause — do not shotgun-edit. Confirm the failing layer (route, middleware, service, model, view, asset).
3. **Fix** with the minimal change. No drive-by refactors, no public contract changes. If a contract change is genuinely required, stop and re-plan under `workflow-feature` (complexity gate applies).
4. **Regression test.** The failing test now passes and stays in the suite. Add adjacent edge cases only when they are real risks, not speculative ones.
5. **Verify** (run and report the output):
   - the new/updated test(s), failing before and passing after
   - `vendor/bin/pint --dirty`
   - `php artisan test` (full suite — a fix that breaks a neighbor is not a fix)
   - browser re-check via Dusk/`webapp-testing` when the bug was visual
6. **Evidence**: record root cause and command outputs in `.opencode/reports/<YYYY-MM-DD>-<slug>/verification.md`.
7. **Report**: root cause, fix, evidence (commands + results), blast radius.

## Rules

- Never connect to or mutate live provisioning infrastructure (HyperV, Proxmox, cPanel, etc.) to reproduce unless the user explicitly approves; prefer test doubles.
- Do not "fix" by weakening a test or a guard.
- If the bug touches auth/permissions/billing, the fix needs explicit authorization coverage and a security-minded review (`workflow-review`).
