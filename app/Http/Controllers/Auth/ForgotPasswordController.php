<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sends the password-reset e-mail — for customers, tenant admins and super-admins alike.
 *
 * Every outcome answers the SAME way. The stock trait distinguished "no such user"
 * (`passwords.user`) from "sent" and "throttled", which made this endpoint an
 * account-existence oracle for the whole platform: `users.email` is globally unique and
 * User has no tenant scope, so any tenant host (or /admin/login, /platform/login, which now
 * link here) could ask about any address, admin and super-admin ones included.
 *
 * "Throttled" is folded in too, not only "not found": the broker's per-account cooldown
 * only ever fires for an address that exists, so showing it would reveal the account on the
 * second request. The per-IP route throttle (`throttle:3,1,password-email`) is unchanged.
 *
 * Residual, accepted here: the mail is sent synchronously inside the request, so an existing
 * address still takes measurably longer than an unknown one. Closing that needs the send
 * queued (or a constant-time pad) and is out of scope. UX cost, deliberate: someone who
 * mistypes their address is no longer told "no such user".
 */
class ForgotPasswordController extends Controller
{
    use SendsPasswordResetEmails;

    protected function sendResetLinkResponse(Request $request, $response)
    {
        return $this->genericResponse($request);
    }

    protected function sendResetLinkFailedResponse(Request $request, $response)
    {
        return $this->genericResponse($request);
    }

    private function genericResponse(Request $request): \Illuminate\Http\RedirectResponse|JsonResponse
    {
        $message = __('passwords.link_requested');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
