# Workflow Reports

Durable evidence written by the agentic workflows. One directory per task:

```text
.opencode/reports/<YYYY-MM-DD>-<slug>/
├── plan.md          # plan phase output (feature/parallel workflows)
├── verification.md  # commands run, results, residual risks
└── review.md        # findings by severity (review workflow)
```

Rules:

- Never overwrite another run's directory; use a new date + slug.
- This directory is workflow-owned. It is not app code, not configuration, and not committed evidence unless the user asks.
- Keep reports free of secrets — command output must be redacted before it lands here.
