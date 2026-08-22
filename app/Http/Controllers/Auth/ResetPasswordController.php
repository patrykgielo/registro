<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Auth\PostAuthDestination;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Http\Request;

class ResetPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset requests
    | and uses a simple trait to include this behavior. You're free to
    | explore this trait and override any methods you wish to tweak.
    |
    */

    use ResetsPasswords;

    /**
     * Where to send the user once the new password is set.
     *
     * ResetsPasswords::resetPassword() calls guard()->login($user), so by the
     * time this runs the user is authenticated exactly as a normal login would
     * leave them — PostAuthDestination therefore decides, and login and reset
     * cannot drift apart.
     *
     * Replaces `$redirectTo = '/home'`, which was a dead end: no route is
     * registered at `/home` (the route NAMED `home` is `/`). Verified against
     * the real flow — the password was set, the user was redirected to
     * `/home` on their own tenant subdomain, and got a 404 with no way back to
     * their panel. Nothing reported the failure; from the user's side the reset
     * simply appeared not to work.
     */
    protected function sendResetResponse(Request $request, $response)
    {
        return redirect(PostAuthDestination::for($request, $this->guard()->user()))
            ->with('status', trans($response));
    }
}
