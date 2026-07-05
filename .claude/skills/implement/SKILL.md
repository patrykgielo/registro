---
name: implement
description: Implement a feature/fix following mandatory Registro process with agent-first, docs-after gates.
argument-hint: <task description>
allowed-tools: Agent, Read, Grep, Glob, Edit, Write, Bash
effort: high
---

# /implement — Gated Workflow

You have been invoked via `/implement`. This is a **MANDATORY GATED WORKFLOW**.
Follow every step in sequence. Do not skip any step. Do not combine steps.

**Task:** $ARGUMENTS

---

## Current Context
- Branch: !`git branch --show-current`
- Status: !`git status --short | head -5`

## Gotchas
- **FILESYSTEM_DISK**: must be `public`, never `local` — breaks file uploads
- **User model**: `first_name`/`last_name` — no `name` column, never `$user->name = "x"`
- **Filament v4**: `Schema $schema` not `Form $form`, `Filament\Actions` not `Tables\Actions`
- **Tests**: run in Docker only (PHP 8.3), `.env.testing` must exist or tests wipe dev MySQL
- **Spatie roles**: always `Role::firstOrCreate()` before `assignRole()` — fresh DB has no roles
- **Pre-existing failures**: 5 tests fail (BookingServiceArea + TenantFeature) — don't try to fix

## GATE 1: Rules Check

Read `.claude/rules/_INDEX.md`. Identify which TIER 1 and TIER 2 rules apply.

State: **"Rules loaded: [list applicable rules]"**

Do NOT proceed until you have stated this.

---

## GATE 2: Agent Analysis (MANDATORY)

Launch the appropriate agent to analyze the task:

| Task type | Agent |
|-----------|-------|
| Laravel/PHP, models, services | `laravel-senior-architect` |
| Frontend, Blade, CSS, UI | `frontend-ui-architect` |
| Security concern | `agent-security-audit-specialist` |
| Unknown scope | `Explore` first, then specialist |

Wait for the agent's report. Do NOT write code until you have it.

State: **"Agent report received. Key findings: [summary]"**

---

## GATE 3: Branch Verification

Run `git branch --show-current`. If on `develop` or `main`, create a feature branch first.

State: **"Branch: [branch name]"**

---

## GATE 4: Implementation

Now implement based on the agent's analysis. Follow all rules from Gate 1.

---

## GATE 5: Test Gate

Run:
```bash
docker compose exec -T app ./vendor/bin/pint --test
docker compose exec -T app php artisan test
```

Fix any failures before proceeding.

State: **"Pint: [pass/fail]. Tests: [X passed, Y failed]"**

---

## GATE 5b: Bounded Retry Gate

If Gate 5 failed: do NOT fix inline and do NOT call `code-reviewer` on broken code.
Re-invoke the SAME building agent from Gate 2/4 with the exact Pint/test failure
output. Repeat Gate 5 → Gate 5b up to 3 cycles total on the same failure.

- If the failure changes each cycle (real progress) → keep going, up to the cap.
- If cycle 3 still fails on the SAME error with no diff change → STOP. Do not
  attempt a 4th cycle automatically. Report the failure to the user and wait —
  see the bounded-retry rule in `.claude/rules/agent-usage.md`.
- NEVER weaken, skip, or delete a test to force green (`.claude/rules/tests.md`).

State: **"Retry cycles used: [N/3]. Final: [ALL GREEN / escalated to user]"**

---

## GATE 6: Documentation Gate (MANDATORY)

Answer EACH question explicitly:

1. **app/docs/** — Did you add a feature or change architecture? → Update relevant doc
2. **.claude/rules/** — Did you discover a pattern, fix an error, or change a convention? → Update relevant rule
3. **memory/MEMORY.md** — Is this significant for future conversations? → Update memory

State: **"Docs updated: [list files] OR No docs needed because: [specific reason]"**

---

## GATE 7: Summary

State:
- **Branch:** feature/...
- **Tests:** passing
- **Docs:** [updated files or "not needed + reason"]
- **Ready for commit:** yes/no
