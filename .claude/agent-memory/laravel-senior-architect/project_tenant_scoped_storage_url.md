---
name: project-tenant-scoped-storage-url
description: fix/tenant-scoped-storage-url — public disk URL was frozen at APP_URL, broke FilePond previews cross-origin on shared-stack tenant subdomains
metadata:
  type: project
---

Branch `fix/tenant-scoped-storage-url` (2026-08-29): `config/filesystems.php:44`'s
`'url' => env('APP_URL').'/storage'` is computed once at config-load and `Storage::url()` never
re-resolves it. `ResolveTenant` already had `URL::forceRootUrl($request->getSchemeAndHttpHost())`
in both its branches (`:124` host-derived, `:178` pinned) to fix `route()`/`url()`, but never
touched the disk URL — so on the shared stack, a tenant admin panel (its own subdomain) fetching
an uploaded image via FilePond's `fetch()` (CORS-bound, unlike a plain `<img src>`) hit a different
origin than `APP_URL` and hung forever on "Loading". Public storefront `<img>` tags looked fine,
masking it as panel-only.

Fix: new private `ResolveTenant::forceTenantOriginUrls()`, replaces both `URL::forceRootUrl()`
call sites, adds one line: `config(['filesystems.disks.public.url' => $origin.'/storage'])`.
Fixed BOTH branches, not just host-derived — `TENANT_HOSTS` (pinned/dedicated-stack mode) is
comma-separated and can list a custom domain alongside the default subdomain, so even a dedicated
stack's `APP_URL` isn't guaranteed to match every host a visitor actually used.

**Why this doesn't leak between requests/break queue:** confirmed (not assumed) no Octane in
`composer.json`, container `CMD` is plain `php-fpm` (fresh container per request) — see
[[project_pesel_per_tenant_toggle]]-adjacent architecture note in `architecture-models.md`
("Kolejka nie ma kontekstu żądania", same mechanism `URL::forceRootUrl()` already relied on).
Horizon workers never run `ResolveTenant` at all, so notifications/PDFs off-request correctly keep
falling back to `APP_URL` — same existing behavior, no new risk.

**`/platform` panel unaffected** — `PlatformPanelProvider`'s middleware list never registers
`ResolveTenant`.

Regression test: `tests/Feature/TenantScopedStorageUrlTest.php` — uses the REAL `ResolveTenant`
(not `TenantBrandNameRegressionTest`'s bind-override pattern, since the fix lives inside the class
being swapped out) with real `Host` headers, per `PasswordResetEmailTest`'s established pattern.
Confirmed red before the fix via `git stash push -- app/Http/Middleware/ResolveTenant.php`, green
after `stash pop`.
