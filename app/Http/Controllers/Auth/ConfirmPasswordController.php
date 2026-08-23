<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\ConfirmsPasswords;

class ConfirmPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Confirm Password Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password confirmations and
    | uses a simple trait to include the behavior. You're free to explore
    | this trait and override any functions that require customization.
    |
    */

    use ConfirmsPasswords;

    /**
     * Where to redirect users when the intended url fails.
     *
     * `/home` used to sit here and 404s — nothing is routed at that path; the
     * route NAMED `home` is `/`. Same dead value as ResetPasswordController
     * carried, and reachable, since `password/confirm` IS registered.
     *
     * Deliberately NOT PostAuthDestination: this is a mid-session re-auth gate,
     * and ConfirmsPasswords resolves it through `redirect()->intended(...)` so
     * the user returns to the page that demanded confirmation. That helper
     * discards `url.intended` by design, which is right after a fresh login and
     * would silently take the return trip away here. This value is only the
     * fallback for when no intended URL exists.
     */
    protected function redirectPath(): string
    {
        return route('home');
    }

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }
}
