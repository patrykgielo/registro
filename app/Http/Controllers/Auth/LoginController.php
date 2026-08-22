<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Auth\IntendedDestination;
use App\Support\Auth\PostAuthDestination;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    use AuthenticatesUsers;

    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }

    /**
     * Show the login form. Captures where the visitor came from (see
     * IntendedDestination) before rendering — must happen here, not only in
     * authenticated(), to cover a voluntary click on a "Zaloguj się" link
     * (no AuthenticationException, so Laravel's own url.intended capture
     * never fires).
     */
    public function showLoginForm(Request $request)
    {
        IntendedDestination::capture($request);

        return view('auth.login');
    }

    /**
     * After authentication, redirect based on role and tenant context.
     *
     * The branching lives in PostAuthDestination so the password-reset flow —
     * which also ends with the user logged in — reaches the same place instead
     * of its own `/home`, a path this application has never routed.
     */
    protected function authenticated(Request $request, $user)
    {
        return redirect(PostAuthDestination::for($request, $user));
    }
}
