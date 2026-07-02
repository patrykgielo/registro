<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantFeature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects requests that reached a tenant-owned route without a resolved tenant
 * (e.g. the bare root domain, where ResolveTenant intentionally sets no `tenant`
 * request attribute — "marketplace, no tenant context").
 *
 * MUST run after ResolveTenant::class in the middleware chain.
 */
class RequireTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(TenantFeature::currentTenant() !== null, 404);

        return $next($request);
    }
}
