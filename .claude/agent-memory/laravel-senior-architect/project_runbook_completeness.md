---
name: project_runbook_completeness
description: Filled the 3 gaps in instalacja-tenanta-od-zera.md (password-link command, offboarding procedure, restore-from-backup procedure) — the non-obvious infra findings behind them
metadata:
  type: project
---

Branch `feature/runbook-completeness` (2026-08-09) closed three named gaps in
`app/docs/deployment/instalacja-tenanta-od-zera.md`: step 4.1's placeholder, no offboarding
procedure, no restore-from-backup procedure. New command: `App\Console\Commands\ResendPasswordSetupLinkCommand`
(`registro:password-setup-link {email} [--no-email] [--force]`), tests in
`tests/Feature/Console/ResendPasswordSetupLinkCommandTest.php`.

**Why:** the owner wanted the doc handed to a DevOps engineer who's never seen the project and have
them onboard/offboard a client with zero questions asked — the prior doc silently assumed context
(a real command for 4.1) or skipped entire procedures (offboarding, restore) rather than naming them
as gaps.

**How to apply:** the non-obvious infra facts below are load-bearing for anyone touching
`apply.sh`/`sync-certificate.sh`/`docker-compose.prod.yml`/the edge stack again — re-verify before
trusting if any of those files have changed since 2026-08-09.

## Non-obvious findings (verified by reading + local reproduction, not guessed)

1. **`docker-compose.prod.yml` has a top-level `name: ${TENANT_PREFIX:-registro}` (line 33).** This
   is the ENTIRE reason Compose project name (and therefore every volume name,
   `tenant-<slug>_mysql_data` etc.) is `TENANT_PREFIX`-based rather than the cwd-basename
   (`<slug>`) Compose would otherwise default to. Verified with `docker compose config --format
   json` against a throwaway `.env` — without spotting this one line, `force_clear_flag()`'s
   manually-computed volume name in `apply.sh` looks like a bug (TENANT_PREFIX="tenant-acme" vs
   cwd basename "acme" would be two different strings) but isn't.

2. **Offboarding a dedicated tenant stack has no super-admin actor by default.**
   `registro:tenant-provision` only grants the owner role `admin`, never `super-admin`. So
   `StartOrganizationOffboarding::execute()` (which requires a super-admin `$actor` for its own
   auth+audit trail) cannot be called from a fresh dedicated stack without first running
   `registro:create-owner` on THAT stack (same command, works per-Laravel-instance since every
   dedicated stack is its own full app+DB). This is the sanctioned mechanism, not a workaround —
   its own docblock already says "the super-admin who administers the SaaS itself via /platform",
   which is per-installation, not global.

3. **`sync-certificate.sh` dies (aborts the WHOLE cert-renewal run, for every tenant, not just
   the one being touched) if a stack directory exists with its compose file present but the
   container doesn't answer.** This means tenant teardown order matters: stopping containers while
   leaving `/opt/stacks/<slug>` in place for any extended window breaks cert renewal platform-wide
   until the directory is finally removed. Correct order: verify final backup FIRST (while
   containers are still healthy), THEN do stop+volume-removal+directory-removal as one
   uninterrupted command chain. A stray cron tick landing in the few-second window is self-healing
   (next 15-min run succeeds once the dir is gone) — an extended pause is not.

4. **Legal retention (Art. 112 VAT, 6 years) survives tenant teardown ONLY via the restic backup.**
   `organizations:purge` anonymizes PII but deliberately leaves legal-record rows (orders, payments,
   tenant_payments, rentals) in the live DB — `docker compose down --volumes` on the tenant's own
   stack destroys that DB permanently. The restic repo at `/opt/registro/tenant-backups/<slug>`
   lives OUTSIDE `/opt/stacks/<slug>` (survives directory removal) and is the only thing that can
   legally NOT be deleted for 6 years post-closure — nothing in code enforces this; it's a named,
   unenforced operator obligation (retention-lock is a real gap, not solved here).

5. **`tenant-backup.sh`/`apply.sh` back up exactly one file: the `mysqldump` output.** Uploaded
   media (`storage-app-public`/`storage-app-private` docker volumes, `FILESYSTEM_DISK=public`) has
   NO backup mechanism at all today — destroyed permanently by `docker compose down -v` with no
   recovery path. Named as a gap in the runbook; the doc gives a manual `docker run ... tar czf`
   one-liner as an opt-in mitigation, not an automated fix.

6. **Edge attachment/detachment is fully regenerative, not something to hand-edit.**
   `apply.sh`'s `edge-sync` step derives `docker-compose.edge.tenants.override.yml` from whichever
   `docker/nginx/edge/tenants.d/*.conf` files are actually present on disk — this supersedes
   `edge-stack.md`'s older "hand-edit docker-compose.edge.yml" manual runbook (that doc says so
   itself). Detaching a tenant is the mirror: delete its `tenants.d/<slug>.conf`, re-run the same
   regeneration loop, reload `edge-nginx`. Runbook's Część 7.4 copies this loop verbatim from
   `apply.sh` with a note to re-diff if the script's own loop ever changes.

7. **restic containers run as root; host user can't clean up the repo directory afterward without
   an intermediary `alpine` container to `chown`/`rm`.** Bit twice during the local restore drill
   (2026-08-09) — relevant if `deploy` isn't root on the real server and needs to clean a restic
   working dir by hand.

## Review round found 4 real defects (2026-08-09) — all fixed, all re-verified

1. **`.env.secrets` values are single-quoted, meant to be sourced, not grepped.**
   `DB_ROOT_PASSWORD='<value>'` — `grep -m1 ... | cut -d= -f2-` keeps the literal quote
   characters (34 chars extracted vs 32 real, reproduced). `mysql -p"$WRONG"` then fails auth —
   and since this ran after `artisan down` in the same `&&` chain, the failure mode was the app
   stuck in maintenance mode with nothing restored. Fix: `set -a && . .env.secrets && set +a`,
   matching `tenant-backup.sh`/`apply.sh` exactly. Any doc/script snippet that reads
   `.env.secrets` must source it, never grep|cut it.
2. **`curl -s -o /dev/null -w "HTTP %{http_code}\n"` against a connection nginx closes with bare
   `444` prints `HTTP 000`, not silence.** `-s` suppresses curl's own error message, not the `-w`
   line. Reproduced against a real throwaway nginx returning `444`. If you want the runbook's
   "expect X" prose to match reality, run the command first — don't infer what `-s` hides.
3. **`php artisan down` blocks HTTP only.** `horizon`/`scheduler` containers keep consuming
   queues and writing to the DB while a restore's `DROP TABLE`/`CREATE TABLE` sequence runs
   non-atomically. Stop them before the import, restart after.
4. **A pipeline fallback (`cmd | cut ... || echo default`) is dead without `set -o pipefail`** —
   the `||` sees `cut`'s exit status (0), not the upstream command's. `tenant-backup.sh` gets away
   with this exact pattern because it has `set -euo pipefail` at the top; a bare `ssh host '...'`
   one-liner in a doc does not. Use `"${VAR:-default}"` parameter-expansion instead — it doesn't
   depend on pipefail.

## Verified end-to-end locally (2026-08-09)

- `registro:password-setup-link`: full command + guard (`--force` required to touch an account
  that already has a password) run against the real dev container (throwaway user, cleaned up).
- Restic backup→destroy→restore→reimport drill: throwaway `mysql:8.0` + `restic/restic` containers
  (never part of this repo's compose project), exact `mysqldump` flags copied from
  `tenant-backup.sh`, both `restic restore --include` and `restic dump` verified, reimported SQL
  produced byte-identical rows. Full transcript is in the runbook itself (Część 8.5) — not
  duplicated here since it's the doc's own content, not a memory-worthy secondary fact.
