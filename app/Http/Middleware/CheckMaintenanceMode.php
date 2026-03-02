<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\MaintenanceType;
use App\Services\MaintenanceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckMaintenanceMode Middleware
 *
 * Handles maintenance mode logic for the application.
 * Checks Redis state and determines if request should be blocked.
 *
 * Bypass logic:
 * - Admin routes (/admin/*): Delegated to Filament (User::canAccessPanel checks maintenance)
 * - DEPLOYMENT/SCHEDULED/EMERGENCY: Admins can bypass via role or secret token
 * - PRELAUNCH: Returns 503 to non-admin users
 *
 * Secret Token Bypass:
 * - Pass token via query parameter: ?maintenance_token=registro-xxxxx
 * - Token stored in session for subsequent requests
 */
class CheckMaintenanceMode
{
    public function __construct(
        private MaintenanceService $maintenanceService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Skip health check endpoint (Docker healthcheck)
        if ($request->is('up')) {
            return $next($request);
        }

        // 2. Skip if maintenance mode is not active
        if (! $this->maintenanceService->isActive()) {
            return $next($request);
        }

        $type = $this->maintenanceService->getType();

        if (! $type) {
            return $next($request);
        }

        // 3. Let Filament handle admin routes entirely
        // Filament has its own auth middleware + User::canAccessPanel() checks maintenance mode
        // This avoids Auth::user() returning NULL due to lazy loading before Filament middleware runs
        if ($request->is('admin') || $request->is('admin/*')) {
            return $next($request);
        }

        // 3.5. Allow Livewire requests (Filament uses Livewire for login)
        // Without this, login form submission to /livewire/update would be blocked
        // because user is not authenticated YET during the login request
        if ($request->is('livewire/*')) {
            return $next($request);
        }

        // 4. Allow authentication routes (login pages only)
        if ($this->isAuthenticationRoute($request)) {
            return $next($request);
        }

        // 5. Check authenticated user bypass (role-based) for non-admin routes
        // Auth::user() is now available because middleware runs AFTER StartSession
        if ($this->canUserBypassMaintenance($request, $type)) {
            return $next($request);
        }

        // 6. Check secret token bypass (query parameter or session)
        if ($this->hasValidSecretToken($request)) {
            return $next($request);
        }

        // 7. Block everyone else - return maintenance page
        return $this->maintenanceResponse($type, $request);
    }

    /**
     * Check if the route is an authentication route that should always be accessible.
     * Note: Admin routes (/admin/*) are handled separately via early return.
     */
    private function isAuthenticationRoute(Request $request): bool
    {
        $authRoutes = [
            'login',
            'register',
            'password/reset',
            'password/reset/*',
            'password/email',
            'password/confirm',
            'email/verify/*',
            'two-factor-challenge',
        ];

        foreach ($authRoutes as $route) {
            if ($request->is($route)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the authenticated user can bypass maintenance mode based on their role.
     */
    private function canUserBypassMaintenance(Request $request, MaintenanceType $type): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false; // No authenticated user
        }

        // Use MaintenanceService's existing canBypass logic
        // This checks user roles (super-admin, admin) based on maintenance type
        return $this->maintenanceService->canBypass($user);
    }

    /**
     * Check if the request has a valid secret token for bypass.
     */
    private function hasValidSecretToken(Request $request): bool
    {
        // Check query parameter first, then session
        $token = $request->query('maintenance_token')
            ?? session('maintenance_bypass_token');

        if (! $token) {
            return false;
        }

        // Validate token against stored secret
        if ($this->maintenanceService->checkSecretToken($token)) {
            // Store in session for subsequent requests
            session(['maintenance_bypass_token' => $token]);

            return true;
        }

        // Token invalid - remove from session
        session()->forget('maintenance_bypass_token');

        return false;
    }

    /**
     * Return appropriate maintenance response based on type.
     */
    private function maintenanceResponse(MaintenanceType $type, Request $request): Response
    {
        // If already on home page, show maintenance template directly
        if ($request->is('/')) {
            $config = $this->maintenanceService->getConfig();

            // Determine view template based on type
            $view = match ($type) {
                MaintenanceType::PRELAUNCH => 'errors.maintenance-prelaunch',
                default => 'errors.maintenance-deployment',
            };

            // Prepare data for view
            $data = [
                'type' => $type,
                'config' => $config,
                'retry_after' => $type->retryAfter(),
            ];

            // Return 503 Service Unavailable
            return response()->view($view, $data, 503)
                ->header('Retry-After', (string) $type->retryAfter());
        }

        // Redirect all other URLs to home page (which shows maintenance template)
        return redirect('/');
    }
}
