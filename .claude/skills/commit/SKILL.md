---
name: commit
description: Stage changes, run Pint + tests, create conventional commit. Use after completing implementation work.
argument-hint: "[optional commit message override]"
disable-model-invocation: true
allowed-tools: Bash, Read, Grep, Glob
effort: low
---

# /commit — Safe Commit Workflow

**MANDATORY** sequence. Do not skip steps.

## Gotchas
- NEVER `git add -A` or `git add .` — may include .env, credentials, large binaries
- NEVER commit to `develop` or `main` — PreToolUse hook blocks this
- Pre-existing test failures (5): BookingServiceArea(4) + TenantFeature(1) — ignore these
- If Pint fails, the commit did NOT happen — fix style, then create NEW commit (don't amend)

## Step 1: Branch Check

```bash
git branch --show-current
```

If on `develop` or `main` — **STOP**. Create feature branch first.

## Step 2: Review Changes

```bash
git status
git diff --stat
```

List what changed. Flag any suspicious files (.env, credentials, large binaries).

## Step 3: Code Quality

```bash
docker compose exec -T app ./vendor/bin/pint --test
```

If Pint fails — fix before proceeding.

## Step 4: Tests

```bash
docker compose exec -T app php artisan test
```

If new failures (beyond 7 pre-existing BookingServiceArea/Confirmation CSRF) — fix before proceeding.

## Step 5: Stage & Commit

Stage specific files (never `git add -A`):
```bash
git add <specific files>
```

Commit with conventional message:
```bash
git commit -m "type(scope): description"
```

Types: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `style`

If `$ARGUMENTS` provided, use as commit message: `$ARGUMENTS`

## Step 6: Verify

```bash
git log --oneline -1
git status
```

State: **"Committed: [hash] [message]. Branch: [name]. Clean: [yes/no]"**
