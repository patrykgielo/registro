<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;

/**
 * Custom LoginResponse for Filament Admin Panel.
 *
 * This class overrides the default Filament LoginResponse to always redirect
 * to the admin panel after successful login, ignoring any "intended" URL
 * stored in the session.
 *
 * Problem solved:
 * During maintenance mode, users visiting "/" get redirected and "/" is stored
 * as the "intended" URL. When they then log in via /admin/login, the default
 * Filament LoginResponse uses redirect()->intended() which returns "/" instead
 * of "/admin", causing users to see the maintenance page instead of the admin panel.
 *
 * Solution:
 * Always redirect to Filament::getUrl() (admin panel) after login, regardless
 * of any stored intended URL.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        // Always redirect to admin panel after login
        // Ignoring intended URL prevents redirect to "/" during maintenance mode
        return redirect(Filament::getUrl());
    }
}
