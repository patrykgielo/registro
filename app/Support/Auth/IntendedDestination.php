<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Http\Request;

/**
 * Post-login/registration return-to-origin, stored in the session under the
 * SAME key Laravel's own `redirect()->guest()`/`Redirector::intended()` use
 * (`url.intended`) — an unauthenticated hit on a protected route (e.g.
 * /koszyk) already populates it via the framework's normal exception-handler
 * path; capture() below only needs to cover the OTHER case, a voluntary click
 * on a login link, which never throws and so never touches that key on its
 * own. discard() must be called on every non-customer login branch: Filament
 * reads this same key, and a stale value would bounce an admin somewhere
 * random.
 *
 * NEVER use `url()->previous()` / `UrlGenerator::previous()` here — it reads
 * the `Referer` header BEFORE falling back to the session, so a page on a
 * different site linking to our /login would resurrect the open-redirect
 * surface this whole mechanism exists to close. `Session::previousUrl()`/
 * `previousRoute()` only ever reflect our own prior request, recorded
 * server-side by `StartSession` — never client-supplied.
 *
 * `url.intended_at` serves two DIFFERENT purposes that look like one field:
 * 1. Disarming values that predate this mechanism entirely — no timestamp at
 *    all means untrusted, full stop, regardless of how safe the URL looks.
 * 2. Bounding how long a genuinely-captured value stays followable — but
 *    this is 60 minutes since the LAST time it was touched by an auth-chain
 *    page view (capture()'s "keep existing, refresh timestamp" branch,
 *    below), not 60 minutes since the original page was left. A visitor who
 *    keeps bouncing between /login, /customer/register and /password/reset
 *    for 3 hours straight never lets the captured value go stale — each
 *    bounce re-validates AND re-stamps it.
 */
class IntendedDestination
{
    /**
     * Public: App\Http\Responses\LoginResponse needs the raw key name (it
     * reads/discards the value itself instead of going through consume() —
     * the admin panel's own panel-path check replaces consume()'s denylist
     * one) — one source of truth for the key name, not a second string
     * literal in a second file.
     */
    public const SESSION_KEY = 'url.intended';

    private const TIMESTAMP_KEY = 'url.intended_at';

    private const TTL_MINUTES = 60;

    /**
     * Our own auth-chain pages — bouncing through one of these must not
     * clobber an already-captured destination. Concretely:
     * card → /login → "Nie mam konta" → /customer/register, where
     * previousRoute() at /customer/register is 'login' itself.
     */
    private const AUTH_CHAIN_ROUTES = ['login', 'customer.register'];

    private const AUTH_CHAIN_ROUTE_PREFIXES = ['password.'];

    /**
     * Superset of the auth-chain list above, plus routes that are never a
     * legitimate landing page regardless of context.
     */
    private const DENYLISTED_ROUTES = ['login', 'logout', 'customer.register'];

    private const DENYLISTED_ROUTE_PREFIXES = ['password.', 'filament.'];

    private const DENYLISTED_PATH_PREFIXES = ['/admin', '/platform', '/livewire', '/api', '/webhooks'];

    /**
     * Called from showLoginForm()/showRegistrationForm() — decides what, if
     * anything, changes about the currently-stored intended destination.
     */
    public static function capture(Request $request): void
    {
        $session = $request->session();
        $previousRoute = $session->previousRoute();

        if (static::isAuthChainRoute($previousRoute)) {
            // Re-validate the ALREADY-stored value before trusting it enough
            // to refresh its timestamp — consume() isn't the only gate that
            // matters here. Without this, a value that somehow became unsafe
            // (origin/path denylist) between being written and this bounce
            // would sail through on nothing but consume()'s own check later,
            // making that the sole line of defense instead of one of two.
            $existing = $session->get(self::SESSION_KEY);

            if (is_string($existing) && static::isSafeUrl($existing, $request)) {
                $session->put(self::TIMESTAMP_KEY, now()->timestamp);
            } else {
                static::discard($request);
            }

            return;
        }

        $previousUrl = $session->previousUrl();

        if (static::isSafeCandidate($previousUrl, $previousRoute, $request)) {
            $session->put(self::SESSION_KEY, $previousUrl);
            $session->put(self::TIMESTAMP_KEY, now()->timestamp);

            return;
        }

        static::discard($request);
    }

    /**
     * One-shot read: removes both keys regardless of outcome (`pull`
     * semantics, same as `Redirector::intended()`), and returns the stored
     * URL only if it is still within TTL and still points at this origin.
     *
     * SECURITY GATE — this is the authoritative check, not a redundant
     * backstop. capture()'s auth-chain branch also re-validates before
     * refreshing the timestamp (defense in depth, same reasoning as
     * isSameOrigin() being checked at both write and read time), but that
     * does NOT make this check safe to weaken or remove: capture() is never
     * called for the interception path (an unauthenticated hit on a
     * protected route — Laravel's own `redirect()->guest()` writes
     * `url.intended` directly, with no timestamp, and this method is the
     * ONLY place that value is ever re-checked before being followed).
     */
    public static function consume(Request $request): ?string
    {
        $session = $request->session();
        $url = $session->pull(self::SESSION_KEY);
        $capturedAt = $session->pull(self::TIMESTAMP_KEY);

        // No timestamp — either never captured by this mechanism, or
        // captured before this mechanism existed. Either way, unverifiable
        // age means the value is untrusted, not merely "not ours".
        if ($url === null || ! is_int($capturedAt)) {
            return null;
        }

        if (now()->timestamp - $capturedAt > self::TTL_MINUTES * 60) {
            return null;
        }

        if (! static::isSafeUrl($url, $request)) {
            return null;
        }

        return $url;
    }

    public static function discard(Request $request): void
    {
        $request->session()->forget([self::SESSION_KEY, self::TIMESTAMP_KEY]);
    }

    private static function isSafeCandidate(?string $previousUrl, ?string $previousRoute, Request $request): bool
    {
        if ($previousUrl === null) {
            return false;
        }

        if (static::isDenylistedRoute($previousRoute)) {
            return false;
        }

        return static::isSafeUrl($previousUrl, $request);
    }

    /**
     * Origin + path safety, independent of any route-name context — the
     * check shared by capture()'s two re-validation points (a fresh
     * candidate, and an already-stored value being re-stamped) and
     * consume()'s own gate.
     */
    private static function isSafeUrl(string $url, Request $request): bool
    {
        if (! static::isSameOrigin($url, $request)) {
            return false;
        }

        return static::isSafePath(static::pathWithinOrigin($url, $request));
    }

    private static function isAuthChainRoute(?string $routeName): bool
    {
        return static::matchesRoute($routeName, self::AUTH_CHAIN_ROUTES, self::AUTH_CHAIN_ROUTE_PREFIXES);
    }

    private static function isDenylistedRoute(?string $routeName): bool
    {
        return static::matchesRoute($routeName, self::DENYLISTED_ROUTES, self::DENYLISTED_ROUTE_PREFIXES);
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<int, string>  $prefixes
     */
    private static function matchesRoute(?string $routeName, array $names, array $prefixes): bool
    {
        if ($routeName === null) {
            return false;
        }

        if (in_array($routeName, $names, true)) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * $path must already be extracted via pathWithinOrigin() — NOT a full
     * URL, and NOT parse_url()'s output (see isSameOrigin()'s docblock for
     * why parse_url() is never trusted for this anywhere in this class).
     */
    private static function isSafePath(string $path): bool
    {
        // Case-insensitive — a denylisted path must not be bypassable by a
        // client-influenced (or merely differently-cased server-generated)
        // URL just because "/Admin" isn't a byte-for-byte match for "/admin".
        $path = strtolower($path);

        foreach (self::DENYLISTED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Public: also reused by App\Http\Responses\LoginResponse (the admin
     * panel's own post-login redirect) — one origin-comparison
     * implementation, two consumers.
     *
     * NEVER parse_url($url, PHP_URL_HOST) + string comparison here — PHP's
     * parse_url() and the WHATWG URL Standard (what every real browser
     * implements) DISAGREE on how to parse an authority containing a
     * backslash:
     *
     *   $u = "http://evil.example\@registro.local/admin/x";
     *   parse_url($u, PHP_URL_HOST);  // "registro.local"  (PHP)
     *   // but WHATWG (browsers): backslash in a special-scheme authority is
     *   // normalized to "/" BEFORE parsing, so the SAME string resolves to
     *   // host "evil.example", path "/@registro.local/admin/x".
     *
     * A check built on parse_url() would call this "our own host" — the
     * browser that actually receives the Location: header navigates to
     * evil.example. Comparing against parse_url()'s opinion of a string is
     * fundamentally the wrong question; the only question that can't be
     * re-litigated by a different parser is "does this string start with
     * literally our own origin". Also rejects any candidate containing a raw
     * backslash or a literal ".." path segment outright, before origin
     * comparison even runs — a browser normalizes
     * "/admin/../platform/x" to "/platform/x", turning an apparently-safe
     * prefix match into a different destination entirely.
     */
    public static function isSameOrigin(string $url, Request $request): bool
    {
        if (static::hasUnsafeRawCharacters($url)) {
            return false;
        }

        $origin = $request->getSchemeAndHttpHost();

        // getSchemeAndHttpHost() never has a trailing slash. A URL equal to
        // the origin itself (no path at all) is a legitimate target — e.g.
        // the panel's own bare root — so it's checked for explicitly rather
        // than only ever matching "{origin}/...".
        return $url === $origin || str_starts_with($url, $origin.'/');
    }

    /**
     * Path portion of $url relative to the current request's origin. Caller
     * MUST have already confirmed isSameOrigin($url, $request) — this does
     * no origin validation of its own, only strips the origin prefix (or
     * returns "/" when $url equals the origin exactly, no path present).
     */
    public static function pathWithinOrigin(string $url, Request $request): string
    {
        $origin = $request->getSchemeAndHttpHost();

        return $url === $origin ? '/' : substr($url, strlen($origin));
    }

    private static function hasUnsafeRawCharacters(string $url): bool
    {
        if (str_contains($url, '\\')) {
            return true;
        }

        foreach (explode('/', $url) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }
}
