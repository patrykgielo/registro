<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\OrganizationLifecycleState;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Resolve the current tenant from subdomain.
     *
     * - {slug}.registro.app → resolves Organization by slug
     * - registro.app (root domain, no subdomain) → no tenant (marketplace)
     * - Unknown subdomain → redirect to root domain (fail closed)
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Marker meaning "ResolveTenant genuinely ran for this specific request" —
        // consumed by BelongsToOrganization's global scope (Layer 2 fail-closed
        // hardening, VULN-003). Set unconditionally, before any branching, so it
        // reflects real HTTP/feature-test requests through this middleware —
        // distinct from runningInConsole()/runningUnitTests(), which can't tell
        // apart a real request from a bare Unit test that never touches this
        // middleware at all. See app/Traits/BelongsToOrganization.php.
        $request->attributes->set('tenant_resolution_attempted', true);

        $pinnedSlug = config('app.tenant_slug');

        if (filled($pinnedSlug)) {
            return $this->handlePinnedTenant($request, $next, $pinnedSlug);
        }

        $host = $request->getHost();
        $baseDomain = config('app.domain', 'registro.local');

        // Root domain (no subdomain) — marketplace, no tenant context
        if ($host === $baseDomain) {
            return $next($request);
        }

        // Extract subdomain: "demo.registro.local" → "demo"
        $suffix = '.'.$baseDomain;
        if (! str_ends_with($host, $suffix)) {
            // Host doesn't match our base domain at all — redirect to root
            return redirect()->to($this->rootUrl($request));
        }

        $slug = substr($host, 0, -strlen($suffix));

        // Validate slug format (prevent injection via crafted Host header)
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/', $slug) && ! preg_match('/^[a-z0-9]{1,2}$/', $slug)) {
            return redirect()->to($this->rootUrl($request));
        }

        $tenant = $this->resolveActiveTenantBySlug($slug);

        if (! $tenant) {
            // Unknown or inactive tenant — redirect to root domain (fail closed)
            // Before redirecting: check if this is a Closing/Closed tenant.
            // These states warrant a dedicated "business closed" page rather than a silent
            // root redirect — the business existed and deliberately shut down.
            // Suspended and truly unknown slugs still redirect to root (temporary / error state).
            // withTrashed() catches soft-deleted orgs (purge command soft-deletes Closed orgs).
            // Cached (5 min): Closing/Closed are terminal/near-terminal, so repeated hits on a
            // closed slug cost 0 DB queries after the first — limits subdomain-enumeration DoS on
            // the 410 path (which runs before throttle). select(name) — only column the view uses.
            $closedOrg = Cache::remember("tenant:closed:{$slug}", 300, fn () => Organization::withTrashed()
                ->select(['name'])
                ->where('slug', $slug)
                ->whereIn('lifecycle_state', [
                    OrganizationLifecycleState::Closing->value,
                    OrganizationLifecycleState::Closed->value,
                ])
                ->first());

            if ($closedOrg) {
                return response()->view('errors.business-closed', [
                    'organizationName' => $closedOrg->name,
                ], 410);
            }

            // Suspended tenant: show a temporary suspension page (503).
            // Suspended orgs are NOT soft-deleted — use a plain query without withTrashed().
            // Short cache (60 s) so reactivation is reflected quickly.
            $suspendedOrg = Cache::remember("tenant:suspended:{$slug}", 60, fn () => Organization::select(['name'])
                ->where('slug', $slug)
                ->where('lifecycle_state', OrganizationLifecycleState::Suspended->value)
                ->first());

            if ($suspendedOrg) {
                return response()->view('errors.business-suspended', [
                    'organizationName' => $suspendedOrg->name,
                ], 503)->withHeaders(['Retry-After' => '3600']);
            }

            return redirect()->to($this->rootUrl($request));
        }

        $request->attributes->set('tenant', $tenant);

        // Store tenant ID in session so Livewire update requests (which skip this
        // middleware) can still resolve the tenant via TenantFeature::currentTenant().
        if ($request->hasSession()) {
            $request->session()->put('tenant_id', $tenant->id);
        }

        if (! $this->staffCanAccessTenant($request, $tenant)) {
            return redirect()->to($this->rootUrl($request));
        }

        // Force route() to generate URLs with tenant subdomain instead of APP_URL.
        // Without this, form actions and redirects point to root domain → 404.
        // Note: In dev mode (npm run dev), Vite HMR won't work on subdomains
        // due to SSL cert mismatch. Use `npm run build` for subdomain testing.
        $this->forceTenantOriginUrls($request);

        return $next($request);
    }

    /**
     * Stack-per-tenant mode (TENANT_SLUG set): resolves the tenant from the
     * container's own environment instead of the Host header — a dedicated
     * stack's database holds exactly one Organization (see
     * `organizations.singleton`, app/docs/features/tenant-stack-provisioning.md),
     * so there is nothing meaningful to derive from a subdomain.
     *
     * Pinning the slug alone would make the stack answer 200 on ANY Host that
     * reaches it (a stray DNS entry, a scanner hitting the bare IP, a Host
     * header that doesn't match the client's actual domain at all) — the
     * container has no other tenant to fall back to or redirect toward, so an
     * unchecked Host would silently serve this tenant's data under it.
     * TENANT_HOSTS is the independent, fail-closed layer that stops that: a
     * Host outside the allowlist gets 404 even though the slug resolves fine.
     * Empty/unset TENANT_HOSTS denies every Host on purpose — an operator who
     * sets TENANT_SLUG but forgets TENANT_HOSTS gets a 404ing stack, not a
     * silently wide-open one.
     *
     * Deliberately does not reuse the closed/suspended-org pages from the
     * host-derived branch above — out of scope here (see the ResolveTenant
     * section of app/docs/features/tenant-stack-provisioning.md); a pinned
     * tenant that is not Active fails closed to a plain 404.
     */
    private function handlePinnedTenant(Request $request, Closure $next, string $pinnedSlug): Response
    {
        $allowedHosts = config('app.tenant_hosts', []);
        $host = strtolower($request->getHost());

        abort_unless(in_array($host, $allowedHosts, true), 404);

        $tenant = $this->resolveActiveTenantBySlug($pinnedSlug);

        if (! $tenant) {
            abort(404);
        }

        $request->attributes->set('tenant', $tenant);

        if ($request->hasSession()) {
            $request->session()->put('tenant_id', $tenant->id);
        }

        // Same staff/admin tenant-membership guard as the host-derived branch —
        // see staffCanAccessTenant() (login route excluded so a user can
        // authenticate first).
        abort_unless($this->staffCanAccessTenant($request, $tenant), 404);

        // Force route() to generate URLs with the request's own host instead of
        // APP_URL — same reasoning as the host-derived branch below.
        $this->forceTenantOriginUrls($request);

        return $next($request);
    }

    /**
     * Resolve the Active organization for a slug, with cache (5 min TTL) —
     * shared by both the host-derived and pinned-stack branches, which
     * otherwise called through to Organization::where() identically.
     *
     * lifecycle_state is authoritative (Faza 5.2): only Active tenants serve
     * the public site. is_active is kept as a derived column for other
     * internal uses but is no longer the gating condition here. Stale cache
     * entries (from pre-5.2) expire in 300 s.
     *
     * Deliberately does NOT decide what to do on a miss (redirect, 410/503
     * page, plain 404) — that differs by branch and stays in the caller.
     */
    private function resolveActiveTenantBySlug(string $slug): ?Organization
    {
        $tenant = Cache::remember("tenant:slug:{$slug}", 300, function () use ($slug) {
            return Organization::where('slug', $slug)
                ->where('lifecycle_state', OrganizationLifecycleState::Active->value)
                ->first();
        });

        if (! $tenant) {
            // Do NOT cache null results — the org might be created/activated soon.
            Cache::forget("tenant:slug:{$slug}");
        }

        return $tenant;
    }

    /**
     * Authorization: an authenticated admin/staff user must belong to the
     * resolved tenant. Login route is excluded — a user must be able to
     * authenticate first. Not admin/staff, not on /admin*, or not
     * authenticated at all → nothing to check here, so `true`.
     *
     * Shared by both branches on purpose: a future change to this rule
     * applied to only one branch would open an authorization gap between the
     * shared stack and a pinned stack, silently — this project already
     * shipped exactly that failure once, duplicated across OrderResource and
     * EditOrder.
     */
    private function staffCanAccessTenant(Request $request, Organization $tenant): bool
    {
        if (
            ! $request->is('admin*') ||
            $request->is('admin/login*') ||
            ! Auth::check()
        ) {
            return true;
        }

        $user = Auth::user();

        return ! $user->hasAnyRole(['admin', 'staff']) || $user->canAccessTenant($tenant);
    }

    /**
     * Force route()/url() AND the "public" disk's URL to the current request's
     * own origin instead of APP_URL. Shared by both branches — a pinned stack's
     * TENANT_HOSTS can list more than one host (e.g. a custom domain alongside
     * the default subdomain), so even there APP_URL isn't guaranteed to match
     * the host the visitor actually used.
     *
     * Without the disk half of this, Storage::url() (and anything built on it —
     * FilePond's preview fetch() in the admin panel, attribute casts that expose
     * a public URL) keeps resolving to APP_URL's host. On a shared stack that's
     * a different origin than the tenant subdomain, and fetch() is subject to
     * CORS unlike a plain <img src>, so the panel-only symptom was a preview
     * that never loads while the public storefront (plain <img>) looks fine.
     *
     * Only mutates the in-memory Config repository for THIS request — php-fpm
     * (confirmed: no Octane in composer.json, container CMD is php-fpm) rebuilds
     * the container fresh per request, so nothing leaks to the next one. Queue
     * workers (Horizon) never run this middleware at all, so a rendered
     * notification/PDF off the request cycle falls back to APP_URL. On a shared
     * stack that is the ROOT domain, not the tenant's — i.e. a wrong host in the
     * customer's e-mail, not a correct fallback. (Compounding it: the horizon and
     * scheduler services do not mount the storage-app-public volume at all —
     * docker-compose.prod.yml, only app:135 and nginx:372 do — so off-request
     * rendering may not even find the file. ClickUp 123k99ct3za.)
     * See architecture-models.md, "Kolejka nie ma kontekstu żądania".
     *
     * The config write alone is not enough: `FilesystemManager::disk()` caches
     * the built adapter in `$disks['public']` on first resolution, and
     * `FilesystemAdapter::url()` reads the 'url' value it was constructed with —
     * not config() live. If ANYTHING resolved the "public" disk (or the default
     * disk, which this project always pins to "public" — FILESYSTEM_DISK=public)
     * before this middleware ran, the config mutation above is a silent no-op
     * and Storage::url() keeps returning APP_URL's host. Nothing in this
     * codebase does that today (checked: AppServiceProvider::boot(),
     * AdminPanelProvider — no eager Storage::disk() calls), but the failure mode
     * is silent, so `forgetDisk()` is added defensively rather than relying on
     * that staying true. It only drops the cached adapter instance from the
     * manager's registry — any reference a caller already holds keeps working
     * unchanged; only the NEXT `Storage::disk('public')` (or the default-disk
     * facade call, since the resolved cache key is the same "public" name)
     * rebuilds from the config just written above.
     */
    private function forceTenantOriginUrls(Request $request): void
    {
        $origin = $request->getSchemeAndHttpHost();

        URL::forceRootUrl($origin);
        config(['filesystems.disks.public.url' => $origin.'/storage']);
        Storage::forgetDisk('public');
    }

    /**
     * Build the root domain URL preserving scheme and port.
     */
    private function rootUrl(Request $request): string
    {
        $scheme = $request->isSecure() ? 'https' : 'http';
        $baseDomain = config('app.domain', 'registro.local');
        $port = $request->getPort();

        // Only append non-standard ports
        $portSuffix = '';
        if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
            $portSuffix = ':'.$port;
        }

        return "{$scheme}://{$baseDomain}{$portSuffix}";
    }
}
