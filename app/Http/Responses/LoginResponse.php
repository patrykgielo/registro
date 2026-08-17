<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Support\Auth\IntendedDestination;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Custom LoginResponse for the admin Filament panel.
 *
 * Filament's own default (`vendor/filament/filament/src/Auth/Http/Responses/
 * LoginResponse.php`) is a bare `redirect()->intended(Filament::getUrl())` —
 * `Redirector::intended()` follows ANY value in session `url.intended`
 * unconditionally, with NO host or path validation of its own (unlike
 * App\Support\Auth\IntendedDestination::consume(), which checks both). Since
 * IntendedDestination::capture() (the customer-facing /login flow, see
 * app/docs/features/post-login-return.md) writes that SAME session key on
 * every visit to the public /login page, a browser session that browsed the
 * public site, hit /login, then separately authenticated at /admin/login
 * WITHOUT having logged in via the public flow first would be bounced to
 * that public page instead of the admin panel.
 *
 * This override only follows `url.intended` when BOTH hold:
 * - same ORIGIN as the current request (reuses
 *   IntendedDestination::isSameOrigin() — a string-prefix comparison against
 *   $request->getSchemeAndHttpHost(), deliberately NOT a
 *   parse_url()+host-comparison; see that method's docblock for the
 *   PHP-vs-WHATWG parser-disagreement it exists to close. One
 *   implementation, two consumers, not a second copy here.)
 * - path falls under the CURRENT panel's own path prefix (e.g.
 *   "/admin/orders/123") — the case Laravel's own auth-middleware
 *   interception legitimately produces (an admin clicked a deep link from an
 *   email, got redirected to /admin/login, and should land back on that
 *   exact page after authenticating)
 *
 * Anything else — a public-site URL, a value for a different panel, a
 * tampered/foreign origin, a panel-shaped path on a foreign origin — falls
 * back to Filament::getUrl() (the panel's own home). The origin check
 * matters even though only two validated writers exist today (capture(),
 * and Laravel's own guest()->intended() from a GET request's server-side
 * fullUrl()): should either ever be bypassed — e.g. a future CSRF-exempt
 * route sitting behind `auth` reopening the `Referer`-header vector — the
 * blast radius would reach ADMIN login, not just the customer flow, without
 * this check.
 *
 * Bound in AdminPanelProvider::register() — see that method's docblock for
 * why this bind was previously silently inert (wrong Filament v3 contract
 * namespace) and app/docs/features/post-login-return.md for the full story.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        // Peek, don't pull — IntendedDestination::discard() below clears
        // BOTH url.intended and url.intended_at unconditionally, regardless
        // of which branch is taken. A bare session()->pull('url.intended')
        // here would leave an orphaned url.intended_at behind: harmless
        // today (consume() re-validates host/path on its own), but it means
        // a LATER, unrelated capture could pair a fresh URL with a STALE
        // timestamp from this login — the TTL would then measure from the
        // wrong moment.
        $intended = $request->session()->get(IntendedDestination::SESSION_KEY);

        IntendedDestination::discard($request);

        if (is_string($intended) && $this->belongsToCurrentPanel($intended, $request)) {
            return redirect($intended);
        }

        return redirect(Filament::getUrl());
    }

    private function belongsToCurrentPanel(string $intended, Request $request): bool
    {
        $panel = Filament::getCurrentPanel();

        if (! $panel instanceof Panel) {
            return false;
        }

        if (! IntendedDestination::isSameOrigin($intended, $request)) {
            return false;
        }

        $path = IntendedDestination::pathWithinOrigin($intended, $request);
        $panelPath = trim($panel->getPath(), '/');

        if ($panelPath === '') {
            // Panel mounted at the domain root — nothing to prefix-check.
            return true;
        }

        return $path === "/{$panelPath}" || str_starts_with($path, "/{$panelPath}/");
    }
}
