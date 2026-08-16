# registro-devops-engineer memory

- [env-bak-manual-escape-hatch](feedback_env_bak_manual_escape_hatch.md) — new per-tenant operator config goes in `.env.bak-manual`, never `.env` (apply.sh rewrites `.env` wholesale every run)
- [tier1-budget-prefer-runbook](feedback_tier1_budget_prefer_runbook.md) — put new deployment guidance in the runbook, not deployment.md; corrected 2026-08-16, "zero headroom" wasn't a floor, an audit recovered 1,312 chars
- [tier1-rules-audit-2026-08-16](project_tier1_rules_audit_2026-08-16.md) — full correctness audit of all six TIER 1 files; one false claim removed (stale `git merge main` hook warning), rest moved to TIER 2, budget 11,997→10,685
- [three-tier-branch-model-2026-08-16](project_three_tier_branch_model_2026-08-16.md) — feature/*→develop→staging→main, default branch moved to develop, deploy-production.yml still has only ONE real target (UAT) regardless of tag source
- [project-silent-failure-probes](project_silent_failure_probes_2026-08-14.md) — cert-expiry probe + backup dead-man's-switch, built 2026-08-14, not yet run on any real server
- [fake-pipe-stdin-drain](feedback_fake_pipe_stdin_drain.md) — a fake reader on a real pipe must drain stdin, but ONLY in the branch matching that one piped call — a catch-all drain hangs forever on open stdin (corrected after a regression)
- [test-both-load-and-stdin-axes](feedback_test_both_load_and_stdin_axes.md) — CPU-load robustness and open-stdin robustness are independent axes; a `&` background job in a test case leaks fds into `$(...)` and orphans its children on `kill`
- [hardening-needs-stateful-volume](feedback_hardening_needs_stateful_volume.md) — cap_drop/cap_add for a service with a named volume must be verified against a volume with real prior activity, not a fresh one (rc12 redis incident, PR #189)
- [project-ci-security-scanning-2026-08-16](project_ci_security_scanning_2026-08-16.md) — composer audit + Trivy shipped as steps not jobs, report-only; real counts (35 advisories, 2275 image findings) measured, not assumed
- [feedback-verify-dont-assume-composer-audit-semantics](feedback_verify_dont_assume_composer_audit_semantics.md) — task brief had composer audit's default-vs---locked backwards; read the source (`gh api`) instead of trusting a plausible assumption for a security gate's semantics
