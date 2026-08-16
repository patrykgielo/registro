---
name: tier1-rules-audit-2026-08-16
description: full correctness audit of the six always-loaded TIER 1 rule files against real repo state -- what was false, what moved to TIER 2, budget recovered
metadata:
  type: project
---

2026-08-16, `feature/tier1-rules-audit`: verified every factual claim in `self-improvement.md`,
`agent-usage.md`, `git-workflow.md`, `deployment.md`, `planning.md`, `_INDEX.md` against the real
repo (hook source, `docker-compose.prod.yml`, `.github/workflows/*.yml` `on:` blocks, `.gitignore`,
`app/Models/AuditLog.php`, `scripts/server/apply.sh`, line counts) instead of trusting the prose.

**The two false TIER 1 claims the task brief cited (self-improvement.md ZASADA 0/1, "push triggers
auto-deploy", "check gh run list after every merge") were ALREADY fixed** by an earlier session,
commit `b3969e5` (#203, [[three-tier-branch-model-2026-08-16]]) — the task brief's examples were
stale by the time this session ran. Confirmed current text is accurate: `git push origin vX.Y.Z`
triggers nothing (all 6 workflow files are `workflow_dispatch`-only, verified by grepping every
`on:` block), only `gh workflow run` does.

**One claim found false and fixed in THIS session**: `git-workflow.md` said `git merge main` is a
hook false-positive requiring `git merge origin/main` as a workaround. Traced to the hook version
at the initial commit (`4d20ef4`), which matched a bare `*main*` substring. The current
`pre-tool-use.sh` RULE 2 only matches `git push` + (`origin main`/`push main`) — verified
EMPIRICALLY, not by reading: `echo '{"tool_name":"Bash","tool_input":{"command":"git merge main -m
x"}}' | .claude/hooks/pre-tool-use.sh` → `permissionDecision: allow`. Removed the false claim
entirely (not moved — a wrong fact doesn't belong anywhere).

**Stale but not wrong-headed**: `deployment.md` cited "3 935 linii skryptów wdrożeniowych" (from
commit `9d96783`, PR #170). Recounted: `scripts/**/*.sh` alone is now 7 988 lines — more than
double, because scripts grew after that number was written. Replaced with "tysiące linii" (no
exact figure) rather than a fresher exact count, on the theory that any hardcoded line count in an
always-loaded rule *will* go stale again — the qualitative point (there's a lot of shell, test it)
doesn't need a number to carry weight.

**TIER 1 → TIER 2 migration** (test applied: "would not knowing this bite BEFORE any path-triggered
file loads?"): moved the Docker-file≠reality mechanics (orphan detection, `NIGDY queue z
Horizonem`, `docker run --entrypoint sh` reasoning, the `${VAR:?}` recovery-path trap) and the full
`TENANT_PREFIX`/two-machine elaboration from `deployment.md` into a new dated section in
`ci-cd-troubleshooting.md` (already `paths`-triggered on `docker-compose*.yml`/`scripts/**`).
Kept in TIER 1: the one-line versions of each fact that can bite through a bare `docker`/`.env`
Bash command with no file touched at all — same logic as why `FILESYSTEM_DISK=public` stays TIER 1
and Filament v4 namespaces don't. `_INDEX.md`'s 24-row three-column table (file/paths/description)
became a flat `file → paths` list, cutting ~330 chars while keeping every path (the actual
load-trigger information) intact — dropped only the free-text "what it covers" column, recoverable
by opening the file.

**Budget**: 11,997/12,000 before this audit → 10,685/12,000 after (`cc-doctor.sh` confirmed clean).
Per-file: `deployment.md` 3,730→2,921, `_INDEX.md` 2,194→1,864, `agent-usage.md` 2,954→2,876,
`git-workflow.md` 1,061→966. `self-improvement.md` and `planning.md` untouched (already accurate,
nothing hook-enforced or path-triggerable to offload).

**Not independently re-verifiable, flagged as such in the PR**: the specific historical incidents
cited by date (2026-01-25 plan overwrite, 2026-02-05 case-sensitivity search miss and
`[object Object]` bug, CC issue #67665's exact mechanics) — these are past-event claims with no
artifact left to re-inspect; treated as trustworthy prior documentation, not re-proven.

**Found but explicitly out of scope (flagged, not fixed)**: `CLAUDE.md` (project root, not a
`.claude/rules/` file) still describes the OLD two-tier `feature/* → develop (PR) → main (PR)`
flow and says the hook blocks "develop/main" commits only — both now wrong under the three-tier
model `git-workflow.md` documents. `ci-cd-troubleshooting.md` already separately flagged
`known-issues.md`'s stale `staging --force-push` rollback playbook and `base-image-split.md`'s
stale `main`-as-default-branch instruction ([[three-tier-branch-model-2026-08-16]]) — CLAUDE.md is
a third, previously-unflagged instance of the same drift.
