<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    /**
     * Ensure the authenticated user has the super-admin role.
     *
     * Used to protect the Platform panel (multi-tenant management).
     * Non-super-admin users get a 403 Forbidden response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('super-admin')) {
            abort(403, 'Access denied. Super-admin role required.');
        }

        return $next($request);
    }
}
