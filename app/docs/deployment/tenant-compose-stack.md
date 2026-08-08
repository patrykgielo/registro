# Tenant Compose Stack

**Scope:** `docker-compose.prod.yml` rebuilt into a per-tenant stack template,
`docker-compose.legacy-public-ports.override.yml` (new), `docker/nginx/production/app.tenant.conf`
(new), and the four scripts that assumed the literal `registro-*` container names
(`scripts/server/deploy.sh`, `scripts/deploy-init.sh`, `scripts/server/sync-certificate.sh`).
**Last verified:** 2026-08-08, `docker compose config` against both modes, two full local bring-ups
under real hardening flags (including the revised memory limits, after security review), a live
end-to-end request through the rendered tenant nginx config, and a live reproduction of both a
review-caught regression and its fix under a deliberately broken `.env` (see "What was actually run"
and "Scripts fixed" below). **Not deployed anywhere** — the live server still runs
`docker-compose.prod.yml`, plus the new override file, with zero `.env` changes (see the
`TENANT_PREFIX` parity table below).
**Related:** [Edge Stack](edge-stack.md) (task 5 — the ingress this stack sits behind once attached),
[Tenant-Stack Provisioning](../features/tenant-stack-provisioning.md) (task 2 — `TENANT_SLUG`/
`TENANT_HOSTS`/`TRUSTED_PROXIES_CIDR`), `.claude/rules/deployment.md`,
`.claude/rules/ci-cd-troubleshooting.md`.

---

## Task numbering, for context

Task 4 of the stack-per-tenant epic. Task 2 landed `TENANT_SLUG`/`TENANT_HOSTS` app-level pinning.
Task 5 landed the edge (`docker-compose.edge.yml`) and documented the contract a tenant stack must
satisfy to sit behind it, unverified against a real implementation. This is that implementation.
Task 6 (an `apply`-style operational script automating attachment) has now landed — see
[Tenant Apply](tenant-apply.md). Every step below is still accurate as the underlying manual
procedure `apply` automates; it is no longer the only way to perform it.

## Two deployments, one file

`docker-compose.prod.yml` now serves both:

1. **The legacy shared stack** — what the live server runs today. `TENANT_PREFIX`/`TENANT_SLUG`
   unset, publishes 80/443 itself, terminates its own TLS, resolves the tenant from the Host header
   per request against a database holding many organizations.
2. **A dedicated per-tenant stack** — `TENANT_SLUG` (app-level identity) and `TENANT_PREFIX`
   (infra-level naming, below) both set, sitting behind `docker-compose.edge.yml` instead of
   publishing ports itself, one organization per database.

## `TENANT_PREFIX` — a second variable, deliberately not derived from `TENANT_SLUG`

Every container name, the compose project name, and the Redis/cache/session/Horizon key prefixes all
need the same shape: `registro` when unset, `tenant-<slug>` for a real tenant. Docker Compose
interpolation only has `${VAR:-default}` (value if set, else default) and `${VAR:+alt}` (alt if set,
else empty) — there is no if/else with two independent literals, and combining the two on the same
variable double-evaluates it (verified: `${TENANT_SLUG:+tenant-${TENANT_SLUG}}${TENANT_SLUG:-registro}`
with `TENANT_SLUG=acme` produces the project name `tenant-acmeacme`, not `tenant-acme` — reproduced
with `docker compose config` before this design was abandoned).

`TENANT_PREFIX` is a second, independent variable an operator (or task 6, later) sets explicitly
alongside `TENANT_SLUG` — by convention `TENANT_PREFIX=tenant-<the same slug>`. Its default,
`registro`, was chosen to reproduce **exactly** what Laravel already computes unprompted for
`APP_NAME=Registro`:

| Compose key | Default (`TENANT_PREFIX` unset) | Laravel's own unprompted default | Config source |
|---|---|---|---|
| `name:` (project) | `registro` | — (directory basename today) | n/a |
| `container_name` | `registro-app`, `registro-nginx`, … | — | n/a |
| `SESSION_COOKIE` | `registro-session` | `Str::slug('Registro').'-session'` | `config/session.php:130` |
| `REDIS_PREFIX` | `registro-database-` | `Str::slug('Registro').'-database-'` | `config/database.php:155` |
| `CACHE_PREFIX` | `registro-cache-` | `Str::slug('Registro').'-cache-'` | `config/cache.php:110` |
| `HORIZON_PREFIX` | `registro_horizon:` | `Str::slug('Registro','_').'_horizon:'` | `config/horizon.php:70` |

Every value in that table's second column was confirmed by rendering `docker compose config` with
`TENANT_PREFIX` unset (see "What was actually run"). The legacy stack's cookie name, cache keys and
Horizon namespace are therefore bit-for-bit unchanged by this file existing, even though they are now
pinned explicitly in the compose file rather than left to Laravel's own default computation.

Two isolated `TENANT_PREFIX` values were run side by side (`docker compose config`, not deployed) and
produced completely disjoint container names, cookie names and Redis key prefixes — confirmed live
against a real Redis instance too (`KEYS *` showed `citest_horizon:*` and
`citest-database-citest-cache-*` for `TENANT_PREFIX=citest`, nothing resembling `registro-*`).

## Why six `container_name` became six *templated* `container_name`, not zero

The task's own wording was "remove all six `container_name`, introduce `name: tenant-<slug>`" — read
literally, that means dropping `container_name` entirely and relying on the project `name:` field
plus Compose's own default naming. Tried and measured before committing to the templated design:

```
$ docker compose -f compose.yml up -d   # name: tenant-acme, no container_name anywhere
Container tenant-acme-nginx-1 Started
```

Compose's default container name is `<project>-<service>-<replica-index>` — **always** with a
trailing `-1` for a non-scaled service. `docker/nginx/edge/tenants.d/_example.conf.disabled` (task 5,
already merged) resolves `tenant-SLUG-nginx:80` literally, with no index suffix, and Docker's embedded
DNS does not resolve `tenant-acme-nginx` to a container actually named `tenant-acme-nginx-1` — the
bare service alias `nginx` works, but not the slug-qualified name the edge's contract requires.
Verified directly (`getent hosts` from a second container on the same network): the slug-qualified
name resolves nothing; only `nginx` (the bare service alias) and the full `-1`-suffixed name do.

Separately, `scripts/backup-maintenance.sh` (unchanged by this task, out of its stated scope) runs
`docker cp registro-app:...` and `docker cp registro-redis:...` against **exact** container names —
dropping `container_name` for those two would have silently broken it for the legacy stack the moment
this file was deployed, with no error until the next backup ran.

Given both, `container_name` was kept for all six services, **templated from `TENANT_PREFIX` instead
of hardcoded**, which is what actually satisfies the spirit of the instruction (no more literal
`"registro-app"` string in the file) without breaking the edge's DNS contract or an out-of-scope
script that depends on exact names.

## The `ports:` conflict — resolved via a second compose file, not documented prose

Two requirements in this task's own brief are in direct tension:

- **Hard constraint:** the live legacy server must keep working, and it publishes port 80 to
  `0.0.0.0` unconditionally today (it has to, before any certificate exists — nginx cannot terminate
  TLS on a machine with no certificate yet).
- **Ground truth:** "after task 5 the tenant stack must publish NOTHING — the edge owns 80/443."

Docker Compose has no conditional list-entry omission — a `ports:` line is either present (with some
string value) or absent from the file entirely; interpolation can change the *value* but not whether
the entry exists. There is no way to make one variable produce "published to 0.0.0.0 when unset, not
published at all when set" without either changing the *live* default or introducing an opt-out.

**First attempt (superseded):** default `ports:` stayed public (`0.0.0.0`), with prose telling a
tenant operator to override `HTTP_PORT_V4`/`HTTP_PORT_V6` to loopback in their own `.env`. Flagged in
review as a footgun documented in prose rather than fixed in code — a step nobody is forced to take.

**Final design:** the default flipped. `docker-compose.prod.yml`'s nginx `ports:` now default to
`${HTTP_PORT_V4:-127.0.0.1:80:80}` / `${HTTP_PORT_V6:-[::1]:80:80}` — safe, mirroring how
`HTTPS_PORT_V4`/`HTTPS_PORT_V6` already behaved. A new file, `docker-compose.legacy-public-ports.
override.yml`, restores the public binding for the one deployment that genuinely needs it — the live
legacy server, which *is* the ingress with no edge in front of it. `scripts/server/deploy.sh` passes
both files on every invocation (`COMPOSE_ARGS=(-f "$COMPOSE_FILE" -f "$OVERRIDE_FILE")`) — **zero
`.env` change on the live server**, and every OTHER way of bringing this file up (a genuine tenant
stack, a manual `docker compose -f docker-compose.prod.yml up`) gets the safe default with nothing to
remember or forget.

The override file uses the Compose Specification's `!override` merge tag, not a bare `ports:` list —
verified this was necessary, not stylistic: Compose **appends** sequence fields like `ports:` across
`-f` files by default, so a bare override list left BOTH the base's loopback binding and the
override's public one active on the same target port simultaneously (reproduced with `docker compose
config` before adding the tag). `!override` makes the override file authoritative for the whole
`ports:` list instead of adding to it. The override file replicates all four entries (`80` public,
`443` still `${HTTPS_PORT_V4:-...}`/`${HTTPS_PORT_V6:-...}` — unchanged, TLS activation still works
exactly as before) rather than just the one that changed, because `!override` replaces the entire
field: leaving `443` out of the override would have un-published it entirely for the legacy stack.

The coordinator's own follow-up tempered the severity, fairly: on a single host the edge already
holding port 80 makes a forgotten override fail loudly (`address already in use`), not silently. The
real exposure was the provisioning order this doc's own runbook recommends (tenant stack up first,
attached to the edge later) and any future multi-host topology — worth fixing at the default either
way, which is what this does.

## `SESSION_DOMAIN` — locked, not defaulted

Set as a bare literal (`SESSION_DOMAIN: ""`) in the compose file's shared environment, not
`${SESSION_DOMAIN:-}`. A tenant stack serves exactly one Host; there is never a legitimate reason for
a wider-scoped cookie in this architecture, so this is enforced at the infra level rather than left to
`.env` convention (which already defaulted it blank, but nothing previously stopped an errant edit
from widening it).

## `X-Tenant` at the tenant nginx hop — `docker/nginx/production/app.tenant.conf`

Edge-stack.md's contract: the tenant's own nginx must set the authoritative `X-Tenant` from its own
`TENANT_SLUG`, never trusting anything already on the request (the edge only clears a client-supplied
value; it never sets the real one). Not yet consumed by any application code (`grep -rn "X-Tenant"` —
zero hits outside the edge's own vhost template) — this is forward-looking infrastructure, not wiring
up an existing feature.

nginx has no built-in way to read an arbitrary OS environment variable inside `fastcgi_param` at
request time. Two real options: nginx's own envsubst-templates mechanism (mount into
`/etc/nginx/templates/*.template`, auto-rendered on container start), or this repo's own established
convention — render a placeholder via `sed` into a gitignored `.local.conf` before mounting, exactly
like `CERT_DOMAIN` in `app.prod-tls.conf` (`scripts/deploy-init.sh`'s `wire_up_tls()`). Chose the
latter for consistency with the rest of this file's own TLS-config-switching mechanism
(`NGINX_CONF`), which already expects a rendered `.local.conf` file, not a live envsubst pass.

`app.tenant.conf` is the tracked template (`TENANT_SLUG_PLACEHOLDER`), plain HTTP only (the edge is
the sole TLS terminator — no `ssl_certificate`, no `/.well-known/acme-challenge/`, the edge answers
ACME itself). Render it once, before first bring-up:

```bash
sed 's/TENANT_SLUG_PLACEHOLDER/<the tenant slug>/g' \
    docker/nginx/production/app.tenant.conf > docker/nginx/production/app.tenant.local.conf
```

then set `NGINX_CONF=app.tenant.local.conf` in that stack's `.env`. `app.tenant.local.conf` is
gitignored, same reasoning as `app.prod-tls.local.conf`: `scripts/server/deploy.sh` runs
`git checkout --force` on every deploy/rollback, which would revert an in-place edit back to the
placeholder. **Automated by task 6** — see [Tenant Apply](tenant-apply.md)'s "nginx vhost" step; same
for the edge's own per-tenant vhost attachment.

Sets `fastcgi_param HTTP_X_TENANT "<slug>";`, not a bare `X_TENANT` — the `HTTP_` prefix is what makes
it land in PHP exactly where a real `X-Tenant` HTTP header would (`$_SERVER['HTTP_X_TENANT']`,
`Request::header('X-Tenant')`), even though this hop is FastCGI, not a second HTTP proxy — so future
code that reads the header can use Laravel's normal header API unchanged.

## `mysql`/`redis`/`nginx` hardening — capabilities found by running, not guessing

`no-new-privileges`, `cap_drop: ALL`, `pids_limit`, `mem_limit` on all six services. Which
capabilities each non-`app`-image service needed back was found by running the **exact pinned
image/tag** with `--cap-drop ALL --security-opt no-new-privileges` and reading the actual failure,
not by reasoning about what "should" be needed:

| Service | Cap-add | Found via |
|---|---|---|
| `app`/`horizon`/`scheduler` | *(none)* | Already non-root (`laravel`, `Dockerfile:228`), binds no privileged port. Ran `ghcr.io/patrykgielo/registro:v0.13.0-rc9` (the pinned image, a tag already on this host) with zero cap-add — entrypoint's own user self-check passed, proceeded straight to waiting on MySQL. |
| `mysql` | `SETUID`, `SETGID`, `CHOWN`, `DAC_OVERRIDE` | `mysql:8.0` with zero cap-add hung; adding caps one failure at a time until a real `mysqladmin ping` succeeded. |
| `redis` | `SETUID`, `SETGID` | `redis:7.2-alpine` with zero cap-add: `setpriv: setresuid failed`. `CHOWN` was tried too and found unnecessary — removed after confirming `SETUID`+`SETGID` alone gave a real authenticated `PONG`. |
| `nginx` | `NET_BIND_SERVICE`, `CHOWN`, `SETUID`, `SETGID` | `nginx:1.25-alpine` with zero cap-add: `chown("/var/cache/nginx/client_temp") failed`. Added `CHOWN`, then hit `setgid(101) failed`; added `SETUID`+`SETGID` too. Confirmed with a real `GET /` returning nginx's welcome page. |

`pids_limit`/`mem_limit`: `app` 512/1536m, `mysql` 512/1g, `redis` 64/384m, `nginx` 128/256m,
`horizon` 256/1536m, `scheduler` 128/256m.

`app` and `horizon`'s `mem_limit` were **raised from an initial 768m/512m**, found in review to sit
*below* what this repo's own configuration already permits, not "conservative":

- `horizon`: `config/horizon.php`'s production `supervisor-1` allows `maxProcesses: 10`, and the
  inherited `memory: 128` is Horizon's own per-worker **soft** self-restart threshold — it does not
  preempt a job mid-run, so it is a target, not a hard cap. 10 x 128M = 1280M worst case against the
  original 512m cgroup, and an OOM kill there takes the Horizon **master** process, not one worker.
- `app`: no custom `www.conf` ships, so `pm.max_children` defaults to 5, and
  `docker/php/uploads.ini:21` sets `memory_limit=256M` — 5 x 256M = 1280M worst case against the
  original 768m cgroup.

Chose to **raise the limits to match what the config permits** (1536m each, headroom above the 1280M
figure for the OPcache shared segment and master/FPM overhead) rather than lowering
`maxProcesses`/`pm.max_children` to fit the old limits — concurrency is a production-capacity
decision this compose-only task has no basis to make unilaterally. Re-verified with a full local
bring-up under the new limits: all six containers reached `healthy`/`Up` again, and
`docker inspect --format '{{.HostConfig.Memory}}'` confirmed `1610612736` (1536 MiB) applied to both
`app` and `horizon`.

`redis` widened from 320m to 384m: 64MB of headroom above `--maxmemory 256mb` was too tight for
connection/client-buffer overhead plus the copy-on-write memory spike a `BGREWRITEAOF` fork can cause
under a heavy write burst (`appendonly yes` is set) — neither is bounded by `maxmemory`, which only
governs the dataset itself.

## Duplicated environment blocks — collapsed via a YAML anchor

`app`, `horizon` and `scheduler` were three independently hand-maintained ~55-line `${VAR}` lists that
had already drifted (`horizon` was missing `SESSION_SECURE_COOKIE`/`SESSION_SAME_SITE`/`LOG_*` that
`app` had). This task adds seven more identical keys to all three (`TENANT_SLUG`, `TENANT_HOSTS`,
`TRUSTED_PROXIES_CIDR`, `SESSION_COOKIE`, `SESSION_DOMAIN`, `REDIS_PREFIX`, `CACHE_PREFIX`,
`HORIZON_PREFIX`) — exactly the failure shape `tenant-stack-provisioning.md` warns about ("every
isolation gate keys on one scalar... an unset gate relaxes silently"), so tripling them by hand here
would have been the wrong call given what this task is already asking these keys to guarantee.
Collapsed into a top-level `x-app-env: &app-env` mapping, merged per service with `<<: *app-env` plus
that service's genuine differences (`app` alone gets `SYNC_PUBLIC_FROM_IMAGE`; `app`/`scheduler` get
`LOG_*`; `app` alone gets `SESSION_SECURE_COOKIE`/`SESSION_SAME_SITE`). `environment:` switched from
list form to mapping form to make the YAML merge key (`<<:`) usable — merge keys only work on
mappings, not sequences.

## `horizon`/`scheduler` storage-volume asymmetry — recorded, not fixed

`horizon` and `scheduler` mount no `storage-*` volumes at all, despite `FILESYSTEM_DISK=public`
(pre-existing, not introduced by this rewrite). Left as-is: confirming it's safe to give a hardened,
`cap_drop: ALL` worker container write access to the same volume `nginx` serves read-only needs a grep
of every queued `Job` class for a `Storage::` write first (PDF/CSV export jobs, image processing) —
guessing wrong on a live-prod-adjacent file is worse than leaving the asymmetry recorded here for
whoever does that audit next.

## Scripts fixed

- **`scripts/server/deploy.sh`** — `status` action's `docker ps --filter "name=registro-"` now reads
  `TENANT_PREFIX` from `.env` via a bare `grep` (not `source .env`, for the same reason the surrounding
  comment already gives: this diagnostic must not depend on every other required var being valid),
  defaulting to `registro-` unchanged. Also now carries `COMPOSE_ARGS=(-f "$COMPOSE_FILE" -f
  "$OVERRIDE_FILE")`, used everywhere the script previously ran `docker compose -f "$COMPOSE_FILE"`
  (19 call sites) — see the `ports:` section above.

  `force_clear_flag()` went through **two** versions; the second was caught in review before shipping.
  The original resolved the `storage-framework` volume by hand (`docker volume ls --filter
  label=com.docker.compose.project=...`) and refused to act on anything but exactly one match, which
  left **no recovery path through the forced command** when that lookup came back empty or doubled.
  The first fix replaced it with `docker compose run --rm --no-deps --entrypoint rm app -f
  .../maintenance.php .../down` — **a regression, found in security review, that never shipped.**
  Every Compose subcommand (`run`, `config`, `ps`) interpolates the ENTIRE file before selecting a
  service, and this file hard-requires `APP_KEY`/`APP_DOMAIN`/`REDIS_PASSWORD` via `${VAR:?}` — so a
  blanked or corrupted `.env` breaks `docker compose run` at interpolation, before `rm` ever executes.
  That is precisely the scenario this function exists for, and the primary path
  (`docker compose exec -T app php artisan up`) already shares that exact failure mode — one bad edit
  would have taken out both the primary path and its own fallback. **Reproduced directly**: blanked
  `REDIS_PASSWORD` in a throwaway `.env`, ran the `docker compose run` version — `error while
  interpolating x-app-env.REDIS_PASSWORD: required variable REDIS_PASSWORD is missing a value`, exit
  1, before any `rm`.

  **Final fix asks Compose nothing.** The volume name is *computed*, not looked up: Compose's own
  deterministic auto-naming is `${project}_${volume-key}` (confirmed by inspection — bringing this
  file up under project name `citest` created a volume literally named `citest_storage-framework`),
  and the project name is `${TENANT_PREFIX:-registro}` — read via the same bare `grep` on `.env` the
  `status` action already uses, needing nothing but that one line to be present. `docker volume
  inspect` (raw docker, not compose) confirms the volume exists before touching it — `docker run -v
  name:/path` on a name that doesn't exist yet **silently creates an empty one**, which would report
  success while removing nothing. **Re-verified end to end under the SAME broken `.env`** that broke
  the `docker compose run` version: computed volume name `citest_storage-framework`, `docker volume
  inspect` confirmed it existed, `docker run --rm --entrypoint rm -v citest_storage-framework:/s
  <image> -f /s/maintenance.php /s/down` exited 0, and bringing `app` back up under the *good* `.env`
  confirmed both files were actually gone — recovery works precisely when the `.env` is broken, which
  is the only time it matters.
- **`scripts/deploy-init.sh`** — the ACME-challenge bootstrap's hardcoded `registro-nginx` check now
  reads `TENANT_PREFIX` via this script's own existing `read_env_value()` helper, same
  unset-defaults-to-`registro` behaviour.
- **`scripts/server/sync-certificate.sh`** — **no change needed.** Task 5 already generalized its
  nginx reload target to `NGINX_RELOAD_CONTAINER` (env-driven, defaults to `registro-nginx`,
  unchanged for the legacy stack). Checked whether that already covered the "four places" this task
  named: it does — the only remaining hardcoded assumption in that script is its **hostname source**
  (`tenants:hostnames`, queried from the legacy stack's own `app` container), which is a documented,
  separate, pre-existing gap (that script's own header, and edge-stack.md's "Known gap" section) about
  *which tenants a certificate should cover*, not about *what a container is named* — out of this
  task's scope, and in practice moot for a tenant stack behind the edge, which never terminates TLS at
  all (see `app.tenant.conf`'s header) and so never calls this script in the first place.

## What was actually run

No server, no live traffic, no dev containers disrupted (verified `registro-app`/`registro-mysql`/
`registro-redis` — this project's actual dev stack — stayed `Up` and untouched throughout, checked
before and after every test below).

1. `docker compose -f docker-compose.prod.yml config` — parses, both with `TENANT_PREFIX`/
   `TENANT_SLUG` unset (legacy) and set (`TENANT_PREFIX=tenant-acme`, `TENANT_SLUG=acme`). Confirmed
   every container name, prefix and default in the tables above by grepping the rendered output.
2. **Full local bring-up**, throwaway project name `citest` (isolated `TENANT_PREFIX`, non-standard
   loopback ports `18080`/`18443`), the real `mysql:8.0`/`redis:7.2-alpine`/`nginx:1.25-alpine` images
   and this project's own already-pulled `ghcr.io/patrykgielo/registro:v0.13.0-rc9`, under the full
   hardening block (`cap_drop: ALL` + the specific `cap_add` list, `no-new-privileges`, `pids_limit`,
   `mem_limit`) exactly as committed:
   - All six containers reached `healthy` (or `Up`, for `scheduler`, which has no healthcheck).
   - `curl -H "Host: example.com" http://127.0.0.1:18080/up` reached PHP-FPM through nginx and
     returned a real Laravel error page (missing schema — no migrations were run; this proves the
     proxy path and hardened containers work, not that the app is fully functional standalone).
   - `Set-Cookie: citest-session=...` on a real response, confirming `TENANT_PREFIX` reaches the
     session cookie name end to end, not just in `config` output.
3. **Rendered `app.tenant.conf`** (`sed s/TENANT_SLUG_PLACEHOLDER/citest/`), recreated the `nginx`
   service on `NGINX_CONF=app.tenant.local.conf`, dropped a one-line probe script into the shared
   `app_public` volume, and confirmed `{"tenant":"citest"}` over a real HTTP request — the FastCGI
   `HTTP_X_TENANT` param reaches `$_SERVER` exactly as designed. Also confirmed via `nginx -T`: no
   `ssl_certificate` directive present, `listen 80`/`listen [::]:80` only.
4. **Real Redis prefix isolation**: `docker exec citest-redis redis-cli ... KEYS '*'` against the live
   instance showed `citest_horizon:*` and `citest-database-citest-cache-*` keys — the prefixes are not
   just present in `config` output, they are what Laravel and Horizon actually write with.
5. **`force_clear_flag()`, both versions, both under the SAME deliberately broken `.env`** (blanked
   `REDIS_PASSWORD`): brought up `mysql`+`redis`+`app`, wrote `storage/framework/{down,
   maintenance.php}` by hand, stopped `app`. The `docker compose run` version failed exactly as
   predicted — `error while interpolating x-app-env.REDIS_PASSWORD: ...`, exit 1, before any `rm`. The
   final (computed-volume-name, raw `docker run`) version succeeded — `docker volume inspect` found
   the volume, `docker run --rm --entrypoint rm -v <vol>:/s <image> -f ...` exited 0 — confirmed by
   bringing `app` back up under the *good* `.env` and listing the directory: both files gone.
6. **`ports:` flip**, full local bring-up of all six services with `-f docker-compose.prod.yml -f
   docker-compose.legacy-public-ports.override.yml` (simulating `deploy.sh`'s `COMPOSE_ARGS` exactly):
   all six reached `healthy`/`Up`, `docker port citest-nginx` showed `80/tcp -> 0.0.0.0:80` and
   `[::]:80` (public, as the legacy stack needs) with `443` still loopback-only (TLS not configured,
   unchanged), and a real HTTP request through nginx to a probe script returned successfully. Base
   file alone (no override) was separately confirmed to resolve to loopback-only via `docker compose
   config`.
7. **Memory limits**, full local bring-up under the revised `1536m`/`1536m`/`384m` limits: all six
   containers reached `healthy`/`Up` again; `docker inspect --format '{{.HostConfig.Memory}}'`
   confirmed `1610612736` (1536 MiB) applied to both `app` and `horizon`.
8. `bash -n` on all edited shell scripts (`scripts/server/deploy.sh`, `scripts/deploy-init.sh`,
   `scripts/server/sync-certificate.sh` — the last one unchanged, checked anyway to confirm no
   accidental edit).
9. `./vendor/bin/pint --test && php artisan test` (+ `--testsuite=Browser`) inside Docker, run twice
   (once per hardening/limits round) — no PHP was touched by this task; both runs held the documented
   baseline: 1 failed (pre-existing, unrelated) / 5 skipped / 1081 passed, Browser 9 passed.
10. Every throwaway stack was torn down with `-v` (removing its own volumes) after each test; the
    rendered `app.tenant.local.conf` test artifact was deleted (it is gitignored, but left over it
    would have confused `git status` for review).

**What was not validated, and why:** actual TLS termination or edge proxying (no edge is running here
— task 5's own doc already covers the edge in isolation), the real `TRUSTED_PROXIES_CIDR` value
against a real edge network (none is attached to anything yet), the public/loopback port bindings
against a genuine internet-facing host and firewall (only confirmed via `docker port` inside this
sandbox, per the "`ports:` flip" test above), and `scripts/deploy-init.sh`'s certbot flow end to end
(unchanged apart from the one name lookup — its own certbot/DNS steps were already out of this task's
reach without a real domain).

## Manual steps an operator must perform

1. **Legacy stack: nothing.** Deploying this file (and the new
   `docker-compose.legacy-public-ports.override.yml`) as-is to the current live server changes no
   behaviour — `scripts/server/deploy.sh` already passes both files on every invocation
   (`COMPOSE_ARGS`), so port 80 stays public and every name/prefix resolves exactly as before. Anyone
   bringing this stack up OUTSIDE `deploy.sh` (a manual `docker compose up` on that server) must pass
   `-f docker-compose.prod.yml -f docker-compose.legacy-public-ports.override.yml` themselves, or nginx
   binds to loopback only and the site goes unreachable from outside — this is the one place "nothing"
   depends on going through the script rather than a bare `docker compose` command.
2. **New tenant stack**, before first bring-up:
   - Set `TENANT_SLUG` and `TENANT_HOSTS` (task 2, unchanged) and `TENANT_PREFIX` (new, this task) in
     that stack's `.env`.
   - Nothing needed for ports — the base file's default is loopback-only; only the legacy override
     file (never referenced by a tenant stack) makes nginx public.
   - Render `app.tenant.conf` → `app.tenant.local.conf` with the real slug (see "`X-Tenant`" above),
     and set `NGINX_CONF=app.tenant.local.conf`.
   - Attach the edge network per `edge-stack.md`'s "How a tenant is attached" — out of this task's
     scope, that document's own runbook.
3. **Either stack**, no action needed: `scripts/server/deploy.sh`'s `status` action and the
   maintenance-mode recovery path both now work correctly regardless of `TENANT_PREFIX`.
