---
name: project-ci-security-scanning-2026-08-16
description: composer audit + Trivy added as steps (not jobs) to test/build, report-only; measured real counts on this repo 2026-08-16
metadata:
  type: project
---

`composer audit --locked` (in `test` job of `test.yml` + `deploy-production.yml`) and a Trivy
OS-only image scan (in `deploy-production.yml`'s `build` job, after push) shipped 2026-08-16 —
first security scanning this repo has ever had. Both **steps in existing jobs**, not new jobs
(GitHub bills every job a rounded-up minute; a dedicated `preflight`-style job would tax every
dispatch — the exact mistake made and reverted the same day for `skip_tests`, see
`.claude/rules/ci-cd-troubleshooting.md` 2026-08-16). Both **report-only** — findings go to
`$GITHUB_STEP_SUMMARY`, neither fails the pipeline yet.

**Why: measured, not assumed, real numbers on this repo, 2026-08-16** (will drift daily — CVE DBs
update constantly, do not treat as a stable baseline):
- `composer audit --locked`: 35 advisories / 11 packages (5 high, 24 medium, 6 low). Without
  `--no-dev`: 32/10 — delta is exactly 3 low-severity `symfony/yaml` advisories.
- Trivy, OS layer only, built image (`OPCACHE_MODE=production`, Debian 13.6/trixie base from
  `php:8.3-fpm`): **2275 findings** (730 low, 729 medium, 701 UNKNOWN/unscored, 122 high, 19
  critical). All 19 critical have **no upstream fix** — really only 6 distinct CVEs
  (perl-family × 4 packages, libxml2 × 2, linux-libc-dev × 1). This is why `exit-code: '0'`
  (Trivy) stays `'0'` today: blocking on raw severity would deadlock every release forever, not
  catch a regression.

**Key technical facts, verified by reading Composer's own source + running things, not assumed:**
- `composer audit --locked` reads `composer.lock` directly (`Auditor::getPackages()` calls
  `$composer->getLocker()->getLockedRepository()`), decoupled from whether `composer install`
  in that step ever gains `--no-dev`. Confirmed empirically too: ran with zero `vendor/` present,
  identical result.
- `composer audit` returns ONLY 0 or 1 — same exit code for "found advisories" and "could not
  audit at all" (e.g. a project with zero installable packages prints empty stdout + exit 1). The
  CI step distinguishes these by checking whether stdout is valid JSON (`jq -e .`), not by exit
  code — fail-safe, not fail-shrink.
- Trivy's `vuln-type` deliberately set to `os` only, NOT the default `os,library`: verified that
  Trivy's own `composer-vendor` scanner on this exact built image reports the IDENTICAL 10
  packages as `composer audit --locked --no-dev` — scanning both would duplicate or (worse)
  someday disagree on the same finding with no way to know which number is right. npm
  devDependencies never reach the final image (only `public/build` is copied) — confirmed zero
  JS findings in the same scan.
- `aquasecurity/trivy-action@ed142fd0673e97e23eac54620cfb913e5ce36c25` (v0.36.0) installs the
  trivy CLI on the runner via `aquasecurity/setup-trivy`, not a nested container — pulls
  `image-ref` directly from the registry. Relies on the job's earlier `docker/login-action` GHCR
  creds carrying over via Docker's standard credential store; **not verified end to end**
  (dispatching forbidden this session).

Full write-up + how to flip either check to blocking (one value each, but Trivy needs
`ignore-unfixed: true` + a narrower `severity` first or it'll block on the 19 unfixable
criticals): `.claude/rules/ci-cd-troubleshooting.md`, 2026-08-16 entry. Branch:
`feature/ci-security-scanning` from `develop`.

See also [[feedback_verify_dont_assume_composer_audit_semantics]].
