<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     * Dynamic redirect based on user role.
     *
     * @return string
     */
    protected function redirectTo()
    {
        $user = auth()->user();

        // Check if user has roles (Spatie Laravel Permission)
        if (method_exists($user, 'hasRole')) {
            try {
                // Admins and staff → Filament panel (use named route)
                if ($user->hasRole('super-admin') || $user->hasRole('admin') || $user->hasRole('staff')) {
                    return route('filament.admin.pages.dashboard');
                }
            } catch (\Exception $e) {
                \Log::error('Error checking user roles in LoginController: '.$e->getMessage());
            }
        }

        // Regular users (or users without roles) → appointments page (use named route)
        return route('appointments.index');
    }

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }

    protected function authenticated(Request $request, $user)
    {
        if ($user->hasAnyRole(['super-admin', 'admin', 'staff'])) {
            return redirect()->route('filament.admin.pages.dashboard');
        }

        return redirect()->route('appointments.index');
    }
}
