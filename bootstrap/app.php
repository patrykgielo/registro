<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'check-rental-enabled' => \App\Http\Middleware\CheckRentalEnabled::class,
            'require.tenant' => \App\Http\Middleware\RequireTenant::class,
        ]);

        // Exclude webhook routes from CSRF protection
        $middleware->validateCsrfTokens(except: [
            'api/webhooks/*',
            'webhooks/przelewy24',
        ]);

        // Add maintenance mode check to web middleware group
        // Runs AFTER session/auth middleware, allowing Auth::user() to work
        $middleware->web(append: [
            \App\Http\Middleware\CheckMaintenanceMode::class,
        ]);

        // Workaround for pest-plugin-browser#1734 (open upstream bug, see the
        // class docblock for the full mechanism). Prepended to the GLOBAL stack
        // (before session, routing, and Livewire's own middleware) so it runs
        // before Livewire\Mechanisms\PersistentMiddleware clones the request's
        // server bag on every /livewire/update call.
        //
        // Registered unconditionally, but inert outside APP_ENV=testing: the
        // environment check lives in the middleware's handle() rather than
        // here. This closure fires from afterResolving(HttpKernel::class),
        // i.e. BEFORE LoadEnvironmentVariables/LoadConfiguration run, so a
        // gate at this point would depend on Laravel's internal bootstrap
        // ordering — an implementation detail, not a documented contract,
        // and one a framework upgrade could change silently.
        $middleware->prepend(\App\Http\Middleware\Testing\PestBrowserHostBugWorkaround::class);

        // TrustHosts: Laravel's own Host-header validation, independent of and
        // in addition to ResolveTenant's app-level checks (subdomain-suffix
        // match, TENANT_HOSTS allowlist for pinned stacks). Not registered at
        // all before this — bootstrap/app.php previously configured neither
        // TrustHosts nor TrustProxies (see task brief).
        //
        // shouldSpecifyTrustedHosts() (vendor/laravel/framework/src/Illuminate/
        // Http/Middleware/TrustHosts.php) is a no-op outside local/testing —
        // this project's dev default (APP_ENV=local) and the full test suite
        // (APP_ENV=testing via .env.testing) are BOTH unaffected by design, so
        // local dev and tests/Browser stay exactly as they were. It enforces
        // in staging/production.
        //
        // subdomains: true (the default) keeps Laravel's own pattern —
        // config('app.url')'s host plus all its subdomains — trusted
        // unconditionally, which is exactly what the shared legacy stack's
        // {slug}.{APP_DOMAIN} resolution needs. TrustedTenantHosts::patterns()
        // adds config('app.tenant_hosts') (TENANT_HOSTS) on top, for a pinned
        // stack-per-tenant container answering on a client's own domain.
        //
        // Passed as a closure (not a resolved array) so config() is read at
        // request time, not while this file's own closure runs — the same
        // early-boot timing hazard documented on PestBrowserHostBugWorkaround.
        $middleware->trustHosts(at: fn () => \App\Support\TrustedTenantHosts::patterns());

        // TrustProxies is ALWAYS in Laravel's global middleware stack
        // (Illuminate\Foundation\Configuration\Middleware::getGlobalMiddleware()
        // lists it unconditionally — unlike TrustHosts, there is no flag to opt
        // out of registering it). What it actually trusts is driven entirely by
        // config('trustedproxy.proxies') (TRUSTED_PROXIES_CIDR) — see
        // config/trustedproxy.php for why that's configured there and not via
        // trustProxies(at: ...) here.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
