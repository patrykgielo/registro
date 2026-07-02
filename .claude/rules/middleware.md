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
- `CheckBookingEnabled` (`check-booking-enabled`) - przekierowuje na home gdy `isBookingEnabled() === false` dla tenanta
- `CheckRentalEnabled` (`check-rental-enabled`) - przekierowuje na home gdy `isRentalEnabled() === false` dla tenanta
- `RequireTenant` (`require.tenant`) - `abort(404)` gdy `TenantFeature::currentTenant() === null`. **KRYTYCZNE (VULN-003)**: `ResolveTenant` na root domain celowo NIE ustawia `tenant` (marketplace) — ale `BelongsToOrganization` scope wtedy silently no-opuje (zero filtrowania). KAŻDA nowa publiczna trasa query-ująca model `BelongsToOrganization` MUSI mieć `RequireTenant::class` zaraz PO `ResolveTenant::class`. Patrz `app/docs/security/vulnerabilities/VULN-003-root-domain-tenant-bypass.md`.

### Rejestracja w `bootstrap/app.php`

```php
$middleware->alias([
    'check-booking-enabled' => \App\Http\Middleware\CheckBookingEnabled::class,
    'check-rental-enabled'  => \App\Http\Middleware\CheckRentalEnabled::class,
    'require.tenant'        => \App\Http\Middleware\RequireTenant::class,
]);
```

### Zastosowanie w `routes/web.php`

```php
// Trasy koszyka/wynajmu — dostępne tylko gdy rental jest aktywny
Route::middleware(['auth', 'tenant', \App\Http\Middleware\CheckRentalEnabled::class])
    ->group(function () {
        Route::get('/koszyk', [CartController::class, 'show'])->name('cart.show');
        // ...
    });

// Trasy rezerwacji — dostępne tylko gdy booking jest aktywny
Route::middleware(['auth', 'tenant', 'check-booking-enabled'])
    ->group(function () {
        Route::get('/rezerwacja', ...)->name('booking.step');
    });

// Publiczna trasa query-ująca model BelongsToOrganization — MUSI mieć RequireTenant
// zaraz po ResolveTenant, inaczej root domain widzi dane WSZYSTKICH tenantów (VULN-003)
Route::middleware([ResolveTenant::class, RequireTenant::class])
    ->get('/uslugi', [ServiceController::class, 'index'])->name('services.index');
```
