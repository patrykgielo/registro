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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
