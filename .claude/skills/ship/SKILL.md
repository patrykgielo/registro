---
name: ship
description: Lightweight builder -> bounded-retry -> code-reviewer loop for small, well-scoped fixes (one bug, one small tweak, a handful of files). NOT for new features or architecture changes -- use /implement for those.
argument-hint: <small task description>
allowed-tools: Agent, Read, Grep, Glob, Edit, Write, Bash
effort: medium
---

# /ship — Bounded Loop for Small Fixes

Lightweight alternative to `/implement`. Use ONLY for a small, well-scoped task —
one bug, one small tweak, changes contained to a handful of files. If the task
touches architecture, adds a feature, or looks like it'll touch 5+ files —
**STOP and use `/implement` instead** (the Stop hook requires docs/rules updates
past that threshold anyway, so `/ship`'s lighter docs step won't satisfy it).

**Task:** $ARGUMENTS

## Gotchas
- Same as always: `FILESYSTEM_DISK=public`, `first_name`/`last_name` (no `name` column), tests run in Docker only.
- Pre-existing test failures (don't try to fix): BookingServiceArea(4) + TenantFeature(1) + CustomerOrdersTest(2).

## Step 1 — Branch Check

```bash
git branch --show-current
```

If on `develop`/`main` — create a `feature/*` branch first (PreToolUse hook blocks direct commits regardless).

## Step 2 — Pick the Builder (Agent First — no exceptions)

| Task touches | Agent |
|---|---|
| PHP/Laravel/Filament logic | `laravel-senior-architect` |
| Blade/Tailwind/UI | `frontend-ui-architect` |
| Unsure / needs root-cause first | `Explore`, then pick |

Dispatch the builder with the task. Wait for its report before touching code yourself.

## Step 3 — Bounded Retry Loop (max 3 cycles)

Run:
```bash
docker compose exec -T app ./vendor/bin/pint --test
docker compose exec -T app php artisan test
```

- **ALL GREEN** → go to Step 4.
- **FAILED, and this is a NEW failure or the error changed** → re-invoke the SAME builder with the exact failure (file:line, what broke, which check). Repeat this step.
- **FAILED on the SAME error 3 cycles in a row, no diff change** → STOP. Do not attempt a 4th cycle. Report the failure to the user and wait. See the bounded-retry rule in `.claude/rules/agent-usage.md`.
- **NEVER** weaken, skip, or delete a test to force green — see `.claude/rules/tests.md`. Fix the code, not the test.

State: **"Retry cycles used: [N/3]. Result: [ALL GREEN / escalated]"**

## Step 4 — code-reviewer (mandatory, not skippable)

Dispatch `code-reviewer` on the diff. It is read-only — it reports, it doesn't fix.

- **Critical findings** → back to Step 3 with the builder. A genuinely NEW issue from review resets the retry count once; the same issue reappearing counts toward the existing cap.
- **Important/Nitpick only** → note them in the report, proceed.

## Step 5 — Docs Check (lightweight)

Only update `.claude/rules/` or `memory/` if this fix revealed a non-obvious pattern, a real bug's root cause, or a convention change. For a genuinely small, self-contained fix:

State: **"No docs needed — [reason]"** instead of skipping the question.

**One question is never skippable, however small the fix:** did anything a CUSTOMER or TENANT
experiences change — a message they receive or stop receiving, a page they land on, a step that
appears or disappears? If yes, `app/docs/business/` needs it (both `.md` and `.en.md`) and this
stops being a `/ship` job — go to `/implement`. A one-line fix can change what a customer
receives; size of diff is not size of consequence.

## Step 6 — Report (do not commit)

Summarize: cycles used, code-reviewer findings, test result, docs decision.
**Wait for explicit user approval before committing** — never commit automatically, same as every other workflow in this project.
