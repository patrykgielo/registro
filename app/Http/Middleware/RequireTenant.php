<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects requests that reached a tenant-owned route without a resolved tenant
 * (e.g. the bare root domain, where ResolveTenant intentionally sets no `tenant`
 * request attribute — "marketplace, no tenant context").
 *
 * MUST run after ResolveTenant::class in the middleware chain.
 *
 * IMPORTANT (VULN-003 gap fix): this checks the `tenant` REQUEST ATTRIBUTE
 * directly — NOT `TenantFeature::currentTenant()`. The latter has a 3rd
 * fallback branch that reads `session('tenant_id')`, which `ResolveTenant`
 * writes on EVERY successful subdomain resolution (including anonymous
 * visitors) and BEFORE the `canAccessTenant()` staff-authorization check
 * (which only runs on the subdomain branch, never on the root-domain
 * branch). Gating on the session fallback would let a staff user who
 * merely *browsed* an unauthorized tenant's subdomain (no login required)
 * carry that tenant into a root-domain admin session via stale session
 * state — the request attribute is the only signal that reflects tenant
 * resolution for THIS request, on THIS host.
 */
class RequireTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->attributes->get('tenant') !== null, 404);

        return $next($request);
    }
}
