---
name: project-security-dependency-updates-2026-08-16
description: PR #200 remediated 35 composer vulnerabilities (11 packages) to 0, no composer.json changes — verification method for dompdf/guzzle/commonmark reusable for future dependency updates
metadata:
  type: project
---

`composer audit --locked` went 35 -> 0 (PR #200, branch `feature/security-dependency-updates`,
merges into `develop`). Two targeted `composer update <pkgs> --with-dependencies` batches
(guzzle/psr7/dompdf first, then laravel/framework + commonmark + symfony/* + psy/psysh) — no
`composer.json` constraint touched, everything fit `^` ranges already there.

**Why:** first real remediation after `.claude/rules/ci-cd-troubleshooting.md`'s 2026-08-16 entry
put `composer audit --locked` into CI (report-only) and measured the 35/11 baseline. That entry
explicitly flagged `laravel/framework`'s signed-URL CVE as "worth checking separately" — this PR
closes that out (landed on v12.66.0, not the v12.61.1 minimum, since `composer update` without a
package pin pulls newest-matching-`^12.60`, not lowest-fixing).

**How to apply — verification pattern for future dependency PRs, not just this one:**
- **dompdf risk (real PDF generation, not test-passing):** temporarily `git stash` push on
  `composer.lock` only + `composer install` reverts vendor to the pre-update lock without touching
  `composer.json` or losing the update diff — generate PDF, `git stash pop` + `composer install`
  restores forward. Compare via `pdftotext` diff, not raw PDF bytes (dompdf's own encoding varies
  run-to-run even with identical input). A one-off Feature test with `RefreshDatabase` writing to
  `storage/app/tmp-<name>/` (bind-mounted `.:/var/www`, visible on host) is the safe way to get
  real PDF bytes without touching dev-MySQL or `tinker --execute` — delete the test file before
  the final commit, it's throwaway.
- **guzzle risk:** grep for direct `Http::`/`GuzzleHttp\Client` usage in `app/` — if the only
  consumer is a fixed-host payment/SMS SDK with no custom redirect/cookie handling, cookie-scope/
  Referer-leak/host-canonicalization CVEs (the kind guzzle patches most often) don't apply; those
  need an *untrusted* redirect target to matter.
- **commonmark/html-sanitizer risk:** check whether the app's own escaping path (here,
  `EmailTemplate::render()`'s `preg_replace_callback` + `e()`/`strip_tags()`, see
  [[project_email_template_escaping]] in the main project memory) actually routes through the
  updated package at all — it didn't here, despite both packages sitting in the dependency tree
  near tenant-editable content.
- Full Pint + test suite once, at the end, not per-package — this is a private machine
  ([[feedback_no_machine_thrashing]] in main project memory), and the packages here have no
  targeted regression surface that would benefit from testing in between.

Result: 1331 passed / 5 skipped / 0 failed, unchanged skip set from before the update.
