---
name: feedback-shared-working-directory
description: This project's working directory can have concurrent uncommitted changes from other sessions/agents mid-task — check `git status` before staging anything, and always stage explicit file paths, never `git add -A`, even when the task feels self-contained.
metadata:
  type: feedback
---

During PR #210 (2026-08-16), `git status` at the point of committing showed unrelated modified
files (`CartController.php`, `CheckoutController.php`, `CartService.php`,
`RentalUnavailableException.php`, `layouts/app.blade.php`, several Cart/Browser test files) that
had appeared mid-session — none of them touched by this task. The branch HEAD had also advanced
(a new commit, `#208`, merged) since the conversation's initial git-status snapshot. This means
another process (another agent session, or the user directly) was working in the same checkout
concurrently.

**Why this matters:** `git checkout -b` carries uncommitted working-directory changes into the new
branch (branch creation doesn't touch the working tree) — harmless by itself, but a `git add -A` or
loosely-scoped `git add resources/` at commit time would have swept unrelated, possibly
half-finished changes into this PR.

**How to apply:** before every commit, run `git status --short` and stage only the exact file paths
this task touched, even when the task feels small and self-contained enough that "surely nothing
else changed". Never assume the working directory is clean just because it was at conversation
start — re-check right before staging. This is the same principle CLAUDE.md's "Nigdy git add -A"
rule already states, but the reason it matters here specifically is concurrency, not just hygiene.

**Test triage: isolate the tree, but rule out flakiness FIRST.** 2026-08-29,
`fix/tenant-scoped-storage-url`: `php artisan test` reported a 3rd failure
(`LocationSlugUniqueScopeTest`) beyond the two the brief named as pre-existing. I isolated it with
`git stash push -- ResolveTenant.php` (never a bare `git stash` — that hides your own work too),
saw the failure disappear, and concluded another session's WIP had caused it.

**That conclusion was wrong.** The test is randomly flaky: `LocationFactory.php:33` generates
`fake()->phoneNumber()` under locale `en_US`, which sometimes emits an extension (`x1234`), against
a field validated as `->tel()` in `LocationForm.php:92`. The failure is `data.phone`, and it has
nothing to do with Storage, URLs or any middleware. Re-running the same test on the *unchanged*
tree passed. My stash experiment "worked" only because the dice came up differently that run.

**How to apply:** a single pass/fail pair proves nothing about causation when the test may be
flaky. Before blaming any file, (1) read the actual assertion message — `data.phone` never had a
plausible link to `Storage::forgetDisk()`, and that mismatch alone should have stopped the
hypothesis; (2) re-run the suspect test unchanged a second time; only if it fails consistently is
per-file stash isolation meaningful. Attributing a failure to someone else's diff is a claim about
cause, and it needs the same standard of evidence as any other. See [[feedback_stop_and_research]].
