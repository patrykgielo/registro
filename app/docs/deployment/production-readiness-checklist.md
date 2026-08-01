# Production Readiness Checklist — First Deploy to `srv1342834.hstgr.cloud`

**Status**: Report/checklist only. Nothing in this document has been executed — items are
flagged for manual fix before (or shortly after) the first real deploy. See
`~/.claude/plans/no-tak-z-innej-scalable-meadow.md` for the full analysis behind this list.

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

## 1. Blocking infra bugs — fix before running any deploy script

- [ ] **nginx config path mismatch.** `docker-compose.prod.yml` and `docker-compose.staging.yml`
  mount `./docker/nginx/app.{prod,staging}.conf`; the real files are at
  `docker/nginx/{production,staging}/app.{prod,staging}.conf`. Same wrong path is baked into
  `scripts/deploy-init.sh` (line 222) and the `curl` steps in
  `.github/workflows/deploy-production.yml` / `ci-staging.yml`. As committed, any of these will
  either fail to mount a config or write a 404 page into nginx's conf.
- [ ] **`scripts/deploy-with-healthcheck.sh` references undefined `$VERSION`** under `set -euo
  pipefail` — running it with no arguments aborts immediately ("unbound variable") unless the
  caller exports `VERSION` first, which isn't documented anywhere in the script's own usage text.
- [ ] **`scripts/deploy-init.sh` seeds `ServiceAvailabilitySeeder`**, which does not exist in
  `database/seeders/` (only `ServiceAreaSeeder` and `ServiceSeeder` do) — this step will fail.
- [ ] **Inconsistent hardcoded project paths across scripts.** `deploy.sh` and
  `deploy-with-healthcheck.sh` assume `/var/www/registro`; `deploy-init.sh`/`deploy-update.sh`
  self-derive from the script's own location. Pick one convention and align all four before first
  use — decide what the real path on the new VPS will be first.
- [ ] **`.env.production.example` and `.env.local.example` are not git-tracked** (the `.gitignore`
  pattern for `.env*` has no carve-out for them, unlike `.env.example`/`.env.staging.example`) —
  a fresh `git clone` on the new VPS won't have the file `deploy-init.sh` depends on. Either
  track them or have `deploy-init.sh` generate its own template.
- [ ] **`scripts/setup-staging-server.sh` step 7 runs `php artisan db:seed --force`** — this
  directly violates the project's own rule (`.claude/rules/deployment.md`: "NIGDY: `db:seed` w
  deploy scripts — nadpisuje dane admina"). Remove or gate it before reusing this script as a
  template for a production bootstrap script.
- [ ] **Image tag is hardcoded to `:latest`, which makes rollback impossible without editing files
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
- [ ] **No `setup-production-server.sh` exists.** Only staging has a from-scratch VPS bootstrap
  script (Docker install, `ufw`/`ufw-docker`, user creation, certbot). Adapt it for production —
  fixing the `db:seed` issue above in the process — before using it on `srv1342834.hstgr.cloud`.

## 2. Network / firewall

- [ ] Install **`ufw-docker`**, not just plain `ufw` — Docker writes its own `DOCKER-USER` chain
  in iptables and silently bypasses bare `ufw allow`/`deny` rules for published container ports.
  See `app/docs/decisions/ADR-007-ufw-docker-security.md`.
- [ ] Open only 22 (SSH), 80/443 (nginx). Confirm after bring-up that mysql/redis are **not**
  reachable from the host at all — `docker-compose.prod.yml` already publishes no ports for
  them (correct design), just verify with `docker compose -f docker-compose.prod.yml config` and
  `docker ps` on the live VPS rather than trusting the file alone.
- [ ] This VPS is now dedicated solely to Registro — the port-80/443-conflict risk that existed
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
- [ ] **Deferred, not blocking:** `P24_MERCHANT_ID`, `P24_REPORTS_KEY`, `P24_CRC`, `P24_LIVE`,
  `P24_POS_ID` — read by `config/przelewy24.php` but absent from every `.env*.example` and from
  `docker-compose.prod.yml`'s `environment:` blocks. Add these when online payments are actually
  needed; the app can launch without them.

## 4. Security follow-ups before going internet-facing

- [ ] `CheckRegistrationEnabled` middleware still resolves tenant via the poisonable
  `TenantFeature::currentTenant()` session-fallback pattern — the same bug class already fixed 6×
  elsewhere as part of VULN-003. Lower severity (no PII leak) but should be closed before
  `/customer/register` is reachable from the internet.
- [ ] `app/docs/security/baseline.md` is stale (dated 2025-12-10, predates the entire
  VULN-003…009 remediation round from July 2026). Regenerate it before treating it as a go-live
  sign-off artifact.
- [ ] VULN-002 (no audit logging for booking events) — open, low severity, non-blocking, but
  worth closing before this becomes a live multi-tenant system with real customer data.
- [ ] **Doc tree duplication:** root `docs/security/` is an unfilled template
  (`docs/security/baseline.md` literally says "Template (not yet scanned)"); the real, current
  security posture lives in `app/docs/security/`. Consolidate or archive the root duplicate so
  nobody mistakes the placeholder for the actual state.

## 5. CI/CD

- [ ] `deploy-production.yml` and `ci-staging.yml` assume the target path already exists on the
  server with a populated `.env` — they're **update** workflows, not fresh-bootstrap workflows.
  The very first deploy has to go through a (fixed) `deploy-init.sh` or an equivalent manual
  setup before either workflow does anything useful.
- [ ] GitHub Secrets `VPS_HOST`/`VPS_USER`/`VPS_SSH_KEY` (production) and `STAGING_VPS_*`
  (staging) almost certainly still point at the decommissioned predecessor hosts — update them to
  `76.13.76.104` before the first workflow run. Workflows remain `workflow_dispatch`-only by
  design; don't switch them to auto-trigger without a separate, explicit decision.
- [ ] `.claude/agents/devops-engineer.md` describes a `meilisearch` service that doesn't exist in
  any current `docker-compose*.yml` — doc drift, refresh the agent description.
- [ ] `README.md`'s deployment section links to `docs/deployment/runbooks/ci-cd-deployment.md`,
  which has moved to `docs/archive/deployment/runbooks/ci-cd-deployment.md` — fix the link.

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
