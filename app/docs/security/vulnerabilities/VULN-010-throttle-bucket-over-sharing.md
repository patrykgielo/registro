# VULN-010: Throttle Bucket Over-Sharing (Auth + Checkout)

**Status**: FIXED (2026-08-17)
**Severity**: MEDIUM (availability — legitimate users blocked, not an exploit)
**Detected**: Production report — user got 429 on a plain login attempt
**Fixed Version**: `feature/auth-throttle-scope`

---

## Summary

Two unrelated routes were rate-limited too *coarsely*, not too loosely (the opposite of VULN-001):
a single numeric `throttle:N,1` bucket was shared by requests that have completely different abuse
profiles, so routine, non-abusive use exhausted a budget meant for actual attacks/spam.

Laravel's default (non-named) `throttle` middleware key is **`sha1($route->getDomain().'|'.$request->ip())`**
— domain + IP, **not** per-route-URI (`vendor/laravel/framework/.../Routing/Middleware/ThrottleRequests.php::resolveRequestSignature()`).
Every route sharing one `Route::middleware([..., 'throttle:5,1'])->group()` on the same subdomain
therefore shares one counter, regardless of HTTP method or what the route actually does.

---

## Finding 1 — Auth routes (`routes/web.php`)

`Auth::routes(['login' => false, 'register' => false])` registered 7 routes — `POST /logout`,
`GET/POST /password/reset`, `GET/POST /password/email`... `GET/POST /password/confirm` — plus a
manually-added `POST /login`, all inside one `throttle:5,1` group. A routine password-recovery
flow (view the form, submit an email, open the emailed link) alone spent 3 of the 5 slots — on top
of anything already spent on login attempts — so a user who also mistyped their password once or
twice hit 429 doing nothing abnormal.

`Illuminate\Foundation\Auth\ThrottlesLogins` (pulled into `LoginController` via `AuthenticatesUsers`)
already throttles login **precisely** — 5 failed attempts per **email+IP** per minute, counting only
failures — so the shared IP-wide bucket was redundant defense-in-depth for `POST /login` and pure
liability for every GET/render route sharing it.

### Fix

Split by what each route actually does, each with its own bucket (`throttle:N,1,prefix`):

| Route | Method | Before | After | Why |
|---|---|---|---|---|
| `/login` | GET | none | none | unchanged — pure render |
| `/login` | POST | `throttle:5,1` (shared) | `throttle:5,1,login` | brute-force backstop, own bucket; `ThrottlesLogins` remains the real per-account defense |
| `/logout` | POST | `throttle:5,1` (shared) | none | requires an existing session already (`auth` middleware); nothing to brute-force |
| `/password/reset` | GET | `throttle:5,1` (shared) | none | pure render |
| `/password/reset/{token}` | GET | `throttle:5,1` (shared) | none | pure render, no DB lookup |
| `/password/email` | POST | `throttle:5,1` (shared) | `throttle:3,1,password-email` | stricter — abuse vector is inbox spam against a **third party**, independent of the broker's own 60s per-account cooldown (`config('auth.passwords.users.throttle')`) |
| `/password/reset` | POST | `throttle:5,1` (shared) | `throttle:5,1,password-reset` | own bucket — the one place a leaked/guessed token could be brute-forced |
| `/password/confirm` | GET | `throttle:5,1` (shared) | none | pure render, requires `auth` already |
| `/password/confirm` | POST | `throttle:5,1` (shared) | `throttle:5,1,password-confirm` | own bucket — re-checks an already-authenticated user's password |

Regression test: `tests/Feature/Auth/AuthThrottleScopeTest.php` — pins the routine recovery flow
not touching the login bucket, GET routes never throttled, `POST /login` still capped, `POST
/password/email` has its own stricter cap, and — critically — that `ThrottlesLogins`'s own
per-account lockout is unaffected (isolated by disabling `ThrottleRequests` and checking the
lockout takes a different error branch than a plain failed attempt).

---

## Finding 2 — Checkout submit (`POST /koszyk/zamowienie`)

`throttle:10,1` counted **every** POST, including ones that never created an `Order` — a failed
Laravel/FormRequest validation redirect is a normal `302`, indistinguishable by status code from a
successful checkout's `302` to the payment gateway. `SubmitCheckoutRequest` validates 15+ fields for
a business customer (PESEL/NIP/REGON checksums, full invoice address, three consent checkboxes), so
a customer correcting typos across multiple submits could exhaust the budget without ever placing
an order — reproduced from production: a user hit 429 on this route with **zero** new rows in
`orders`.

**Decision**: the actual abuse vector is order *creation* — it briefly holds inventory and (for
online settlement) calls the P24 gateway — and that is already bounded by equipment availability
(`RentalUnavailableException`). Failed validation is signal that the form is hard, not that the
route is under attack, which is the opposite of the login case (repeated failed logins *are* an
attack signal) — so this is deliberately **not** the same fix pattern as Finding 1.

### Fix

Named rate limiter `checkout-submit` (`AppServiceProvider::boot()`), keyed by user id, 10/min,
using `Limit::after()` to only `hit()` when `CheckoutController::submit()` actually created an
`Order` — marked via `request()->attributes->set('checkout_order_created', true)` right after
`CartService::convertToOrder()` succeeds, regardless of what happens downstream (a P24 registration
failure still cancels/compensates the order, but the resource-consuming creation already happened,
so it still counts).

**Implementation gotcha**: the marker must be set on `request()` (the container-bound
`Illuminate\Http\Request` singleton the `RateLimiter::for()` closure closes over), **not** on the
`SubmitCheckoutRequest $request` injected into the controller method — `FormRequestServiceProvider`
builds the latter via `Request::createFrom($app['request'], $request)`, which snapshots attributes
into a **brand-new** `ParameterBag` (`Illuminate\Http\Request::initialize()`). Setting it on the
injected `$request` is invisible to the limiter and silently never throttles anything — caught by
`CheckoutSubmitThrottleTest::test_successful_order_creations_are_still_rate_limited` failing with
"expected 429, got 302" on the 11th successful attempt during development.

| Route | Before | After |
|---|---|---|
| `POST /koszyk/zamowienie` | `throttle:10,1` (all POSTs) | `throttle:checkout-submit` — 10/min per user, counts only attempts where an `Order` was actually created |

Regression test: `tests/Feature/Cart/CheckoutSubmitThrottleTest.php` — 15 failed validations never
exhaust the bucket; 10 real order-creating submissions still hit the cap (11th is `429`); a P24
failure that creates-then-cancels an order still counts as 1 of the 10.

---

## Related

- VULN-001 (`missing-rate-limiting.md`) — the opposite failure mode (no throttle at all)
- `.claude/rules/security.md` — rate limiting section
