---
paths:
  - "app/Http/Middleware/**"
---

# Middleware Rules

## Basic Structure

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMaintenanceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        // Logic przed request
        if ($this->shouldBlock($request)) {
            return response()->view('maintenance', [], 503);
        }

        // Przepuść request
        $response = $next($request);

        // Logic po request (opcjonalne)
        return $response;
    }
}
```

## Handle Method Signature

```php
// ✅ PRAWIDŁOWO - nowy typ Response
public function handle(Request $request, Closure $next): Response

// ❌ ŹLE - stary pattern
public function handle($request, Closure $next)
```

## Bypass Patterns (CheckMaintenanceMode example)

```php
protected function shouldBypass(Request $request): bool
{
    // Admin bypass
    if ($request->user()?->hasRole('admin')) {
        return true;
    }

    // IP whitelist
    if (in_array($request->ip(), config('maintenance.allowed_ips', []))) {
        return true;
    }

    // Route bypass
    $bypassRoutes = ['login', 'admin.*'];
    if ($request->routeIs(...$bypassRoutes)) {
        return true;
    }

    return false;
}
```

## Service Injection

```php
public function __construct(
    protected MaintenanceService $maintenanceService
) {}

public function handle(Request $request, Closure $next): Response
{
    if ($this->maintenanceService->isEnabled()) {
        return response()->view('maintenance', [], 503);
    }

    return $next($request);
}
```

## Registration in Kernel

```php
// bootstrap/app.php (Laravel 11+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \App\Http\Middleware\CheckMaintenanceMode::class,
    ]);
})
```

## Route-Specific Middleware

```php
// routes/web.php
Route::middleware(['auth', 'verified', 'check-maintenance'])
    ->group(function () {
        // Protected routes
    });
```

## Response Modification

```php
public function handle(Request $request, Closure $next): Response
{
    $response = $next($request);

    // Add headers
    $response->headers->set('X-Frame-Options', 'DENY');
    $response->headers->set('X-Content-Type-Options', 'nosniff');

    return $response;
}
```

## Rate Limiting (via middleware)

```php
// routes/web.php
Route::middleware(['throttle:10,1'])->group(function () {
    Route::post('/booking', [BookingController::class, 'store']);
});
```

## Terminate Method (cleanup po response)

```php
public function terminate(Request $request, Response $response): void
{
    // Cleanup, logging, etc.
    // Wykonywane PO wysłaniu response do klienta
    Log::info('Request completed', [
        'path' => $request->path(),
        'status' => $response->getStatusCode(),
    ]);
}
```

## Istniejące Middleware (reference)

- `CheckMaintenanceMode` - tryb maintenance z bypass dla admin
- `Authenticate` - Laravel default
- `VerifyCsrfToken` - Laravel default
