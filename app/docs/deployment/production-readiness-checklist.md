# Production Readiness Checklist — First Deploy to `srv1342834.hstgr.cloud`

**Status (2026-08-02)**: §1 fixed in code, §1b found and fixed by the local dress rehearsal
(Phase 2), §2 executed on the VPS (Phase 3), §1c found by the code review that §1–§2 skipped.
§3–§8 remain open. **§2 must be re-run on the VPS** — it now installs firewall rules that the
executed version lacked, without which the site is unreachable from the internet. The machine is
bootstrapped;
**the application has never run on it** — no clone, no `.env`, no image pulled, and
`/opt/registro/deploy.sh` has still never been executed. That is Phase 4. Plan:
`~/.claude/plans/vps-bootstrap-registro-first-deploy.md`; original analysis:
`~/.claude/plans/no-tak-z-innej-scalable-meadow.md`.

**Context**: VPS `76.13.76.104` (`srv1342834.hstgr.cloud`) was reset to a clean Ubuntu 24.04 and
is now dedicated to Registro (a prior, unrelated project was wiped off it). This will be
Registro's **first-ever deploy to any real server** — `docker-compose.prod.yml`,
`docker-compose.staging.yml`, `scripts/deploy-*.sh`, and `.github/workflows/deploy-production.yml`
/`ci-staging.yml` were all inherited from a predecessor project ("Paradocks") during the
2026-03-02 migration and have not been exercised against the current codebase or this VPS since.
Treat everything below as "needs a fresh validation pass," not "known-good."

Domain decision so far: use `srv1342834.hstgr.cloud` as a **technical placeholder host**
everywhere (APP_URL, nginx `server_name`, Let's Encrypt cert) — not a real production domain yet.
Przelewy24 (P24) payment wiring is **deferred** — launching without live online payments is
acceptable for now.

---

## 1. Blocking infra bugs — FIXED 2026-08-01 (`feature/deploy-infra-fixes`)

Fourteen bugs, not the nine originally listed: five more surfaced while fixing the first nine, and
one listed item turned out to be a false alarm. Each entry keeps its original description so the
diff is reviewable against what was actually claimed.

- [x] **nginx config path mismatch.** `docker-compose.prod.yml` and `docker-compose.staging.yml`
  mount `./docker/nginx/app.{prod,staging}.conf`; the real files are at
  `docker/nginx/{production,staging}/app.{prod,staging}.conf`. Same wrong path is baked into
  `scripts/deploy-init.sh` (line 222) and the `curl` steps in
  `.github/workflows/deploy-production.yml` / `ci-staging.yml`. As committed, any of these will
  either fail to mount a config or write a 404 page into nginx's conf.
- [x] **`scripts/deploy-with-healthcheck.sh` references undefined `$VERSION`** under `set -euo
  pipefail` — running it with no arguments aborts immediately ("unbound variable") unless the
  caller exports `VERSION` first, which isn't documented anywhere in the script's own usage text.
- [x] **`scripts/deploy-init.sh` seeds `ServiceAvailabilitySeeder`**, which does not exist in
  `database/seeders/` (only `ServiceAreaSeeder` and `ServiceSeeder` do) — this step will fail.
- [x] **`deploy-init.sh` and `deploy-update.sh` derive the project root one directory too high.**
  `deploy-init.sh:43-44` and `deploy-update.sh:50-51` set `SCRIPT_DIR` to the directory containing
  the script — `<repo>/scripts` — and then assign `PROJECT_ROOT="$SCRIPT_DIR"` with no `dirname`.
  Invoked the documented way (`./scripts/deploy-init.sh`, per its own line 9), both look for
  `docker-compose.prod.yml`, `.env` and `.env.production.example` inside `<repo>/scripts/` and abort
  immediately. This is not merely "an inconsistent convention" — this convention is broken outright.
  Fix: `PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"`.
- [x] **Align every script on the agreed path.** Decision (2026-08-01): the repo lives at
  **`/var/www/registro`**. `deploy.sh:31`, `setup-staging-server.sh:33` and
  `deploy-production.yml:194` already use it; the two scripts above must be fixed to resolve to the
  same place. Note that `/opt/registro/deploy.sh` — the root-owned forced-command target from §8 —
  deliberately sits *outside* the repo: if it were writable by the deploy user, the forced-command
  restriction would protect nothing.
- [x] **`setup-staging-server.sh` creates a user named `ubuntu`** (line 187, plus the `chown` at
  line 206). Decision (2026-08-01): the service account is **`deploy`** — key-only, no password, in
  the `docker` group. Rename in the script when adapting it for production, and set the GitHub
  `VPS_USER` secret to match. Be explicit about what group membership means: `docker` is
  root-equivalent, so a shell as `deploy` is a shell as root. That is the reason the forced-command
  work in §8 matters — it exists to prevent obtaining that shell in the first place.
- [x] **`.env.production.example` and `.env.local.example` are not git-tracked** (the `.gitignore`
  pattern for `.env*` has no carve-out for them, unlike `.env.example`/`.env.staging.example`) —
  a fresh `git clone` on the new VPS won't have the file `deploy-init.sh` depends on. Either
  track them or have `deploy-init.sh` generate its own template.
- [x] **`scripts/setup-staging-server.sh` step 7 runs `php artisan db:seed --force`** — this
  directly violates the project's own rule (`.claude/rules/deployment.md`: "NIGDY: `db:seed` w
  deploy scripts — nadpisuje dane admina"). Remove or gate it before reusing this script as a
  template for a production bootstrap script.
- [x] **Image tag is hardcoded to `:latest`, which makes rollback impossible without editing files
  on the live server.** `docker-compose.prod.yml:3,132,183` (`app`, `horizon`, `scheduler`) all pin
  `ghcr.io/patrykgielo/registro:latest`; `docker-compose.staging.yml:11,155,202` does the same with
  `:develop`. The build job (`deploy-production.yml:156-163`) *does* push an immutable
  `:${VERSION}` tag alongside `:latest`, so known-good images exist in GHCR — the compose files
  just can't select them. Consequence: when a deploy breaks production, `docker compose pull` re-
  fetches the broken image, and recovering means hand-editing compose or re-tagging in the middle
  of an outage. Change to `ghcr.io/patrykgielo/registro:${REGISTRO_VERSION:-latest}` and set
  `REGISTRO_VERSION` in the server's `.env`, so rollback becomes
  `REGISTRO_VERSION=<previous-tag> docker compose -f docker-compose.prod.yml up -d` — no build, no
  GitHub, no runner. See §8.
- [x] **No `setup-production-server.sh` exists.** Only staging has a from-scratch VPS bootstrap
  script (Docker install, `ufw`/`ufw-docker`, user creation, certbot). Adapt it for production —
  fixing the `db:seed` issue above in the process — before using it on `srv1342834.hstgr.cloud`.
- [x] **The deploy step sent a 70-line shell script over SSH** (`deploy-production.yml:186-256`,
  `ssh … 'bash -s' << 'DEPLOY_SCRIPT'`). That makes the CI key a run-anything-as-`deploy` key, and
  `deploy` is in the `docker` group — i.e. root. Replaced by a single
  `ssh deploy@host "deploy <tag>"` against `/opt/registro/deploy.sh`, whose source lives at
  `scripts/server/deploy.sh` and which is installed root-owned, outside the repo. The script
  re-validates its own arguments out of `SSH_ORIGINAL_COMMAND` rather than trusting the caller.

### Found while fixing the above

- [x] **`composer install --optimize-autoloader --no-dev` ran on every production deploy**
  (`deploy-production.yml:245`), inside a container whose image already ships a built `vendor/`.
  It made each deploy depend on packagist being up, opened a supply-chain window at the worst
  possible moment, and wrote into a container layer that the next `up -d --force-recreate`
  discards — so the box silently drifted from the image it claimed to run. Removed.
- [x] **Deploy `curl`-ed `docker-compose.prod.yml` and the nginx config from
  `raw.githubusercontent.com`** using `secrets.GITHUB_TOKEN` (`deploy-production.yml:200-209`,
  `ci-staging.yml:210-217`). The repo is private and that endpoint is not the API — the token is
  not reliable there. It also overwrote tracked files inside what is otherwise a git working tree.
  Both replaced with `git fetch --tags` + `git checkout <tag>`: one source of truth, no token.
- [x] **`workflow_dispatch` had no `version` input**, while the build job derived the tag from
  `${GITHUB_REF#refs/tags/}`. Triggered manually (the only trigger this workflow has), that yields
  a *branch name* as the image tag. Added a required `version` input, validated against
  `vMAJOR.MINOR.PATCH` and checked for existence before anything is built, with both `checkout`
  steps pinned to that tag rather than to the dispatch branch.
- [x] **`scripts/validate-env.sh` never read `.env`.** Every check reads shell variables, and the
  script sources nothing — so the documented `./scripts/validate-env.sh production` validated an
  empty environment and reported all variables missing. Exactly backwards from its purpose, and it
  is the gate that is supposed to run *before* the first `up`. Now loads `.env` (overridable via
  `ENV_FILE`) and fails loudly when the file is absent.
- [x] **Three scripts ran `docker compose build` against compose files that declare no build
  context** (`deploy-init.sh:242`, `deploy.sh:190,250`, `setup-staging-server.sh:338`). The
  production and staging images come from GHCR; `build` there fails outright. Changed to `pull`.
- [x] **`scripts/backup-maintenance.sh` printed an "Emergency Rollback" runbook telling the
  operator to run a destructive migration command** (`migrate:` + `fresh --seed`) on production —
  the exact operation `.claude/rules/deployment.md` forbids, handed to a human at their most
  stressed. Rewritten to the tag-repin rollback, with an explicit warning that rolling the image
  back does not roll the schema back.
- [x] **`deploy-init.sh` seeded `VehicleTypeSeeder`** — a leftover from the automotive project this
  infrastructure was inherited from, with no meaning in Registro. Dropped; the three genuine
  bootstrap seeders (`RolePermissionSeeder`, `SettingSeeder`, `EmailTemplateSeeder`) remain.
- [x] **`.env.production.example` was missing variables `docker-compose.prod.yml` requires** —
  `DB_ROOT_PASSWORD`, `REGISTRO_VERSION`, `SMSAPI_*`. Filled in, with `APP_URL` pointed at the
  technical host and comments on the three settings that fail silently when wrong
  (`REDIS_PASSWORD` mismatch, `FILESYSTEM_DISK`, `SESSION_DOMAIN`).

### False alarm

- The `db:seed --force` calls in `deploy-production.yml:97` and `ci-staging.yml:101` are **correct
  and must stay**. They run in the `test` job on `ubuntu-latest` against a throwaway
  `registro_test` database. The deployment rule they appeared to violate is about deploy scripts,
  not CI. Only `setup-staging-server.sh` had a real violation.

### Not fixed here, deliberately

- **Four overlapping deploy scripts still exist** — `deploy.sh`, `deploy-update.sh`,
  `deploy-with-healthcheck.sh`, and now `server/deploy.sh`. "Which one do I run at 2am" is its own
  failure mode. Consolidating to one is a follow-up decision, not a bug fix.

  An earlier revision of this section claimed all four were "individually correct after this pass".
  That was wrong, and the §1c review found the counter-examples: `deploy-with-healthcheck.sh` still
  carried two `grep -q` pipefail bugs, one of which aborted a successful deployment. Do not read
  this list as an assurance that the other three are audited to the same depth as
  `server/deploy.sh`; they are not.

## 1b. Found by the local dress rehearsal (Phase 2, 2026-08-01)

Phase 2 ran `docker-compose.prod.yml` against the real production image on a laptop, from an
empty state, layer by layer. It surfaced six more defects — four of which would have taken the
first VPS bring-up down outright, and one of which would have left the site technically "up" and
completely unusable. This is what the phase exists for.

- [x] **`docker/nginx/production/app.prod.conf` could not have started at all, and would have
  been wrong if it had.** Every HTTPS block referenced
  `/etc/letsencrypt/live/{registro.local,srv1117368.hstgr.cloud}/…` — certificates for the *local
  dev domain* and the *predecessor project's host*. nginx refuses to start when a referenced
  certificate is missing, so the first bring-up on a fresh VPS dies immediately. Worse, the
  `:80` block 301-redirected everything to `https://registro.local`, so even a running stack
  would have bounced every visitor to a hostname that does not exist. Replaced with a two-file
  scheme: `app.prod.conf` (HTTP, no certificates, catch-all `server_name`) is the default, and
  `app.prod-tls.conf` is switched in via `NGINX_CONF` in `.env` once certbot has succeeded —
  reversible with the same one-line edit. Both now also listen on IPv6.
- [x] **`APP_DOMAIN` was set nowhere** — absent from `.env.production.example` and from all three
  `environment:` blocks — so it fell back to `registro.local` (`config/app.php:68`). This is the
  value `ResolveTenant` derives every tenant subdomain from; on the VPS it would have resolved no
  tenant for any host and redirected every root-domain visitor to `http://registro.local/`. The
  rehearsal caught it as a 302 to a nonexistent host on the very first request to `/`. Added to
  the example (with the reasoning) and to prod and staging compose.
- [x] **The entrypoint ran `migrate --force` on every container start.** `app`, `horizon` and
  `scheduler` share one image and start concurrently, so every deploy and every reboot raced
  three migrators against one database, before any maintenance mode, and the failure was
  swallowed (`"container will start anyway"`) — leaving a container serving traffic against a
  half-migrated schema with nothing failing loudly. Migrations now have exactly one owner:
  `/opt/registro/deploy.sh`, which wraps them in `artisan down` and aborts on failure. Verified:
  the rehearsal asserts the entrypoint does *not* migrate.
- [x] **`scripts/validate-env.sh` aborted after its first check.** Under `set -euo pipefail`,
  `((CHECKS++))` returns 1 when `CHECKS` is 0, and the `check_var_*` helpers return 1 by design
  when a variable is missing — so `set -e` killed the run at the first `pass` and again at the
  first real failure. Combined with the §1 finding that it never read `.env` at all, this gate
  has never once done its job. Counters are plain arithmetic now and `-e` is off, which is
  correct for a script that accumulates errors and exits on the total.
- [x] **`scripts/verify-deployment.sh` had the identical counter bug** (`((MISSING_COUNT++))`),
  so it would abort on the first missing file instead of reporting the list — the exact failure
  mode the script was written to catch.
- [x] **`.env.production.example` drifted from what the containers actually run:**
  `SESSION_DRIVER=database` vs `redis`, `SESSION_SAME_SITE=strict` vs `lax`, `LOG_STACK=single`
  vs `daily`. Because compose `environment:` beats `.env`, editing those keys on the server does
  nothing — a genuinely confusing trap. Aligned, with a comment naming the keys that compose pins.

**Phase 2 exit condition met:** two consecutive clean runs from an empty state, 26/26 assertions,
with no changes between them. Covered: layered bring-up and health, 127 migrations on an empty
database, volume writability as `laravel:1000`, Redis reachability, Horizon as sole consumer
processing a real job end-to-end, scheduler ticking, HTTP 200 on `/` and `/up`, built assets
served, `/.env` refused, no orphan containers.

**What the rehearsal did NOT cover** — do not read the above as "the deploy works":
`scripts/server/deploy.sh` and `scripts/setup-production-server.sh` have still never been
executed. Every *command* deploy.sh issues has now been exercised in the same order by hand, but
its own orchestration (git checkout of a tag, GHCR pull, lock handling, rollback path) has not.
It runs for the first time in Phase 4. Nothing has been pushed to GHCR either — the image exists
only on this laptop.

## 1c. Found by code review of the §1/§1b/§2 diff (2026-08-02, `feature/deploy-review-fixes`)

The §1–§2 work was merged as PR #125 **without** the mandatory `code-reviewer` gate. Running that
gate afterwards, plus a security audit, found four more Critical defects — two of them introduced
by the §1/§1b fixes themselves. Every one sits in code that had never been executed. The pattern
from Phase 2 held exactly: executed code was sound, unexecuted code was not.

- [x] **The TLS certificate config was reverted on every single deploy.** `deploy-init.sh` wrote
  the real domain into `docker/nginx/production/app.prod-tls.conf` with `sed -i` — a **git-tracked**
  file — while `scripts/server/deploy.sh` runs `git checkout --quiet --force "tags/$VERSION"` on
  every deploy *and* every rollback. The edit was silently reverted to the `CERT_DOMAIN`
  placeholder each time. Nothing broke immediately, because nginx keeps serving its loaded config;
  the failure surfaces at the next container recreation — a reboot, an image update — when nginx
  refuses to start on a nonexistent certificate path and takes **HTTP and HTTPS down together**,
  potentially weeks after the change that caused it. Fixed by making `app.prod-tls.conf` an
  explicit template and rendering it to `app.prod-tls.local.conf` (gitignored). `deploy.sh`
  regenerates that file after each checkout, so template changes shipped in a release still land
  and a missing file self-heals. Verified: the generated config passes `nginx -t`, and a gitignored
  file demonstrably survives `git checkout --force`.
- [x] **`scripts/backup-database.sh` truncated its own retention pass.** `((deleted_count++))`
  under `set -e` returns 1 on the first increment (post-increment evaluates to the old value, 0),
  killing the script mid-rotation with no error and no summary. The same bug class was fixed in
  two other scripts during §1 and missed here. Invisible until backups are old enough to rotate.
- [x] **`APP_DOMAIN` had no guard in `docker-compose.prod.yml`.** Staging used `${APP_DOMAIN:-…}`,
  production used a bare `${APP_DOMAIN}`. `env()` in `config/app.php` falls back only when a
  variable is **absent**; an empty `APP_DOMAIN=` line is present and wins, so tenant-subdomain
  routing breaks with the exact defect §1b identified as the most serious finding of that pass.
- [x] **A blank `REDIS_PASSWORD` took down the whole stack.** Compose drops the empty token, so
  `redis-server --requirepass --maxmemory 256mb` parses `--maxmemory` as the password argument and
  exits with `FATAL CONFIG FILE ERROR` — cache, sessions and queues with it. Reproduced directly
  against `redis:7.2-alpine`. `validate-env.sh` did not check the variable at all.

  Both of the above are now enforced twice: `validate-env.sh` checks them, and
  `docker-compose.prod.yml` uses the `${VAR:?message}` form for `APP_DOMAIN`, `APP_KEY` and
  `REDIS_PASSWORD`, which refuses to start on unset **or empty**. The compose guard is the real
  one — `validate-env.sh` only runs when a human remembers to run it.

- [x] **`ufw-docker install` was never paired with an allow rule.** `install` is default-deny for
  everything Docker publishes, so the first `up -d` would have left the site unreachable from the
  internet, looking like a broken application rather than a firewall. Fixed with the port-based
  `ufw route allow proto tcp from any to any port 80` / `443`. Deliberately *not*
  `ufw-docker allow registro-nginx 80`: that form pins the container's current IP, which changes
  every time `up -d` recreates it, and repairing it needs `ufw-docker reload` as root — which the
  deploy user cannot run. Confirmed against the ufw-docker documentation.
- [x] **SSH hardening was verified one directive out of five.** Only `PasswordAuthentication` was
  asserted; `PermitRootLogin`, `KbdInteractiveAuthentication`, `X11Forwarding` and `MaxAuthTries`
  were written and trusted — the exact assumption the first-wins `00-` prefix fix exists to
  correct. `KbdInteractiveAuthentication` matters most: it is the standard way password logins
  survive `PasswordAuthentication no`, since PAM answers the keyboard-interactive prompt instead.
  All five are now asserted via a helper, and three are re-checked in the verification block.
- [x] **Maintenance mode came up after the readiness waits, not before.** `artisan down` ran
  between the MySQL/Redis waits and the migration, leaving up to ~3 minutes of new code serving
  against an unmigrated schema on a cold start. Moved before `up -d`; the flag lives in
  `storage/framework`, a named volume, so it survives the container recreation.
- [x] **`REGISTRO_VERSION` was persisted to `.env` before the image pull.** A failed pull left
  `.env` naming a tag whose images are not on the host, so a later bare `docker compose up -d`
  fails on a missing image. The version is now exported for the run (a shell variable beats `.env`
  in Compose interpolation) and written to `.env` only once the pull has succeeded.
- [x] **Seven more `grep -q`-under-`pipefail` bugs.** §1 fixed this class in
  `setup-production-server.sh` and declared it handled; it was not. `deploy-with-healthcheck.sh`
  had two — one of which aborted a *successful* deployment with "App container is not running" —
  plus `backup-database.sh`, `deploy-update.sh` (×2) and `deploy-init.sh` (×2). The last is the
  nastiest: a false "nginx is not running" makes the script bind a temporary container to port 80
  that the real nginx already holds, turning a working stack into a failed certificate request.
  All replaced with capture-then-match. The two survivors are safe and were verified as such:
  `scripts/deploy.sh` sets no `pipefail`, and the Redis probe in `server/deploy.sh` runs inside
  `timeout bash -c`, a fresh shell that does not inherit the flag.
- [x] **The `deploy` job had no `permissions:` block.** It never checks out code and never calls
  the GitHub API, so it is now `permissions: {}`.

### Second review round — the fixes above were themselves reviewed (2026-08-02)

This time the gate ran **before** the PR. It found one Critical caused by a §1c fix and one that
the new compose guards introduced. Both are fixed in the same commit.

- [x] **Moving `artisan down` before `up -d` could strand the site in permanent 503.** The
  maintenance flag lives in a named volume — the property that makes the fix work across container
  recreation is also what makes a stranded flag survive reboots. With `down` now running earlier,
  a failure at `up -d`, the MySQL wait or the Redis wait left the flag set, and `public/index.php`
  short-circuits **every** request including `/up`. Worse, `rollback` never cleared it, so the
  operator's instinctive recovery would inherit the 503 *and then fail its own health check
  because of it* — reporting a broken rollback that actually worked. The forced-command grammar
  (`deploy|rollback|status`) offers no way to run `artisan up`, so recovery needed the root key.
  Fixed with an `EXIT` trap that lifts maintenance on any non-zero exit, plus an unconditional
  lift at the start of every rollback. Verified across all seven failure paths.

  One deliberate exception: **a failed migration now stays in maintenance**. Serving new code
  against a half-applied schema risks user-visible errors and bad writes; an honest 503 is better.
  That is only a safe choice because `rollback` now lifts the flag, so recovery no longer requires
  the root key. The error message names the exact rollback command.
- [x] **The `${VAR:?}` guards deadlocked the first-ever bootstrap.** Compose evaluates
  interpolation for *every* subcommand, not just `up`. `deploy-init.sh` copies
  `.env.production.example` — which ships `APP_KEY=` and `REDIS_PASSWORD=` empty — and then
  immediately ran a compose command, which now aborts the whole script under `set -e`. Phase 4
  would have died at step 2. Secrets are now generated on the host with `openssl` before compose
  is touched at all, and only when empty, so re-running never rotates a live `APP_KEY` (which
  would break every encrypted value and every session) or a password MySQL was initialised with.

  This also fixed a silent pre-existing bug: the old `docker compose run --rm app php artisan
  key:generate` wrote `.env` **inside an ephemeral container** — no `.env` is bind-mounted into
  the app service — so it had never once generated a key that survived.
- [x] **`deploy.sh status` was collateral damage from the same guards.** It is the only diagnostic
  the forced command exposes, and it would have returned a compose interpolation error instead of
  container state in exactly the situations where you need it: `.env` not yet written (all of
  Phase 4), or a password blanked by a bad edit. Switched to `docker ps --filter name=registro-`,
  which does not read the compose file. Note the general escape hatch: `docker compose` subcommands
  can be forced past a guard with `REDIS_PASSWORD=x docker compose down`.
- [x] **The TLS regeneration could write a config nginx will not start on.** Three defects in the
  §1c fix: `sed` exits 0 when it substitutes nothing, so a release whose template changed shape
  would yield a file still containing `CERT_DOMAIN`; the write was not atomic, so a disk-full
  truncation would sit there until the next reboot recreated nginx on it; and the domain came from
  `APP_URL`, while certbot appends `-0001` to the live directory whenever the SAN set changes —
  which happens the first time `www.<host>` starts resolving, since `deploy-init.sh` adds it
  conditionally. Now: render to `.tmp`, reject any output still containing `CERT_DOMAIN`, `mv` into
  place, and take the directory name from `CERT_DIR` in `.env` (falling back to the host) after
  checking that `/etc/letsencrypt/live/$CERT_DIR` actually exists.
- [x] **`REGISTRO_VERSION` was still written too early.** §1c moved it after the pull, but `up -d`
  comes after that, so a failed `up -d` left `.env` naming a version the running containers were
  not on — and `restart: unless-stopped` means a reboot would silently promote it, in the worst
  case against a schema never migrated for it. Now written after the health check passes.
- [x] **Two of the `grep -q` replacements were faithful to a check that never worked.**
  `*healthy*` also matches `unhealthy`, so `deploy-init.sh` reported "All services are healthy"
  for an explicitly broken stack — reproduced against a container with a failing healthcheck. And
  `docker compose ps` lists only *running* containers, so `deploy-update.sh`'s "some containers
  have exited" guard could never fire — also reproduced. Fixed to `*"(healthy)"*` and `ps -a`.
  §1c listed both as fixed when only their SIGPIPE half was.
- [x] **A failed sshd assertion left the box with no firewall.** The five hard `die`s sat between
  `systemctl reload ssh` and the ufw block, trading a possible SSH misconfiguration for a
  guaranteed absence of a firewall. The assertions now run after the firewall is up.

### Third review round (2026-08-02)

Round 3 reviewed round 2's fixes and again found Criticals introduced by them. The pattern is the
point: **each round of fixes to unexecuted code introduced new bugs into unexecuted code.** Three
rounds in, the fixes are converging, but nothing here substitutes for Phase 4 actually running it.

- [x] **The `EXIT` trap did not fire on signals — and signal death is the likely case.** `trap
  on_exit EXIT` does not run on HUP, INT, TERM or PIPE. CI wraps the deploy in a step timeout, and
  killing the ssh client orphans the remote script, which then dies at its next `log()` call
  because `echo` to a closed stdout takes SIGPIPE. Maintenance flag set, containers healthy, site
  serving 503 with nobody to lift it — and the two retry attempts then race the orphan for the
  lock and return "another deploy is already running". Fixed: traps on all four signals, `log()`
  tolerates a dead stdout, and the step timeout raised to 25 minutes to match the script's own
  worst-case budget.
- [x] **The rollback lift happened too late to help.** Round 2 documented an "unconditional lift at
  the start of every rollback"; the code only set a flag there and did the actual `artisan up`
  after the checkout, pull, `up -d` and both readiness waits. So a rollback run to escape a
  MySQL-related failure would die in the same MySQL wait, before lifting anything. Worse,
  `artisan up` needs a working app container — exactly what is missing in the failure paths that
  strand the flag. Fixed twice over: the lift now happens immediately, and it falls back to
  deleting `maintenance.php` and `down` straight out of the storage volume with a throwaway
  container, which needs only docker-group membership. Verified end to end against a real volume.
- [x] **`set_secret` could kill `deploy-init.sh` silently.** `current="$(grep … | cut …)"` is an
  assignment whose status is the pipeline's; under `set -e -o pipefail` a key absent from `.env`
  exits the script with no message, mid-write, leaving a half-built `.env`. The `else` branch that
  appends a missing key was unreachable dead code. Fixed with a `read_env_value` helper; the same
  latent bug in two `APP_URL` extraction sites is fixed with it.
- [x] **The "never rotates a live APP_KEY" guarantee was defeated by the overwrite prompt.**
  Answering `y` to "overwrite .env?" copies the example over it, blanking every secret, after which
  `set_secret` sees them empty and generates new ones. The MySQL volume still holds the old
  credentials, and `APP_KEY` decrypts every `encrypted` column and signs every session. Fixed:
  secrets are read out of the old file first and carried across, the prompt spells out what is and
  is not preserved, and the previous `.env` is backed up.
- [x] **`deploy-init.sh`'s own TLS renderer never got round 2's hardening** — and it runs *first*.
  It still took the domain from `APP_URL`, checked no directory, validated no output and wrote
  non-atomically. It is also where the `-0001` problem is *created*. Now shares a hardened
  `wire_up_tls`, and `resolve_cert_dir` finds the real directory (exact name first, then the
  newest `-NNNN`), verified across five cases.
- [x] **`CERT_DIR` had no writer.** Round 2 introduced the mechanism but nothing ever set it, so
  the fallback to the hostname was the only value that could occur and the `-0001` case would
  abort a deploy that should have worked. `wire_up_tls` now writes it; it is documented in
  `.env.production.example`.
- [x] **Declining certificate renewal silently dropped the server back to plain HTTP.** The
  "certificates already exist / renew? (y/N)" early return skipped both the config render and the
  `NGINX_CONF=` write — and on a re-run where `.env` was just recreated from the example, that
  means `NGINX_CONF=app.prod.conf`. Declining now still wires up the existing certificate.
- [x] **`APP_MAINTENANCE_DRIVER=cache` in the example contradicted the whole safety net.** The
  maintenance flag is detected by `public/index.php` as a *file*; with the cache driver it stops
  being seen and the net goes dark with no error. It was inert only because compose has no
  `env_file:` — one reasonable-looking change away from breaking. Now `file` in the example and
  pinned explicitly in all three compose `environment:` blocks.
- [x] **CI retried deterministic failures.** `max_attempts: 3` re-ran failed migrations against a
  half-applied schema, producing a different and harder-to-read error each time. Now
  `retry_on_exit_code: 255` — ssh's own transport-error code — so only network failures retry.
  Confirmed against the action's documentation that this retries *only* the given code.
- [x] Smaller: maintenance state now records what happened rather than what was intended;
  `clear_maintenance` is bounded by `timeout` so a wedged PHP process cannot hang the trap; the
  lift moved to after the cache rebuild so the first real request does not hit a just-cleared
  cache; the final `docker compose ps` no longer determines the script's exit status; and
  `DB_HOST` / `REGISTRO_VERSION` in the example no longer contradict their own comments.

### Fourth review round (2026-08-02) — and why reviewing stopped here

Round 4 found that round 3's headline fix was **worse than the bug it replaced**, which is the
clearest possible signal that reading the same never-executed code again has stopped paying.

- [x] **The signal handler prevented the cleanup it existed to perform.** `on_signal`'s first
  action was `log()`, and `log()`'s first action was writing to stdout. When the orphaning event
  *is* a dead stdout — CI's step timeout killing the ssh client — that write raises SIGPIPE inside
  the PIPE handler and bash re-enters it. Reproduced directly: with a closed stdout, a SIGTERM
  produced no cleanup at all. Fixed three ways at once: `log()` writes to the durable log file
  before touching stdout, `on_signal` disarms every trap as its first statement, and SIGPIPE is
  ignored outright rather than handled.
- [x] **More importantly: correctness no longer depends on traps at all.** No trap can catch
  SIGKILL, so a trap-based guarantee was never going to be one. **Every** run — deploy and
  rollback alike — now clears any stranded maintenance flag before doing anything else. A stranded
  503 therefore survives at most until the next deploy attempt, including the automatic CI retry,
  with no signal handling involved. The traps remain a best-effort fast path.
- [x] **`MAINTENANCE_ON` could be false while the flag was on disk.** `artisan down` writes
  `storage/framework/down` before it writes `maintenance.php` and before it prints anything, so it
  can fail with the flag already set — and then the cleanup skipped it. The errors are wildly
  asymmetric: a false positive costs one no-op `artisan up`, a false negative is a silent outage.
  Now over-approximated from container existence rather than exit status.
- [x] **The volume lookup could have deleted another project's files.** `docker volume ls --filter
  name=storage-framework` is an unanchored substring match across the whole daemon, and the result
  was silently narrowed to the first line. Verified with two compose projects side by side: the
  old filter returns *both* volumes. Now matched on `com.docker.compose.project` +
  `com.docker.compose.volume` labels, refusing to act unless exactly one matches, and resolved
  lazily so it works on a first bring-up where the volume does not exist until `up -d`.
- [x] **The recovery path depended on an image the script itself prunes.** The fallback used
  `alpine:3`, which `docker image prune -af --filter until=24h` deletes on every successful deploy
  — so recovery would have needed a Docker Hub pull at the exact moment the stack was broken. Now
  uses the application image, which is present by definition.
- [x] **`sed`-based `.env` writes corrupted carried-over secrets.** With `|` as the delimiter and
  `&` meaning "the whole match", a password of `p@ss|word&x` silently became `p@ss` — on the code
  path whose entire purpose is preserving the credentials MySQL was initialised with. Verified,
  then replaced with an `awk` writer using `ENVIRON` (no `-v` escape processing) and `index()` (no
  regex). Tested against pipes, ampersands, backslashes, `$(...)`, backticks and base64.
- [x] **The `-0001` mechanism was solving a problem it had backwards.** certbot does not delete the
  original lineage when it creates `example.com-0001`, so preferring the exact name would always
  return the *older, narrower* certificate. Fixed at the source instead: both certbot invocations
  now pass `--cert-name "$domain" --expand`, which pins the lineage so the rename never happens.
- [x] **CI's timeout path bypassed the retry restriction.** `retry_on_exit_code: 255` governs only
  the exit-code branch; the timeout branch is separate and still defaulted to retrying. A 25-minute
  overrun would retry 60 s later while the orphan still held the deploy lock, get exit 4, and
  report "another deploy is already running" — disguising a timeout as a concurrency error. Added
  `retry_on: error`.
- [x] Several comments asserted things that were false: `/up` is not short-circuited by
  `index.php` (it 503s via the middleware, same conclusion, wrong mechanism); the `cache`
  maintenance driver does not stop the site entering maintenance (it stops it getting *out*, which
  is the real reason to pin `file`); and the "never 'latest'" comment sat directly above
  `REGISTRO_VERSION=latest`. All corrected.

**Reviewing stopped here deliberately.** Four rounds, and rounds 2, 3 and 4 each found defects
introduced by the previous round's fixes — all of it in code that has never run. The remaining
defect density is dominated by things only execution will reveal. The next step is a deliberate
dry run on the VPS (deploy a throwaway tag, kill the CI step mid-run on purpose, confirm the flag
lifts on the following attempt), not a fifth read.

### Frozen `public/` volume — FIXED 2026-08-02 (`feature/public-assets-volume`)

**`docker-compose.prod.yml` mounts `app_public:/var/www/public` as a named volume.** Docker seeds a
named volume from the image only when the volume is *empty*, so from the second deploy onward
`/var/www/public` never received the new image's contents. `docker/entrypoint.sh` was supposed to
compensate for `public/build`, but its guard compared modification times against a destination that
`cp -r` restamps — and since the volume is seeded from the same image, both directories start with
identical mtimes. **Reproduced: it printed "Frontend assets already up to date" on the very first
run and every run after it. The copy never once executed.**

> **Correction.** An earlier revision of this section — written from the review that found the
> defect, before it was reproduced — claimed "every asset 404s from deploy #2 onward … the site is
> unstyled". That is wrong, and testing showed why: the frozen `build/` keeps its `manifest.json`
> **and** its hashed assets together, so it stays internally consistent and nothing 404s.
>
> The real behaviour is quieter and arguably worse to diagnose: **the site serves the old frontend
> indefinitely.** A UI fix is deployed, reported successful, and simply never appears. The stale
> `index.php` front controller is frozen too. The sharp edge is a **newly added Vite entry point**:
> it is absent from the stale manifest, so Laravel raises `Unable to locate file in Vite manifest`
> and that page 500s.

**Fix.** `docker/entrypoint.sh` now syncs `public/` from the image's `/tmp/public` snapshot on every
app-container start. `build/` is staged into `build.new` and swapped by rename rather than merged,
so past releases' hashed assets do not accumulate and nginx never reads a half-copied tree; the rest
of `public/` is overwritten in place. The `storage` symlink is created *after* the sync and is
absent from the image (`.dockerignore` excludes it), so it cannot be clobbered. Only the `app`
service mounts this volume read-write — nginx has it `:ro`, horizon and scheduler not at all — so
there is exactly one writer.

**Gate.** `scripts/server/deploy.sh` now compares the volume's `manifest.json` against the image's
by SHA-256 and fails the deploy on a mismatch. This is deliberately *not* "check the files the
manifest names exist" — that weaker check passes happily on a volume that is a year out of date,
which was verified by building it, watching it pass on a stale volume, and rewriting it. The health
check cannot see any of this: `/up` returns 200 throughout.

**Verified by reproduction, not inference:** a named volume stays frozen across image versions; the
old guard never fired; the new sync updates `index.php`, `.htaccess`, dotfiles, `css/` and hashed
assets across three consecutive deploys; a planted stale asset is pruned; the `storage` symlink
survives; rollback to an older image works; ownership stays `1000:1000` running as `laravel`; the
sync is idempotent and leaves no `build.new`/`build.old` behind; the corrected gate **fails** the
old entrypoint's second deploy and **passes** the new one.

**Known residual:** replacing a directory needs two renames, because `rename(2)` cannot replace a
non-empty directory, so there is a microsecond in which `build/` does not exist. Deploys run inside
maintenance mode, so this is not worth a symlink indirection to close.

### Confirmed sound by the same review

The security audit found the SSH forced-command boundary itself **airtight**: the
`SSH_ORIGINAL_COMMAND` grammar admits no injection, the tag regex is anchored at both ends, the
extra-argument check closes the obvious bypass, and nothing reaches a shell before validation.
`flock` handling, the workflow's single-source version flow, and `/up` reachability under both
nginx configs all passed.

### Still open, deliberately

- **`StrictHostKeyChecking=accept-new` plus `ssh-keyscan` on an ephemeral runner** re-trusts the
  host key on *every* deploy, not just the first — a recurring MITM window. The fix is pinning the
  host key fingerprint in a secret. Not a bug in the code as written; a decision to make before
  the first real deploy.
- **The `deploy` user is in the `docker` group**, which is root-equivalent. The containment is the
  forced command, not the absence of `sudo` — a docker-socket-proxy would be the stronger boundary.
  Recorded so nobody mistakes the sudo omission for the security control.

## 2. Network / firewall — executed 2026-08-01, MUST BE RE-RUN after §1c

`scripts/setup-production-server.sh` ran on `76.13.76.104` and passed 11/11 self-checks on two
consecutive runs, confirming idempotency. Verified independently afterwards, not taken from the
script's own report: `deploy` logs in by key and is in `docker` but **not** `sudo`; password
auth is genuinely refused (`Permission denied (publickey)`); `/opt/registro/deploy.sh` is
`root:root 755` and unwritable by `deploy`; `/var/www/registro` is `deploy:deploy`; only port 22
listens; 2 GB swap active; Docker log rotation in place.

> **This section was marked DONE prematurely.** The 11/11 result only ever meant "the script's own
> checks passed" — and the version that ran was missing the `ufw route allow` rules, so the box as
> it stands today would refuse all traffic to the containers the moment nginx starts. It also
> verified one of five sshd directives. Re-run the script (it is idempotent) before Phase 4; the
> check count is now 15. The remaining honest gap: **nothing has yet confirmed reachability from
> outside the host** — no container has ever listened on it. That test belongs to Phase 4 and
> cannot be done from the server itself.

### Three defects the execution exposed

- **SSH hardening was inert, and the box was accepting password logins.** `sshd` uses the
  **first** value it sees for a keyword — the opposite of almost every other drop-in config
  system — and files in `/etc/ssh/sshd_config.d/` are read in alphabetical order. Ubuntu's cloud
  image ships `50-cloud-init.conf` with `PasswordAuthentication yes`, so a hardening file named
  `99-registro.conf` (the intuitive choice) never took effect. Renamed to `00-registro.conf`,
  plus a hard assertion on the *effective* value from `sshd -T` rather than trusting that
  writing the file was enough.
- **The verification helper reported failure on checks that passed.** `sshd -T | grep -q …`
  under `set -o pipefail`: `grep -q` exits at the first match, the upstream command dies of
  SIGPIPE (141), and the pipeline is reported as failed. Every `grep -q` in a pipe was removed
  from the checks and the note explaining why is in the script, because this reads as a genuine
  failure and sends you debugging the wrong thing.
- **`/etc/docker/daemon.json` was written all-or-nothing.** This VPS shipped one containing
  `default-address-pools`, so the original code took the "file exists, just warn" branch —
  meaning on any machine with a pre-existing daemon.json, log rotation would silently never be
  configured. Now merged with `jq` (backing up the previous file), preserving the provider's
  settings while adding rotation and `live-restore`.

### Found by re-reading the diff before merge

- [x] **`deploy-init.sh`'s certificate step had become a silent no-op.** It rewrote
  `/etc/letsencrypt/live/DOMAIN` inside `app.prod.conf` — a file that, after the §1b split,
  contains no `ssl_certificate` directive at all; the placeholder is now `CERT_DOMAIN` and lives
  in `app.prod-tls.conf`. `sed` matched nothing and the script still printed "Nginx config
  updated with domain". The same function also (a) served the ACME challenge from
  `/var/www/certbot` while nginx serves it from `/var/www/letsencrypt`, (b) always requested
  `www.$domain`, which fails the *entire* issuance for a technical hostname that has no `www`
  record, and (c) went straight for a real certificate with no `--dry-run`, against a five-
  failures-per-hour limit. All four fixed, and the function now also flips `NGINX_CONF` to the
  TLS config once the certificate exists.

### Known gap, deliberately not fixed

- **`docker/nginx/staging/app.staging.conf` still hardcodes the predecessor's staging host** in
  `server_name` and in both certificate paths — the same defect class fixed for production in
  §1b. Not touched because no staging server exists and none is planned; `docker-compose.
  staging.yml` was parameterised (`APP_URL`, `APP_DOMAIN`) so the compose side is ready. Whoever
  stands staging up must apply the production split (HTTP config + TLS config + `NGINX_CONF`)
  there too, or it will fail to start for exactly the reasons documented above.

### The AAAA record stays — the earlier recommendation to delete it was wrong

`2a02:4780:c:fdab::1` is a real, working global address **on this machine**, with a default
IPv6 route and functioning outbound IPv6. The record is accurate, not stale. Deleting working
infrastructure to dodge a certbot failure would be the wrong repair; instead
`docker-compose.prod.yml` now publishes nginx on `0.0.0.0` **and** `[::]` (verified locally:
both `127.0.0.1` and `[::1]` return 200), and both nginx configs `listen [::]`. Docker's
userland proxy forwards the IPv6 connection to the container over IPv4, so no daemon-level
container IPv6 is needed. `ufw` covers v6 as well.

- [x] Install **`ufw-docker`**, not just plain `ufw` — Docker writes its own `DOCKER-USER` chain
  in iptables and silently bypasses bare `ufw allow`/`deny` rules for published container ports.
  See `app/docs/decisions/ADR-007-ufw-docker-security.md`.
- [x] Open only 22 (SSH), 80/443 (nginx). Confirm after bring-up that mysql/redis are **not**
  reachable from the host at all — `docker-compose.prod.yml` already publishes no ports for
  them (correct design), just verify with `docker compose -f docker-compose.prod.yml config` and
  `docker ps` on the live VPS rather than trusting the file alone.
- [x] This VPS is now dedicated solely to Registro — the port-80/443-conflict risk that existed
  when it hosted an unrelated Docker stack no longer applies, but re-confirm with `docker ps`
  after reset that nothing else is listening before bringing the stack up.

## 3. Secrets / environment variables

- [ ] Set real values before first boot: `APP_KEY` (`php artisan key:generate`), `DB_PASSWORD` /
  `DB_ROOT_PASSWORD`, `REDIS_PASSWORD` (must be **identical** across `app`, `horizon`, and
  `scheduler` — a mismatch here was a real past incident, see
  `app/docs/deployment/known-issues.md`), `MAIL_USERNAME`/`MAIL_PASSWORD`,
  `GOOGLE_MAPS_API_KEY`, `SMSAPI_TOKEN`/`SMSAPI_WEBHOOK_SECRET`.
- [ ] **Rotate** the Gmail App Password found in a local, untracked `.env.production` on this
  machine (`GOOGLE_GMAIL_PASSWORD`) — don't reuse that file as-is on the new server.
- [ ] Use `srv1342834.hstgr.cloud` for `APP_URL` and nginx `server_name` for now; mark it clearly
  as a technical/interim host in whatever config carries it, so it's obvious it needs replacing
  once a real domain is picked.
- [x] **Deferred, not blocking — plumbing added 2026-08-01.** `P24_MERCHANT_ID`,
  `P24_POS_ID`, `P24_CRC`, `P24_REPORTS_KEY`, `P24_LIVE` and `P24_TRANSACTION_GRACE_MINUTES`
  (the sixth was missing from the original list) are read by `config/przelewy24.php` but were
  absent from every `.env*.example` and from all three `environment:` blocks in
  `docker-compose.prod.yml`. Now present and empty-by-default everywhere, so switching payments
  on is an `.env` edit rather than a compose edit on a live server. The app still launches
  without them.

## 4. Security follow-ups before going internet-facing

- [x] ~~`CheckRegistrationEnabled` resolves tenant via the poisonable
  `TenantFeature::currentTenant()` session-fallback pattern.~~ **Stale — verified fixed
  2026-08-01.** The middleware reads `$request->attributes->get('tenant')` and calls
  `isRegistrationEnabledFor($tenant)` (`app/Http/Middleware/CheckRegistrationEnabled.php:29-32`),
  with the rationale in its own docblock. Both `/register` routes wire it after
  `ResolveTenant` (`routes/web.php:237,240`). This item was carried over from the VULN-003
  follow-up list after the fix had already landed.
- [ ] `app/docs/security/baseline.md` is stale (dated 2025-12-10, predates the entire
  VULN-003…009 remediation round from July 2026). Regenerate it before treating it as a go-live
  sign-off artifact.
- [ ] VULN-002 (no audit logging for booking events) — open, low severity, non-blocking, but
  worth closing before this becomes a live multi-tenant system with real customer data.
- [x] **Doc tree duplication — partially resolved 2026-08-01; the original claim was wrong.**
  Root `docs/security/` is *not* an unfilled template: only `baseline.md` was
  ("Template (not yet scanned)"), and six of its eight files exist **only** there — including
  `compliance.md` (631 lines) and `patterns/file-upload-security.md` (703 lines), both served by
  the MkDocs portal. Archiving the tree wholesale would have destroyed ~1,500 lines of unique
  content. Only the misleading placeholder was replaced, with a pointer to the real
  `app/docs/security/baseline.md`. Whether to merge the trees remains open — see §6.

## 5. CI/CD

- [ ] `deploy-production.yml` and `ci-staging.yml` assume the target path already exists on the
  server with a populated `.env` — they're **update** workflows, not fresh-bootstrap workflows.
  Still true after the §1 rewrite, and now explicit: `/opt/registro/deploy.sh` aborts with a
  clear message if `/var/www/registro`, its `.env`, or the compose file is missing. The first
  deploy goes through `setup-production-server.sh` + a manual clone + `deploy-init.sh`.
- [ ] GitHub Secrets `VPS_HOST`/`VPS_USER`/`VPS_SSH_KEY` (production) and `STAGING_VPS_*`
  (staging) almost certainly still point at the decommissioned predecessor hosts — update them to
  `76.13.76.104` before the first workflow run. Workflows remain `workflow_dispatch`-only by
  design; don't switch them to auto-trigger without a separate, explicit decision.
- [x] `.claude/agents/devops-engineer.md` described a `meilisearch` service that exists in no
  `docker-compose*.yml`. Corrected 2026-08-01 to the actual 8 dev services (verified against
  `docker compose config --services`; production has 6), with the Horizon sole-consumer rule
  and the "no build context in prod" note attached.
- [x] **`README.md`'s whole deployment section was substantially false**, not just one dead link.
  It advertised tag-triggered automatic deployment, Trivy vulnerability scanning and "automatic
  rollback on failure" — none of which exist (every workflow is `workflow_dispatch`-only, there
  is no Trivy step anywhere, and there is no rollback-on-failure path). It also told the reader
  to SSH into a decommissioned predecessor host by raw IP. Rewritten 2026-08-01 to
  describe what the pipeline actually does, with an explicit "nothing here has ever run" status
  banner, the forced-command SSH syntax, and the archived runbook link corrected.

## 6. Docs tree consolidation (architectural decision, not yet made)

Root `docs/` (the tree the self-hosted MkDocs portal in `docs-site/` actually serves —
`business/`, `architecture/*`, `assets/`) and canonical `app/docs/` (per `CLAUDE.md`: *"ALL docs
are in: app/docs/, NOT in: /docs/ (root)"*) are two separate, unmerged trees. The newly-merged
`app/docs/architecture/{panel-isolation,data-isolation,infrastructure,tenant-provisioning,
request-flow}.md` (see `docs-site/mkdocs.yml`'s NOTE comment) exist and are readable in the repo,
but **do not appear in the self-hosted portal**, because:

- The portal's `docs_dir` resolves (via `docker-compose.docs.yml`'s `./docs:/workspace/app/docs`
  bind mount) to root `docs/`, not `app/docs/`.
- The new files use relative links like `../security/vulnerabilities/VULN-003-...` that only
  resolve correctly inside `app/docs/` — root `docs/security/` is the stale placeholder tree
  described in §4, so copying the files as-is into `docs/architecture/` would silently break
  those links.

**Options, not yet decided:**
1. Relocate the portal's live content (root `docs/README.md`, `business/*`, `architecture/*`,
   `assets/`, `guides/architecture-docs-portal.md` — roughly 30 files) into `app/docs/`, and
   repoint `docker-compose.docs.yml`/`mkdocs.yml` `docs_dir` there. Fixes the relative-link
   problem and brings the repo into line with `CLAUDE.md`'s canonical-location rule. Real cost:
   a ~30-file migration with careful nav/link updates.
2. Keep `docs/` (root) as a deliberately separate, portal-curated tree distinct from `app/docs/`
   (dev/agent-facing docs), and accept that portal content and canonical docs will periodically
   diverge/duplicate.

Until this is decided, treat `app/docs/architecture/` as the authoritative source for
engineers/Claude, and the portal's "Development" section as a partial, business-presentation-
oriented subset — not the full picture.

## 7. Runbook pointers for the actual deploy day

Read these, in this order, before touching the VPS:

1. `app/docs/deployment/known-issues.md` + `app/docs/deployment/deployment-history.md` — real
   incidents from the predecessor project's deploys (env-var propagation traps, `phpredis` vs
   `predis`, healthcheck timeouts). Read *before*, not after something breaks the same way again.
2. `app/docs/decisions/ADR-007-ufw-docker-security.md` — firewall.
3. `app/docs/decisions/ADR-014-ssl-https-configuration.md` — certbot + nginx TLS pattern.
4. `app/docs/decisions/ADR-013-docker-user-model.md` — non-root container user rationale.
5. `app/docs/deployment/environment-variables.md` — required vars per service.

All IPs/domains referenced inside those documents are stale (old predecessor hosts) — substitute
`76.13.76.104` / `srv1342834.hstgr.cloud` (or the real domain, once chosen) wherever you see them.

## 8. Break-glass — deploy and rollback without GitHub Actions

**Why this section exists:** the CI/CD plan under discussion uses a **self-hosted runner** (private
repo ⇒ paid Actions minutes; public repo is not an option). A self-hosted runner is a single machine
that can die, be stolen, or simply be switched off. This section defines what must be true *in
advance* so that losing it costs convenience, not the ability to fix production.

**What a dead runner does and does not break.** Production is a Compose stack pulling a prebuilt
image from GHCR — it keeps serving, keeps restarting on reboot, and does not care that the runner is
gone. Only *deployment* stops. A `workflow_dispatch` run targeting `runs-on: [self-hosted]` sits in
"Queued" and is cancelled by GitHub after roughly 24 h. Nothing fails loudly; nothing happens.

- [ ] **Store offline, outside the runner machine** (password manager — not the laptop that runs the
  runner): the production deploy SSH key, a GHCR PAT with `write:packages`, and the current server
  path + `.env` location. With these three, any machine with Docker can build and ship: the
  Dockerfile is in the repo and there is nothing secret about the build itself.
- [ ] **Keep deploy runnable by hand.** The deploy logic must live on the server as
  `/opt/registro/deploy.sh` (updated from git), not as the heredoc currently piped over SSH in
  `deploy-production.yml:186-187`. Same refactor as the forced-command hardening in §4 — it also
  means a human with SSH can deploy with one command when no CI exists. Note the current workflow
  additionally `curl`s `docker-compose.prod.yml` and `docker/nginx/app.prod.conf` from
  raw.githubusercontent (lines ~200-210); the second path is the §1 nginx path bug, and both go away
  once the server owns its own deploy script.
- [ ] **Re-registering a runner is a ~5-minute task**, deliberately not a documented dependency:
  unpack the `actions-runner` tarball on any Linux box, `./config.sh` with a token from
  Settings → Actions → Runners, `./run.sh` in the foreground (systemd not required for a one-off).
  Any spare machine or a throwaway cloud VM will do.
- [ ] **Revoke the dead runner.** Its `.credentials` file (containing the registration key) sits on
  that machine's disk. If the machine is lost, stolen, or its disk is recoverable, remove the runner
  in Settings → Actions → Runners — that invalidates the registration.

**In an outage, roll back — do not hotfix.** A hotfix needs a build, a test run, and clear judgment
at a bad hour. A rollback needs one known-good tag. This is only possible once the `:latest` pinning
bug in §1 is fixed; with `REGISTRO_VERSION` in the server `.env` it is a single SSH command and
takes seconds.

- [ ] **Caveat: rolling the image back does not roll migrations back.** If the bad deploy already
  ran `migrate --force`, old code meets a new schema. Additive migrations (new column, new table)
  survive this; `drop`/`rename` do not. This is the concrete scenario the `migrations.md` rule about
  meaningful `down()` methods — and the pre-commit hook rejecting empty `down()` — exist for. Before
  the first real deploy, confirm `php artisan migrations:check-rollback` passes.

---

## What this checklist deliberately does NOT include

- Any fix already applied — none of §1–§5 or §8 has been executed; this is a report, not a changelog.
- The self-hosted runner setup itself — §8 states what must be true *before* relying on one; the
  hardening design (dedicated unprivileged user, rootless Docker, forced-command SSH, egress rules)
  is a separate, undecided piece of work.
- P24 wiring — deferred per explicit decision above.
- The docs-tree consolidation itself — §6 is a decision to make, not a task done.
