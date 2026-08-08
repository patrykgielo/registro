---
name: project_e2e_order_lifecycle_and_cancellation_2026-08-08
description: tests/Browser/OrderLifecycleEmailTest.php + OrderCancellationTest.php — real (non-faked) notification path, Filament confirmation-modal timing, throttle-bucket collision, and how to recover from a hung Browser test run
metadata:
  type: project
---

Task: two new Browser tests that do NOT `Notification::fake()`, specifically to exercise the real
Notification -> EmailServiceChannel -> EmailService -> `email_templates` DB lookup layer that every
other order/rental test fakes past. Found the bug documented in
[[project_email_template_tenant_scope_bug_2026-08-08]] plus a rate-limiter bug and two harness-timing
issues. Read that file first for the headline finding; this one is the how-I-built-it record.

## Filament `requiresConfirmation()` modal mounts asynchronously — needs an explicit wait

Clicking a header action's trigger button (e.g. "Potwierdź" on `EditOrder`) does NOT put
`.fi-modal-footer-actions` in the DOM synchronously — confirmed by direct DOM dump:
`document.querySelector('.fi-modal-footer-actions')` was `null` immediately after `click()`, even past
a chained `waitForEvent('networkidle')`. It appeared reliably ~2s later (verified via `$page->script()`
returning real HTML with `fi-modal-footer-actions` after `wait(2)`). There is no "Livewire action modal
mounted" wait primitive in this plugin version (same class of gap `EmployeeCreationTest`'s docblock
documents for hydration) — the working pattern is:

```php
$page->click('Potwierdź')
    ->wait(2)
    ->click('.fi-modal-footer-actions button[type="submit"]')
    ->wait(2);
```

The trailing `wait(2)` matters too — without it, `$order->refresh()` right after can read the
PRE-transition status (confirmed empirically: same click sequence without a post-click wait read back
`'paid'` instead of `'confirmed'` — a false negative, not a real state).

The modal's own submit button is `type="submit"` (verified: `x-data="filamentFormButton"`,
`wire:target="callMountedAction"`), while `Anuluj`/cancel-modal is `type="button"` — so
`.fi-modal-footer-actions button[type="submit"]` is a clean, unambiguous selector. It ALSO happens to
share literal text ("Potwierdź") with the trigger button (Filament's pl `modal.actions.confirm.label`
translation collides with this action's own label) — scoping by `.fi-modal-footer-actions` avoids that
collision; do not click by bare text here.

`EditOrder`'s header actions (`app/Filament/Resources/OrderResource/Pages/EditOrder.php`) are a
simpler, equally-real alternative to the table's grouped "Potwierdź zamówienie" action in
`OrderResource.php` itself — identical `transitionTo()` call, identical hooks, but reachable by direct
URL (`/admin/orders/{id}/edit`) instead of opening `OrderResource`'s table `ActionGroup`, whose trigger
is bound on `x-on:mousedown` inside a generic `.fi-dropdown-trigger` class Filament reuses for several
unrelated dropdowns on the same list page (bulk actions, column toggle) — not a safe, unambiguous
selector. Chose the simpler one deliberately; this is a legitimate substitution, not a shortcut that
loses coverage (verified via mutation testing that the assertions genuinely catch real breakage).

## Second real finding: bare `throttle:N,M` shares ONE bucket per user/IP across unrelated routes

`Illuminate\Routing\Middleware\ThrottleRequests::resolveRequestSignature()`:

```php
if ($user = $request->user()) {
    return $this->formatIdentifier($user->getAuthIdentifier());
} elseif ($route = $request->route()) {
    return $this->formatIdentifier($route->getDomain().'|'.$request->ip());
}
```

No route/limiter-name component in the key at all when the 3rd "prefix" middleware argument is omitted
— and `routes/web.php` uses the bare 2-arg form (`throttle:5,1`, `throttle:60,1`) everywhere. For an
AUTHENTICATED user this collapses to just their user ID — meaning `Route::middleware([ResolveTenant::class,
'throttle:5,1'])->group(fn () => Auth::routes(...))` (wraps POST /login AND POST /logout) shares its
bucket with the rental-availability AJAX endpoints' `throttle:60,1` for that same logged-in user. A
handful of calendar-widget polling requests during checkout (Alpine's `$watch` on date selection) is
enough to push the STRICTER 5/min logout limit over its cap by the time logout is attempted — even
though logout itself was only ever requested once.

Confirmed empirically: `OrderLifecycleEmailTest`'s logout step got a genuine 429 (raw response body
"429 TOO MANY REQUESTS", not inferred) on the first working version of the test; adding a second
`Cache::flush()` immediately before the logout POST fixed it — same class of workaround `tests/Pest.php`
already documents for `loginAsTenantAdmin()`'s own login throttle. Not fixed app-side (would need a
distinct `prefix` per throttled group, e.g. `throttle:5,1,auth` vs `throttle:60,1,rental-availability`).

## Logging out mid-test: script()-submit the real form, don't click through the Alpine dropdown

The storefront navbar's logout `<form action="/logout">` (`resources/views/components/nav/header.blade.php`)
only exists inside an `x-interactive.dropdown` component that's `x-cloak`'d shut (`open: false`) until
its trigger is clicked. A text-based selector on the trigger (`button:has-text("{$customer->first_name}")`)
was tried first and **deadlocked the whole in-process server** on the very first run — no exception, no
timeout, just an indefinitely blocked process (zero PHPUnit output for 7+ minutes; same failure
signature as `EmployeeCreationTest`'s documented "second visit()" deadlock, but this file never opens a
second context, so the trigger is a different, not-yet-fully-diagnosed cause — most likely a malformed
Playwright selector from Faker-generated text, though this was never confirmed in isolation because the
fix below made it moot).

Fix: submit the SAME real, already server-rendered form directly via `script()` — still a genuine POST
to `/logout` with the real CSRF token already in the DOM, functionally identical to what the button
click would do, but sidesteps the Alpine/selector fragility entirely:

```php
$page->script('document.querySelector(\'form[action$="/logout"]\').submit();');
$page->waitForEvent('load');
```

Note `script()` returns whatever `page.evaluate()` returns and is NOT itself chainable — call it as its
own statement, then continue chaining from `$page` on the next line (same pattern already used in
`OrderCancellationTest`'s `window.confirm = () => true` override).

## Recovering from a hung `php artisan test --testsuite=Browser` run

Several genuine hangs happened while building these two tests (both bugs above, before their fixes were
found). `pkill -9 -f "artisan test"` / `-f "playwright run-server"` etc. is **unreliable in this
container** — it repeatedly killed only one PID then exited 137 (its own shell got killed), leaving most
of the leaked `playwright run-server` + `chrome-headless-shell` processes alive across attempts (dozens
accumulated). The only reliable fix found: `docker compose restart app`. The tell that this has
happened (rather than the run just being slow): `tail storage/logs/laravel.log` — repeated stuck runs
eventually produce `Allowed memory size of 268435456 bytes exhausted` FatalErrors from
`InteractsWithPlaywright.php`, and the redirected output file for the hung run stays at 0 bytes forever
(PHPUnit only prints results on completion, not per-assertion, so a genuine hang produces zero partial
output — don't waste time waiting for "at least some" output before concluding it's stuck).

## Diagnosing a hang/mystery without guessing further

When static reasoning ran out, the fast, cheap technique that actually worked: a disposable
`tests/Browser/ZZDebugTest.php` (deleted before finishing) that does the SAME setup as the real test but
`throw new \Exception($page->script('...'))` right after the suspect step — `script()`'s return value
IS returned (unlike `click()`), and throwing forces PHPUnit to print it immediately instead of waiting
for a hang or a vague timeout. Used this to: (1) get the real trigger button's `outerHTML` (confirmed
`type="button"`, no ambiguity), (2) confirm the modal footer genuinely doesn't exist yet at time T+0,
(3) get the raw HTTP response body for the cancel-flow 500 (confirmed "500 SERVER ERROR" text, not
inferred from a redirect target). `script()` content must be a bare expression, NOT `return expr;` —
top-level `return` throws `SyntaxError: Illegal return statement` in this plugin's eval context.

## Mutation-verification evidence (all reverted, confirmed via `git diff` = clean)

- **`OrderCancellationTest`, "does not show the cancel button"**: mutated `orders/show.blade.php`'s
  `@if($order->status === 'pending_payment')` to `@if(true)` → test failed with
  `Expected not to see text [Anuluj zamówienie]... but it was found` — exact right message.
- **`OrderCancellationTest`, `cancelled_at` stays NULL**: mutated `OrderService::cancel()` to set
  `cancelled_at` BEFORE `transitionTo()` instead of after → `expect($order->cancelled_at)->toBeNull()`
  failed with a real Carbon object dump — proves the assertion is reading a genuine, not tautological,
  side effect of the bug.
- **Both `OrderCancellationTest` assertions at once (500 body, `cancelled_at`, zero emails)**: mutated
  `EmailService::sendFromTemplate()` to add `EmailTemplate::withoutGlobalScope('organization')` (i.e.
  temporarily FIXED the bug) → the "500 SERVER ERROR" assertion failed first (page redirected cleanly
  instead), proving the whole assertion chain is load-bearing on the real bug, not coincidental.
- **`OrderLifecycleEmailTest` step 1/2 (`EmailSend::count()->toBe(0)` after fake-pay)**: mutated
  `FakePaymentController` to also `event(new OrderPaid($order))` → test failed at an EARLIER assertion
  (`Dziękujemy za zamówienie!` never rendered) because `OrderPaidNotification` hits the SAME
  EmailTemplate bug and 500s the checkout-return redirect itself — still a caught mutation, just
  upstream of where I expected; left as evidence the compounding bug is real everywhere it's exercised.
- **`OrderLifecycleEmailTest` step 4 (no `order-confirmed` email exists)**: same
  `withoutGlobalScope('organization')` fix applied → `expect($confirmedEmail)->toBeNull()` failed with
  a REAL sent `EmailSend` row dumped in full (status=`sent`, real rendered Polish subject/body,
  `error_message` null) — direct proof the assertion is reading genuine behavior, not a tautology.
- **`OrderLifecycleEmailTest` step 6 (final `EmailSend::count()->toBe(0)`)**: mutated
  `OrderStatusStateMachine::afterTransitionHooks()` to add an `'in_progress'` hook that creates a dummy
  `EmailSend` row → `Failed asserting that 1 is identical to 0` — confirms the gap-documenting
  assertion is real, not dead code.

All mutations reverted; `git diff --stat` on every touched source file confirmed clean before finishing.

## Suite-level confirmation

Full `tests/Browser` suite (7 files, 9 tests incl. these 2 — also found `DoubleBookingTest`, not
authored in this task) passes in ~31s. Full default suite (`php artisan test`, Unit+Feature) still shows
exactly the same pre-existing baseline as before this work — 3 failed, 5 skipped, 1054 passed — i.e.
these two new Browser tests introduce zero regressions, and (bonus) the 2 `CustomerOrdersTest` failures
in that baseline are now explained, not just logged as "cancel flow, email-template lookup".
