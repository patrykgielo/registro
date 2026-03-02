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
 * - super-admin: ALWAYS full access (never sees maintenance page)
 * - admin, staff: See maintenance page during maintenance mode
 * - Unauthenticated users: Pass through to login page
 */
class AdminMaintenanceCheck
{
    public function __construct(
        private MaintenanceService $maintenanceService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Skip if maintenance mode is not active
        if (! $this->maintenanceService->isActive()) {
            return $next($request);
        }

        // 2. Super-admin ALWAYS has full access
        $user = Auth::user();
        if ($user && $user->hasRole('super-admin')) {
            return $next($request);
        }

        // 3. Other authenticated users (admin, staff) - show maintenance page
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
