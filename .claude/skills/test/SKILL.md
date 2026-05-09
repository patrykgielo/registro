---
name: test
description: Run Pint + PHPUnit tests in Docker. Reports results and identifies new failures vs pre-existing.
argument-hint: "[optional --filter pattern]"
disable-model-invocation: true
allowed-tools: Bash, Read
effort: low
---

# /test — Run Tests

## Step 1: Verify .env.testing exists

```bash
test -f .env.testing && echo "OK" || echo "MISSING — tests will hit dev MySQL!"
```

If MISSING — **STOP**. Create `.env.testing` with `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`.

## Step 2: Pint

```bash
docker compose exec -T app ./vendor/bin/pint --test
```

Report: pass/fail. Known issue: `HasGroupedSettingsTraitTest` has pre-existing style issue.

## Step 3: PHPUnit

```bash
docker compose exec -T app php artisan test $ARGUMENTS
```

## Step 4: Analyze Results

**Pre-existing failures (7, do NOT try to fix):**
- `BookingConfirmationSecurityTest` (1) — CSRF
- `BookingServiceAreaBypassTest` (6) — CSRF

Any failure NOT in this list = **new regression**, must fix.

Report: **"Pint: [pass/fail]. Tests: [X passed, Y failed]. New failures: [list or none]."**
