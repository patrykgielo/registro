<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\MaintenanceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * AdminMaintenanceCheck Middleware
 *
 * Checks maintenance mode AFTER user is authenticated.
 * This middleware runs in Filament's authMiddleware stack, AFTER Authenticate.
 *
 * Logic:
 * - Dedicated tenant stack (TENANT_SLUG set): admin AND super-admin keep panel access.
 *   The tenant admin is the one who switches maintenance on (MaintenanceSettings), the stack
 *   has its own database and NO super-admin to let them back in: blocking `admin` there is a
 *   permanent lockout that only the CLI can undo.
 * - Shared stack (no TENANT_SLUG): super-admin ONLY, as before. The maintenance flag is one
 *   global cache key, so an operator-wide window must not be overridable by any tenant admin.
 * - staff (and admin on the shared stack): See maintenance page during maintenance mode
 * - Unauthenticated users: Pass through to login page
 */
class AdminMaintenanceCheck
{
    /**
     * Roles that keep panel access during maintenance, by deployment model. Public-site bypass
     * is a separate rule (MaintenanceService::canBypass); this one only keeps the panel reachable.
     *
     * @return array<int, string>
     */
    private function panelBypassRoles(): array
    {
        return config('app.tenant_slug') ? ['super-admin', 'admin'] : ['super-admin'];
    }

    public function __construct(
        private MaintenanceService $maintenanceService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Skip if maintenance mode is not active
        if (! $this->maintenanceService->isActive()) {
            return $next($request);
        }

        // 2. Panel managers keep full access (explicit, not a side effect of any role promotion).
        //    On a dedicated stack that includes `admin`: they must be able to switch it off.
        $user = Auth::user();
        if ($user && $user->hasAnyRole($this->panelBypassRoles())) {
            return $next($request);
        }

        // 3. Other authenticated users (staff; admin on the shared stack) - show maintenance page
        if ($user) {
            \Log::info('Admin panel access blocked during maintenance', [
                'user' => $user->email,
                'roles' => $user->roles->pluck('name'),
                'maintenance_type' => $this->maintenanceService->getType()?->value,
            ]);

            return response()->view('filament.pages.maintenance', [
                'type' => $this->maintenanceService->getType(),
                'user' => $user,
            ], 503);
        }

        // 4. Unauthenticated users - pass through to login page
        return $next($request);
    }
}
