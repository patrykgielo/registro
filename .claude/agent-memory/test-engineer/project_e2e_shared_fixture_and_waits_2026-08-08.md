---
name: project-e2e-shared-fixture-and-waits-2026-08-08
description: Consolidated Browser test fixture (loginAsTenantAdmin in tests/Pest.php) and empirical findings on which pest-plugin-browser wait primitives actually work vs which race — read before touching tests/Browser or tests/Pest.php
metadata:
  type: project
---

Narrow cleanup pass on `feature/e2e-browser-tests` (branch already had 3 working Browser tests:
[[project_e2e_browser_foundation_2026-08-08]], [[project_e2e_multi_session_deadlock_2026-08-08]],
[[project_e2e_tenant_isolation_2026-08-08]]). Two things only: dedupe the repeated
org+role+user+pivot+login setup, and replace `->wait(1)` fixed sleeps with real wait-for-condition
where one actually exists. No new tests, no scope change, no `app/` changes survive (all 4 mutation
checks reverted, `git diff app/` empty at the end).

## Shared fixture: `loginAsTenantAdmin(string $slug): array` in `tests/Pest.php`

Chose `tests/Pest.php` over a new support file — it's Pest's canonical bootstrap location, already
scoped to `Browser` via the existing `uses(...)->in('Browser')` call, and a second file would need
its own `require`/autoload wiring for one function. Deliberately **not** a factory/builder: one
required parameter (`$slug`, constrained to `grent`/`qatest` — the only two that resolve in
`/etc/hosts`, see [[project_e2e_browser_foundation_2026-08-08]]), one fixed password, one org, one
admin, attached as `'owner'`. Returns
`['organization', 'admin', 'password', 'port', 'baseUrl', 'page']`.

**What stayed local, deliberately, per-file (the task's explicit "don't generalize" instruction):**
- `EmployeeCreationTest`: the `'staff'` Role, and the employee's own (non-admin) login — genuinely
  different data shape, reuses only the *admin* half via the helper.
- `TenantIsolationTest`: the second organization (`qatest`) + both `Service` rows — the helper only
  ever creates ONE org, so the second tenant this test needs is built by hand in the file, same as
  the original. Restructured so the org/admin/login the helper DOES cover happens inside each `it()`
  (not `beforeEach`) since Pest's one-context-per-test rule means login must happen per-test anyway;
  only the qatest org + qatestService (which don't need the helper's org) stayed in `beforeEach`.

Net effect: ~164 insertions / ~161 deletions across the 4 files — genuinely smaller and less
repetitive, not a wash. If a 4th Browser test file ever needs a *third* deviation shape, resist the
urge to add a parameter to `loginAsTenantAdmin()` for it — add it locally in that file instead,
exactly like the two above did.

## Wait primitives: what's real, what's a landmine

Investigated `vendor/pestphp/pest-plugin-browser/src/Api/Concerns/*` and `Playwright/Page.php` before
touching anything (per the task's own instruction) — three findings:

1. **`fill()` and `click()` already auto-wait.** Both are thin passthroughs to real Playwright
   `Locator::fill()`/`Locator::click()` (`Playwright/Locator.php`), which auto-wait for their target
   to be attached/visible/enabled before acting, using Playwright's real (node-side) actionability
   polling. **Any `wait(1)` sitting immediately before a `fill()`/`click()` call is provably
   redundant** — deleted these outright (no replacement needed), e.g. before filling the
   create-employee form right after `navigate()`, and before clicking the logout button right after
   `navigate()` to the dashboard.

2. **`assertSee`/`assertDontSee`/`assertPathIs`/`assertHostIs` do NOT wait or retry.** Read
   `MakesElementAssertions.php` and `MakesUrlAssertions.php` directly: `assertSee` calls
   `page.getByText(...)->all()` and checks `isVisible()` **once**, immediately; `assertPathIs` reads
   `$this->page->url()` **once**, immediately. `waitForText()` is a deprecated alias for `assertSee()`
   — despite the name, it does NOT poll either. Don't trust the name; read the body.
   `navigate()`/`visit()` (→ `Page::goto()`) DO block until Playwright's own `'load'` state, including
   following any HTTP redirect chain to its final page — so a `wait(1)` placed right after a
   `navigate()` call, before an assertion, is *also* redundant (the wait it would have covered
   already happened inside `goto()`). Deleted these too (e.g. after `navigate()` to `/admin/services`
   before `assertSee`).

3. **`waitForEvent('load')` (→ real `page.waitForLoadState('load')`) is the right primitive after a
   `click()` that itself triggers navigation** (login submit, employee-create submit) — genuinely
   replaced `wait(1)` with it in 4 spots and it held up over 3 consecutive full-suite runs with zero
   flake. **BUT it does not always work — confirmed empirically, not theoretically, and confirmed
   deterministically wrong, not flaky:**

   One spot — the employee's own login, reached by filling the login form on a page that itself
   arrived via clicking a real `<form method="post">` logout button *inside an already-hydrated page*
   (not via `visit()`/`navigate()`) — fails **100% of the time (3/3 runs)** with both
   `waitForEvent('load')` and `waitForEvent('networkidle')`: the subsequent `fill()` calls write into
   inputs that show empty on submit (Chromium's native "Please fill out this field" validation blocks
   the POST — see the screenshot this produced, saved via the plugin's own auto-capture-on-failure).
   Likely cause (not fully proven, but consistent with the evidence): Playwright's load-state tracking
   resolves against a race with Livewire/Alpine's OWN client-side hydration of the freshly-swapped-in
   login page, and something in that hydration path clobbers what `fill()` already wrote. This does
   NOT happen for the structurally-identical selectors in `loginAsTenantAdmin()`'s own login, because
   that one lands via `visit()` (a brand-new browser context + `goto()`), which — probably by
   incidental extra RPC/bootstrap overhead — gives the JS bundle enough of a head start.

   **There is no "wait until Livewire/Alpine is hydrated" primitive in this plugin version** (checked
   the whole `Concerns/` directory, nothing fits). Left `wait(1)` in place at this ONE spot only, with
   an inline comment documenting exactly this — both alternatives tried, both confirmed to fail
   deterministically, not just "seemed risky." This matches the task's own explicit allowance:
   leave a justified fixed wait where no real condition is available, rather than inventing a fake
   one.

## Mutation verification (all reverted, `git diff app/` empty at the end)

| Mutation | Location | Result |
|---|---|---|
| Disabled pivot assignment | `CreateEmployee.php:33` (commented out the `syncWithoutDetaching` call) | `EmployeeCreationTest` fails: `Actual path [/admin/login] does not equal expected path [/admin]` at the employee-relogin assertion |
| Disabled tenant filter | `BelongsToOrganization.php` (`if ($tenant) { return; }`, dropped the `->where(...)`) | `TenantIsolationTest`'s FIRST test fails: `qatest`'s service visible on `grent`'s list; second test (cross-subdomain) correctly still passes — different mechanism |
| Disabled `canAccessTenant` branch | `ResolveTenant.php` (`if (false && $user->hasAnyRole(...) ...)`) | `TenantIsolationTest`'s SECOND test fails: host stays `qatest.registro.local` instead of redirecting to root; first test correctly still passes |
| Wrong password | `tests/Pest.php`'s `loginAsTenantAdmin()`, temporarily (test-side, not `app/`) | `SmokeTest` fails: stuck on `/admin/login` |

All four confirm the refactored/deduped tests still detect the exact regressions they're named for —
the point of doing this at all, not just "tests stayed green."

## Final numbers (2026-08-08, this branch)

- `./vendor/bin/pint --test`: 769 files, pass (whole project, not just Browser).
- `tests/Browser`: 4 passed (18 assertions), 3 consecutive full-suite runs, ~7.9–8.0s test duration
  each (~10.6–10.8s wall including Docker exec overhead) — no flake, times essentially identical
  across runs.
- `php artisan test` (default suite, Browser excluded): `3 failed, 5 skipped, 1054 passed` — unchanged
  from the baseline noted in `MEMORY.md` (2x `CustomerOrdersTest`, 1x `TenantFeatureTest`). No
  regression.
