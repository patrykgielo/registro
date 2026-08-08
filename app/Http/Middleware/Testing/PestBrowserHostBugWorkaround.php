<?php

declare(strict_types=1);

namespace App\Http\Middleware\Testing;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workaround for an OPEN, unfixed upstream bug in pestphp/pest-plugin-browser:
 * https://github.com/pestphp/pest/issues/1734 (opened 2026-06-21, no maintainer
 * response as of 2026-08-08). Related, unmerged fix attempt:
 * https://github.com/pestphp/pest-plugin-browser/pull/224
 *
 * DELETE this class and its registration in bootstrap/app.php once the upstream
 * issue is fixed and the fixed pest-plugin-browser version is required.
 *
 * --- The bug --------------------------------------------------------------
 *
 * `Pest\Browser\Drivers\LaravelHttpServer::handleRequest()` always builds the
 * Symfony request from a hardcoded `http://127.0.0.1:{port}` URL (see
 * `Pest\Browser\ServerManager::DEFAULT_HOST`), never from the real Host the
 * Playwright-driven browser actually sent. `Symfony\Component\HttpFoundation\
 * Request::create()` derives the SERVER bag (`SERVER_NAME`, `HTTP_HOST`) from
 * that fake URL, so it is always `127.0.0.1` — regardless of which tenant
 * subdomain the test visited.
 *
 * The vendor code then does `$symfonyRequest->headers->add($request->getHeaders())`
 * to overlay the browser's REAL headers, including the real `Host` header —
 * but only onto the HEADERS bag, not the SERVER bag. That patch is enough for
 * most of Laravel, since `Request::getHost()` reads the headers bag first.
 *
 * It is NOT enough for `Symfony\Component\HttpFoundation\Request::duplicate()`:
 * when called with a `$server` argument, it rebuilds `$dup->headers` FROM
 * `$dup->server->getHeaders()`, discarding whatever the headers bag held.
 * `Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware::makeFakeRequest()`
 * does exactly this on every `/livewire/update` call, to replay this project's
 * tenant-scoping middleware against the component's original mount path (see
 * `AppServiceProvider::registerLivewireTenantIsolation()` and
 * `app/docs/security/patterns/livewire-tenant-isolation.md`, Layer 6). Since
 * the SERVER bag was never patched, the replayed request's host reverts to
 * `127.0.0.1`, `ResolveTenant` can't match any tenant, and it redirects to the
 * root domain — the browser lands on `/` instead of `/admin`.
 *
 * This does NOT affect production: real PHP-FPM/Octane requests populate the
 * SERVER bag from the actual connection, never from a hardcoded fake URL, so
 * the server and headers bags already agree.
 *
 * --- The fix ----------------------------------------------------------------
 *
 * Re-sync `SERVER_NAME`/`HTTP_HOST` from the (already-correct) `Host` header
 * before anything else in the pipeline can clone/duplicate the request.
 * Registered as the very first middleware in the GLOBAL stack (see
 * `bootstrap/app.php`) — before session, before routing, before Livewire's
 * own middleware. It is registered unconditionally but returns immediately
 * unless `APP_ENV=testing`, so outside tests it costs one string comparison
 * and changes nothing.
 *
 * Scoped to hosts matching `config('app.domain')` (and its subdomains) rather
 * than blindly trusting any `Host` header: this class exists to fix a test
 * harness quirk, not to become a general-purpose Host-header rewriter.
 */
class PestBrowserHostBugWorkaround
{
    public function handle(Request $request, Closure $next): Response
    {
        // The ONLY thing keeping this out of production. Checked here, not at
        // registration time in bootstrap/app.php, because that closure runs
        // before the environment and config are loaded — see the comment there.
        if (! app()->environment('testing')) {
            return $next($request);
        }

        $hostHeader = $request->headers->get('Host');
        $baseDomain = config('app.domain');

        if ($hostHeader === null || $baseDomain === null) {
            return $next($request);
        }

        $hostWithoutPort = explode(':', $hostHeader, 2)[0];

        $matchesConfiguredDomain = $hostWithoutPort === $baseDomain
            || str_ends_with($hostWithoutPort, '.'.$baseDomain);

        if ($matchesConfiguredDomain) {
            $request->server->set('HTTP_HOST', $hostHeader);
            $request->server->set('SERVER_NAME', $hostWithoutPort);
        }

        return $next($request);
    }
}
