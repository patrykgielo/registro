<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Auth\PostAuthDestination;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    use ResetsPasswords {
        resetPassword as protected resetPasswordAndLogin;
    }

    /**
     * A reset proves control of the mailbox, not of the account.
     *
     * The stock trait ends in guard()->login($user). For a super-admin that turns
     * "can read this inbox" into "owns every tenant on the platform" — the token
     * alone opened /platform, with no knowledge of the old password. So a
     * super-admin's reset sets the password and stops there: login() is never
     * called (signing in and then out would still open a window, and fire Login
     * listeners). They sign in afterwards through the normal, throttled login.
     *
     * Everyone else is unchanged and still signed in by the trait.
     */
    protected function resetPassword($user, $password)
    {
        if (! $user->hasRole('super-admin')) {
            $this->resetPasswordAndLogin($user, $password);

            return;
        }

        $this->setUserPassword($user, $password);
        $user->setRememberToken(Str::random(60));
        $user->save();

        event(new PasswordReset($user));

        $this->auditResetWithoutSignIn($user);
    }

    /**
     * Where to send the user once the new password is set.
     *
     * For everyone but a super-admin ResetsPasswords::resetPassword() has called
     * guard()->login($user), so by the time this runs the user is authenticated
     * exactly as a normal login would leave them — PostAuthDestination therefore
     * decides, and login and reset cannot drift apart.
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
        // No instance state on purpose: Laravel caches the controller on the Route
        // (Route::getController()), so a property would survive into the next reset in the
        // same process. The role is read again from the request instead.
        if ($this->broker()->getUser($this->credentials($request))?->hasRole('super-admin')) {
            return $this->redirectToPlatformLogin();
        }

        return redirect(PostAuthDestination::for($request, $this->guard()->user()))
            ->with('status', trans($response));
    }

    private function redirectToPlatformLogin()
    {
        $message = __('passwords.reset_sign_in');

        // The Filament login page renders no flash `status`, so the message also goes out
        // as a Filament notification (session-backed, shown by the layout on the next page).
        Notification::make()->success()->title($message)->send();

        return redirect(Filament::getPanel('platform')->getLoginUrl())
            ->with('status', $message);
    }

    /**
     * Same table and shape as LogAuthenticationEvents. Never carries the password or token.
     */
    private function auditResetWithoutSignIn(User $user): void
    {
        AuditLog::create([
            'auditable_type' => 'authentication',
            'auditable_id' => $user->id,
            'event' => AuditLog::EVENT_PASSWORD_RESET,
            'old_values' => null,
            'new_values' => ['role' => 'super-admin', 'signed_in' => false],
            'user_id' => $user->id,
            // REMOTE_ADDR only: forwarded headers are client-controlled (see security.md, TrustProxies)
            'ip_address' => request()->server('REMOTE_ADDR'),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
