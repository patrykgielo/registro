<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\OrganizationLifecycleState;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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

        // Resolve tenant with cache (5 min TTL).
        // lifecycle_state is authoritative (Faza 5.2): only Active tenants serve the public site.
        // is_active is kept as a derived column for other internal uses but is no longer
        // the gating condition here. Stale cache entries (from pre-5.2) expire in 300 s.
        $tenant = Cache::remember("tenant:slug:{$slug}", 300, function () use ($slug) {
            return Organization::where('slug', $slug)
                ->where('lifecycle_state', OrganizationLifecycleState::Active->value)
                ->first();
        });

        if (! $tenant) {
            // Unknown or inactive tenant — redirect to root domain (fail closed)
            // Do NOT cache null results (tenant might be created/activated soon)
            Cache::forget("tenant:slug:{$slug}");

            // Before redirecting: check if this is a Closing/Closed tenant.
            // These states warrant a dedicated "business closed" page rather than a silent
            // root redirect — the business existed and deliberately shut down.
            // Suspended and truly unknown slugs still redirect to root (temporary / error state).
            // withTrashed() catches soft-deleted orgs (purge command soft-deletes Closed orgs).
            $closedOrg = Organization::withTrashed()
                ->where('slug', $slug)
                ->whereIn('lifecycle_state', [
                    OrganizationLifecycleState::Closing->value,
                    OrganizationLifecycleState::Closed->value,
                ])
                ->first();

            if ($closedOrg) {
                return response()->view('errors.business-closed', [
                    'organizationName' => $closedOrg->name,
                ], 410);
            }

            return redirect()->to($this->rootUrl($request));
        }

        $request->attributes->set('tenant', $tenant);

        // Store tenant ID in session so Livewire update requests (which skip this
        // middleware) can still resolve the tenant via TenantFeature::currentTenant().
        if ($request->hasSession()) {
            $request->session()->put('tenant_id', $tenant->id);
        }

        // Authorization: authenticated admin/staff must belong to this tenant.
        // Login route is excluded — user must be able to authenticate first.
        if (
            $request->is('admin*') &&
            ! $request->is('admin/login*') &&
            Auth::check()
        ) {
            $user = Auth::user();
            if (
                $user->hasAnyRole(['admin', 'staff']) &&
                ! $user->canAccessTenant($tenant)
            ) {
                return redirect()->to($this->rootUrl($request));
            }
        }

        // Force route() to generate URLs with tenant subdomain instead of APP_URL.
        // Without this, form actions and redirects point to root domain → 404.
        // Note: In dev mode (npm run dev), Vite HMR won't work on subdomains
        // due to SSL cert mismatch. Use `npm run build` for subdomain testing.
        URL::forceRootUrl($request->getSchemeAndHttpHost());

        return $next($request);
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
