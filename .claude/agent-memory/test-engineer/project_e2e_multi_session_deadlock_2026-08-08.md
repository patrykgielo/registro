---
name: project-e2e-multi-session-deadlock-2026-08-08
description: A second top-level visit() call inside one Pest browser test deadlocks the in-process server — use the real Filament logout form in the SAME context instead, not a second browser context
metadata:
  type: project
---

Built the SECOND E2E browser test: `tests/Browser/EmployeeCreationTest.php` (branch
`feature/e2e-browser-tests`). Guards `app/Filament/Resources/EmployeeResource/Pages/CreateEmployee.php:33`
(`$this->record->organizations()->syncWithoutDetaching(...)`) — without it, a newly created employee
looks fully created (Spatie role assigned, success toast, but NOT visible in the tenant-scoped
`EmployeeResource` table either, since that query also filters on the same pivot) yet cannot log in,
because `User::canAccessTenant()` reads ONLY `organization_user`.

## Finding: a second `visit()` call in the same test deadlocks — confirmed, not theoretical

The natural first attempt for "admin creates employee, then employee logs in" is two top-level
`visit()` calls — one browser context per session, to cleanly simulate "logout". **This hangs
indefinitely, with near-zero CPU (confirmed via `docker stats`, ~0.65%), no exception, no timeout.**
Not a slow test — a genuine stuck process, reproduced 100% of the time via bisection (breadcrumb
logging with `file_put_contents(..., FILE_APPEND)` at every step, since Pest's own output only
appears at test completion and gives zero visibility into where a hang is).

Root cause (reasoned from vendor source, not from an upstream issue — none found for this specific
symptom): `tests/Pest.php`'s in-process model runs the AmpHttp test server AND the Playwright RPC
client cooperatively in a single PHP process via `Amp\async`/`await`/`delay(0)` (see
`Pest\Browser\Execution::tick()`/`waitForExpectation()`). `PendingAwaitablePage::createAwaitablePage()`
(`vendor/pestphp/pest-plugin-browser/src/Api/PendingAwaitablePage.php`) launches a brand new
`$browser->newContext()` + `$context->newPage()->goto($url)` on the FIRST method call against a new
`visit()` result. The browser process itself is cached/reused (`Playwright::browser()->launch()` is a
singleton), so it's specifically opening the second CONTEXT — while context #1's page is still alive —
that never returns. Exact failure point isolated via crumbs: execution stopped dead on the very first
API call (`->fill(...)`) against the second page, before `createAwaitablePage()` even finished.

**Do not spend more time trying to make two contexts coexist. Use one context for the whole test.**

## The fix: drive the real Filament logout form, same context

Filament's `AccountWidget` (`vendor/filament/filament/resources/views/widgets/account-widget.blade.php`)
renders a genuine `<form method="post" action="{logout url}">` with `@csrf` — not a Livewire
`wire:click`. It's registered in `AdminPanelProvider`'s `->widgets([Widgets\AccountWidget::class, ...])`,
so it's on the `/admin` dashboard (not every page — navigate there first if you're elsewhere).
Submitting it is a real full-page POST, exactly what a human clicking "Wyloguj się" does — staying in
the SAME browser context/page for the entire test (login as admin → create employee → **navigate to
`/admin`, click the logout form's submit button** → login as the new employee, all as one linear
`$page->` chain) is both the only working option AND the more realistic E2E flow.

**Gotcha inside that fix:** Filament renders BOTH a labeled (`fi-btn ... fi-labeled-from-sm`) and an
icon-only (`fi-icon-btn`) variant of that submit button in the DOM simultaneously (CSS/breakpoint
toggling, not server-side conditional) — `.fi-account-widget-logout-form button[type="submit"]` alone
is a Playwright strict-mode violation (2 matches). Append `:visible` (a Playwright CSS extension the
vendor passes straight through to `page.locator()`, not standard browser CSS) to pick only whichever
variant the current viewport is actually showing:
`.click('.fi-account-widget-logout-form button[type="submit"]:visible')`.

**Also confirmed:** `wire:target="create"` (Filament's split Create button — a second HIDDEN
`button[type="submit"]` exists in the same DOM for the "Create & create another" dropdown item, so the
generic selector used on the login form is ambiguous on resource create pages) needs its colon escaped
for the CSS selector engine: `button[wire\\:target="create"]` in a PHP single-quoted string (`\\`
becomes a literal `\`). Unescaped, Chromium throws a `SyntaxError` immediately (fast, readable) — this
is a DIFFERENT failure mode than the [[project_e2e_browser_foundation_2026-08-08]] bare-dotted-id hang,
worth not confusing the two.

**Resource form statePath is `"form"`, not the Filament default `"data"`.** Verified empirically by
dumping real rendered HTML (`$page->content()`) rather than trusting
`CreateRecord::defaultForm()`'s `->statePath('data')` in vendor — something in this app's
`EmployeeResource::form()` chain overrides it to `"form"` (same statePath the Login page happens to use,
confirmed in [[project_e2e_browser_foundation_2026-08-08]]). **Always verify statePath against real DOM
output for each new page type** — don't assume vendor defaults hold once a form schema is customized.

## Workflow note: dump real rendered HTML before guessing selectors

For both this test and the SmokeTest foundation, guessing selectors from vendor/Blade source was
slower and less reliable than: log in via a throwaway Pest test, call `$page->content()`, and
`file_put_contents()` it somewhere readable (`/tmp/...html` inside the container, read via
`docker compose exec cat`). Confirms real ids/classes/attributes as actually rendered (post-Livewire
hydration state), not what the source implies. Delete the throwaway test file before finishing.

## No tool for "search the web" was available this session

The global CLAUDE.md rule (3 failed attempts → WebSearch) could not be followed literally — no
WebSearch/WebFetch tool was exposed to this agent invocation. Root-caused via vendor source reading +
systematic breadcrumb bisection instead. Worth flagging if this recurs: either the rule needs a
documented fallback for tool-less sessions, or the tool wiring for this agent type should be checked.

## Verification performed (2026-08-08)

- Standalone + full `--testsuite=Browser` run: both tests pass together, 3 consecutive full-suite runs,
  ~10.5–11.3s total each time, timings essentially identical across runs — no flake observed.
- Mutation test: commented out `CreateEmployee.php:33` → test fails deterministically (2 separate runs)
  at the re-login step (`Actual path [/] does not equal expected path [/admin]`) — the intended failure
  point, not the earlier DB assertion. Restored → passes again, `git diff` on that file is empty.
- `php artisan test` (default suite): `3 failed, 5 skipped, 1054 passed` — same 3 pre-existing failures
  as current baseline (`CustomerOrdersTest` × 2, `TenantFeatureTest` × 1). No regression.
- `./vendor/bin/pint --test`: 768 files, pass.
