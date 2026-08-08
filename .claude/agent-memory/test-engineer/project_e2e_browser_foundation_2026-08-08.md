---
name: project-e2e-browser-foundation-2026-08-08
description: E2E browser test foundation (Pest v4 + pest-plugin-browser) on feature/e2e-browser-tests — cookie bug fixed upstream by v4.3.0, host/tenant bug is still open upstream (pest#1734) and is now worked around app-side, NOT by patching vendor
metadata:
  type: project
---

Built the FIRST E2E browser test for Registro: `tests/Browser/SmokeTest.php` (admin login via
subdomain, Filament panel). Branch `feature/e2e-browser-tests`. Foundation pieces:
`tests/Pest.php` (`uses(TestCase::class, RefreshDatabase::class)->in('Browser')` — scoped only to
that dir, zero effect on classical PHPUnit tests), `phpunit.xml` `defaultTestSuite="Unit,Feature"`
+ new `Browser` testsuite (excluded from plain `php artisan test`; run explicitly with
`--testsuite=Browser`), `.env.testing` `SESSION_SECURE_COOKIE=false`, `.gitignore` entry for
`tests/Browser/Screenshots`.

## UPDATE 2026-08-08 — vendor patches are GONE, replaced with an app-side workaround

The original version of this memory (same date) documented **two vendor bugs patched directly in
`vendor/pestphp/pest-plugin-browser` v4.1.1** — both patches were, by design, non-durable (`vendor/`
is gitignored) and were explicitly flagged as a foreseeable landmine for the next `composer update`.
That landmine went off: the package was bumped 4.1.1 → 4.3.0 → 4.3.2, the unpatched vendor code came
back, and the Browser suite failed again (`Actual path [/] does not equal expected path [/admin]`).

**Bug 1 (cookies never decrypt past the first request) is FIXED UPSTREAM as of v4.3.0.**
`LaravelHttpServer::handleRequest()` now does
`$cookies = array_map(fn (RequestCookie $cookie): string => urldecode($cookie->getValue()), $request->getCookies());`
inline — exactly the fix this memory used to describe as a local patch. Nothing to do here anymore.
Do not re-patch it if it looks "wrong" again — check the installed version first.

**Bug 2 (Livewire persistent-middleware replay sees host=127.0.0.1) is STILL OPEN upstream.**
Tracked at https://github.com/pestphp/pest/issues/1734 (opened 2026-06-21, no maintainer response as
of 2026-08-08). Related, unmerged fix attempt: https://github.com/pestphp/pest-plugin-browser/pull/224.
`vendor/` is CLEAN — this is no longer worked around by editing vendor code at all.

### The fix now lives entirely in this app, not in vendor/

`App\Http\Middleware\Testing\PestBrowserHostBugWorkaround`
(`app/Http/Middleware/Testing/PestBrowserHostBugWorkaround.php`) — re-syncs the Symfony
`SERVER_NAME`/`HTTP_HOST` server-bag entries from the (already-correct) `Host` header before
anything else in the pipeline can clone/duplicate the request. Registered in `bootstrap/app.php` as
`$middleware->prepend(...)` (the application's truly GLOBAL middleware stack — runs before session,
routing, and Livewire's own middleware), guarded by `if (env('APP_ENV') === 'testing')`. Restricted
to hosts matching `config('app.domain')` and its subdomains — not a general-purpose Host rewriter.

Mechanism (full writeup in the class docblock): `LaravelHttpServer::handleRequest()` always builds
the Symfony request from a hardcoded `http://127.0.0.1:{port}` URL (`ServerManager::DEFAULT_HOST`),
so the SERVER bag is always `127.0.0.1` regardless of the real tenant subdomain visited. The vendor
code overlays the real `Host` header onto the HEADERS bag only (`headers->add($request->getHeaders())`)
— fine for most of Laravel (`Request::getHost()` reads headers first), but
`Symfony\Component\HttpFoundation\Request::duplicate($server: ...)` rebuilds `$dup->headers` FROM the
server bag, discarding that patch. `Livewire\Mechanisms\PersistentMiddleware::makeFakeRequest()` does
exactly this on every `/livewire/update` call (to replay this project's `ResolveTenant`/`RequireTenant`
— see `app/docs/security/patterns/livewire-tenant-isolation.md`, Layer 6), so the replayed request's
host reverts to `127.0.0.1`, `ResolveTenant` can't match a tenant, and it redirects to the root
domain — landing on `/` instead of `/admin`.

**Why `env('APP_ENV')` and not `app()->environment('testing')` for the registration gate:**
verified experimentally (temporary debug probe in `bootstrap/app.php`, removed after) that
`withMiddleware()`'s closure fires via `$app->afterResolving(HttpKernel::class, ...)` /
`afterResolving(ConsoleKernel::class, ...)`, which can trigger BEFORE the container's `'env'` binding
exists — `app()->environment()` throws `BindingResolutionException` (`Target class [env] does not
exist`) at that point in some resolution paths (confirmed by reproducing the crash). `env('APP_ENV')`
reads the raw process environment directly (no container involved) and is safe at every point this
closure can fire. Confirmed reliable across all 4 resolution points hit during a real
`php artisan test --testsuite=Browser` run (outer artisan wrapper: `null` then `local` — correctly
never registers; inner pest/phpunit child process: `testing` both times — correctly always registers).

**Why global `prepend()` and not `web(prepend: ...)`:** the vulnerable clone happens inside Livewire's
handling of `POST /livewire/update`, which needs the fix applied before ANY of that route's own
middleware group runs. The GLOBAL stack (`Middleware::prepend()`, i.e. the app's kernel-level
middleware, not a route-group middleware) is the only point guaranteed to run first for every request
regardless of grouping.

**Why scoped to `config('app.domain')`:** task constraint — a middleware that rewrites an arbitrary
incoming `Host` header is a strictly more dangerous tool than this bug needs. Only hosts equal to or
a subdomain of `app.domain` (`registro.local` locally) get their server bag re-synced; anything else
passes through untouched.

**Verified dead outside testing:** reflected the resolved `Illuminate\Contracts\Http\Kernel`'s
`$middleware` property directly (not `route:list`, which only shows route/group middleware, never the
global stack) — absent under the real `.env` (`APP_ENV=local`), present only when `APP_ENV=testing`
is forced. Mutation-tested by temporarily disabling the `if` block: Browser suite fails with the
original `path [/]` error, re-enabling makes it pass again.

## Selector gotcha (pest-plugin-browser, unrelated to the two bugs above)

`Selector::isExplicit()` treats a bare string like `"form.email"` as CSS `tag.class` syntax — Filament
renders login form input `id`s as the dotted statePath (`id="form.email"`, NOT `id="data.email"`
despite `wire:model="data.email"`). Using the bare id string as a Pest `fill()`/`click()` selector
makes `GuessLocator` search for a nonexistent `<form class="email">` element and HANG far longer than
its own 5s timeout. Always wrap ambiguous dotted ids in an explicit attribute selector:
`fill('[id="form.email"]', ...)`.

## Full verification run (2026-08-08, pest-plugin-browser v4.3.2, vendor CLEAN)

- `grep -c rawurldecode vendor/pestphp/pest-plugin-browser/src/Drivers/LaravelHttpServer.php` → `0`
  (confirms vendor untouched; the cookie fix present in this version uses `urldecode`, not
  `rawurldecode` — different function name, still upstream-owned code, zero lines changed by us).
- `php artisan test --testsuite=Browser` → 1 passed.
- Mutation test: commented-out registration → `path [/]` failure reproduced exactly; restored →
  passes again.
- `php artisan test` (default suite, no Browser) → `3 failed, 5 skipped, 1051 passed` — identical to
  the pre-existing baseline (`CustomerOrdersTest` × 2 + `TenantFeatureTest` × 1, unrelated to this
  branch). No regression from the Pest 4.3.0 → 4.3.2 bump or from this workaround.
- `./vendor/bin/pint --test` → 766 files, pass.

## Open question resolved

The previous version of this memory asked whether to report upstream, add `cweagans/composer-patches`,
or just document a re-apply-after-update ritual for the vendor patch. Moot now for bug 2: the fix lives
in this app's own code (`app/Http/Middleware/Testing/PestBrowserHostBugWorkaround.php` +
`bootstrap/app.php`), survives `composer update` unconditionally, and only needs manual deletion (both
the class and its `bootstrap/app.php` registration) once pest/pest#1734 is fixed upstream.
