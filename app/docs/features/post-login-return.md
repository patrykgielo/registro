# Post-Login/Registration Return-to-Origin

**Implemented:** 2026-08-17 (`feature/post-login-return`)

---

## Overview

A customer clicking "Zaloguj się, aby zarezerwować" on a service card used to
land on `appointments.index` after logging in — a route hard-404s for a
rental-only tenant (`RequireTenant`), and is unrelated to the page they came
from either way. Two independent causes, fixed together:

1. **The interception mechanism existed and was dead.** Laravel's own
   `AuthenticatesUsers::sendLoginResponse()` (vendor `laravel/ui`) calls
   `$this->authenticated()` and returns immediately if it returns anything —
   `LoginController::authenticated()` always returned a redirect, so
   `redirect()->intended(...)` was unreachable. Laravel's `redirect()->guest()`
   (fired by the framework's own `AuthenticationException` handling) correctly
   wrote `session('url.intended')` on every intercepted request — nothing ever
   read it back.
2. **A voluntary login-link click never wrote anything.** The 9 `<a
   href="{{ route('login') }}">` links across the app (`services/show.blade.php`
   and friends) don't throw an `AuthenticationException`, so cause #1's
   mechanism never fires for them at all.

## Mechanism

`App\Support\Auth\IntendedDestination` — three static operations, all keyed
under Laravel's own `url.intended` session key (piggybacking cause #1's
mechanism deliberately, not a separate key) plus a second key,
`url.intended_at`, added by this feature only:

- **`capture(Request $request)`** — called from
  `LoginController::showLoginForm()` and
  `RegisterController::showRegistrationForm()`. Three outcomes:
  - previous route is our own auth chain (`login`, `customer.register`,
    `password.*`) → **keep** the existing value, just refresh the timestamp.
    Needed for `card → /login → "Nie mam konta" → /customer/register`: at
    `/customer/register`, `previousRoute()` is `'login'` itself — naively
    treating that as "not a candidate" would have discarded the card URL
    right before the form that needs it.
  - previous URL is safe (same origin, not on the route/path denylist) →
    **overwrite** both keys.
  - no value, or unsafe → **discard** both keys.
- **`consume(Request $request): ?string`** — one-shot read (`pull` semantics,
  same as `Redirector::intended()`). Rejects and clears if: no value, no
  timestamp, timestamp older than 60 minutes, foreign origin, or denylisted
  path — re-checked independently of `capture()`'s own check, because the
  session state consumed here may not have gone through `capture()` at all
  (see "Why the TTL doesn't break interception" below).
- **`discard(Request $request)`** — called explicitly on every
  `LoginController::authenticated()` branch except the customer one. Filament
  reads the same `url.intended` key; a value left over from an earlier
  customer session would otherwise bounce an admin/staff login somewhere
  random.

### The pitfall: never `url()->previous()`

`Illuminate\Routing\UrlGenerator::previous()` checks the `Referer` **header**
before falling back to session. A page on another site linking to our
`/login` sends `Referer: https://evil.example/` and would resurrect the
open-redirect surface this whole mechanism exists to close. `capture()`/
`consume()` use `$request->session()->previousUrl()` /
`Session::previousUrl()` only — both are populated exclusively by our own
`StartSession` middleware from a prior request on **this** server, never from
anything the client sends. `PostLoginRedirectTest::
test_referer_header_is_never_used_as_a_destination` pins this: a forged
`Referer` on a session with no real prior navigation is never followed.

### Why the TTL doesn't break interception

`consume()` requires `url.intended_at`, but Laravel's own `redirect()->guest()`
(cause #1) never sets it — only `capture()` does. This isn't a gap: whenever
an unauthenticated visitor is intercepted on a protected **GET** route (e.g.
`/koszyk`), `StartSession`'s `storeCurrentUrl()` still runs for that
now-redirected request (Laravel's routing `Pipeline` converts the thrown
`AuthenticationException` into a response *at the failing middleware*, so
outer middleware — including `StartSession` — see a normal response, not a
propagating exception). That sets `session('_previous.route')` to the
intercepted route. The browser then follows the redirect to `/login`, which
calls `capture()` — its "safe candidate" branch independently recomputes the
same URL from `previousUrl()`/`previousRoute()` and re-writes `url.intended`
**with** a fresh timestamp. The two mechanisms converge on every GET-route
interception; only a POST-intercepted request (not exercised by this feature
or its tests) would rely on the framework's un-stamped value alone, and stays
exactly as un-hardened as vanilla Laravel already was.

### What "60 minutes" actually bounds

`url.intended_at` does two DIFFERENT jobs that read like one field:

1. **Disarms values that predate this mechanism** — no timestamp at all means
   untrusted, full stop, regardless of how safe the URL itself looks.
2. **Bounds how long a genuinely-captured value stays followable** — but this
   is 60 minutes since the value was **last touched by an auth-chain page
   view**, not 60 minutes since the original page was left. `capture()`'s
   "keep existing, refresh timestamp" branch (the one that handles
   `card → /login → /customer/register` and `login → password/reset →
   login`) re-stamps on every bounce. A visitor circling those pages for 3
   hours straight never lets the value go stale — each bounce both
   re-validates (see next section) and re-stamps it. The clock only ever
   measures "how long has it been sitting untouched," never "how long ago
   did the user leave the original page."

### `capture()`'s auth-chain branch re-validates, not just re-stamps

The "keep existing" branch doesn't blindly refresh the timestamp on whatever
is already in `url.intended` — it re-runs the same origin/path safety check
`consume()` uses (`isSafeUrl()`) and calls `discard()` if it fails. This is
deliberate defense-in-depth, mirroring `isSameOrigin()` already being checked
at both write time (`capture()`) and read time (`consume()`): without it,
`consume()` would be the ONLY place a stored value is ever re-checked, and a
future change that treated `consume()`'s check as "redundant, capture()
already validated this" would open an open-redirect. `consume()`'s own
docblock says so explicitly — it is the authoritative gate, and stays one
even though a second check now exists.

`isSafePath()`'s path-prefix denylist check is case-insensitive
(`strtolower()`) — `/Admin/...` must not slip past a denylist written in
lowercase. (Origin comparison itself needs no separate case-insensitive
step: `Request::getHost()` — the basis for both `getSchemeAndHttpHost()`,
read side, and `fullUrl()`/`previousUrl()`, write side — always lowercases,
per RFC 952/2181, so a stored value's host is never differently-cased than
the live request's own host to begin with. Only path segments lack that
framework-wide normalization.)

### `isSameOrigin()` is a string-prefix comparison, NEVER `parse_url()`

An earlier version of this method extracted the host with
`parse_url($url, PHP_URL_HOST)` and compared it against
`$request->getHost()`. That is wrong, and was fixed same-day (2026-08-17)
after code review: PHP's `parse_url()` and the WHATWG URL Standard — what
every real browser actually implements — **disagree** on how to parse an
authority containing a raw backslash:

```php
$u = "http://evil.example\@registro.local/admin/x";

parse_url($u, PHP_URL_HOST);  // "registro.local"  — PHP: "\" isn't special,
                               // so everything up to the LAST "@" is userinfo
// A BROWSER (WHATWG, special schemes): "\" in the authority is normalized to
// "/" BEFORE parsing at all. The SAME string resolves to
// host = "evil.example", path = "/@registro.local/admin/x".
```

A check built on `parse_url()`'s opinion of a string is answering the wrong
question. The `Location:` header this class (or `IntendedDestination::
consume()`) emits is interpreted by the BROWSER, not by PHP — so the only
question that can't be re-litigated by a different parser is "does this
string start with literally our own origin, byte for byte":

```php
$origin = $request->getSchemeAndHttpHost();   // e.g. "https://registro.local"

$url === $origin || str_starts_with($url, $origin.'/')
```

No parsing happens at all, so there is nothing left for a second parser to
disagree about. `isSameOrigin()` additionally rejects any candidate
containing a raw `\` or a literal `..` path segment OUTRIGHT, before this
comparison even runs — fail-closed, same posture as the "no timestamp" and
"foreign origin" decisions elsewhere in this class. The `..` case matters
independently of the backslash trick: a browser normalizes
`/admin/../platform/x` to `/platform/x`, so a check that only looks at the
literal, un-normalized string can be fooled into denylisting (or allowlisting)
the wrong path even with a perfectly correct origin comparison.
`PostLoginRedirectTest::test_dot_segment_path_traversal_is_rejected`
deliberately targets a path NOT already on `DENYLISTED_PATH_PREFIXES`
(`/uslugi/../admin/dashboard`) to prove the dedicated check closes this, not
the pre-existing prefix denylist coincidentally catching it.

**Bare origin, no path, is a legitimate target.**
`getSchemeAndHttpHost()` never has a trailing slash, so a candidate EXACTLY
equal to the origin (e.g. a plain `GET /` request's `fullUrl()`) is checked
for explicitly — `pathWithinOrigin()` resolves it to `/` rather than treating
"no path" as "no match". `PostLoginRedirectTest::
test_url_equal_to_bare_origin_is_honored` covers the customer-flow side of
this; `AdminPanelLoginResponseTest::test_bare_origin_intended_falls_back_to_panel_home`
covers the admin-panel side (still falls back there, but for the SEPARATE
reason that `/` doesn't match the panel's own `/admin` path prefix — not
because the origin comparison rejected it).

**Sanity-checked by mutation** (same method used throughout this feature):
temporarily reverted `isSameOrigin()` to the old `parse_url()`-based
comparison and re-ran the backslash test for both consumers.
`PostLoginRedirectTest::test_backslash_authority_trick_is_rejected` went
red exactly as expected — `assertRedirect(route('home'))` failed with the
malicious URL as the actual redirect target, proving a browser really would
have been sent to `evil.example`. Worth recording honestly:
`AdminPanelLoginResponseTest::test_backslash_authority_trick_falls_back_to_panel_home`
stayed GREEN even with the shared check broken — not because
`belongsToCurrentPanel()` has its own independent defense, but by
coincidence: `pathWithinOrigin()`'s naive `substr()` against the (reverted,
wrong) origin length happens to land the resulting "path" mid-string for
THIS specific payload, and that garbage string doesn't happen to start with
`/admin` either. That is NOT a guarantee for every possible payload shape,
which is exactly why the fix lives in the ONE shared `isSameOrigin()` rather
than being left to accidental string-length coincidences downstream.

### Denylists (used at both capture time and consume time)

- **Route name** (`previousRoute()`): `login`, `logout`, `customer.register`,
  `password.*`, `filament.*`.
- **Path prefix**: `/admin`, `/platform`, `/livewire`, `/api`, `/webhooks`.

The auth-chain subset used for `capture()`'s "keep existing" branch is
narrower — only `login`, `customer.register`, `password.*` — deliberately
excluding `logout`/`filament.*`: bouncing off *those* should discard, not
preserve, whatever was captured earlier in a possibly-different browser
session on a shared machine.

## `App\Support\Auth\CustomerLandingUrl`

Default landing page for the customer role, used only when `consume()`
returns null.

```
tenant = $request->attributes->get('tenant')   // NEVER TenantFeature::currentTenant()

no tenant:   user has an org  → TenantUrl::route($org, ...)
             no org           → route('home')
tenant set:  supportsAppointments() && isBookingEnabled() → appointments.index
             supportsRentals()                            → orders.index
             otherwise                                    → profile.index
```

`$request->attributes->get('tenant')` is read directly — **never**
`TenantFeature::currentTenant()`, which has a 3rd fallback branch reading
`session('tenant_id')` (written by `ResolveTenant` on every anonymous
subdomain visit). Using it here would let a customer who merely *browsed*
another tenant's subdomain earlier in the same browser session land on that
tenant's page after logging in on a completely different one — the VULN-003
class of bug. `PostLoginRedirectTest::test_poisoned_tenant_id_session_does_not_influence_landing`
pins this with two orgs of different `booking_type`, so a leak would be
observable as the wrong route.

When resolving the "no tenant, but user has one org" branch, the
`isBookingEnabled()` check is skipped (`checkBookingEnabled: false`) — that
method itself resolves the tenant via `TenantFeature::currentTenant()`, which
has no way to know about `$org` when it isn't the request's own tenant, and
would silently evaluate the wrong tenant's setting.

## `LoginController::authenticated()` — role table

| Branch | `url.intended` | Target |
|---|---|---|
| super-admin | discard | `/platform` |
| admin/staff, tenant subdomain | discard | `/admin` |
| admin/staff, root domain, has org | discard | tenant's admin URL |
| admin/staff, **no organization at all** | discard | `route('home')` |
| customer | consume | captured URL, or `CustomerLandingUrl::for()` |

The "admin/staff, no organization" branch is new — previously such a user
fell through to the customer branch and hit `appointments.index`, 404ing on
root domain (`RequireTenant`) exactly like the original bug report, just for
a different role.

`LoginController::redirectTo()` (the `AuthenticatesUsers::redirectPath()`
fallback used only when `authenticated()` returns falsy) is removed — it was
already unreachable before this change, since `authenticated()` has always
returned a `Response` on every branch.

## Admin panel: the Filament `LoginResponse` bind was dead

`AdminPanelProvider::register()` has bound a custom `App\Http\Responses\
LoginResponse` since before this feature, meant to always land an admin on
the panel instead of following `url.intended` (originally to avoid landing
on the maintenance page during a maintenance-mode "/" capture). Both files
imported the contract from the **wrong namespace** —
`Filament\Http\Responses\Auth\Contracts\LoginResponse`, which is Filament
**v3**'s path and does not exist at all in this app's v4. `SomeClass::class`
on a non-existent class/interface still compiles (it's just a string
literal), so:

- The bind silently registered under a container key nothing ever asks for.
  Filament's `Login::authenticate()` resolves the REAL v4 contract,
  `Filament\Auth\Http\Responses\Contracts\LoginResponse` — never bound here —
  so the **vendor default** ran instead: a bare
  `redirect()->intended(Filament::getUrl())`, unconditional, no panel-path
  check at all.
- Our own `App\Http\Responses\LoginResponse` also `implements` the same wrong
  interface, so the class itself was **unloadable** — `new
  App\Http\Responses\LoginResponse()` throws `Error: Interface
  "Filament\Http\Responses\Auth\Contracts\LoginResponse" not found`. It was
  fully dead code, never exercised, and would have fataled every single admin
  login had the bind ever actually worked before this fix.

**Why this became load-bearing for this feature specifically:** before
`IntendedDestination::capture()` existed, `url.intended` was written rarely
(only by Laravel's own interception mechanism, and only for whatever route
was actually being protected — never a customer-facing public page). After
this feature, `capture()` writes it on **every** visit to the public
`/login` page. A browser session that browsed the public site, hit `/login`,
then separately authenticated at `/admin/login` without ever completing the
public login would — once the bind was fixed to actually apply — be bounced
to that public page instead of the admin panel, via the vendor default's
unconditional `redirect()->intended(...)`. Fixing the bind's namespace
without also fixing what it does would have made this WORSE, not better.

**Both fixed together:** the namespace import in both files now points at
`Filament\Auth\Http\Responses\Contracts\LoginResponse` (the real v4
contract), and `App\Http\Responses\LoginResponse::toResponse()` now only
follows `url.intended` when its path falls under the CURRENT panel's own
path prefix (`Filament::getCurrentPanel()->getPath()`, e.g. `/admin`) AND its
origin matches the request — otherwise it falls back to `Filament::getUrl()`
(the panel's own home), same as before. A deep link into the panel itself
(e.g. an admin clicking `/admin/orders/123` from an email, intercepted by
`auth` middleware) is still honored; a public-site URL, a foreign origin, or
no value at all is not.

**Path alone is not enough** — `https://evil.example/admin/steal` looks
exactly like a legitimate panel deep link path-wise. The origin check runs
FIRST in `belongsToCurrentPanel()`, via `IntendedDestination::isSameOrigin()`
(made `public` specifically for this — one origin-comparison implementation
shared by both consumers, not a second copy). The panel path is then taken
from `IntendedDestination::pathWithinOrigin()`, never from `parse_url()` —
see the origin section above for why.
This matters even though only two validated writers exist today
(`capture()`, and Laravel's own `guest()->intended()` from a GET request's
server-side `fullUrl()`): should either ever be bypassed — e.g. a future
CSRF-exempt route sitting behind `auth` reopening the `Referer`-header
vector — the blast radius would reach ADMIN login, not just the customer
flow, without this check.
`AdminPanelLoginResponseTest::test_intended_with_a_panel_shaped_path_on_a_foreign_host_falls_back_to_panel_home`
pins this specifically (panel-shaped path, foreign origin → still rejected;
the test's own name is unchanged since renaming it isn't worth invalidating
the already-verified test suite over — see "Files" below for the same
constraint applied consistently).

`AdminPanelLoginResponseTest::
test_admin_panel_login_response_contract_resolves_to_the_custom_class` is
the regression test for the bind itself — it asserts the container resolves
the real v4 contract to our class, and fails immediately if the v3 namespace
is ever reintroduced in either file (verified by temporarily reintroducing
it during development: the test failed with "expected instance of
App\Http\Responses\LoginResponse, got Filament\Auth\Http\Responses\
LoginResponse").

**Both session keys are always cleared, regardless of branch.**
`toResponse()` peeks the value with `session()->get(IntendedDestination::
SESSION_KEY)` (not `pull()`) and then unconditionally calls
`IntendedDestination::discard($request)`, which clears BOTH `url.intended`
and `url.intended_at` — `IntendedDestination::SESSION_KEY` is `public`
specifically so this class references the same constant instead of a second
`'url.intended'` string literal. A bare `pull('url.intended')` alone (an
earlier version of this class did exactly that) would leave `url.intended_at`
orphaned: not a live vulnerability on its own (`consume()` re-validates origin
and path independently of the timestamp's age), but it silently breaks what
the timestamp is supposed to mean — a LATER, unrelated `capture()` could
pair a fresh URL with this STALE leftover timestamp, and the TTL would then
measure from the wrong moment entirely.
`AdminPanelLoginResponseTest::test_both_session_keys_are_cleared_when_intended_belongs_to_the_panel`
and its `_does_not_belong_to_the_panel` sibling pin this for both branches —
verified to fail (`assertNull(session('url.intended_at'))` failing with the
leftover timestamp) by temporarily reverting to the bare-`pull()` version
during development.

## Files

- `app/Support/Auth/IntendedDestination.php` (new)
- `app/Support/Auth/CustomerLandingUrl.php` (new)
- `app/Http/Controllers/Auth/LoginController.php` — `showLoginForm()` added
  (capture), `authenticated()` rewritten (discard/consume per role,
  `redirectTo()` removed)
- `app/Http/Controllers/Auth/RegisterController.php` —
  `showRegistrationForm()` (capture), `registered()` now returns a redirect
  (consume), dead `$redirectTo = '/'` property removed
- `app/Providers/Filament/AdminPanelProvider.php` — `LoginResponse` contract
  import fixed to the real Filament v4 namespace
- `app/Http/Responses/LoginResponse.php` — contract import fixed; body
  rewritten to only honor `url.intended` when it belongs to the current panel
- `tests/Feature/Auth/PostLoginRedirectTest.php` (new)
- `tests/Feature/Auth/AdminPanelLoginResponseTest.php` (new) — bind
  regression test + `LoginResponse` behavior
- `tests/Feature/Auth/SessionRegenerationTest.php` — assertion updated from
  the old hardcoded `'/'` fallback to the tenant's actual landing page
- `tests/Feature/Security/RootDomainTenantIsolationTest.php` — last test
  renamed and updated: a root-domain customer with no organization now lands
  on `route('home')` (200) instead of `appointments.index` (404). The
  security property the test guards — no cross-tenant data ever reaches this
  customer — is unchanged; `home` still renders no tenant-scoped content.
