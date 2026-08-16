# Release Process Guide

**Rewritten 2026-08-16, corrected same day** — everything below the original version of this
document described a push-to-tag / auto-deploy-staging model that predates the "Disabled for
Registro migration" comment now sitting at the top of every workflow file. That model has not
matched reality for a long time: **all workflows are `workflow_dispatch` only.** Nothing deploys
automatically from a push, a PR merge, or a tag push. `git push origin v4.2.0` today does nothing
but push a tag.

**Branch model (adopted 2026-08-16):** `feature/* → develop (PR) → staging (PR) → main (PR)`.
`develop` is the repo's **default branch** (GitHub only registers a new workflow file dispatched
from the default branch — a workflow added on any other branch 404s on `gh workflow run` until it
lands on default). `staging` is where `rc*` tags get cut. `main` is where production tags get cut.
Full rules: `.claude/rules/git-workflow.md`.

**This does not add a staging server.** UAT (`registrolabs.com`, live, one tenant) is still the
only real machine, and it is what `staging`'s `rc*` tags deploy to via `deploy-production.yml` —
there is no separate staging VPS to point at. PreProd (`registroapps.com`), the machine `main`'s
production tags are meant for, has not been bought; until it is, tags cut from `main` have nowhere
to deploy. `ci-staging.yml`, a DIFFERENT and now-deleted thing (an auto-deploy workflow that
targeted a staging VPS which never existed), was removed 2026-08-16 — zero runs in its entire
history, its `STAGING_VPS_*` secrets never existed. Do not confuse it with the `staging` git branch
introduced the same day, which is a promotion tier, not a workflow. See
`.claude/rules/ci-cd-troubleshooting.md`.

## Current Workflow

### 1. Feature Development
```bash
git checkout -b feature/my-feature develop
# ... develop feature ...
git push -u origin feature/my-feature
gh pr create --base develop --title "feat: ..."
```
`test.yml` (Run Tests) is `workflow_dispatch` only — it does **not** run automatically on push or
PR. Dispatch it manually if you want a clean-room check of `develop` before tagging.

Promote `develop` to `staging` the same way, once develop is ready to cut a release candidate:
`gh pr create --base staging --title "chore: promote develop to staging"` (from `develop`).

### 2. Tag a Release
```bash
git checkout staging   # rc* tags; use main for a production tag once PreProd exists
git pull
git tag -a v4.2.0-rc.1 -m "Release candidate v4.2.0-rc.1 - Feature Description"
git push origin v4.2.0-rc.1
```
Pushing the tag triggers **nothing**. It only makes `v4.2.0-rc.1` resolvable for the next step.

**Documentation requirement differs by tag shape** (`.claude/rules/release-documentation.md`): an
`rc*` tag from `staging` only needs a substantive `-m` message on the annotated tag itself — no
separate file. A production tag (`vX.Y.Z`, no `-rc` suffix) cut from `main` after a `staging → main`
promotion REQUIRES `docs/releases/vX.Y.Z.md` written before the tag is pushed.

### 3. Dispatch the Deploy
```bash
gh workflow run deploy-production.yml -f version=v4.2.0-rc.1
```
This is the **only** thing that builds, tests, and deploys. It runs three-to-four jobs in order:

**One target today, regardless of branch.** `deploy-production.yml`'s `deploy` job SSHes to exactly
one machine, named by the fixed secrets `VPS_HOST`/`VPS_USER`/`VPS_SSH_KEY` — it has no branch- or
tag-shape-based logic to pick between UAT and PreProd. Dispatching this workflow with an `rc*` tag
cut from `staging` or a `vX.Y.Z` tag cut from `main` deploys to the exact same place (UAT) today,
because `VPS_HOST` only has UAT's address configured. The `staging → UAT` / `main → PreProd` split
in `.claude/rules/git-workflow.md` describes the *intended* target once PreProd is bought and this
workflow (or a second one) is wired to it — it is not yet enforced by any code in this file.

| Job | What | Skippable |
|-----|------|-----------|
| `test` (PHPUnit) | Full Feature suite against `mysql:8.0`+`redis:7.2-alpine`, plus `composer audit --locked` (report-only) | Only via `skip_tests` (below) — skips PHPUnit and the audit together, they're one job |
| `build` | Builds the image with `docker/build-push-action`, pushes `:VERSION` and `:latest` to GHCR, then scans it for OS-package vulnerabilities with Trivy (report-only) | No |
| `deploy` | SSHes to the VPS, runs `deploy.sh`, health-checks `/up` | No |

### Security scanning (added 2026-08-16, report-only)

`composer audit --locked` (in `test`) and a Trivy OS-layer scan of the built image (in `build`)
are both **steps inside these existing jobs**, not new jobs — same per-job-billing reasoning as
`skip_tests` above. Neither fails the pipeline today; both write their findings to the run's job
summary (severity counts, full detail for `composer audit`, `CRITICAL`-only detail plus a
downloadable `trivy-results` artifact for Trivy, since the OS layer alone had ~2300 findings when
last measured). Trivy intentionally scans OS packages only — PHP dependency vulnerabilities are
already covered by `composer audit` against the same `composer.lock`, and scanning both would
either duplicate or, worse, silently disagree with the same finding. See the 2026-08-16 entry in
`.claude/rules/ci-cd-troubleshooting.md` for the measured counts on this repo, why report-only is
deliberate (not a placeholder), and the exact one-line change to flip either check to blocking.

No separate validation job for `skip_tests` — GitHub Actions bills every job a minimum of one full
minute regardless of actual runtime, and a job that only compares two strings would tax that minute
onto *every* dispatch, including the overwhelming `skip_tests=false` majority, to serve the rare
skip case. The comparison lives directly in `test`'s own `if:` instead.

Each job requires the previous one to have **succeeded or been deliberately skipped** — a job that
*failed* or was *cancelled* stops the chain; nothing after it runs.

### `skip_tests` — re-deploying an already-tested tag

Added 2026-08-16. Re-running the full pipeline against a tag that already passed it once (e.g. a
deploy step failed for an infra reason and you're retrying the exact same, unchanged commit) used
to pay for the test job a second time for an identical result.

```bash
gh workflow run deploy-production.yml \
  -f version=v4.2.0 \
  -f skip_tests=true \
  -f skip_tests_confirm=v4.2.0
```

Both `skip_tests=true` **and** `skip_tests_confirm` matching `version` **exactly** are required —
this is deliberate, not an accident-tolerant boolean:
- `skip_tests=false` (the default): tests always run. Nothing about `skip_tests_confirm` matters.
- `skip_tests=true` + `skip_tests_confirm` **matching** `version`: tests are skipped.
- `skip_tests=true` + `skip_tests_confirm` **not matching** (wrong tag, empty, typo, a value left
  over from a previous dispatch): **fail-safe, not fail-loud** — the tests just run anyway, exactly
  as if `skip_tests=false` had been set. The deploy is not aborted over a typo in a field whose only
  job is opting into something optional.

Either way, `build`'s "Report skip_tests outcome" step prints a `::warning::` whenever
`skip_tests=true` was requested at all — one message if the skip actually took effect, a different
one if it didn't (confirm mismatch) and the tests ran instead. Check that step's log if you're
unsure whether a deploy was tested. This lives in `build` (which always runs once `test` has
succeeded or been skipped) rather than a dedicated validation job, for the same per-job billing
reason noted above.

**Only use this for a tag that has already passed `test` in this exact pipeline once.** It does not
check that for you — no automated "has this tag passed before" lookup was built (considered and
rejected: matching a tag to a prior successful run by tag name is fragile if the tag is ever
re-pointed at a different commit, and an automated green-light is exactly the kind of "looks safe by
itself" mechanism this flag is designed to avoid — see `.claude/rules/ci-cd-troubleshooting.md`).

### Docker layer cache

`build`'s "Build and push image" step now caches Docker layers via
`cache-from/cache-to: type=gha` (GitHub Actions cache, scope `registro-image`, `mode=max` so the
frontend-builder stage's `npm ci`/`npm run build` layers are cached too, not just the final image).
Chosen over a GHCR registry cache (`type=registry`) because this pipeline only ever builds from a
git **tag** via `workflow_dispatch`, never a branch push, and GitHub's cache access rule ("current
ref, base ref, default branch") is unverified for whether a tag ref gets its own permanently
isolated cache scope — `type=registry` has no such ambiguity but costs GHCR storage that would need
its own cleanup. `type=gha` costs nothing to get wrong: worst case is zero cache hit (same build
time as before), no registry storage, nothing to clean up. `cleanup-cache.yml` targets a
`buildcache-*` GHCR tag scheme unrelated to this cache and untouched by this change — it predates
this decision and has never had anything to clean (verified: zero `buildcache-*` package versions
exist).

**What to check after your first two real dispatches** (this could not be verified without a live
run): dispatch two different version tags back to back, and look at the `build` job's "Build and
push image" step log on the *second* run. Steps that hit the cache are annotated `CACHED` in
BuildKit's output. If the apt-get/`docker-php-ext-install`/`composer install`/`npm ci` layers show
`CACHED` on the second run, cross-tag cache reuse works as designed. If none do, GitHub's ref-ACL is
scoping the cache per-tag and it is providing zero benefit for this pipeline shape — the fallback is
`type=registry` with a single reused tag (e.g. `buildcache-latest`) and `provenance: false` to keep
the untagged-manifest surface minimal.

## Rollback

If a deploy fails after `build` succeeded (i.e. after the image was pushed), re-dispatch with the
previous known-good version:
```bash
gh workflow run deploy-production.yml -f version=v4.1.0
```
`deploy.sh` on the server is the actual rollback mechanism — see
`app/docs/deployment/instalacja-tenanta-od-zera.md`. There is no tag-push-triggers-rollback
mechanism; nothing in this repo triggers off tag pushes at all.

## Best Practices

1. Dispatch `test.yml` on `develop` before tagging, if you want a signal before committing to a tag.
2. Use semantic versioning for tags (`v4.2.0`, `v4.2.1`, `v4.3.0`) — `deploy-production.yml`'s
   "Resolve version" step rejects anything not shaped `vMAJOR.MINOR.PATCH[-suffix]`.
3. Never force-push tags — cut a new patch version instead.
4. Treat `skip_tests` as an escape hatch for a known-tested tag, not a way to go faster on a tag
   you haven't run through this pipeline yet.
