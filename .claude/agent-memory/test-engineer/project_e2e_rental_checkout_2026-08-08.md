---
name: project-e2e-rental-checkout
description: tests/Browser/RentalCheckoutTest.php (4th Browser test, 1st storefront test) — the money path (rent equipment -> paid order); found and worked around a harness-wide CORS bug that silently kills Alpine.js on every non-admin page, and found a real unfixed app bug along the way
metadata:
  type: project
---

`tests/Browser/RentalCheckoutTest.php` — logged-in customer: service page -> real calendar
widget -> add to cart -> checkout -> DEV fake-pay bypass -> paid `Order`, DB-verified
(org/service/status/cart-converted). First Browser test to touch the storefront instead of
`/admin` — the other three ([[project_e2e_browser_foundation_2026-08-08]],
[[project_e2e_multi_session_deadlock_2026-08-08]], [[project_e2e_tenant_isolation_2026-08-08]])
only ever drove the Filament panel, which is why the finding below went undetected for 3 prior
Browser test files.

## Finding #1 (harness bug, worked around in-test): Alpine.js never loads on storefront pages

**Root cause:** `Pest\Browser\Drivers\LaravelHttpServer::bootstrap()` (vendor) force-sets
`config('app.url')` to `http://127.0.0.1:{port}` and calls `$urlGenerator->useAssetOrigin($url)`
— regardless of which tenant subdomain the page under test actually renders on
(`http://grent.registro.local:{port}`). Vite's `@vite()` directive builds `resources/js/app.js`'s
`<script>` tag from that forced origin. That file compiles to `type="module"`, and **module
scripts are always fetched in CORS mode** (unlike classic `<script>` tags or `<link
rel="stylesheet">`, which is why CSS loads fine regardless of origin mismatch) — so the browser
silently refuses to execute it when the page's real origin doesn't match the script's origin.
Net effect: `window.Alpine` stays `undefined` on every storefront page tested this way — `x-show`
never resolves (loading skeletons stay visible forever), `x-for` renders zero elements, and
**the failure is invisible to `$page->assertNoJavaScriptErrors()`**: failed *resource* loads
dispatch a non-bubbling `error` event, and vendor's own diagnostic listener
(`Pest\Browser\Playwright\InitScript`) registers `window.addEventListener('error', ...)` without
`capture: true`, so it never sees it.

**Symptom if you don't know this yet:** any `click()`/`fill()` targeting an Alpine-rendered
element (visibility-gated by `x-show`/`x-for`) hangs indefinitely — not a fast failure, no
timeout, no exception, just a stuck `docker compose exec` (confirmed via `/proc/*/cmdline`
inspection: `chrome-headless-shell` process alive and idle, not crashed). This is a DIFFERENT
hang mechanism than the two already documented (dotted-id selector misparse in
[[project_e2e_browser_foundation_2026-08-08]]; second `visit()` deadlock in
[[project_e2e_multi_session_deadlock_2026-08-08]]) — same *symptom* (silent hang), three
different *causes*. If a Browser test hangs, check window.Alpine/gridcell-count/script-tag-origin
BEFORE assuming it's one of the other two.

**Fix (test-file-local, not a vendor patch, not app code):**
```php
$baseUrl = "http://grent.registro.local:{$port}";
app('url')->useAssetOrigin($baseUrl);   // BEFORE navigating to any Alpine-dependent page
// ...
afterEach(function () {                 // restore — process-wide singleton, leaks to later files otherwise
    app('url')->useAssetOrigin("http://127.0.0.1:{$port}");
});
```
`useAssetOrigin()` is a real public method on `Illuminate\Routing\UrlGenerator` — this just
corrects vendor's own override to the actual host under test, it doesn't monkeypatch anything.
**Every future Browser test that touches a non-admin, Alpine-driven page needs this** — Filament
admin pages have never needed it, so it's plausible admin JS ships as classic (non-module)
scripts; not independently confirmed, don't assume it doesn't apply if a future admin-side test
starts doing something CSS-only-tested paths haven't exercised before.

**Diagnosis recipe used to nail this down** (useful template for the next silent-hang Browser
bug): `$page->script('typeof window.Alpine')`, `$page->script("document.querySelectorAll(...).length")`,
`$page->script("Array.from(document.querySelectorAll('script[src]')).map(s => s.src)")`,
`getComputedStyle(document.body)` (proves CSS — same asset pipeline — loads fine, isolating the
bug to JS/module-specific behavior, not "assets don't load at all").

## Finding #2 (real app bug, found but NOT fixed — reported instead per task instruction)

Once Alpine actually ran for the first time against `resources/views/services/show.blade.php`'s
item_rental branch, it threw `Uncaught ReferenceError: bookingUrl is not defined`. Root cause:
that file has what looks like duplicated/leftover markup — a second near-identical "price
breakdown + CTA + availability badge" block (roughly lines 480-527, right after the real
availability calendar) that references `:href="bookingUrl"`, a property that does **not** exist
anywhere in the `availabilityCalendar()` Alpine component's returned data object (verified by
reading the whole component). The legitimate CTA (the actual `cart.add` form, `canBook`-gated) is
the EARLIER block around line 284-320. This second block appears to be copy-pasted from the
time_slot booking flow and never adapted/removed for item_rental services. It did not block this
test (Alpine's per-binding evaluation seems to isolate the failure — the calendar still rendered
and the real add-to-cart flow still worked), but it is a genuine, previously-invisible console
error on every real customer's rental service page — invisible to Feature tests (no JS
execution at all) and invisible to manual QA unless devtools console is open. **Not fixed** —
flagged to the user per this task's explicit "found a real bug -> stop and report, don't
silently patch or hide it" instruction. `app/` diff was kept clean (mutation-tested and
reverted); this bug was pre-existing and untouched.

## Selector gotcha (own, distinct from [[project_e2e_browser_foundation_2026-08-08]]'s dotted-id one)

A logged-in customer on the storefront gets the layout's own navbar `button[type="submit"]`
(the "Wyloguj" logout form) on EVERY authenticated page — so a bare `button[type="submit"]`
selector for the "Dodaj do koszyka" button is ambiguous (Playwright strict-mode violation, 2
matches) on `services/show.blade.php`. Fixed by using the button's exact visible text
(`click('Dodaj do koszyka')` — routes through `GuessLocator`'s `getByText($selector, exact: true)`
fallback) instead of a type-based CSS selector. The checkout page's DEV fake-pay button doesn't
have this problem because it's queried scoped to its own `<form>`:
`form[action$="/dev/fake-pay"] button[type="submit"]`.

## Calendar widget selector (own pattern, reusable for future rental E2E tests)

The day-cell grid (`resources/views/services/show.blade.php`'s `availabilityCalendar()` Alpine
component) has no `id`/`data-testid` on individual cells — only a computed, locale-dependent
`aria-label`. `[role="gridcell"][aria-current="date"]` reliably targets "today"'s cell regardless
of which day-of-month that is (Alpine only sets `aria-current="date"` on that one cell). Clicking
it twice hits `selectDate()`'s "second click on the already-selected start date" branch, producing
a valid single-day rental range without needing to drive the month-navigation buttons or
reimplement the component's Polish month-name array in PHP just to build a selector.

## Fixture decisions worth remembering

- **Logged-in customer, not guest**: `cart.*`/`checkout.*` routes carry `auth` middleware —
  guest checkout literally isn't a route that exists to test here.
- **DEV fake-pay bypass** (`POST /dev/fake-pay`, `App\Http\Controllers\Dev\FakePaymentController`,
  gated `! app()->isProduction()`) instead of driving `SubmitCheckoutRequest`'s ~15-field
  legal/billing form or mocking `Przelewy24Service`. Builds its own order payload from the
  authenticated user's profile, skips terms/RODO/withdrawal/PESEL/NIP/company fields entirely,
  transitions the order straight to `paid`. Had **zero** prior test coverage (Feature or Browser)
  before this test — first one to exercise it at all. The 15-field form itself is already covered
  at the HTTP layer by `tests/Feature/Cart/CheckoutFlowTest.php` — no need to duplicate that in a
  browser test.
- Single-day rental (today -> today) instead of a date range, specifically to avoid needing to
  drive the calendar's next/prev-month buttons for a today-near-month-end edge case.
- Customer role + `organizations()->attach(..., ['role' => 'customer'])` mirrors what
  `RegisterController::registered()` does for a real subdomain sign-up — not strictly required
  (`CartController`/`CheckoutController` never call `canAccessTenant()` for non-admin/staff
  users), but keeps the fixture realistic.

## Mutation verification performed (per task requirement)

Target: `Order::create([...])` in `App\Services\Cart\CartService::convertToOrder()`
(`app/Services/Cart/CartService.php`, the `'user_id' => $cart->user_id` line). First attempt
mutating `organization_id` to `null` was silently neutralized by `BelongsToOrganization`'s
`creating` hook (auto-refills `organization_id` from ambient tenant context when falsy — see
`.claude/rules/models.md`'s GOTCHA section) — a reminder that this trait's safety net can mask a
mutation aimed at the wrong field. Switched to mutating `user_id` to `null` instead (not
auto-corrected by anything) — test failed cleanly on the "Dziękujemy za zamówienie!" assertion
(order becomes unfindable by `CheckoutController::return()`'s `where('user_id', auth()->id())`
lookup). Reverted, `git diff app/` empty, re-ran green.

## Stability

3 solo runs (`--filter=RentalCheckoutTest`): 3.09-3.24s test time each, no flakiness. 3 full
`--testsuite=Browser` runs (all 5 tests together, alphabetical order so this one runs BEFORE
`SmokeTest`/`TenantIsolationTest` — confirms the `afterEach` asset-origin restore doesn't break
subsequent admin-panel tests): 10.36-10.65s total, all green. Default suite
(`php artisan test`, no `--testsuite` flag) unaffected: still 3 failed / 5 skipped / 1054 passed,
identical to the pre-existing baseline in MEMORY.md.
