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

**Pre-existing failures (3, do NOT try to fix)** — verified 2026-08-08 against
`docker compose exec -T app php artisan test` (`3 failed, 5 skipped, 1051 passed`):
- `customer can cancel pending payment order` — CustomerOrdersTest
- `cancel sets cancelled at timestamp` — CustomerOrdersTest
- `booking wizard has 4 steps without vehicles` — TenantFeatureTest

Root cause of these three is **not** diagnosed — do not assume it, check.

This list drifts. It previously claimed 7 failures in two completely different
test files, which would have made anyone comparing against it either chase a
phantom regression or wave a real one through. **Re-derive it, don't trust it:**
`php artisan test 2>&1 | grep -E "^  ⨯|Tests:"` — and if the output disagrees
with the list above, fix the list in the same PR.

Any failure NOT in this list = **new regression**, must fix.

Report: **"Pint: [pass/fail]. Tests: [X passed, Y failed]. New failures: [list or none]."**
