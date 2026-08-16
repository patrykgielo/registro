---
name: feedback-verify-dont-assume-composer-audit-semantics
description: don't trust a tool's --help summary or common assumption for exit-code/scope semantics when a security gate depends on it — read the source or run it
metadata:
  type: feedback
---

The task brief assumed `composer audit` reads `composer.lock` (not installed packages) by
default. That's backwards: per Composer's own `AuditCommand`/`Auditor` source, the DEFAULT (no
`--locked`) audits *installed* packages; `--locked` is what forces it to read the lock file. Since
the CI job's `composer install` step has no `--no-dev` today, both would have given the same
answer here — so the wrong assumption wouldn't have broken anything YET, but would have silently
started under-auditing the moment someone added `--no-dev` to that install step for a speed
optimization. Caught by fetching `AuditCommand.php`/`Auditor.php` from GitHub via `gh api` and
reading the actual `getPackages()` branch, then confirming empirically (ran audit with zero
`vendor/` installed, `--locked` still gave the full, correct count).

**Why:** a security-gate's semantics are exactly the kind of thing worth reading source for
instead of trusting a plausible-sounding assumption in a task brief — a wrong assumption here
doesn't fail loudly, it silently narrows what gets audited, which is the worst failure mode for a
security check (looks green, isn't).

**How to apply:** when a task brief states "X tool behaves like Y" as a *fact to verify* rather
than *reproduce*, actually verify it — `gh api repos/<owner>/<repo>/contents/<path>` to pull the
real source (works for public repos without cloning), or run the tool against a deliberately
minimal fixture (empty `vendor/`, tiny `composer.json`) to observe the real behavior. Same
discipline as [[project-ci-security-scanning-2026-08-16]]'s Trivy `vuln-type` decision — measured
overlap with `composer audit`, not assumed.
