# Tenant Apply — `apply` / `check` / `tenant-backup`

**Scope:** `scripts/server/apply.sh`, `scripts/server/tenant-check.sh`,
`scripts/server/tenant-backup.sh`, and the `setup-production-server.sh` additions
(`dnsutils`/`restic`, `/opt/stacks`, `/opt/registro/tenant-backups`, `loginctl
enable-linger`, the two new cron entries) that support them.
**Last verified:** 2026-08-10, three rounds — an initial full local dry run
against two independently provisioned tenant stacks (real image, real
migrations), a second infrastructure-review pass that found six more
problems (signal-safety of the status file, maintenance-mode fail-open on
two isolation-risk checks, a migrate-retry overclaim, restic lock
contention, backup-failure status conflation, and a port-allocation race —
see "Infrastructure review — six more fixes" below), and a third pass (Faza 1
of the two-machines plan, see "One tenant, one domain" below) that removed
the hardcoded two-domain default and re-verified end to end. All fixed and
individually reproduced against real Docker/restic state except the
port-allocation race under genuine concurrent load (noted as such in its own
section). See "What was actually run" below for all three rounds. **Not
deployed anywhere** — no server has `/opt/stacks` yet.
**Related:** [Tenant Compose Stack](tenant-compose-stack.md) (task 4 — the
per-tenant compose file `apply` reconciles), [Edge Stack](edge-stack.md) (task 5
— the ingress `apply`'s edge-sync step attaches to), [Tenant-Stack
Provisioning](../features/tenant-stack-provisioning.md) (task 2/1 —
`registro:tenant-provision`/`registro:tenant-provisioned`, which `apply` calls
rather than duplicating), `scripts/server/deploy.sh` (the legacy stack's own
forced-command entry point — `apply` is a sibling verb, not an extension of
that file), `.claude/rules/ci-cd-troubleshooting.md`, `tests/shell/` (the
permanent regression suite pinning eight of the bugs below — see "Permanent
regression suite" further down).

---

## Task numbering, for context

Task 6 of the stack-per-tenant epic — the last big one. Tasks 1/2 (provisioning
primitives), 4 (the per-tenant compose file) and 5 (the edge) all landed with
every manual step they required left undocumented-as-automation on purpose,
deferred here. This is that automation.

## Why `apply`, not a separate `tenant-new.sh`

The task brief's own framing: a script that only runs once per client is
exercised once, then not again until the *next* new client — by which time
everything else in this repo has moved and nobody notices the drift until it
breaks. `apply` is the **same command** for provisioning a brand-new tenant and
for reconciling an existing one on every later release: `apply <slug> <tag>`.
Every step is written to be idempotent — safe to re-run, safe to re-run after a
failure on the same tag, safe to re-run with a new tag on a tenant that has
been live for a year. It is a sibling verb to `deploy.sh`, not an extension of
it: `deploy.sh` is hardcoded, throughout, to the one legacy directory
(`APP_DIR="/var/www/registro"`) and every one of its recovery paths
(`force_clear_flag`, the `OVERRIDE_FILE` it always passes) is written for that
single, known stack — see that file's own header. Threading a second
tenant/directory concept through those functions would have weakened the
guarantees documented there for no benefit; `apply.sh` reuses the same
*conventions* (locking, logging, a maintenance-mode safety net, `set -euo
pipefail`, computed-not-looked-up volume names) without touching that file.

## The sequence, and why each step sits where it does

The brief's own ordering, verbatim, with the reasoning apply.sh's own comments
carry inline (this is a summary, not a duplicate):

1. **Preconditions — `dig` + disk space.** First, before anything touches disk
   or Docker, so an unready box fails in milliseconds with nothing to clean up.
2. **`.env.secrets` — written only when absent, refuses to overwrite.**
   `APP_KEY` encrypts `audit_logs`; regenerating it makes every existing
   encrypted record permanently undecryptable — there is no re-encryption path,
   because the *old* key is gone the moment the file would be overwritten.
   Grouped with `DB_PASSWORD`/`DB_ROOT_PASSWORD`/`REDIS_PASSWORD` for the
   related-but-different reason that once written, they are what the database
   and Redis actually hold — changing them here without also changing them
   there locks the stack out of its own data. `set -o noclobber` backs the
   if/else with a structural guard: even a future logic bug that reached the
   write branch while the file existed would hit a hard shell error on `>`,
   never a silent overwrite.
3. **Pre-dump.** A fast, *local* safety net taken immediately before
   migration — captures the database exactly as the *old* code left it, so a
   bad migration can be undone in seconds without restic's restore machinery.
   Skipped on a stack's first apply (nothing to dump yet).
4. **Migrate from a one-off container on the new image, old containers still
   serving.** `docker compose run --rm --no-deps app php artisan migrate
   --force` on the freshly-pulled image, while the currently-serving `app`/
   `nginx`/`horizon`/`scheduler` stay on the old one — bounds the schema-change
   window to the migration itself rather than the full `up -d`/health-wait
   cycle `deploy.sh` needs. `app artisan down` is still called on the old
   serving container first, if one exists, as a safety net around the DDL
   itself — orthogonal to which container runs the migration.
5. **`up -d`.** Recreates all six services on the new image.
6. **Seeds, gated by the provisioning marker.** Calls
   `registro:tenant-provisioned` before deciding whether to call
   `registro:tenant-provision` — exactly the gap
   `tenant-stack-provisioning.md`'s own "Known gaps" section named. See
   "The `--assert` surprise" below for a real bug this uncovered.
7. **Edge-sync.** Renders this tenant's vhost into `tenants.d/<slug>.conf`,
   seeds `tenant-pages/<slug>/`, regenerates the edge's own tenant-network
   override file from the ground truth (every `tenants.d/*.conf` present, not
   a separately hand-maintained list), and reloads `edge-nginx`. Runs from the
   **legacy checkout** (`/var/www/registro`), not the tenant's own — the edge
   stack is not duplicated per tenant. See "Correcting edge-stack.md" below.
8. **Assert X-Tenant.** Drops a one-line probe into the shared `app_public`
   volume and reads it back through *this tenant's own nginx* (not through the
   edge over HTTPS — see below), confirming `app.tenant.local.conf` was
   rendered with the real slug and the fastcgi hop sets it correctly. Same
   technique `tenant-compose-stack.md`'s own manual verification used.
9. **Asset gate.** Identical logic to `deploy.sh`'s own (manifest hash
   compare, then a full `public/` diff) — not reinvented, just re-scoped to
   this tenant's containers.
10. **Backup.** restic, described fully below.
11. **`REGISTRO_VERSION` pinned in `.env` — last, only once everything above
    succeeded.** A failure at any earlier step leaves `.env` naming the
    *previous* version, so re-running the exact same command is a clean retry,
    never a resume from a half-migrated state.

### Why the X-Tenant assertion doesn't go through the edge

The edge's certificate may not cover this tenant's hostname **yet** — even now
that `sync-certificate.sh` enumerates dedicated stacks too (see `edge-stack.md`'s
"Known gap, fixed"), a brand-new tenant still has to wait for the next
15-minute cron reconcile before its name is actually on the certificate.
Asserting through HTTPS via the edge would make this step flaky on every
brand-new tenant for a reason that has nothing to do with what this step
actually verifies. What it verifies instead — the one thing genuinely in this
task's control — is that this tenant's own nginx hop sets `HTTP_X_TENANT` from
the real rendered slug, not the placeholder. **Residual, stated plainly:** this
does not prove the edge correctly proxies to this tenant end-to-end over HTTPS
with a valid certificate within that first 15-minute window.

## One tenant, one domain

Faza 1 of the two-machines plan (`~/.claude/plans/dwie-maszyny-uat-preprod.md`). The problem it
closes: `apply.sh` used to default `[hosts]` to a **hardcoded pair**,
`<slug>.registrolabs.com,<slug>.registroapps.com`, and the edge vhost template
(`tenants.d/_example.conf.disabled`) carried that same pair literally in its `server_name` line —
kept in sync with the constant by hand, per that constant's own former comment. Harmless on one
machine. Once a second machine exists and `*.registroapps.com`'s wildcard DNS points at it, a
tenant provisioned on the FIRST machine still asks for a certificate covering
`<slug>.registroapps.com` — HTTP-01 validation for that name lands on the other box, which has no
challenge file for it, and Let's Encrypt rejects the **whole** certificate order over that one
failed name. Every tenant's renewal on the first machine breaks at once, silently, until expiry.

**The fix: the domain is a property of the machine, not of the invocation.** `apply.sh` no longer
carries a domain constant at all. When `[hosts]` is omitted, it reads `APP_DOMAIN` from the
LEGACY checkout's own `.env` (`${LEGACY_APP_DIR}/.env`) — the exact same precedent `CERT_DIR`
already established (read from the same file, for the same reason: a value that belongs to this
installation, not something an operator retypes per tenant and can get wrong). `APP_DOMAIN` is not
a new key to configure either — `deploy-init.sh`'s `create_env_file` already prompts for it and
writes it, for the legacy stack's own subdomain routing (`config/app.php`'s `baseDomain`). The live
UAT machine already has `APP_DOMAIN=registrolabs.com` in that file, so it needs **no** new
configuration to keep working correctly under the new default.

If `APP_DOMAIN` is absent (unset, or set to an empty value) and no explicit `[hosts]` argument was
given, `apply.sh` refuses immediately — before DNS/disk preconditions, before the lock, before
anything touches disk or Docker — naming the exact file to fix:

```
ERROR: APP_DOMAIN not set in /var/www/registro/.env -- this machine's domain must be configured
(run deploy-init.sh on the legacy stack) before a tenant can be provisioned without an explicit
[hosts] argument
```

An explicit `[hosts]` argument still works exactly as before and is not affected by any of this —
it is the intended path for a client's own domain later (Faza 3 of the two-machines plan).

The edge vhost template's `server_name` line changed from two hardcoded domains to a single
placeholder, `TENANT_SERVER_NAMES`, substituted by `apply.sh`'s own edge-sync step with this
stack's own `HOSTS` (comma-to-space converted — nginx wants a space-separated list). This is what
ends the "kept in sync by hand" coupling the old constant's comment warned about: `server_name` now
carries exactly what THIS stack's own `.env` says, never a second hardcoded domain, whether that is
one hostname (the default) or several (an explicit `[hosts]` override).

**Verified end to end** in a sandboxed clone (throwaway git origin, a locally built image, real
`docker compose`, nothing touching the live server, dev database, or Let's Encrypt): a
default-hosts tenant got exactly one name in `TENANT_HOSTS` and in the rendered `server_name`;
an explicit two-host `[hosts]` argument produced exactly those two names in both places; a legacy
`.env` with no `APP_DOMAIN` failed immediately with the message above; `tenant-check.sh` passed
silently against the resulting stack; and the rendered vhost passed `nginx -t` in a throwaway
`nginx:1.25-alpine` container against a throwaway self-signed certificate — the same pattern
`edge-stack.md`'s own validation already used.

## `${LEGACY_APP_DIR}/.env` — the complete list of keys `apply.sh` and `sync-certificate.sh` read

Verified by grepping both scripts for `.env` reads against `LEGACY_APP_DIR`/`APP_DIR` (the same
directory, two names — see each script's own header), not by copying `.env.production.example`.
Four keys total, two required unconditionally:

| key | required? | read at | behavior if absent |
|---|---|---|---|
| `APP_DOMAIN` | yes, unless `apply.sh` is given an explicit `[hosts]` argument | `apply.sh:205`, `sync-certificate.sh:301` (optional there — only gates whether `www.<domain>` is probed) | `apply.sh` refuses immediately, before touching disk or Docker |
| `CERT_DIR` | yes, always | `apply.sh:956` (edge-sync step), `sync-certificate.sh:72` | both `die()` immediately |
| `NGINX_RELOAD_CONTAINER` | no (defaults to `registro-nginx`) — but see the legacy-free case below | `sync-certificate.sh:86` | reload targets `registro-nginx`, which is correct on the legacy shared stack and wrong on a machine that never ran it |
| `MAIL_FROM_ADDRESS` | no | `sync-certificate.sh:89` | falls back to `admin@${CERT_DIR}` — where Let's Encrypt sends expiry warnings. **Whether that mailbox receives anything is unverified.** Does not block issuance; loses the warning |

`apply.sh` also requires `${LEGACY_APP_DIR}/.git` to exist (line 486 — a real git checkout, to
determine the origin to clone tenants from), which is not an `.env` key but is the other genuine
precondition on this directory. Nothing else in `.env.production.example` — `APP_KEY`,
`DB_PASSWORD`, `DB_ROOT_PASSWORD`, `REDIS_PASSWORD`, every `MAIL_*` besides `MAIL_FROM_ADDRESS`,
every `P24_*`, ... — is read by either script; those belong to `docker-compose.prod.yml`'s own
containers.

**On a legacy-free machine (two-machines plan, Faza 4 — PreProd), `NGINX_RELOAD_CONTAINER` moves
from "optional" to "must be pre-seeded before the first certificate request."** `registro-edge-nginx`
is the only nginx that will ever hold 80/443 there, from the very first bring-up — there is no
pre-cutover legacy nginx for the default to protect. Full reasoning and operator sequence:
`edge-stack.md`'s "A legacy-free machine does not cut over — it bootstraps" and
`instalacja-tenanta-od-zera.md`'s Część 10. `setup-production-server.sh`'s own closing instructions
were split into paths 3a (legacy shared stack) / 3b (control-plane only) for exactly this reason —
3a's `.env.production.example` + `validate-env.sh production` requires secrets a 3b machine's
containers never start, and running `deploy-init.sh` on a 3b machine would stand up a second,
unwanted legacy stack fighting the edge for ports 80/443.

## Bugs this task's own validation found and fixed

Every one of these was found by *running* the guard it broke, not by
inspection — listed because each is a specific claim about behavior that
turned out to be wrong on the first real attempt:

1. **`log()`/`die()` referenced `$LOG_FILE` before it was ever assigned.**
   `LOG_FILE` is derived from `SLUG`, which is only known after argument
   parsing — but `die()` is reachable *from* argument validation (an invalid
   slug or tag). Under `set -u`, the first such failure crashed with `LOG_FILE:
   unbound variable` instead of printing the actual "invalid tag" message —
   the operator's very first mistake produced a useless bash crash instead of
   guidance. Fixed with `${LOG_FILE:-/dev/null}`.
2. **The lock file lived inside the git working tree it was about to clone
   into.** `mkdir -p "$STACK_DIR"; exec 9>"${STACK_DIR}/.apply.lock"` made the
   directory non-empty *before* `git clone` ran, and `git clone` refuses to
   clone into a non-empty directory — every first apply for every new tenant
   failed here. Fixed by moving all of `apply.sh`'s own bookkeeping (lock,
   log, status, pre-apply dumps) into a sibling `STACKS_ROOT/.state/<slug>/`
   directory, never inside the tenant's own checkout.
3. **The same problem, one layer down, for the log file itself** — an early
   `mkdir -p "$STACK_DIR"` (added to fix #2's symptom) plus a log write before
   the clone reproduced the identical non-empty-directory failure. Resolved
   permanently, not re-ordered again, by #2's `STATE_DIR` split.
4. **`registro:tenant-provisioned --assert` does not mean "consistent."** See
   "The `--assert` surprise" below — this one would have made *every*
   first-time provisioning run die, always, forever, on a perfectly healthy
   new stack.
5. **`VAR="$(cmd)"` on its own line is not a conditional under `set -e`.**
   The line immediately after the fix for #4 — `PROVISION_RC=$?` — never
   executed, because `set -e` killed the script the instant the command
   substitution's underlying command returned non-zero (the *expected*,
   frequent case: "not provisioned yet"). Fixed with `VAR="$(cmd)" ||
   RC=$?`, which gives the compound statement its own always-zero exit status.
6. **`tenant-check.sh`'s `find`-based orphan scan matched `apply.sh`'s own
   `.state/` directory as an "orphan tenant directory."** Bash's glob (used
   for the main tenant-discovery loop) skips dot-entries by default; `find`
   does not. Fixed with `-not -name '.*'`.

### The `--assert` surprise

`App\Console\Commands\TenantProvisioningStatusCommand::handle()` runs
`assertConsistent()` first when `--assert` is passed, but **even when that
passes**, it falls through to the ordinary `isProvisioned()` check and returns
`self::FAILURE`, printing the bare line `not-provisioned`, for any stack that
simply hasn't been provisioned yet. `--assert` only *adds* the consistency
check on top of the existing provisioned/not-provisioned behavior; it was
never a "just tell me if this is inconsistent" gate on its own. Treating any
non-zero exit as "die, tenant isolation is broken" — the first version of this
script did exactly that — would have made the seeds step fail, hard, on every
single first-time tenant, since "not provisioned yet" is not an edge case for
a new tenant, it is the *entire starting state*.

The two failure shapes are told apart by the **output**, not the exit code:
a genuine `assertConsistent()` failure prints one of four specific
`components->error(...)` messages (see that command's own docstring table);
only the benign case prints exactly the single line `not-provisioned`.
`apply.sh` captures both, and only `die`s when the output is *not* that exact
line.

## Permanent regression suite

Every bug catalogued on this page (and on `edge-stack.md`, and in
`ci-cd-troubleshooting.md`) was, until 2026-08-10, found and proven fixed in a
**throwaway** validation sandbox — fake `docker`/`certbot`/`su`/`git`/`restic`
on `PATH`, torn down at the end of the session. Nothing accumulated, so a
later change could silently break what an earlier one had already proved —
which is exactly what happened once: a volume backup proven working in one PR
turned out never to have worked, caught two PRs later only because a human
happened to re-read an old validation report.

`tests/shell/` (`bash tests/shell/run.sh`, documented in
`.claude/rules/tests.md`) turns eight of those sandboxes into a permanent,
committed suite instead — the same fake-executable-on-`PATH` pattern, kept
around so a regression shows up as a failing test on the next run, not as a
report nobody re-reads. It pins:

1. `stage_volume()` must pass `--entrypoint` (`apply.sh`, `tenant-backup.sh`)
   — "`stage_volume()` bug found during Faza 3 validation" below.
2. `force_clear_flag()` must never invoke `docker compose` (`apply.sh`,
   `deploy.sh`) — `ci-cd-troubleshooting.md`'s "docker compose run w
   forced-command recovery path".
3. `sync-certificate.sh`: an unprobeable legacy stack aborts, never
   contributes zero names silently.
4. `sync-certificate.sh`: a stopped tenant stack with a readable `.env` still
   contributes its hosts.
5. `sync-certificate.sh`: no empty element ever reaches certbot's `-d` list.
6. `apply.sh`'s edge-sync preserves the running edge's `EDGE_NGINX_CONF`
   across recreation ("Infrastructure review" #1 below).
7. `apply.sh`'s lock/log/state live outside `STACK_DIR` (bug #2 above).
8. `apply.sh`'s `registro:tenant-provisioned --assert` treats a benign
   `not-provisioned` result as non-fatal (bug #4/"The `--assert` surprise"
   above).

Plus two cheap bonus pins: `tenant-check.sh` never reports `.state/` as an
orphan tenant directory (bug #6 above), and `apply.sh`'s
`NGINX_RELOAD_CONTAINER` write is idempotent across repeated runs.

`tenant-restore.sh` (added 2026-08-12, see its own section above) adds three more, none found as
bugs during its own drill -- added because the properties they pin ARE the safety promise this
script makes, not because the drill caught a regression:

9. `--restore-live` refuses without a `--confirm-slug` matching the slug argument exactly, and
   never touches `docker`/`restic` before refusing.
10. The default (non-live) mode refuses a `--target-db` equal to the tenant's live `DB_DATABASE`,
    and never runs `restic ls`/`restic dump` before refusing.
11. `restore_files_live()` always `chown -R 1000:1000`s after extracting into a live volume.

A second review the same day DID find real bugs (see `tenant-restore.sh`'s own section above,
"That first drill only proved the happy path") -- three more pins, each proven red-then-green
against the exact pre-fix script text, not a synthetic mutation:

12. `--restore-live` wraps BOTH the database and files phases in ONE maintenance window, in the
    correct order (`artisan down` → database → files → `artisan up`) -- caught the original bug
    where `artisan up` ran before files were extracted.
13. `--restore-live --skip-db` still enters maintenance mode before extracting files -- caught the
    original bug where it extracted straight into live volumes with none at all.
14. A failed database load aborts before touching files, leaves maintenance mode on, and writes a
    `FAILED` `STATE_DIR/apply-status` -- caught the original bug where it fell through into
    extracting files after the database load had already failed.

`check-certificate-expiry.sh` (new script, added 2026-08-14 -- see
`instalacja-tenanta-od-zera.md` step 0.4a) and a dead-man's-switch ping added
to `tenant-backup.sh` the same day add nine more, none found as bugs in
pre-existing code (both are additive) -- pinning the properties that ARE the
whole point of building either mechanism, proven red-then-green against a
deliberately reverted version of each fix, same as every entry above:

15. A certificate with days remaining above both thresholds is a silent,
    exit-0 pass -- no log line, no alert ping, ever.
16. A certificate at or below the warning threshold logs a `WARNING`, exits 1.
17. A certificate at or below the critical threshold logs `CRITICAL`, exits 2,
    and (if `REGISTRO_CERT_ALERT_URL` is set) actually fires the webhook.
18. A connection/handshake failure that returns nothing readable is `CRITICAL`
    with a DISTINCT exit code (3) from both thresholds above -- "could not
    tell" never collapses into either "healthy" or a dated finding.
19. No way to determine which hostname to check (`REGISTRO_CERT_CHECK_SNI`
    unset, no `CERT_DIR` in the legacy `.env`) refuses before ever opening a
    socket.
20. `tenant-backup.sh` with no `BACKUP_HEALTHCHECK_URL` in `.env` never
    invokes `curl` at all -- genuinely inert, not "off but still tries".
21. A fully successful backup pings the configured URL.
22. A failed ping is logged but never changes the backup's own exit code
    (0) -- reverted to "ping first, then check FILES_FAILED" to prove this
    catches a placement regression, not just a happy-path assertion.
23. A PARTIAL backup (one storage volume failed to stage) already `die()`s
    before the ping is ever reached -- caught by moving the ping above the
    `FILES_FAILED` gate and confirming the case fails.

**Rule going forward:** a shell bug found and fixed in `scripts/server/**`
gets a test in `tests/shell/cases/` in the same change, the same way a PHP
bug gets a regression test in `tests/Feature`/`tests/Unit`. See
`tests/shell/lib/harness.sh`'s own header for why plain bash (no bats) and
why fakes-on-`PATH` rather than a real Docker daemon.

## Infrastructure review — six more fixes

A second pass, after the first round above shipped, reviewed the result against
real infrastructure again and found six more problems, all fixed and (bar one,
noted below) reproduced directly. In the order they were fixed:

### 1. CRITICAL — the status file could lie after a signal kill

**The bug, reproduced first:** `on_exit`'s own `local rc=$?` can read `0` even
when bash is dying from an untrapped signal — bash reports the exit status of
the *last command that ran*, not the signal, when the signal lands between
commands. `write_status "FAILED"` never fired, and a stale `OK` from a
*previous* successful run stayed on disk, unchanged, through a live SIGTERM.
Every one of `systemctl --user stop`, an operator `kill`, and an OOM kill sends
SIGTERM first — and the whole detached-via-`systemd-run` design in "The three
open questions" below rests on this file being trustworthy. A stale `OK` is
worse than no file at all.

**The fix:** `write_status "RUNNING" ""` is now written unconditionally, as the
very first thing after the per-tenant lock is acquired — before git clone,
before `.env.secrets`, before anything. From that point on, the file can only
read `OK` again if the single, explicit `write_status "OK"` call at the very
end of a successful run actually executes. Every other exit path — `die()`,
the new `on_signal` handler (HUP/INT/TERM, mirroring `deploy.sh`'s own
pattern), and `on_exit`'s catch-all fallback — overwrites it to `FAILED`
first. An interrupted run, however it dies, is left at `RUNNING` at worst,
never a false `OK`. `check.sh` treats a `RUNNING` status older than 30 minutes
(the full happy path, git clone through backup, was timed at well under 3
minutes) as a crashed run, not a live one — the residual case (SIGKILL, which
cannot be trapped at all) is what that threshold exists for.

**A second, related bug found while fixing the first:** the signal traps
themselves were originally registered *after* `write_status "RUNNING"` and
several real steps (git clone, `.env.secrets`). A signal landing in that
window hit bash's own default disposition instead of the custom handler — the
core "never falsely OK" guarantee still held (RUNNING had already been
written unconditionally), but the *informative* half (an immediate, reasoned
`FAILED` instead of a `RUNNING` an operator has to wait out the age threshold
to notice) did not cover it. Fixed by moving the entire maintenance-mode/trap
block to be the first thing after the lock, before the RUNNING write.

**A third, genuinely surprising thing found while reproducing this, worth
recording because it is not obvious and applies to `deploy.sh`'s own
identical trap pattern too:** bash defers running a trap handler until the
*current foreground command* completes, per its own documented signal
behavior ("If bash is waiting for a command to complete and receives a signal
for which a trap has been set, the trap will not be executed until the
command completes"). A SIGTERM sent to `apply.sh` while it is inside a
long-running `docker compose` command does not interrupt that command — the
trap fires only once it finishes. This does not weaken the guarantee (the
file is at `RUNNING` for the entire wait, never `OK`), but it means "killed"
is not instant; an operator watching `journalctl` live will see the FAILED
write land only after whatever docker command was in flight returns.

**SIGTERM reproduction, exactly as asked, against the code as fixed:**

```
$ printf 'OK v9.9.9-old 2026-08-01T00:00:00Z (from a previous successful run)\n' \
    > .state/sigtest/apply-status
$ cat .state/sigtest/apply-status
OK v9.9.9-old 2026-08-01T00:00:00Z (from a previous successful run)

$ apply.sh sigtest v9.9.9-sigtest1 example.com --foreground &   # started
# ... once RUNNING is confirmed written:
$ cat .state/sigtest/apply-status
RUNNING v9.9.9-sigtest1 2026-08-08T19:27:24Z

$ kill -TERM <pid>                                              # signalled mid-flight
$ cat .state/sigtest/apply-status                                # immediately after
RUNNING v9.9.9-sigtest1 2026-08-08T19:27:24Z        # still RUNNING -- NEVER read OK

# bash defers the trap until the in-flight command completes (see above);
# once it does:
$ cat .state/sigtest/apply-status                                # after the process exits
FAILED v9.9.9-sigtest1 2026-08-08T19:27:39Z killed by SIGTERM
```

The file went `OK (stale)` → `RUNNING` → `RUNNING` (through the signal) →
`FAILED (killed by SIGTERM)`. It was never, at any point after the run
started, readable as `OK`.

### 2. HIGH — maintenance mode now holds on both isolation-risk failures

Every `die()` between `MAINTENANCE_ON=true` and the point maintenance is
normally lifted was gone through individually and decided on purpose. Final
list:

| Failure | Holds maintenance? | Why |
|---|---|---|
| Migration fails (one-off container) | **Yes** (pre-existing) | Schema may be half-applied; see fix 3 below |
| MySQL/Redis not ready after `up -d` | No | Availability/readiness, not a routing risk — no other tenant or wrong-tenant exposure |
| `registro:tenant-provisioned --assert` fails for real (not just "not-provisioned") | **Yes (fixed)** | TENANT_SLUG, the singleton lock and the provisioning marker disagree — the exact shape that can mean "registration/`/platform` are live here" or "pointed at another tenant's database" |
| Edge-sync failures (CERT_DIR unset, vhost render, edge reload) | No | About the **edge's** own config, a different stack; does not mean *this* tenant's own nginx/app routing is wrong |
| **X-Tenant probe mismatch** | **Yes (fixed)** | The one mechanism that is supposed to make it structurally impossible for this hop to serve/attribute a request to the wrong tenant just failed its own check |
| Asset gate (manifest/`public/` mismatch) | Yes (pre-existing) | Stale or half-synced frontend, not a routing risk per se, but treated conservatively since a deploy that failed here is not verifiably the release it claims to be |
| Cache-rebuild commands, Horizon restart (bare, no explicit `die`) | No (falls through to `on_exit`'s generic fallback) | Neither is a tenant-isolation risk |
| Health check, backup failures | N/A — occur after maintenance is already lifted (see `deploy.sh`'s own reasoning: `/up` itself 503s under maintenance, so it has to be lifted first) | |

**Proven, not just decided on paper** — the X-Tenant fix specifically, against
a real running tenant stack:

1. Hand-corrupted the *live, rendered* `app.tenant.local.conf` to report
   `HTTP_X_TENANT "WRONG-TENANT"` instead of the real slug, force-recreated
   `nginx` so the container picked it up (confirmed via `docker exec ...
   grep` — a plain `nginx -s reload` after a host-side edit did **not**
   propagate in this sandbox, a real and separate finding about this
   environment's bind-mount behavior, not about the script).
2. Ran `php artisan down` for real — confirmed genuinely in maintenance
   (homepage `503`; `/up` still `200`, Laravel's own health route bypasses
   maintenance by design, which is *why* `deploy.sh`'s own convention lifts
   maintenance before checking `/up` rather than relying on it as a
   maintenance-state signal).
3. Ran the real X-Tenant probe apply.sh itself runs — confirmed mismatch:
   `PROBE_RESULT=[WRONG-TENANT] expected=[sigtest2]`.
4. Called the **real, unmodified `clear_maintenance()` function** copied
   verbatim from `apply.sh`, once with `KEEP_MAINTENANCE=true` (the fix) and
   once with `false` (the old behavior), as a direct control:
   - `KEEP_MAINTENANCE=true`: `"Leaving maintenance mode ON deliberately"` →
     homepage stayed `503`.
   - `KEEP_MAINTENANCE=false`: `"Maintenance mode cleared"` → homepage went
     `200` — a live tenant stack, **confirmed serving the wrong slug**,
     back in front of real traffic. This is what shipped before the fix.

### 3. HIGH — "a re-run is a clean retry" corrected; `REGISTRO_VERSION` timing stated plainly

Two overclaims, both now corrected in the migrate step's own comments and
here:

- **MySQL DDL is not atomic.** A migration that adds column A then fails on
  column B leaves A in place with the migration itself unrecorded. An
  identical re-run does not cleanly retry — it fails again with "column
  already exists" and needs manual schema surgery, not just another `apply`.
  "A re-run is a clean retry" is true of every step **above** the migrate
  step (git clone, `.env.secrets`, port/subnet allocation, `.env`
  reconciliation) and *false* of the migration itself, which is why its own
  `die()` message points at the pre-migration dump (`PRE_DUMP`) rather than
  promising a clean retry.
- **`export REGISTRO_VERSION="$TAG"` (right after `.env` is regenerated) wins
  over the `.env` file in Compose interpolation, so `pull`/`migrate`/`up -d`
  already run on `${TAG}` well before `.env`'s own `REGISTRO_VERSION=` line
  is rewritten at the very end.** Writing it last protects the **durable
  record**, not the **running state** — an operator who fails between `up -d`
  and that final pin, and trusts `.env` alone, is told the *old* version is
  live when the *new* one already is. Anyone debugging a partial run should
  check `docker compose ps` / the actual running image tag, not `.env`, to
  know what is really serving traffic.

### 4. HIGH — restic lock contention: `restic unlock` documented, and a real nuance found

**What was assumed going in:** any interrupted backup could leave a stale
lock blocking every future backup for that tenant. **What reproduction
actually found:** `restic backup` (the only restic subcommand `apply.sh` or
`tenant-backup.sh` ever calls) takes a **shared**, not exclusive, lock — a
stale shared lock left by a SIGKILLed backup does **not** block a subsequent
`restic backup` (reproduced directly: killed a real 300MB backup mid-flight
with `SIGKILL`, confirmed a real lock object survived in `repo/locks/`, then
ran a second `restic backup` against the same repo — it succeeded normally).
The scenario that genuinely produces `unable to create lock in backend:
repository is already locked` is a **shared-vs-exclusive** conflict — an
operator running `restic check` or `restic prune` (both exclusive) while a
backup is in flight, reproduced directly with the real error text:

```
unable to create lock in backend: repository is already locked by PID 794508 on patrick by patrick (UID 1000, GID 1000)
lock was created at 2026-08-08 21:30:06 (742.100765ms ago)
storage ID e3f3fa39
the `unlock` command can be used to remove stale locks
```

Narrower than first assumed (neither script calls `check`/`prune`), but real
whenever an operator does so manually — and the fix costs nothing to keep.
`apply.sh`'s backup step now `grep -qi 'already locked'`s the captured restic
output (confirmed to match the real message above) and, on a match, logs the
exact recovery command with `RESTIC_REPOSITORY`/`RESTIC_PASSWORD_FILE`
already filled in:

```
RESTIC_REPOSITORY='/opt/registro/tenant-backups/<slug>/repo' \
RESTIC_PASSWORD_FILE='/opt/registro/tenant-backups/<slug>/password' \
restic unlock
```

A lock conflict is treated as a backup failure (see fix 5, `DEGRADED`), not a
fatal `die()` — a stuck lock should not itself take down a healthy release's
status reporting.

### 5. MEDIUM — `DEGRADED` status, distinct from `FAILED`, for a healthy release with a failed backup

The health check passes, and only then does the backup run. Dying on a
backup failure (the original behavior) meant `apply-status` read `FAILED`
for a tenant that was, at that exact moment, live and serving correctly —
inconsistent with the deliberate choice a few lines earlier to only *warn*
when restic is absent entirely. Fixed: the backup step no longer calls
`die()` for its own failures (a broken `BACKUP_DIR`/password file, `restic
init` failing, the dump failing, `restic backup` failing including the lock
case above). `REGISTRO_VERSION` is pinned regardless — the release genuinely
is live and health-checked — and the run finishes by writing `DEGRADED`
(distinct status, distinct log line, **exit code 5**, distinct from both `0`
and the `FAILED` family) rather than `OK` or `FAILED`. `check.sh` reports it
as its own finding shape: `"release is live but DEGRADED"`, not `"last apply
FAILED"`.

**A related, adjacent bug found and fixed while testing this path:** the
`mkdir -p "$BACKUP_DIR"` that creates the per-tenant backup directory was, in
the first cut of this fix, still a bare unguarded command — an unwritable
`BACKUP_ROOT` died there under `set -e`, uncaught, before `BACKUP_FAILED`
could ever be set, recreating exactly the FAILED/DEGRADED conflation this fix
exists to remove. Guarded the same way as the rest of the block now.

**Proven end to end, twice** — once forcing the failure, once forcing
recovery, against a real running tenant stack with a real restic binary:

```
$ apply.sh sigtest2 v9.9.9-sigtest1 ... (RESTIC_PASSWORD_FILE deliberately corrupted)
...
[21:35:23] ERROR: restic init failed -- see .../apply.log
[21:35:23] === apply sigtest2 v9.9.9-sigtest1 DEGRADED (release is live and healthy; backup failed, see above) ===
$ echo $?
5
$ cat .state/sigtest2/apply-status
DEGRADED v9.9.9-sigtest1 2026-08-08T19:35:23Z backup failed after a healthy release -- see .../apply.log
$ grep REGISTRO_VERSION sigtest2/.env
REGISTRO_VERSION=v9.9.9-sigtest1          # pinned anyway -- release IS live
$ curl -s -o /dev/null -w '%{http_code}' -H 'Host: example.com' http://127.0.0.1:18080/
200                                        # genuinely serving, not broken
$ tenant-check.sh sigtest2
tenant-check: 1 problem(s)
  - sigtest2: release is live but DEGRADED (...)

# fixed the password file, re-ran the identical command:
$ apply.sh sigtest2 v9.9.9-sigtest1 ...
=== apply sigtest2 v9.9.9-sigtest1 OK ===
$ cat .state/sigtest2/apply-status
OK v9.9.9-sigtest1 2026-08-08T19:36:07Z
$ tenant-check.sh sigtest2                 # silent, exit 0
```

### 6. MEDIUM — port allocation race closed with a global, short-lived lock; concurrent provisioning is supported by design

**Concurrent provisioning of different tenants is a deliberate yes, not a
refusal.** An operator onboarding several clients around the same time
should not have to serialize whole `apply` runs against each other just
because of one shared host resource (the port number). What needed
serializing was never the runs — it was the brief window between *scanning*
for a free port and *persisting* the chosen one, which `allocate_subnet()`
never had (nothing is persisted early there, so a retry naturally rescans and
picks something else) but `allocate_ports()` did: two tenants provisioned at
the same instant could scan an identical "used" set, pick the identical
candidate, and the loser would keep reading its own colliding choice back
out of its `.env` on every future apply — stuck until an operator hand-edited
the file.

Fixed with a short, blocking, **global** (cross-tenant, cross-slug) `flock`
around exactly the scan-then-reserve critical section — distinct from each
tenant's own `STATE_DIR/apply.lock`, which serializes a tenant against
itself, not against other tenants. The reservation itself is a one-line
placeholder (`HTTP_PORT_V4=...`) appended to the tenant's own `.env` inside
the lock, superseded harmlessly by the real `.env` regeneration later in the
same run. Not reproduced under genuine concurrent load (two real `apply`
processes racing) — the logic was verified by inspection and via the
non-concurrent regression run (port allocation, reservation-write, and the
final `.env` regeneration all behaved correctly for a single tenant, with no
change to observed behavior) — a genuine two-tenant race is the one item in
this list not directly fired, noted rather than glossed over.

### Also fixed (cheap, found alongside the above)

- The `set -o pipefail` comment on the backup step described a subshell that
  does not exist in this script (there is no pipe in that block at all,
  every command's exit status is read directly) — corrected to say so
  plainly instead.
- `.env.secrets`' `APP_KEY` (and the three password fields) were only
  checked for being non-empty, which would have accepted `APP_KEY='base64:'`
  — exactly what a silently-failed `openssl rand` inside `printf "...%s\n"
  "$(openssl ...)"` would produce (command substitution failing does not
  itself trip `printf`'s own exit status, so `set -e` never catches it).
  Given this is the one file this script promises never to regenerate,
  validated the actual shape now (`^base64:[A-Za-z0-9+/=]{40,}$` for the key,
  a minimum length for the three passwords), on every run, not only the
  generation branch — so a corrupted pre-existing file is caught too, not
  just a freshly-broken generation.

## Correcting `edge-stack.md`'s manual runbook

`edge-stack.md`'s "How a tenant is attached" (step 4) describes hand-editing
`docker-compose.edge.yml` to add the tenant's network — "uncomment and fill in
the example block." That description predates this task and is now
**incorrect for anything going through `apply`**: `docker-compose.edge.yml` is
a tracked file inside the same checkout `deploy.sh` runs `git checkout --force`
against on every legacy-stack deploy. An in-place edit there survives only
until the next such deploy, silently. `apply.sh`'s edge-sync step instead
generates `docker-compose.edge.tenants.override.yml` (gitignored) from
scratch, every run, by enumerating the `tenants.d/*.conf` files that already
exist — the tenant network attachment is *derived*, not hand-maintained, and
every other already-attached tenant survives automatically because it is
re-derived from its own still-present vhost file. Compose's default merge
behavior appends list fields (`services.edge-nginx.networks`) and unions
top-level map keys (`networks:`) across `-f` files — confirmed while
investigating the unrelated `ports:` conflict in `tenant-compose-stack.md` —
so a second file needs no `!override` tag here, it only needs to exist. The
manual runbook in `edge-stack.md` remains correct for an operator attaching a
tenant **without** `apply` (e.g. debugging, or before this task existed); it
is simply no longer the path `apply` itself takes, and should not be followed
by hand on a box where `apply` is also in use — the two would fight over the
same file on the next `apply` run for any tenant.

## The three open questions, answered

### restic — installed how, and by what

**Not installed by default on Ubuntu 24.04.** Confirmed in the Ubuntu 24.04
package archive (`apt-cache policy restic` on a fresh box would show a real,
if not bleeding-edge, version — no PPA or manual binary needed). Added to
`setup-production-server.sh`'s existing `apt-get install` list alongside
`dnsutils` (for `dig`, the DNS precondition). This is the one and only install
step; `apply.sh` itself checks `command -v restic` and **degrades gracefully**
(logs a warning, completes the rest of the run successfully) rather than
failing the whole apply if it is ever missing — a missing backup tool should
not be why a release can't ship, but it must not be silent either.

### `systemd-run` — how an operator learns whether `apply` succeeded

Detaching via `systemd-run --user --collect` means the SSH client that
launched it gets its exit status back immediately (0, "launched"), which is
**not** the same as "succeeded" — the real work continues after the SSH
session could safely close. Two ways to check, deliberately layered:

1. **The status file — the one to actually depend on.**
   `STACKS_ROOT/.state/<slug>/apply-status`, written as literally the first or
   last thing on every exit path (see `write_status()` and the `EXIT` trap),
   never expires, and needs nothing systemd-specific to read:
   `cat /opt/stacks/.state/<slug>/apply-status` → `OK v1.4.0
   2026-08-08T20:58:28Z` or `FAILED v1.4.0 2026-08-08T20:58:28Z <reason>`.
   `--collect` unloads the transient unit once it finishes (keeping
   `systemctl --user list-units` clean across dozens of applies over a
   server's lifetime), which means `systemctl --user status <unit>` stops
   answering anything shortly after completion — **verified directly**: a
   detached run against a box with `/opt/stacks` genuinely absent (an
   unrelated finding, below) exited within a second, `--collect` reaped the
   unit almost immediately, and `journalctl --user -u <unit>` afterward showed
   only `Main process exited, code=exited, status=1/FAILURE` — no reason at
   all. That is precisely the "read the log and guess" failure mode the task
   brief named. The status file has no such window.
2. **`journalctl --user -u <unit> -f`** — the full narrative, for when the
   status file says `FAILED` and an operator wants to see *why*, or wants to
   watch a long apply live. Printed by `apply.sh` itself in its detach
   message, alongside the status-file path.

**Verified both ways, for real:** a fully detached `systemd-run --user` run
completed a genuine first-time tenant provision (migrations, seeders, edge
attach, X-Tenant assertion, asset gate, health check) end to end with no
interactive process watching it, and the status file correctly read `OK`
once it was done — see "What was actually run."

**Requires `loginctl enable-linger deploy` once, at server-setup time** — now
in `setup-production-server.sh`, idempotent. Without it, `deploy`'s systemd
--user instance (and everything running under it) is torn down the moment the
last login session for that user closes, which is exactly the session
`apply.sh` is trying to detach from.

### Per-tenant restic repo and password — where they live, and the honest gap

`/opt/registro/tenant-backups/<slug>/{repo,password}` — one directory per
tenant, `password` mode 600 owned by `deploy` (the same user `apply.sh` runs
as; restic needs no elevated privilege to dump-then-back-up a SQL file --
staging the two storage volumes into that same snapshot DOES need root,
scoped to a throwaway `docker run --user 0:0` per volume, see `tenant-backup.sh`
above -- the restic invocation itself still runs as `deploy`).
`RESTIC_REPOSITORY` defaults to the local `repo` path but is overridable via
environment (`RESTIC_REPOSITORY`) if a real remote backend (S3-compatible,
B2, SFTP to a second host) is ever wired in later — no script change needed,
only the environment the operator launches `apply`/`tenant-backup.sh` under.

**Stated plainly, because the task asked for it stated plainly: as shipped,
this is disk-redundancy, not a real backup.** The restic repository and its
password both live on the same host as the database they protect. If this VPS
dies, the restic repository dies with it, and — worse — so does the only copy
of the password needed to decrypt any *other* copy of that repository someone
did manage to save. A backup whose key exists only on the machine it backs up
is not a disaster-recovery backup, it is a local snapshot that survives
`DROP TABLE` and a bad migration, not a burned VPS. Closing this gap for real
needs an operator to **copy `/opt/registro/tenant-backups/<slug>/password` to
a separate credential store** (this project's own password manager, not
another file on the same box) **and point `RESTIC_REPOSITORY` at a location
that is not this host** — neither of those is a code change, both are
deliberately left as a manual, per-deployment decision rather than guessed at
here with invented cloud credentials.

## `check`

`scripts/server/tenant-check.sh` — same convention as `scripts/cc-doctor.sh`:
silent (zero stdout) when every tenant is clean, specific and non-zero when
not. Every assertion is something this task's own design process (or a prior
review of tasks 2/4/5) caught drifting silently once already:

| Assertion | Verified against |
|---|---|
| Exactly one `organizations` row | Raw `mysql -N -e "SELECT COUNT(*)"` inside `tenant-<slug>-mysql` — deliberately not Eloquent/`tinker`, so it does not depend on the `app` container being healthy, only `mysql` |
| No orphans | `docker ps -a` (containers matching `tenant-<slug>-<service>`) vs `/opt/stacks/*` directories, **both directions** |
| `TRUSTED_PROXIES_CIDR` is never `*`/`**` | The tenant's own `.env` text (this is specifically what a deployment check can see that a unit test pinning the shipped default cannot) |
| Tenant nginx is loopback-bound | `docker port <container> 80/tcp` — Docker's own view, not the `.env` text, which can say `127.0.0.1` and still resolve differently depending on which compose file actually started the container |
| `TRUSTED_PROXIES_CIDR` matches the real `tenant-<slug>-edge` subnet | `docker network inspect` vs the `.env` value — these are hand-matched today per `edge-stack.md`'s runbook, and nothing else catches them drifting |
| Last apply succeeded | `STACKS_ROOT/.state/<slug>/apply-status` |

**Every one of the above was made to fire, on purpose, against real Docker
state** — not just read for plausibility. See "What was actually run."

## `tenant-backup.sh`

Standalone wrapper around the exact same dump-then-restic-backup logic
`apply.sh`'s own final step uses, factored out so cron can run it *between*
releases — a tenant's data changes every day; `apply` only backs up at release
time. Deliberately its own file rather than a shared "source this" library:
the two copies are small enough that duplicating them is a smaller risk than
making `apply.sh` depend on an external file that could drift from what it
actually needs mid-run, with the maintenance-mode trap and lock already held.
Not installed into cron by this task (no server exists to install it on) —
the runbook below documents the line to add per tenant.

**Two-machines plan, Faza 2 (2026-08-10): the snapshot also covers the two
storage volumes, not just the database.** `storage-app-public`/
`storage-app-private` (client logos, equipment photos, portfolio images, CMS
block images, GDPR export ZIPs) previously had no backup mechanism at all —
the only thing that existed was a `tar` command an operator had to remember,
in `instalacja-tenanta-od-zera.md`. Both scripts now stage those two volumes
into a plain host directory (via a throwaway container on the tenant's own
already-pulled image, `docker run -v <volume>:/src:ro`) and pass the staged
paths to the SAME `restic backup` invocation as the SQL dump, so a restore
means one snapshot ID, not two close-in-time ones. Two things had to be
gotten right, both found by actually running this against a real Docker
volume, not by inspection:

- **`docker run -v <name>:/path` silently CREATES an empty volume when the
  name does not exist**, instead of erroring — a missing/renamed volume would
  otherwise back up as "successfully" empty, with nobody noticing until a
  restore came back with nothing in it. Guarded with `docker volume inspect`
  BEFORE the mount, proven to fire: called against a volume name that was
  never created, confirmed the function returns non-zero AND that
  `docker volume ls` shows no phantom volume was created as a side effect.
- **GNU `cp -a /src/. /dest/` does not only copy files INTO the pre-existing
  `/dest`, it also re-stamps `/dest`'s OWN ownership to match `/src`'s top
  level** (root:root, since a Docker volume's own root directory is
  root-owned). The staging container runs as `--user 0:0` (root) because the
  volume's files are owned by the image's fixed `laravel` user (UID 1000,
  ADR-013) and the host-created staging directory does not grant that UID
  write access — but without a trailing `chown -R $(id -u):$(id -g) /dest`
  inside the SAME privileged `docker run`, the staging directory itself
  silently flips to root-owned, and the non-root user running this script
  (no sudo on this box) gets `Permission denied` cleaning it up on every
  subsequent run. Reproduced both ways (without the `chown`, cleanup failed;
  with it, cleanup succeeded) before shipping.

A files-staging failure for ONE volume does not drop the database or the
OTHER volume from the snapshot — `RESTIC_TARGETS` is built incrementally and
`restic backup` runs with whatever succeeded, only reported as a failure
(`DEGRADED` in `apply.sh`, non-zero exit in `tenant-backup.sh`) afterwards.

**The ownership fix above is a BACKUP-side fix; the restore side needed its
own, found by infrastructure review, not assumed to follow automatically.**
`instalacja-tenanta-od-zera.md`'s restore procedure (8.6) streams
`restic dump ... --archive tar` straight into a root-privileged `docker run`
that extracts AND `chown -R 1000:1000`s in the same command, the same
precedent as the backup side. Without that final `chown`, restored files
keep whatever UID `deploy` happened to be on the machine that made the
backup (not necessarily 1000) — a UID-1000 process (the real app, ADR-013)
can then read them but not write, delete, or overwrite them. Reproduced with
a deliberately different simulated `deploy` UID (1002, ADR-010's own real
example) so the sandbox's own coincidental host UID couldn't mask the bug:
without the restore-side `chown`, a UID-1000 write into the restored tree
failed with `Permission denied`; with it, the same write succeeded and the
pre-existing restored content was still readable.

## `tenant-restore.sh`

The read side of the pair above -- until 2026-08-12, nothing in this repo ever read a restic
snapshot back; the only restore procedure was prose in `instalacja-tenanta-od-zera.md` Część 8 for
an operator to retype by hand. `tenant-restore.sh` is that procedure, made runnable, same standalone
file reasoning as `tenant-backup.sh` above.

**Safe by default, not by convention.** Without `--restore-live`, the script never touches the
tenant's live database or live storage volumes: the database dump loads into a scratch database
inside the tenant's OWN mysql container (default name `<DB_DATABASE>_restore_verify`, refuses if
`--target-db` is set to the live `DB_DATABASE` itself), and the storage volumes extract to a plain
host directory (`mktemp -d` by default), never into the live Docker volumes. `--restore-live`
requires a SECOND flag, `--confirm-slug <slug>`, which must equal the slug argument byte-for-byte --
guards against a pasted/looped command whose first argument silently changed doing the destructive
thing to the wrong tenant. Pinned in `tests/shell/cases/13_tenant_restore_live_requires_confirm_slug.sh`
and `14_tenant_restore_target_db_not_live.sh`.

**The restore-side ownership gap (named in the section above, already found and fixed in the manual
procedure on 2026-08-10) is fixed identically here, and independent of host UID by construction.**
`restore_files_live()`'s `chown -R 1000:1000` is a literal, not `$(id -u):$(id -g)` -- unlike the
BACKUP side's own chown (which deliberately targets the invoking host user, for a different reason:
letting that same non-root user clean up the staging directory afterward, see above), the restore
side always wants the fixed `laravel` UID regardless of who runs the script. This means the trap
that bit earlier validation elsewhere in this project (a sandbox's host UID coincidentally equaling
the app's UID masking a chown bug) cannot mask THIS particular fix -- verified directly anyway
(`instalacja-tenanta-od-zera.md` 8.7a, point 7): a snapshot manufactured with content owned by UID
1002 (not 1000), extracted twice into fresh volumes -- without the restore's `chown -R 1000:1000`, a
UID-1000 write failed with `Permission denied`; with it, the same write succeeded.

**Verified end-to-end 2026-08-12 against the real image and a real six-container stack** (not an
extracted function, not a stand-in image) -- see `instalacja-tenanta-od-zera.md` 8.7a for the full
run: seed real data (including an encrypted `audit_logs` row) → `tenant-backup.sh` unmodified →
default scratch-mode restore while the stack stayed live (`sha256sum` match, live DB/app untouched,
encrypted row decrypts) → four safety-guard checks (`--target-db` equal to live DB, `--restore-live`
without/with-wrong `--confirm-slug`, `--restore-live` combined with `--target-db`) → full stack
destroyed (`docker compose down -v`, fresh empty stack stood up in its place) → `--restore-live
--confirm-slug` → app serves again, `sha256sum` match, ownership `1000:1000`, encrypted row decrypts
through the live app itself, and a UID-1000 process could WRITE a new file into the restored tree,
not just read the existing one.

**That first drill only proved the happy path -- a second review the same day found four real gaps
on `--restore-live`, all reproduced in code, not argued in the abstract:**

1. Maintenance mode was scoped INSIDE the database block only, so (a) `--restore-live
   --confirm-slug <slug> --skip-db` (an ordinary, documented invocation) extracted straight into
   the live volumes with NO maintenance mode at all, and (b) on the normal path, `artisan up` ran
   BEFORE the storage volumes were extracted -- a fully successful run could serve traffic against a
   database referencing files still mid-`tar -x`.
2. Nothing gated the files phase on the database phase failing -- a failed dump load logged "app
   left in maintenance mode... fix manually" and fell straight through into extracting files into
   the live volumes anyway.
3. No signal traps -- the only trap was `rm -f "$DUMP"` on EXIT, disarmed midway through the
   script. A Ctrl-C, dropped SSH, or systemd timeout mid-restore left the tenant in maintenance mode
   or with a half-overwritten volume, nothing logged explaining why.
4. `tenant-restore.sh` never wrote `STATE_DIR/apply-status`, which `tenant-check.sh` trusts as
   ground truth -- a failed or killed live restore read as a healthy tenant because the last
   *apply* had succeeded.

**Fix:** ONE maintenance window (`artisan down` → stop horizon/scheduler) wraps BOTH phases
regardless of `--skip-db`/`--skip-files`; the files phase never starts once the database phase has
failed; `on_exit`/`on_signal` mirror `apply.sh`'s own pair exactly (unconditional `RUNNING` write the
moment maintenance mode is entered, `FAILED` with a reason on any failure/signal, `OK` only on full
success). Deliberately DIFFERENT from `apply.sh` in one place: `clear_maintenance()` here NEVER
attempts to auto-heal (`apply.sh`'s does, on an interrupted migration) -- a restore has TWO
dependent phases, and auto-clearing on an interrupt landing between them would risk exactly the
same "serving inconsistent data" state item 1 above already proved was reachable. A human
confirming both are consistent before typing `artisan up` by hand is the deliberately safer
default here.

Three new tests pin the CALL SEQUENCE, not just the guards (`tests/shell/cases/16-18`) -- each
proven red-then-green by substituting the exact PRE-fix script text and confirming the test fails on
precisely the described defect (test 16 caught `artisan up` before files; 17 caught the missing
`artisan down` under `--skip-db`; 18 caught files extracting after a database-load failure), not on
something incidental.

**Re-verified for real a second time**, not only through `tests/shell/`'s fakes --
`instalacja-tenanta-od-zera.md` 8.7c: the same real image/stack drill repeated against the fixed
script. Happy path: the script's own log now shows the corrected order verbatim (`artisan down` →
database restored → both volumes restored → "Application is now live") and `apply-status` reads
`OK`. Failure path: `DB_ROOT_PASSWORD` corrupted in `.env.secrets` while the real mysql container
kept its real, original password -- a genuine `Access denied`, not a simulated one -- and the run
stopped with exit 3, zero file-extraction log lines, horizon/scheduler still `Exited`, and
`apply-status` reading `FAILED`. Restoring the correct password and re-running the identical command
recovered fully (exit 0, `apply-status` → `OK`, app serving). Not verified: a real SIGTERM/Ctrl-C
mid-restore (the trap pair is logically identical to `apply.sh`'s own, whose SIGTERM reproduction is
already documented in `ci-cd-troubleshooting.md`, but was not reproduced a second time for this
script specifically), and `--restore-live` run from a real non-1000 host account.

## `stage_volume()` bug found during Faza 3 validation (two-machines plan): backups of both
## storage volumes were silently empty against the real image

Found by actually running the migration drill in `instalacja-tenanta-od-zera.md`'s Część 9.6, not
by inspection -- the same "found by running it" pattern as every other entry in this document.
Neither `apply.sh`'s own copy of `stage_volume()` nor `tenant-backup.sh`'s ever passed
`--entrypoint` to `docker run`. This project's own image (`ghcr.io/patrykgielo/registro`) ships
`docker/entrypoint.sh`, which unconditionally refuses to run as anyone but the `laravel` user:

```
🔍 Validating container configuration...
❌ CRITICAL: Running as 'root' but expected 'laravel'
```

`--user 0:0` (needed so the container can read root-owned volume internals and `chown` the staging
directory back afterward -- see the section above) never reached the `cp -a`/`chown` command at
all: the entrypoint's own `whoami` check killed the container FIRST, on every invocation, silently.
`docker run` itself reports success (a container started, ran, and exited -- just with exit code 1
and nothing copied), and the surrounding `stage_volume()` DOES catch that failure (`|| { log
"ERROR..."; return 1; }`) -- so this was never a crash. It was `apply.sh`'s final step reporting
`DEGRADED` (or `tenant-backup.sh` exiting 3) on every single run, for every tenant, for as long as
this code existed unexercised against the real image -- easy to read as "restic isn't installed
yet" noise rather than "the storage volumes have never once made it into a snapshot."

**Fix, identical shape in both files:** `--entrypoint sh` on the `docker run` invocation, and the
command split into `-c "..."` (now the argument to `sh` itself, not part of a `sh -c "..."` CMD
that never got interpreted, since the image's own entrypoint no longer intercepts it). Verified
directly, before and after: same volume, same command shape, `❌ CRITICAL` reproduced with the
original code, `cp -a`/`chown` actually executing (confirmed by `sha256sum` matching source
content, and by ownership landing on the invoking user, not root) with the fix.

**Why Faza 2's own validation (edge-stack.md/this doc, 2026-08-09/10) didn't catch this:** that
work exercised `stage_volume()` as an isolated function against *a* Docker volume, not necessarily
against a container built from THIS project's own `Dockerfile`/`entrypoint.sh` -- a generic
image would never hit this guard. Faza 3's drill was the first time this exact function ran with
the real, entrypoint-guarded `ghcr.io/patrykgielo/registro` image as the staging container, which
is what every real `apply`/cron backup on the actual server has always used.

## Faza 3 (two-machines plan) -- moving a tenant between machines

Full procedure, decision (documented runbook, not a cross-machine orchestrator -- see that
section's own header for why), and the six-point end-to-end validation live in
`instalacja-tenanta-od-zera.md`'s **Część 9**, not duplicated here. What's relevant to `apply.sh`
specifically: the migration's first apply on the destination machine passes an explicit `[hosts]`
argument and deliberately omits `--name`/`--owner-email`/`--owner-name` -- see "One tenant, one
domain" above for the `[hosts]` mechanism, and Część 9.4/9.6 for why omitting the owner flags is
what stops this from provisioning a second, fabricated organization on top of the one about to be
restored from backup.

## Manual steps an operator must perform

1. **Once, at server-setup time** (already automated by the
   `setup-production-server.sh` additions, but stated here for anyone reading
   the runbook rather than re-running the whole bootstrap): `dnsutils` and
   `restic` installed, `/opt/stacks` and `/opt/registro/tenant-backups`
   created and owned by `deploy`, `loginctl enable-linger deploy` run,
   `apply.sh`/`tenant-check.sh`/`tenant-backup.sh` installed to
   `/opt/registro/` root:root 755 (same non-writable-by-the-account-it-serves
   reasoning as `deploy.sh`), the `registro-tenant-check` cron entry installed.
2. **Per new tenant, before the first `apply`:** nothing — `apply <slug> <tag>
   [hosts] --name=... --owner-email=... --owner-name=...` is the entire
   provisioning path. DNS for the tenant's hostname(s) must already resolve to
   this host (the precondition checks this and refuses to proceed otherwise).
3. **Per new tenant, ongoing:** add a staggered cron line for
   `tenant-backup.sh <slug>` (see that script's own header) — `apply` only
   backs up at release time, and a tenant that goes months between releases
   should not go months between backups.
4. **The restic key-custody gap, above:** copy
   `/opt/registro/tenant-backups/<slug>/password` to a separate credential
   store, and decide on (and set) a genuinely off-host `RESTIC_REPOSITORY` if
   disaster recovery (not just accidental-`DROP TABLE` recovery) matters for
   that tenant. Not automated; a deliberate per-deployment decision.
5. **If `.env.secrets` is ever genuinely lost or corrupted** (not the normal
   case — this file is never regenerated by design): there is **no**
   `--force` flag to make `apply` overwrite it, on purpose. Recovering means
   either restoring that exact file from a backup taken before it was lost, or
   accepting that every row `audit_logs` ever encrypted with the old
   `APP_KEY` becomes permanently undecryptable and generating a fresh
   `.env.secrets` deliberately, by hand, with that consequence explicitly
   accepted — never something a script should decide unattended.

## What was actually run

No live server touched, no dev containers disrupted (`registro-app`/
`registro-mysql`/`registro-redis` — this project's actual dev stack — verified
`Up`/`healthy` before and after every test below). All of it against real
Docker, a real locally-built image containing this branch's actual application
code (`docker build` from this checkout, not a stand-in), and two genuinely
independent tenant slugs to prove multi-tenant co-location on one host works
(distinct ports, distinct `/29` subnets, distinct container prefixes, zero
manual bookkeeping between them).

1. `bash -n` and `shellcheck -x` (0.11.0, downloaded standalone — not present
   in this sandbox by default) on every new/edited script
   (`apply.sh`, `tenant-check.sh`, `tenant-backup.sh`,
   `setup-production-server.sh`). Findings: two pre-existing patterns
   (`SC2155`, `SC2329`) already present, unfixed, in `deploy.sh` and
   `sync-certificate.sh` themselves — confirmed by running shellcheck against
   those two files too, so these are house style, not new problems.
2. **Every precondition guard made to fire, individually:**
   - `dig` against a hostname engineered not to resolve — refused with the
     exact message, before touching disk.
   - Disk-space threshold temporarily raised past what the sandbox actually
     has free — refused with the exact free-space figure it measured.
3. **`.env.secrets` refuse-to-overwrite, proven against a REAL reconcile
   pass, not a stub:** seeded a fake `.env.secrets` (`APP_KEY=
   'base64:PRESERVE_ME_DO_NOT_OVERWRITE'`) into an already-cloned stack
   directory, ran `apply` for real (git fetch/checkout, network allocation,
   `.env` regeneration, mysql/redis bring-up, an actual `docker pull`
   attempt) — `sha256sum` before and after the full run: byte-for-byte
   identical.
4. **`check`'s orphan detection, both directions, against fabricated state:**
   a container (`tenant-orphan2-mysql`) with no matching `/opt/stacks`
   directory, and a directory (`/opt/stacks/mismatch`) with no matching
   container — both findings fired with the right slug named in each message.
5. **`check`'s other three per-tenant assertions, each made to fire on a
   deliberately broken fabrication:** a real `nginx:1.25-alpine` container
   published to `0.0.0.0` under the `tenant-<slug>-nginx` name pattern (public-
   bind finding fired, quoting the real `docker port` output); a `.env` with
   `TRUSTED_PROXIES_CIDR=*` (wildcard finding fired); a real Docker network
   created with one subnet while `.env` claimed a different one (mismatch
   finding fired, both subnets named in the message).
6. **A genuinely full, two-tenant, end-to-end happy path**, built from a real
   `docker build` of this checkout (not a repurposed old image — the first
   attempt, using a retagged `v0.13.0-rc9`, correctly failed with "Command
   registro:tenant-provisioned is not defined," proving the OLD image
   predates task 1's own commands, which is exactly why a stand-in image was
   the wrong test and a real build was needed):
   - Git clone of a throwaway local origin (a real local clone of this
     checkout, tagged locally), all the way through `git checkout --force`.
   - Full migration run (every migration in this branch's history, including
     the singleton-lock migration from task 2, in a one-off container on the
     new image).
   - `up -d`, cache rebuild, Horizon restart, all six containers reaching
     `healthy`/`Up`.
   - `registro:tenant-provisioned --assert` → `not-provisioned` → real
     `registro:tenant-provision` run: roles/permissions seeded, 38 e-mail
     templates seeded, organization + owner created, a real
     `password.setup` link printed.
   - Edge-sync: a real `docker-compose.edge.yml` brought up for the first
     time, `edge-nginx` reached `healthy`.
   - X-Tenant assertion: **`X-Tenant confirmed: scapply`** — a real HTTP
     request through the tenant's own rendered `app.tenant.local.conf`,
     probe file confirmed removed afterward (not left behind).
   - Asset gate: **"8 manifest entries, matching this image, all files
     present"** / **"public/ matches the image"** — passed against the real
     build's own manifest.
   - Maintenance mode cleared, health check passed, `restic` absent in this
     sandbox → **degraded gracefully with a warning**, did not fail the run
     (proving the "installed how" answer above is enforced in code, not just
     documented).
   - `REGISTRO_VERSION` pinned in `.env` only at the very end; `apply-status`
     read back `OK`.
   - `check` run against the resulting live stack: **silent, exit 0** —
     confirming all four per-tenant assertions pass together on a genuinely
     healthy stack, not just individually against fabrications.
   - A **second, entirely independent tenant** (`scapply2`) provisioned the
     same way, fully detached via a real `systemd-run --user`, confirmed to
     complete with `apply-status` reading `OK` with no process watching it —
     the concrete demonstration for the `systemd-run` question above. Both
     tenants' containers, ports (`18080`/`18090`-range, non-colliding),
     `/29` subnets, and `.state/` directories coexisted with zero manual
     bookkeeping; `check` stayed silent across both.
7. Every Docker resource created during testing (containers, networks,
   volumes, the locally-built image, the throwaway git clones and local tags)
   was removed afterward; confirmed nothing tenant-shaped remained and the
   real dev stack was still `Up`/`healthy` throughout.

**What was not validated, and why:** the real `ghcr.io` registry push/pull
path (no credentials or intent to publish a throwaway tag there — the local
build stood in for it, which is what actually exercises `apply.sh`'s own
logic; the registry round-trip itself is `docker compose pull`, unmodified
and already exercised by `deploy.sh`), a real `certbot`-issued certificate and
the edge's HTTPS path against it (no domain resolves to this sandbox), and
`--policy missing` is **not** something `apply.sh` ships with — it was a
one-line addition to a throwaway copy used only to avoid a real network pull
of a tag that only exists locally; confirmed via `diff` against the real
script before every such run that it was the *only* difference.
