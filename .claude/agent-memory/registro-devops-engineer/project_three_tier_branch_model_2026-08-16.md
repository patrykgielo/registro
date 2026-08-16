---
name: three-tier-branch-model-2026-08-16
description: feature/* -> develop -> staging -> main adopted 2026-08-16, default branch moved to develop, staging revived as a permanent branch -- what changed and what is still aspirational
metadata:
  type: project
---

2026-08-16: user decision, not a bug fix. Branch model became three-tier:
`feature/* → develop (PR) → staging (PR) → main (PR)`.

- `develop` — integration, now the repo's **default branch** (moved from `main`). Reason: GitHub
  only registers a new workflow file for `gh workflow run` once it exists on the default branch —
  a workflow added anywhere else 404s until it lands there. With `main` as default (protected,
  full PR chain required) a new/changed workflow couldn't be dispatched even once for testing
  before merging all the way to `main`.
- `staging` — cuts `rc*` tags. Was a stale branch pointing at the project's first commit; rewound
  to `develop`'s tip. `release/*` (old two-tier naming, ephemeral branch per release) is
  superseded — `staging` is now permanent, don't expect to see new `release/*` branches.
- `main` — production tags. `hotfix/*` stays as the emergency escape hatch straight from `main`,
  skipping `staging`.

**What is NOT true yet, despite the table implying otherwise:** `deploy-production.yml`'s `deploy`
job has exactly ONE target (`secrets.VPS_HOST`, i.e. UAT) with no branch/tag-shape routing logic.
Dispatching an `rc*` tag from `staging` or a production tag from `main` deploys to the same,
single machine today. The `staging → UAT` / `main → PreProd` split only becomes real once PreProd
is bought and wired into this (or a second) workflow — see [[project-two-machines-uat-preprod]].

**Two real gaps found in `.claude/hooks/pre-tool-use.sh` by running it, not reading it** (both now
fixed, `tests/shell/cases/34_pretooluse_hook_three_tier_branch_model.sh` pins both): RULE 5 fell
through to the generic branch bucket for `staging`, blocking exactly the `staging → main`
promotion PR it needed to allow. RULE 1 protected `develop`/`main` from direct commits but not
`staging`. Testing this hook is tricky: the OUTER hook (the live one, already-edited) intercepts
the AGENT's OWN Bash tool calls if the command text contains a literal trigger substring like
`gh pr create --base main` — write JSON test payloads to files via the Write tool, never embed
them literally in a Bash command string, or the test harness blocks itself.

**Full incident + reasoning**: `.claude/rules/ci-cd-troubleshooting.md` → "2026-08-16: model
trzywarstwowy". Rule changes: `.claude/rules/git-workflow.md` (rewritten, trimmed hard to stay in
TIER 1 budget — see [[tier1-budget-prefer-runbook]]), `.claude/rules/release-documentation.md`
(new scope split: `rc*` tags need only a substantive annotated-tag message, production tags off
`main` need the full `docs/releases/vX.Y.Z.md` doc), `.github/workflows/RELEASE_PROCESS.md`
(rewritten — its "there is no staging environment" framing predated this decision by hours).

**Two other stale docs found but NOT fixed (flagged only, out of this session's scope):**
`app/docs/deployment/known-issues.md` (dated 2025-11-30) has a rollback playbook instructing
`git push origin staging --force` and a `hotfix/*` merge-back-into-`staging` flow — under the new
model `staging` is merge-only (direct push/force-push forbidden) and there's no merge-back step,
so this historical doc now reads as live, wrong advice if anyone finds it during an incident.
`app/docs/deployment/base-image-split.md` line ~210 says to dispatch `build-base-image.yml` "z
`main`/gałęzi zawierającej" — should now say `develop`, the actual default branch.
