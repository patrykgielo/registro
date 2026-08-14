# registro-devops-engineer memory

- [env-bak-manual-escape-hatch](feedback_env_bak_manual_escape_hatch.md) — new per-tenant operator config goes in `.env.bak-manual`, never `.env` (apply.sh rewrites `.env` wholesale every run)
- [tier1-budget-prefer-runbook](feedback_tier1_budget_prefer_runbook.md) — TIER 1 rules at 11,924/12,000 chars, no headroom; put new deployment guidance in the runbook, not deployment.md
- [project-silent-failure-probes](project_silent_failure_probes_2026-08-14.md) — cert-expiry probe + backup dead-man's-switch, built 2026-08-14, not yet run on any real server
- [fake-pipe-stdin-drain](feedback_fake_pipe_stdin_drain.md) — a fake reader on the far end of a real pipe (fake `docker run` consuming fake `restic dump`) must drain stdin or the test flakes under CPU load
