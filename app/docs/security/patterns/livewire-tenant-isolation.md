# Livewire Admin/Platform Tenant Isolation (VULN-003 Layer 6)

**Status**: FIXED
**Severity**: CRITICAL
**Detected**: 2026-07-04/05 (8 of 13 review domains, independent security review)
**Fixed**: 2026-07-05
**Branch**: `fix/livewire-admin-tenant-isolation`
**Related**: `app/docs/security/vulnerabilities/VULN-003-root-domain-tenant-bypass.md` (Layers 1-5 — this is the same root vulnerability class, applied to Livewire's own AJAX transport instead of full page loads)

---

## Problem

`POST /livewire/update` is the shared AJAX route Livewire itself registers
(`vendor/livewire/livewire/src/Mechanisms/HandleRequests/HandleRequests.php`) with only the
base `web` middleware group. `AdminPanelProvider` applies `ResolveTenant::class` +
`RequireTenant::class` only to the admin panel's own page-load routes — never to this shared
Livewire route. Almost all real interaction inside `/admin` (table filters/sort/pagination, form
saves, record creation, edits) happens via Livewire AJAX, not fresh page loads.

`ResolveTenant::handle()` writes `session()->put('tenant_id', $tenant->id)` on **every**
successful subdomain resolution — including an anonymous, unauthenticated visit — before any
`canAccessTenant()` authorization check. `TenantFeature::currentTenant()`'s 3rd fallback branch
reads that session key. Net effect: a staff/admin user with an open Org A `/admin` tab whose
browser merely loaded Org B's public site in an unrelated tab (or an embed, no login required)
had `session('tenant_id')` silently overwritten to Org B. The next ordinary Livewire interaction
in the still-open Org A tab — a table filter, a save — resolved Org B's tenant instead, with
**zero re-authorization**: cross-tenant read (Org B's PII rendered into the Org A admin UI) and
cross-tenant write (new records auto-assigned `organization_id = Org B` via
`BelongsToOrganization`'s `creating` hook) both possible.

## Constraints

- Both panels are **path-based**, not domain-based, at the Filament routing level (`->path('admin')`
  / `->path('platform')`). `/admin` requires a resolved tenant (subdomain); `/platform` has no
  tenant concept at all (super-admin only, gated by `EnsureSuperAdmin`) and must never be forced
  through `RequireTenant`.
- `Livewire::setUpdateRoute()` registers **one** route for the whole application — not natively
  panel-scoped. Registering two competing custom update routes doesn't work either: Livewire's
  `findUpdateRoute()` (used to embed the JS payload URL at page-render time) just returns the
  first non-default named route it finds in the entire route table, with no awareness of which
  panel is currently rendering — it can't correctly pick "the right one" per page.
- Grep-confirmed: no `App\Livewire\*` components exist anywhere outside Filament's own two
  panels — the only two consumers of `/livewire/update` are `admin` (tenant-aware) and
  `platform` (tenant-less).

## Research: how Livewire 3.8 actually solves this class of problem

Livewire ships a mechanism built for **exactly** this problem:
`Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware`. Its default allow-list already
contains `Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful`,
`Laravel\Jetstream\Http\Middleware\AuthenticateSession`, and Laravel's own `Authenticate` /
`Authorize` — i.e. Livewire's authors already anticipated "some middleware must re-run on every
AJAX update, not just the original page load," for the exact same class of problem (session-based
auth/tenancy state that can go stale between the initial mount and a later AJAX call).

Mechanism, read from `vendor/livewire/livewire/src/Mechanisms/PersistentMiddleware/PersistentMiddleware.php`:

1. On `dehydrate` (i.e. every time a component is rendered — including the very first, full-page
   mount), Livewire stores `memo.path` and `memo.method` in the component's snapshot: the URL path
   and HTTP method of the request that **originally mounted** the component. This snapshot is
   later checksum-verified (HMAC'd with `APP_KEY`) — the client cannot forge or tamper with this
   value; any modification invalidates the whole snapshot before our code ever runs.
2. On every subsequent `/livewire/update` call, after checksum verification (`on('snapshot-verified', ...)`),
   Livewire builds a **fake request**: a PHP-level `clone` of the real, current request (same Host
   header, same cookies, same already-started session object — verified empirically, see below),
   with only `REQUEST_URI`/`REQUEST_METHOD` swapped to the original mount path/method.
3. It resolves which **route** that fake path/method would have matched, gathers *that route's*
   configured middleware, filters it down to whatever is in `PersistentMiddleware`'s allow-list,
   and actually executes those middleware against the fake request via a real
   `Illuminate\Pipeline\Pipeline`. A `redirect()` result is converted to an aborted
   `HttpResponseException` (terminates the real update request with that redirect); an
   `abort()`/thrown exception propagates out normally and becomes the real HTTP response.

This is a first-class, documented Livewire extension point (`Livewire::addPersistentMiddleware()`),
not an internal we're relying on by accident.

**Filament core already uses this exact mechanism today**, independent of this fix — confirmed by
reading `vendor/filament/filament/src/FilamentServiceProvider.php:105-113`:
`Livewire::addPersistentMiddleware([Authenticate::class, DisableBladeIconComponents::class,
DispatchServingFilamentEvent::class, IdentifyPageConfiguration::class,
IdentifyResourceConfiguration::class, IdentifyTenant::class, SetUpPanel::class])` runs
unconditionally, app-wide, for every panel. Two of those are worth calling out precisely, since
they clarify exactly which gap is old vs. which one this PR closes:

- `Filament\Http\Middleware\Authenticate` (already persistent) re-checks `Filament::auth()->check()`
  and `$user->canAccessPanel($panel)` on **every** Livewire update, for both panels — so an
  unauthenticated/anonymous replay of a captured snapshot was **already** rejected before this fix
  existed. `canAccessPanel()` for the admin panel checks role + "belongs to at least one
  organization" — it does **not** check *which* organization. This fix's entire job is the missing
  piece: re-deriving *which* tenant this specific request is for (from the real Host header) and
  re-running `canAccessTenant()` against it — that check did not exist anywhere in the persistent
  path before this PR.
- `Filament\Http\Middleware\IdentifyTenant` (already persistent) is Filament's own **native**
  multi-tenancy tenant-identification middleware. It no-ops here (`$panel->hasTenancy()` is
  `false` — `AdminPanelProvider` never calls `->tenant()`) — confirmed by reading its source, it
  returns `$next($request)` immediately when `hasTenancy()` is false. It does not overlap with or
  duplicate `ResolveTenant`/`RequireTenant` in any way; both systems coexist because this app's
  subdomain+session tenancy entirely predates and bypasses Filament's native tenancy feature.
  Notably, `IdentifyTenant`'s own source code comment says: *"Ensure tenant-aware middleware uses
  `isPersistent: true` for Livewire AJAX enforcement"* — i.e. Filament's own maintainers flag this
  exact class of gap. Panels can pass `isPersistent: true` directly to `->middleware()`/
  `->authMiddleware()`/`->tenantMiddleware()` as sugar over the same
  `Livewire::addPersistentMiddleware()` call. This fix does not use that sugar — `AdminPanelProvider`'s
  `->middleware([...])` array bundles many unrelated middleware (`StartSession`, `VerifyCsrfToken`,
  etc.) that were never audited for safe re-execution against a replayed request; calling
  `Livewire::addPersistentMiddleware()` directly in `AppServiceProvider` keeps the persistent set
  limited to exactly the two classes this fix needs, reducing risk.

### Why this maps perfectly onto our two panels, with zero extra code

- A component mounted under `/admin/*` was mounted through a route that (via `AdminPanelProvider`'s
  `->middleware([...])`) already carries `ResolveTenant::class` + `RequireTenant::class`. Replaying
  those two middleware, with the fake request's **real** Host header (the actual subdomain the
  open tab is on — same-origin AJAX, cannot be a different host than the page that issued it) and
  the **real**, current session/auth state, re-derives the tenant fresh from the Host every single
  time and re-runs `canAccessTenant()` — then overwrites `session('tenant_id')` with the correct
  value, before Filament resolves any `BelongsToOrganization`-scoped query for that update.
- A component mounted under `/platform/*` was mounted through a route whose middleware list never
  includes `ResolveTenant`/`RequireTenant` (`PlatformPanelProvider` never registers them) — the
  allow-list filter yields an empty middleware set, `applyPersistentMiddleware()` no-ops, and
  platform Livewire traffic is completely unaffected.

No new routes, no Referer/Origin header inspection, no snapshot-decoding logic of our own to write
or maintain — the path/panel signal Livewire already captures is exactly what's needed, and it's
tamper-proof by construction (part of the checksummed snapshot).

## Fix

`app/Providers/AppServiceProvider.php` (`boot()` → `registerLivewireTenantIsolation()`):

```php
\Livewire\Livewire::addPersistentMiddleware([
    \App\Http\Middleware\ResolveTenant::class,
    \App\Http\Middleware\RequireTenant::class,
]);
```

That is the entire runtime change. No route files, no new middleware classes, no panel provider
changes.

### Why not a custom panel-aware update route/middleware (the originally-proposed surgical option)

A hand-rolled "decode the snapshot ourselves, figure out the panel, apply the right middleware"
middleware would have to reimplement — with more custom surface area and more chances to get it
wrong — exactly what `PersistentMiddleware` already does: extract `memo.path` post-checksum-verification,
build an equivalent fake request, match a route, gather + filter its middleware, execute it, and
correctly propagate aborts/redirects as the real response. Since Livewire already ships this,
duplicating it would only add risk for no benefit. This is why the fix ended up smaller than the
originally-scoped "custom route" plan.

### Why not Filament's native multi-tenancy (`->tenant(Organization::class)`)

Out of scope entirely for this fix — would require re-architecting URL structure, `getTenant()`
semantics on every Resource, and backward compatibility with the existing subdomain+session
resolution used everywhere else in the app (public pages, mail, SMS, etc.). The persistent-middleware
fix is fully additive and touches none of that.

### Why the session write itself (`ResolveTenant`) was left unchanged

Removing the unconditional `session()->put('tenant_id', ...)` write for anonymous visitors was
considered and rejected: Livewire's replay mechanism now **overwrites** that value with the
correct, Host-derived tenant on every admin-panel Livewire update anyway, before any scoped query
runs — the poisoned value becomes harmless for the one consumer (`/livewire/update`) that used to
trust it blindly. Removing the write would also need to preserve `TenantFeature::currentTenant()`'s
session fallback for other legitimate (non-Livewire) callers documented elsewhere (e.g. mail/SMS
event listeners running outside an HTTP request context) — a larger, riskier change for no
additional protection given the replay fix above.

## Guarantees

**Important — this fix has exactly ONE independent layer of defense on this path, not two.**
It would be natural to assume `BelongsToOrganization`'s Layer 2 fail-closed global scope (VULN-003
Layer 2 — see the main vulnerability doc) provides a second, independent backstop here the way it
does for ordinary page-load requests. It does not, and this was verified empirically (not just
read), not assumed: `PersistentMiddleware`'s fake request is a **genuinely independent PHP clone**
(`Symfony\Component\HttpFoundation\Request::__clone()` explicitly re-clones `$attributes` into a
new `ParameterBag`). A diagnostic test dispatching a real, successful Livewire update through the
fix confirmed `app('request')->attributes->get('tenant')` and `get('tenant_resolution_attempted')`
on the REAL, container-bound request remain `null` throughout — `ResolveTenant` setting those
attributes on the fake request during replay never reaches the real request Laravel/Filament
actually uses to resolve `TenantFeature::currentTenant()`'s 2nd branch or Layer 2's fail-closed
check. **Layer 2's backstop is structurally inert for this path — it can never see the signal.**
The only two things that actually protect this path are:

1. **The shared session object.** Unlike `$attributes`, Symfony's `Request::__clone()` does not
   touch Laravel's custom `$session` property, so PHP's default shallow clone copies the *same*
   session store reference — `ResolveTenant`'s `session()->put('tenant_id', ...)` write during
   replay genuinely reaches the real session, which is what `TenantFeature::currentTenant()`'s 3rd
   (session) branch reads for the real update logic that follows.
2. **`RequireTenant`'s abort being genuinely fatal.** When replay resolves no tenant (or
   `canAccessTenant()` fails), the thrown `NotFoundHttpException`/redirect propagates all the way
   out of the real `/livewire/update` request and becomes its actual HTTP response — there is no
   second chance for an unscoped query to run after that, but also no defense-in-depth if this
   propagation were ever accidentally swallowed (e.g. by a future `try/catch` added around the
   `snapshot-verified` listeners) — worth remembering when reviewing changes near this call path.

**Protects against:**
- Cross-tenant **read** via any Livewire-driven interaction on an already-open `/admin` tab
  (table loads, filters, sorts, pagination, widget refreshes) when the session's `tenant_id` has
  been poisoned by any other request on the same browser (different tab, embed, or even an
  anonymous visit) — via mechanism (1) above.
- Cross-tenant **write** via the identical mechanism: `BelongsToOrganization`'s `creating` hook
  reads the same `TenantFeature::currentTenant()` session fallback that the read-path scope does,
  and that call only ever sees the session value *after* the replay has corrected it.
- Re-validates `canAccessTenant()` on every single Livewire update (not just the original page
  load) — if a user's org membership were revoked mid-session, the next Livewire interaction
  would hard-404 via mechanism (2) above, not silently continue on stale authorization from the
  initial page load. Verified directly: replaying a validly checksum-signed snapshot (captured
  from a real admin mount) against the root domain — where `ResolveTenant` resolves no tenant —
  returns a genuine 404 (`NotFoundHttpException`), not a silent unscoped continuation. See
  `test_replaying_a_valid_snapshot_where_no_tenant_can_be_resolved_hard_aborts` in the test file.
- Anonymous/unauthenticated snapshot replay: **already** covered before this fix, by Filament core's
  own pre-existing `Authenticate::class` persistent middleware registration (see Research above) —
  not a guarantee this PR adds, listed here only so the full picture on this path is in one place.
- `/platform` traffic: proven unaffected (see Tests below) — zero coupling to this fix.

**Does NOT protect against / residual limitations:**
- `POST /livewire/upload-file` (Filament file uploads) is a completely separate Livewire route
  (`Livewire\Features\SupportFileUploads\SupportFileUploads` registers it directly against
  `FileUploadController`, bypassing `handleUpdate()`/the snapshot pipeline entirely) — it never
  fires `PersistentMiddleware`'s `snapshot-verified` hook, so this fix structurally cannot extend
  to it. Not currently exploitable (no tenant-scoping logic lives in that path today), but a
  future feature that ties org-scoped logic to file uploads must not assume this fix covers it.
- This does not (and cannot) change *which* tenant a legitimately-open admin tab belongs to — if
  a staff user is legitimately authorized for and currently viewing Org A's subdomain, this fix
  cannot distinguish "the user's own poisoned session" from "the user's own correct session"; it
  simply always re-derives from the real Host header, which for a genuine same-origin AJAX call
  can only ever be the host of the page that's open. It does not protect against a fully
  server-side forged request with a spoofed Host header and a stolen, valid session cookie — that
  is a different threat (session/cookie theft), out of scope here and already mitigated by
  standard session security (`SESSION_ENCRYPT`, secure cookies, CSRF).
- The replay only re-applies middleware present in `PersistentMiddleware`'s allow-list. Any
  *other* admin-panel middleware that should also be "live" on every Livewire update (e.g. a
  future maintenance-mode check) must be explicitly added to the same
  `addPersistentMiddleware()` call, or it will silently only apply at the original page load.
- If the matched route for the original mount path can't be resolved (e.g. route
  caching/renaming edge cases), the replay silently yields no middleware and this protection is a
  no-op for that one request — no different from today's behavior, not a regression, but not a
  hard guarantee either. No such gap was found in this codebase's current route set.

## Tests

`tests/Feature/Security/LivewireAdminTenantIsolationTest.php` — dispatches a **real** HTTP
`POST /livewire/update` with a genuine, checksum-signed snapshot extracted from an actual page
render (NOT `Livewire::test()`, which calls `app('livewire')->update()` directly without an HTTP
route and therefore never triggers `PersistentMiddleware`'s `isLivewireRoute()` guard — it would
silently skip the very mechanism this fix depends on and give a false-positive pass).

- `test_livewire_update_ignores_poisoned_session_and_serves_only_the_real_hosts_tenant` — core
  regression: reproduces the exact attack chain (admin authorized for Org A only, session
  poisoned to Org B, real Livewire table update) and asserts Org A's data is served, Org B's is
  not, and the session key is corrected.
- `test_session_is_corrected_before_any_component_hydration_runs` — asserts the session
  correction side effect directly (proves the write-path guarantee shares the same primitive).
- `test_livewire_update_works_normally_on_real_tenant_subdomain_without_poisoning` — positive
  control, no regression to legitimate same-tenant usage.
- `test_replaying_a_valid_snapshot_where_no_tenant_can_be_resolved_hard_aborts` — proves the sole
  independent backstop on this path (see Guarantees above) is real: a validly checksum-signed
  snapshot replayed against a host with no resolvable tenant (root domain) gets a genuine 404, not
  a silent unscoped continuation.
- `test_platform_livewire_update_has_no_tenant_requirement` — proves `/platform` Livewire traffic
  is unaffected (would 404 if `RequireTenant` were ever incorrectly replayed there).

**Empirically verified both directions** (manually reverted `registerLivewireTenantIsolation()`
in `AppServiceProvider::boot()` and re-ran this suite): the two security tests fail pre-fix with
`assertStringContainsString('Org A Visible Service', ...)` failing (Org B's secret service
appears instead) and the session-correction assertion failing (`2` !== `1`, i.e. the session
still held Org B's id) — confirming the tests reproduce the real vulnerability rather than a
tautology. The positive-control and platform tests pass unchanged in both states, confirming
they're a true no-regression baseline rather than accidentally exercising the fix too.

Full suite (`docker compose exec -T app php artisan test`): 784 passed, 3 pre-existing failures
(unrelated: `CustomerOrdersTest` ×2 — missing `order-cancelled` email template fixture,
`TenantFeatureTest` ×1 — booking wizard step count, both predate this branch), 5 skipped — matches
the known `develop` baseline plus this suite's 4 new tests, i.e. zero regressions.
