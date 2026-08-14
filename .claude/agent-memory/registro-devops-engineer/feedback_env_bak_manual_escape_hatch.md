---
name: env-bak-manual-escape-hatch
description: apply.sh regenerates .env wholesale every run -- any new operator-configurable per-tenant value must go in .env.bak-manual, never written to .env directly
metadata:
  type: feedback
---

For any per-tenant value an operator (not `apply.sh` itself) supplies -- monitoring URLs, business
credentials, anything `apply.sh` has no way to originate -- the value must live in
`${STACK_DIR}/.env.bak-manual`, never be written straight into `.env`.

**Why:** `apply.sh`'s "reconcile .env" step does `cat >"${STACK_DIR}/.env" <<ENV ... ENV` -- a full,
unconditional overwrite on every single apply/release. `.env.bak-manual`'s contents are appended
verbatim right after that heredoc, on every run, and is the ONE documented exception. This is already
[[deployment-md-9-2-env-regeneration-trap]] (the runbook's own "9.2" pitfall) -- I re-derived it while
adding `BACKUP_HEALTHCHECK_URL` (tenant-backup.sh's dead-man's-switch) rather than being told, by
reading apply.sh's own comment at its `.env` heredoc ("Business-specific credentials ... Put those in
.env.bak-manual instead").

**How to apply:** before adding ANY new per-tenant config value to a script under `scripts/server/**`,
check whether `apply.sh` owns that tenant's `.env` (it does, for every dedicated stack). If so, read it
via the same one-line `grep -m1 '^KEY=' .env` pattern every other non-secret value already uses, and
document in the script/runbook that the operator sets it via `.env.bak-manual`, not `.env`. Never invent
a second config file for this -- the mechanism already exists and is load-bearing elsewhere.
