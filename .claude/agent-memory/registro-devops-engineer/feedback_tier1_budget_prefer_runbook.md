---
name: tier1-budget-prefer-runbook
description: TIER 1 rules (always-loaded) sit at ~11.9k of a 12k hard character budget -- default to the operator runbook for new deployment guidance, not deployment.md
metadata:
  type: feedback
---

As of 2026-08-14, `self-improvement.md` + `agent-usage.md` + `git-workflow.md` + `deployment.md` +
`planning.md` + `_INDEX.md` together total 11,924 of the 12,000-char TIER 1 budget `cc-doctor.sh`
enforces (`.claude/rules/_INDEX.md`'s own stated limit). There is essentially no headroom left.

**Why:** every TIER 1 rule is loaded in EVERY session regardless of relevance -- adding to it means
cutting the same amount elsewhere, and past this budget rules compete for attention with no signal
that they're being under-applied ([[deployment-md-9-2-env-regeneration-trap]] and every other
deployment.md rule already lives in this squeeze).

**How to apply:** when documenting a new script, procedure, or operator step, default to
`app/docs/deployment/instalacja-tenanta-od-zera.md` (the operator runbook) or the relevant deep-dive
doc (`tenant-apply.md`, `edge-stack.md`, `tenant-compose-stack.md`) -- neither is budget-constrained.
Only touch `deployment.md` for a genuinely universal, always-relevant absolute (a new BEZWZGLĘDNY
ZAKAZ), and even then, measure the total four-file+index character count first and cut elsewhere if
adding. Confirmed as the right call when building the certificate-expiry-probe and backup dead-man's-
switch (2026-08-14, `feature/silent-failure-probes`) -- put all operator-facing detail in the runbook,
touched deployment.md not at all.

**Confirmed again 2026-08-16** (`feature/branch-model-hook-and-rules`, three-tier branch model
`develop → staging → main`): `git-workflow.md` genuinely needed to grow (new tier, new hook
behavior to document) and even the terse version blew the budget by ~1.6k chars on the first draft.
Put the deep reasoning (why `staging` isn't a server, why `deploy-production.yml` has one target
today, the default-branch-404 mechanism) in `.github/workflows/RELEASE_PROCESS.md` and a new
`ci-cd-troubleshooting.md` incident entry (both TIER 2 / doc, zero budget cost) instead of TIER 1 --
then trimmed `git-workflow.md` itself sentence-by-sentence, re-running `cc-doctor.sh` after every
cut, until it landed at 11,997/12,000. Zero headroom is now the steady state of this budget, not an
anomaly -- expect every future TIER 1 edit to require an equal-sized cut somewhere in the same file.
