# Edge Stack

**Scope:** `docker-compose.edge.yml`, `docker/nginx/edge/**`, and the `NGINX_RELOAD_CONTAINER`
addition to `scripts/server/sync-certificate.sh` — the shared ingress that will eventually sit in
front of every per-tenant stack (task 4) instead of each one publishing 80/443 itself.
**Last verified:** 2026-08-08, `nginx -t` against nginx:1.25-alpine in throwaway containers and
real HTTP/2 requests against a running one (see "What was actually run" below). **Not deployed
anywhere** — the live server still runs `docker-compose.prod.yml` unmodified.
**Related:** [Tenant-Stack Provisioning](../features/tenant-stack-provisioning.md) (task 2 — slug
pinning and `TENANT_HOSTS`, the thing this edge sends traffic to), `.claude/rules/deployment.md`,
`.claude/rules/ci-cd-troubleshooting.md`.

---

## Task numbering, for context

This is **task 5** of the stack-per-tenant epic. Task 2 (`feat(tenant): pin a stack to one tenant`,
#155) landed `TENANT_SLUG`/`TENANT_HOSTS` pinning and taught a dedicated stack to fail closed on an
unrecognised Host. **Task 4 (rebuilding the tenant compose so each tenant is its own container) has
since landed** — see
[Tenant Compose Stack](tenant-compose-stack.md) for the implementation of the contract described
below (`tenant-<slug>-nginx:80`, `X-Tenant`, `TRUSTED_PROXIES_CIDR`). It is still not attached to
this edge or to anything live — attaching the first tenant is a later, separate operational step.
Task 6 (an `apply`-style operational script) has now landed and automates the manual steps this
document describes — see [Tenant Apply](tenant-apply.md), including a correction to the network-
attachment step below (`apply` generates an override file rather than hand-editing
`docker-compose.edge.yml`, which this document's own runbook still describes as manual-only). This
document remains the reference for what `apply`'s edge-sync step actually does and why.

## What the edge is

One nginx container, `docker-compose.edge.yml`, that will become **the only thing publishing
80/443** once it's actually deployed. It:

- Terminates TLS for every tenant hostname.
- Answers ACME HTTP-01 challenges itself (`/.well-known/acme-challenge/`), since once it holds 80 it
  is the only thing that can.
- Returns **444** (connection closed, no response) for any Host it doesn't recognise, rather than
  the app-layer catch-all (`server_name _`) that `docker/nginx/production/app.prod.conf` uses today.
- Proxies matched requests over a **per-tenant external Docker network** to that tenant's own
  nginx, which is the only thing that talks to that tenant's PHP-FPM.

It does **not** run any application code, hold any database credentials, or know anything about a
tenant beyond its slug, its hostnames, and which Docker network reaches it.

### Why it doesn't exist as one nginx catch-all like today's app.prod.conf

Today's `app.prod.conf`/`app.prod-tls.conf` are deliberately host-agnostic (`server_name _`)
*because* the many-tenants-in-one-database shared stack resolves the tenant from the Host header
**inside the app**, after nginx has already let everything through. The edge is the opposite: on a
stack-per-tenant architecture there is no single app process behind it that can make that call for
every tenant at once — each tenant is its own container reachable only through its own network.
An unrecognised Host at the edge has nowhere correct to go, so it gets nothing back instead of a
same-origin-looking 404 (see the comment in `edge-tls.conf` for the Certificate-Transparency /
scanner-fingerprinting reasoning for 444 specifically).

## Bring-up order (mirrors app.prod.conf / app.prod-tls.conf exactly)

1. `docker compose -f docker-compose.edge.yml up -d` with the default `EDGE_NGINX_CONF=edge.conf`
   (HTTP-only, no certificate required — same reason `app.prod.conf` has none: nginx refuses to
   start if a referenced certificate file is missing).
2. Once a certificate exists at the path `edge-tls.conf` expects, render it:
   ```bash
   sed 's|CERT_DOMAIN|<live-cert-directory-name>|g' \
       docker/nginx/edge/edge-tls.conf > docker/nginx/edge/edge-tls.local.conf
   ```
   `<live-cert-directory-name>` is whatever `CERT_DIR` in `.env` already names on the box running
   the edge — the *same* certificate `scripts/server/sync-certificate.sh` already maintains, not a
   new one. `edge-tls.local.conf` is gitignored for the identical reason
   `app.prod-tls.local.conf` is (see that file's header): nothing re-renders it after a
   `git checkout --force`, so it must never be a tracked file with a real value baked in.
3. Set `EDGE_NGINX_CONF=edge-tls.local.conf` and `docker compose -f docker-compose.edge.yml up -d
   edge-nginx`.

**Nothing here has been run against a real box.** Steps 2–3 above are the manual procedure until
task 6 automates them; per the task brief, this PR does not touch the live server, does not run
certbot, and does not issue anything.

## Certificate source — the swappable part

Today: the **same multi-SAN certificate** `scripts/server/sync-certificate.sh` already issues and
renews for the legacy stack, one certificate carrying every live tenant hostname as a SAN, validated
over HTTP-01. `edge-tls.conf`'s `CERT_DOMAIN` placeholder points at it, exactly like
`app.prod-tls.conf` does today.

**The wildcard (`*.registrolabs.com`, `*.registroapps.com`) is deliberately not implemented here.**
Both domains are parked on Hostinger's nameservers, and Hostinger is supported by neither certbot's
DNS plugins nor acme.sh's dnsapi (checked directly against both plugin lists). Getting a wildcard
means either moving the nameservers to Cloudflare (`certbot-dns-cloudflare`) or writing custom
`--manual-auth-hook`/`--manual-cleanup-hook` scripts against Hostinger's REST API — both are
outward-facing changes to live domains and are the domain owner's call, not something to invent
speculative hook code for here.

When a wildcard does arrive, **only `edge-tls.conf`'s certificate block changes**: two
`ssl_certificate`/`ssl_certificate_key` pairs (one per apex domain), and every per-tenant vhost in
`tenants.d/` stops needing its own `ssl_certificate` lines (they only exist today because nginx
requires one on every `listen ... ssl` block that isn't the matching `default_server`). Nothing about
the `proxy_pass`/`resolver` pattern, `error_page`, the per-tenant network model, or the 444 fallback
changes. This is why the certificate is a `CERT_DOMAIN` placeholder rendered at deploy time rather
than baked into the tracked config — the render step is the one place that has to change later.

**New tenants stop touching ACME entirely once the wildcard lands** — the 15-minute warning window
and the per-tenant-name leak into public Certificate Transparency logs (both documented in
`scripts/server/sync-certificate.sh` and the Phase 7 deployment doc) both go away, because a new
subdomain is already covered by the wildcard the moment DNS resolves it.

## How a tenant is attached (manual procedure; automated by `apply` — see below)

**Read this alongside [Tenant Apply](tenant-apply.md)'s "Correcting edge-stack.md's manual runbook"
section.** Step 4 below (hand-editing `docker-compose.edge.yml`) is what `apply` does NOT do — that
file is git-tracked and reverted by `deploy.sh`'s own `git checkout --force` on every legacy-stack
deploy, so an in-place edit there does not survive. `apply`'s edge-sync step instead generates a
gitignored override file from the set of `tenants.d/*.conf` files actually present. This procedure
remains correct for attaching a tenant BY HAND, without `apply` — just do not mix the two approaches
on the same box, they will fight over the same file on `apply`'s next run.

Preconditions: the edge is already running on `edge-tls.local.conf` (a tenant vhost is only ever
loaded into the TLS config — see `edge.conf`'s header for why loading one into the HTTP-only
bootstrap config would be a live-incident-shaped mistake), and task 4 has produced a per-tenant
stack whose nginx container this can actually reach.

1. **Copy the template.**
   ```bash
   cp docker/nginx/edge/tenants.d/_example.conf.disabled docker/nginx/edge/tenants.d/<slug>.conf
   ```
   Replace every `SLUG` with the tenant's slug, `CERT_DOMAIN` with the same value
   `edge-tls.local.conf` was rendered with, and `TENANT_SERVER_NAMES` with this tenant's own
   space-separated host list (one hostname, `<slug>.<this machine's domain>`, unless `apply` was
   given an explicit `[hosts]` argument — see `tenant-apply.md`'s "One tenant, one domain" section;
   never a second hardcoded domain). This file is **gitignored** (`.gitignore`) — it names a
   live customer subdomain, which doesn't belong in this repo's history any more than
   `app.prod-tls.local.conf`'s certificate path does.
   In practice `apply.sh`'s own edge-sync step does this rendering automatically on every apply —
   this manual copy is the fallback for attaching a tenant by hand.

2. **Seed the service page.**
   ```bash
   mkdir -p docker/nginx/edge/tenant-pages/<slug>
   cp docker/nginx/edge/tenant-pages/_default/503.html docker/nginx/edge/tenant-pages/<slug>/
   ```
   Every tenant gets the generic page unless someone later customises that one file. The vhost's
   `root` is hardcoded to `/etc/nginx/tenant-pages/<slug>` — there is no fallback-to-default lookup
   inside nginx, so this step is not optional.

3. **Create the shared network** (out-of-band — Compose's `external: true` means it expects this to
   already exist, never `docker network connect`, and never lets a `docker compose down` on either
   side delete it):
   ```bash
   docker network create --subnet <chosen-CIDR> tenant-<slug>-edge
   ```
   **Pin the subnet explicitly.** An unpinned `docker network create` picks whatever's next free,
   which is fine for connectivity but leaves the tenant stack's `TRUSTED_PROXIES_CIDR` (see
   `tenant-stack-provisioning.md`'s section on `TrustProxies`) with nothing stable to name — that
   variable has to trust the edge's address on *this* network, and "whatever Docker picked" isn't
   a value you can write into a tenant's `.env` with confidence it'll be the same after a restart.

4. **Declare the network on both sides**, `external: true`:
   - Here: uncomment and fill in the example block at the bottom of `docker-compose.edge.yml`
     (`tenant-<slug>-edge:` under the top-level `networks:`), and add the same name under
     `services.edge-nginx.networks`.
   - The tenant stack's own compose file (task 4): identical `external: true` declaration, and the
     tenant's nginx service attached to it.
   - **Never `docker network connect` in a runbook.** A network attached by an ad-hoc command isn't
     recorded anywhere Compose looks, so the *next* `docker compose up`/`down` on either stack
     doesn't know it exists — it either leaves it dangling after a teardown or fails to recreate it
     after a legitimate recreation. Declaring it in both compose files is what makes the attachment
     survive routine operations instead of only surviving until someone forgets.

5. **Reload.**
   ```bash
   docker compose -f docker-compose.edge.yml up -d edge-nginx
   ```

### The contract task 4 satisfies on the tenant side

This PR did not touch task 4 — no tenant compose file existed to rebuild yet at the time it was
written — but the edge's per-tenant vhost (`tenants.d/_example.conf.disabled`) was written against a
specific contract, now implemented by [Tenant Compose Stack](tenant-compose-stack.md):

- The tenant's nginx container must be reachable at `tenant-<slug>-nginx:80` (plain HTTP, no TLS —
  the edge is the only TLS terminator) on the `tenant-<slug>-edge` network. Container names are
  unique host-wide already, so using the container name itself as the address needs no separate
  Docker network alias.
- **`X-Tenant` is task 4's header to set, not the edge's.** The edge actively clears any
  client-supplied `X-Tenant` before proxying (`proxy_set_header X-Tenant "";`) so a request can't
  arrive at the tenant stack already carrying a forged value; task 4's tenant-nginx must set the
  real one itself, from that container's own `TENANT_SLUG` environment variable, at the point it
  hands the request to PHP-FPM — never trusting anything already present on the request.
- The tenant's `.env` needs `TRUSTED_PROXIES_CIDR` set to the `tenant-<slug>-edge` network's subnet
  (step 3 above) once it sits behind this edge — `tenant-stack-provisioning.md` currently documents
  leaving it unset because no edge network existed yet. That note is now stale for any stack
  actually attached to this edge; it's still correct for a tenant stack that isn't.
- `X-Forwarded-Proto` arrives as `https` unconditionally (the edge is the only TLS terminator, so
  this is never the "sometimes http" case `TrustProxies` usually has to handle).

## error_page 502/503/504 — the per-tenant service page

Chosen by directory, not a shared template: every vhost's `root` for the `@tenant_down` named
location is hardcoded to `/etc/nginx/tenant-pages/<slug>`, populated once at attach time (step 2
above). `error_page 502 503 504 =502 @tenant_down;` — **the explicit `=502` matters**. Verified by
request against a running container: with a bare `= @tenant_down` (no status), nginx reports the
status of whatever the named location actually served — a successfully found static file is `200`,
which would make a real tenant outage invisible to curl, uptime monitors, and the browser's own
network tab, even though the page correctly tells a human the service is down. `=502` preserves the
outage status while still serving the friendly body. `proxy_intercept_errors on;` is required in the
same location block — without it, nginx passes back whatever raw error body the failed upstream hop
produced (or nothing, on connection-refused) and `error_page` never fires at all.

## The `proxy_pass` variable pattern

`tenants.d/_example.conf.disabled` sets `$upstream_tenant` and reads it via `resolver 127.0.0.11`,
never a literal `proxy_pass http://tenant-<slug>-nginx:80;`. This is the exact pattern already in
production at `docker/nginx/production/app.prod.conf:34-35`, for the same reason: nginx resolves a
**literal** hostname in `proxy_pass` once, when the config loads or reloads. If that one tenant's
container happens to be down at that instant, nginx refuses to load — not just that file, the whole
process, because every tenant's vhost is `include`d into the same running nginx. One stopped tenant
would take the entire edge, and every other tenant behind it, down with it. The variable form defers
resolution to request time, so a stopped tenant only 502s for its own visitors.

## `default_server` → 444, not 404

`edge-tls.conf`'s fallback `server{}` block (`server_name _`) returns 444 for anything that doesn't
match a per-tenant vhost. This inverts today's philosophy on purpose: `app.prod.conf`/
`app.prod-tls.conf` are deliberate catch-alls because the shared stack's app resolves the tenant
*after* nginx, from the Host header — copying that 1:1 to the edge would route an unrecognised Host
into a random attached tenant's vhost-matching logic (there is no "random tenant" here; it would
fall to whichever server block nginx picks as default, i.e. whichever `default_server` is loaded,
which on a copy-pasted catch-all could easily be an actual tenant's block if it happened to be first
in file order). 444 vs 404: a 404 is a real, cacheable, crawlable HTTP response that confirms a
working server is willing to discuss hostnames it doesn't recognise — scanners and Certificate
Transparency watchers use exactly that distinction to fingerprint ingress. 444 is nginx's own
non-standard code that closes the connection without sending a status line at all. Verified by
request: an HTTP/2 request for an unrecognised Host against a running edge container terminates with
`PROTOCOL_ERROR` — no response, not even headers.

## What was actually run

No server, no certbot, no live traffic. Everything below is `nginx -t` and real requests against
disposable `nginx:1.25-alpine` containers, throwaway self-signed certificates, and rendered copies of
the tracked templates:

1. `docker compose -f docker-compose.edge.yml config` — parses.
2. `docker compose -f docker-compose.prod.yml config` — still parses, unmodified, confirming the
   live single-stack path is untouched.
3. `nginx -t` against `edge.conf` alone (the HTTP-only bootstrap phase, no certificate mounted) —
   passes.
4. `nginx -t` against `edge-tls.conf` rendered with a throwaway `CERT_DOMAIN` and a throwaway
   self-signed certificate at that path, `tenants.d/` **empty** (the zero-tenants-attached state
   this ships in) — passes. Confirms `include /etc/nginx/tenants.d/*.conf;` is not an error when
   nothing matches the glob.
5. Same, with `_example.conf.disabled` rendered into a real `acme.conf` (`SLUG` → `acme`,
   `CERT_DOMAIN` → the same throwaway value) and dropped into `tenants.d/` — passes, including with
   `$upstream_tenant` pointing at a hostname (`tenant-acme-nginx`) that does not exist anywhere.
   This is the concrete demonstration that the variable+resolver pattern doesn't make `nginx -t`
   (or a real start) depend on the tenant actually being up — a literal `proxy_pass` would have
   failed step 5 outright, which is the whole point of using a variable at all.
6. A real container was started from step 5's config and queried with real HTTP/2 requests:
   - Unrecognised `Host: nobody.example.com` over HTTPS → `PROTOCOL_ERROR`, no response (444).
   - Recognised `Host: acme.registrolabs.com` with the upstream unreachable → first attempt returned
     a bare `200` with a `0`-byte body (the bug described above, from `= @tenant_down` with no
     explicit status and an accidentally-empty test fixture file); fixed to `=502 @tenant_down` and
     re-run against the real (non-empty) `_default/503.html` → `502` with the actual service-page
     body, confirmed by content length and `<title>`.
7. `./vendor/bin/pint --test && php artisan test` inside Docker — no PHP was touched by this task;
   run anyway to confirm no regression. Baseline unchanged.

**What was not validated, and why:** actual TLS termination against real client browsers, actual
proxying to a real tenant nginx container (none exists — that's task 4), actual firewall/`ufw-docker`
interaction on a real box, actual certificate rendering via `sed` against a real `CERT_DIR` value
(exercised with a throwaway string instead), and anything involving the real
`registrolabs.com`/`registroapps.com` DNS (not yet pointed at anything).

## `scripts/server/sync-certificate.sh` — reload target

Was hardcoded to `docker exec registro-nginx`. Now reads `NGINX_RELOAD_CONTAINER` from `.env`,
defaulting to `registro-nginx` when unset — **today's live single-stack deployment needs zero `.env`
changes and behaves identically.** Once the edge is actually the thing holding the ACME webroot and
terminating TLS, this key becomes:

```
NGINX_RELOAD_CONTAINER=registro-edge-nginx
```

**Written by `apply`'s own edge-sync step (task 6, stack-per-tenant epic, Faza 2 of the two-machines
plan) — not a manual step anymore.** The value is read from `docker-compose.edge.yml`'s own
`container_name`, never hardcoded a second time. It is written CONDITIONALLY, gated on the running
edge-nginx container's actual bind mount (`docker inspect` on its
`/etc/nginx/conf.d/default.conf` mount source), not on the `EDGE_NGINX_CONF` env var alone -- the
documented manual cutover below (`EDGE_NGINX_CONF=edge-tls.local.conf docker compose ... up -d`)
sets that var only for the one command performing the cutover, not persisted into `.env` anywhere a
later `apply` run could read it back from, so gating on it directly would silently never fire again
after that one command. `apply`'s edge-sync step runs on EVERY apply, cutover or not -- writing the
var unconditionally there would have been exactly as wrong as never writing it: the edge answers
plain HTTP only (`EDGE_NGINX_CONF`'s own default, `edge.conf`) until the cutover below has actually
happened, and pointing the reload at it before that would mean certbot renews correctly while
NEITHER container actually gets reloaded with the new certificate.

**Known gap, fixed:** the *hostname source* used to query only the legacy shared stack's `app`
container via `docker-compose.prod.yml` (`DESIRED=...tenants:hostnames`), which was correct as long
as every tenant lived in that one shared database. Once tenants started moving to dedicated
per-tenant stacks (task 4), their hostnames live in *their own* databases and were never seen — worse,
because `certbot --expand` reissues against exactly the list computed, any SAN an operator added for
a dedicated tenant by hand was silently stripped on the next 15-minute cron run. `sync-certificate.sh`
now also enumerates every stack under `STACKS_ROOT` (`/opt/stacks`, overridable via
`REGISTRO_STACKS_ROOT`) and unions their names in. The source per dedicated stack is `TENANT_HOSTS`,
not another `tenants:hostnames` call — that command's own "baseDomain + org-slug.baseDomain" logic is
written for the legacy shared-tenant architecture and would emit a broken double-subdomain name
(`acme.acme.registrolabs.com`) if pointed at a dedicated stack, since `apply.sh` already sets that
stack's `APP_DOMAIN`/org slug to the tenant's own primary host. `TENANT_HOSTS` is already the exact
allowlist `ResolveTenant`/`TrustedTenantHosts` enforce for that container, so it needs no
recomputation.

**Two-machines plan, Faza 2 (2026-08-10): both the legacy source AND the dedicated-stack source
changed shape again**, independently of the fix above:

- **Legacy source:** originally an unconditional `tenants:hostnames` call that `die()`d if it
  returned nothing. That was correct as long as every machine ran the legacy stack, but a fresh
  PreProd box (two-machines plan, checkout present, legacy containers never started) legitimately
  contributes ZERO legacy names — not an error. Now gated on `docker compose ps -q app`: empty means
  "nothing running here" (checkout absent, container stopped, never started — all legitimate, zero
  names, continue), non-empty but the subsequent `tenants:hostnames` call still empty/failing means
  "running but broken" (still a hard abort, unchanged).
- **Dedicated-stack source:** originally read live from the container's own environment via `docker
  compose exec`, which doubled as the "is this stack reachable" probe the fail-safe needed. Correct
  for "one always-on stack per client"; wrong once UAT started hosting prospect projects that get
  created, torn down, and left stopped between sessions — "directory present, container down" became
  a NORMAL state there, and the fail-safe (rightly) treated it as indistinguishable from "broken",
  freezing renewal for every OTHER tenant over one sleeping stack. Now read from the stack's own
  `.env` ON DISK — the identical value `apply.sh` already wrote there, readable whether or not the
  container happens to be running.

Fail-safe by design, for both sources: a stack directory with no compose file is skipped quietly (not
provisioned yet); a stack that exists but whose `.env` cannot be read (missing, unreadable, no
`TENANT_HOSTS` key) aborts the **entire** run before anything touches certbot, leaving the live
certificate exactly as it was; a `DESIRED` list that ends up empty after ALL sources (legacy,
dedicated stacks, `www`) also aborts rather than asking certbot for zero names. See the script's own
comments for the full reasoning and this task's validation notes for the seven scenarios actually
exercised in a sandbox (no `/opt/stacks`; dotdir + junk directory both skipped; no legacy stack, only
dedicated stacks, certbot called with the right list; legacy running but its query failing, certbot
never called; a dedicated stack's container stopped but `.env` present, hosts still included; a
dedicated stack's `.env` missing, certbot never called; `NGINX_RELOAD_CONTAINER` written idempotently
across three `apply` runs).

## Cutover sequencing (documented, not performed)

Moving the live server from `docker-compose.prod.yml`'s nginx to the edge is a **later, separate**
operation. Both `nginx` (legacy) and `edge-nginx` bind `0.0.0.0:80`/`443` unconditionally, so the
legacy service must release the ports before the edge can bind them — there is no overlap window
where both hold them at once; Docker refuses to bind an already-held host port. This is the only
section of this document that touches a currently-serving site, so it is written as a literal,
numbered sequence with the failure branch at every step, not as prose to paraphrase.

**Precondition:** `edge-tls.local.conf` has already been rendered per "Bring-up order" step 2 above,
pointing at the **same** certificate directory (`CERT_DIR` in `.env`) the live legacy `nginx` service
already uses. `docker-compose.edge.yml` is not running yet.

1. **Pre-flight: validate the edge's rendered config without binding any port**, while the legacy
   nginx is still serving traffic normally:
   ```bash
   EDGE_NGINX_CONF=edge-tls.local.conf docker compose -f docker-compose.edge.yml \
       run --rm --entrypoint sh edge-nginx -c "nginx -t"
   ```
   **Verified, not assumed:** `docker compose run` (no `--service-ports`) never publishes the
   service's `ports:` mapping. Run against this exact compose file with a throwaway cert and a
   rendered `edge-tls.local.conf`, `docker inspect` on the resulting container showed
   `NetworkSettings.Ports` **and** `HostConfig.PortBindings` both `{}` — nothing bound to the host at
   all — and the command printed:
   ```
   nginx: the configuration file /etc/nginx/nginx.conf syntax is ok
   nginx: configuration file /etc/nginx/nginx.conf test is successful
   ```
   exiting `0`. Safe to run at any time, including seconds before step 2, with zero risk to the live
   site — it only creates and immediately discards (`--rm`) a container that never listens on a host
   port.
   - **On failure** (anything other than that exact "test is successful" line): **STOP.** Do not
     proceed to step 2. Fix the rendered config or the certificate path and re-run this step. The
     legacy nginx has not been touched; the site is unaffected.

2. **Stop the legacy nginx's port binding:**
   ```bash
   docker compose -f docker-compose.prod.yml stop nginx
   ```
   The site goes down at this exact moment. There is no partial state to inspect here — either this
   command has run or it hasn't.

3. **Start the edge:**
   ```bash
   EDGE_NGINX_CONF=edge-tls.local.conf docker compose -f docker-compose.edge.yml up -d edge-nginx
   ```
   - **On failure** (container exits, keeps restarting, or `docker compose -f docker-compose.edge.yml
     ps` doesn't report `healthy` within its three 10s healthcheck retries): go straight to step 4.
     Do not debug with the site down — step 1's pre-flight command reproduces the identical config
     check with zero downtime; debug against that after rolling back.

4. **Rollback** (only if step 3 failed, or if the edge came up healthy but is serving something
   wrong):
   ```bash
   docker compose -f docker-compose.edge.yml stop edge-nginx
   docker compose -f docker-compose.prod.yml up -d nginx
   ```
   `up -d nginx`, not `start nginx` — idempotent regardless of whether step 2 left the container
   stopped-but-present or something later removed it. This is the same command
   `docker/nginx/production/app.prod-tls.conf`'s own header already documents for reverting a TLS
   config change on the legacy stack; not invented for this doc.

5. **Verify**, after step 3 succeeds or step 4's rollback completes:
   ```bash
   curl -sSf https://<APP_DOMAIN>/up
   ```
   Anything other than a `2xx` here means the site is not actually back — keep iterating between
   steps 3 and 4 rather than leaving it in a half-cut-over state.

The firewall itself needs no new rule for any of this: `setup-production-server.sh` opens `80`/`443`
by **port**, not by container (`ufw route allow proto tcp from any to any port 80/443` — see
`production-readiness-checklist.md`'s note on why *not* `ufw-docker allow registro-nginx 80`), so
whichever container is actually bound to those ports at any point in the sequence above is already
reachable; there's nothing to reconfigure at the firewall layer during the cutover.

## A legacy-free machine (two-machines plan, Faza 4) does not cut over — it bootstraps

Everything above ("Cutover sequencing") describes **displacing** an already-running legacy `nginx`
that currently holds 80/443. A machine that has never run `docker-compose.prod.yml` at all (the
two-machines plan's PreProd) has nothing to displace: `registro-edge-nginx` is the *only* nginx that
will ever hold those ports there, from the very first `docker compose -f docker-compose.edge.yml up
-d` onward. Two consequences that do not apply to the cutover case above:

- **No step 2 ("stop the legacy nginx"), and no user-facing outage window at any point** — nothing
  was serving this machine's domain before the edge existed, so there is nothing to lose by getting
  a step wrong. Step 1's pre-flight and step 4's rollback both still apply (rendered config can still
  be wrong), just without the time pressure a live cutover carries.
- **`sync-certificate.sh`'s `NGINX_RELOAD_CONTAINER` must be pre-seeded to `registro-edge-nginx` in
  `.env` BEFORE the first certificate request, not left for `apply.sh`'s edge-sync step to discover
  later.** That step only writes the var once it can see the edge's *actual* bind mount already
  pointing at `edge-tls.local.conf` (see "reload target" above) — correct for the cutover case, where
  writing it prematurely would target the edge before the legacy nginx has actually released TLS. On
  a legacy-free machine that guard never has anything to guard against (there is no pre-TLS legacy
  nginx to protect), but the script's own default (`registro-nginx`) is still read unconditionally
  and is simply wrong here — that container has never existed on this machine. Left unset, the
  **first ever** certificate request on this machine succeeds (files land under
  `/etc/letsencrypt/live/`) and then `die()`s on the reload step immediately after, which reads as a
  total failure when the part that matters — the certificate — actually worked. Full operator
  sequence: `instalacja-tenanta-od-zera.md`, Część 10.

## Files

| File | Purpose |
|------|---------|
| `docker-compose.edge.yml` | The edge stack; zero tenant networks declared until the first attach |
| `docker/nginx/edge/edge.conf` | HTTP-only bootstrap (no certificate required) |
| `docker/nginx/edge/edge-tls.conf` | Tracked TLS template, `CERT_DOMAIN` placeholder |
| `docker/nginx/edge/edge-tls.local.conf` | Rendered, gitignored, server-local |
| `docker/nginx/edge/tenants.d/_example.conf.disabled` | Tracked per-tenant vhost template/doc |
| `docker/nginx/edge/tenants.d/<slug>.conf` | Real per-tenant vhosts, gitignored |
| `docker/nginx/edge/tenant-pages/_default/503.html` | Generic service-unavailable page, tracked |
| `docker/nginx/edge/tenant-pages/<slug>/` | Per-tenant copy, gitignored, seeded from `_default/` |
| `scripts/server/sync-certificate.sh` | `NGINX_RELOAD_CONTAINER` addition |

## Known gaps / explicitly out of scope

- No wildcard issuance — see "Certificate source" above. Blocked on a nameserver decision that
  belongs to the domain owner.
- **Per-tenant attachment automation now exists** — `apply` (task 6, see [Tenant Apply](tenant-apply.md)).
  "How a tenant is attached" above remains the manual procedure and the reference for what `apply`'s
  edge-sync step actually does; it is no longer the only way to perform it, and its step 4 is
  specifically superseded by `apply`'s generated-override-file approach (see that document's
  correction).
- `sync-certificate.sh`'s hostname source now enumerates dedicated stacks too (see the "Known gap,
  fixed" callout above it) — done, not a gap anymore.
- No cutover has been performed or scheduled. The live server is untouched.
- Task 4 landed — see [Tenant Compose Stack](tenant-compose-stack.md). The contract this edge assumes
  (`tenant-<slug>-nginx:80`, `X-Tenant`, `TRUSTED_PROXIES_CIDR`) is implemented and verified there
  against a real local stack, but still unattached to this edge or to anything live.
- **`scripts/deploy-init.sh:299-305`'s hardcoded-name trap is fixed, its post-cutover trap is not.**
  Task 4 generalized the `registro-nginx` literal to read `TENANT_PREFIX` from `.env` (unset still
  resolves to `registro-nginx`, unchanged) — but that only fixes the check for a *tenant-prefixed*
  stack, and does nothing for the scenario this bullet originally described: on the **legacy** stack
  specifically (`TENANT_PREFIX` still unset), after the cutover sequence above, port 80 is held by
  `registro-edge-nginx`, not `registro-nginx` — the check still reports "not running" (true, but
  misleading) and the subsequent `docker run -p 80:80` still collides with the edge and fails. Blast
  radius is still low (same reasoning as before: first-time bootstrap only, not routine renewal or
  routine deploys). Still worth a fix (teaching the check about `registro-edge-nginx` too, or retiring
  the temp-nginx branch once the edge is the only thing that's ever first to exist on a fresh box)
  whenever a legacy-to-edge cutover is actually being planned.
