<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\LocationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Faza 5.1 (86cbahqg3) foundation. Keeps `session('selected_location_id')`
 * honest for the tenant THIS request resolved — never resolves a tenant
 * itself, never reads `session('tenant_id')` (that would reintroduce the
 * class of bug VULN-003 Layer 8 removed, see models.md).
 *
 * Registered in the base 'web' group (bootstrap/app.php), appended AFTER
 * ResolveTenant → SubstituteBindings → CheckMaintenanceMode — it needs the
 * `tenant` request attribute ResolveTenant sets (VULN-003 Layer 7 ordering:
 * that attribute, and TenantFeature::currentTenant()'s branch reading it, is
 * exactly what LocationContext derives the tenant from). Does NOT need to
 * run before route-model-binding the way ResolveTenant does — nothing here
 * resolves a route parameter — so appending after SubstituteBindings/
 * CheckMaintenanceMode costs nothing and keeps the diff to bootstrap/app.php
 * minimal (one new line, no re-ordering of the existing three).
 *
 * Does NOT touch /admin or /platform: both Filament panels carry their own
 * ->middleware([...]) arrays and never reference the app's 'web' group (see
 * the ordering comment on ResolveTenant's registration in bootstrap/app.php)
 * — this middleware would be a no-op for the panels anyway (no public
 * location switcher exists there), but confirming it structurally can't run
 * there is cheaper than relying on that.
 *
 * Session cookie scope: config/session.php:159 → SESSION_DOMAIN is falsy in
 * every environment this project runs (.env.example: literal "null", which
 * Laravel's env() helper converts to real null; docker-compose.prod.yml:
 * empty string "") — confirmed via Illuminate\Session\Middleware\
 * StartSession::addCookieToResponse() passing $config['domain'] straight
 * into the cookie, and Symfony's Cookie::__toString() only emitting a
 * `Domain=` attribute `if ($this->getDomain())` (both null and '' are
 * falsy). No Domain= attribute means a HOST-ONLY cookie (RFC 6265) — the
 * browser will not send a tenant-a.{domain} session to tenant-b.{domain} or
 * to the root domain. So a genuinely cross-SUBDOMAIN stale selection cannot
 * happen via cookie carry-over in this deployment. What DOES stay reachable
 * on the SAME host: a location that gets deleted or deactivated after being
 * selected, or (defensively, since the mechanism above is deployment
 * config, not a language guarantee) a tenant slug reassigned to a different
 * organization while a browser still holds an old session — pruneStaleSelection()
 * covers all of these identically, without needing to know which one
 * occurred.
 */
class ShareSelectedLocation
{
    public function __construct(private readonly LocationContext $locations) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            $this->locations->pruneStaleSelection();
        }

        return $next($request);
    }
}
