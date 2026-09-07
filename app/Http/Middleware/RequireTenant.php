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
 * directly — NOT `TenantFeature::currentTenant()`. Until Layer 8 (2026-08-31),
 * the latter had a 3rd fallback branch reading `session('tenant_id')`, which
 * `ResolveTenant` writes on EVERY successful subdomain resolution (including
 * anonymous visitors) and BEFORE the `canAccessTenant()` staff-authorization
 * check (which only runs on the subdomain branch, never on the root-domain
 * branch) — that branch is now gone from every real HTTP request (it only
 * remains as a narrow Livewire::test()-only escape hatch, structurally
 * unreachable outside APP_ENV=testing — see TenantFeature::currentTenant()).
 * Kept on the request attribute anyway: it is still the only signal that
 * reflects tenant resolution for THIS request, on THIS host, and doesn't
 * depend on TenantFeature's resolution order staying what it is today.
 */
class RequireTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->attributes->get('tenant') !== null, 404);

        return $next($request);
    }
}
