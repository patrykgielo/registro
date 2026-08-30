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

## Rate Limiting (via middleware)

```php
// routes/web.php
Route::middleware(['throttle:10,1'])->group(function () {
    Route::post('/booking', [BookingController::class, 'store']);
});
```

## Terminate Method (cleanup po response, wykonywane PO wysłaniu do klienta)

```php
public function terminate(Request $request, Response $response): void
{
    Log::info('Request completed', ['path' => $request->path()]);
}
```

## Istniejące Middleware (reference)

- `CheckMaintenanceMode` - tryb maintenance z bypass dla admin
- `Authenticate` - Laravel default
- `VerifyCsrfToken` - Laravel default
- `CheckBookingEnabled` (`check-booking-enabled`) - przekierowuje na home gdy `isBookingEnabled() === false` dla tenanta
- `CheckRentalEnabled` (`check-rental-enabled`) - przekierowuje na home gdy `isRentalEnabled() === false` dla tenanta
- `RequireTenant` (`require.tenant`) - `abort(404)` gdy `$request->attributes->get('tenant') === null`. **KRYTYCZNE (VULN-003)**: `ResolveTenant` na root domain celowo NIE ustawia `tenant` — `BelongsToOrganization` scope wtedy no-opuje. KAŻDA trasa query-ująca `BelongsToOrganization` (w tym `routes/api.php` — `api` group NIE ma `ResolveTenant` domyślnie!) MUSI mieć `RequireTenant::class` zaraz PO `ResolveTenant::class`. Szczegóły + 2 gap-fixy: `app/docs/security/vulnerabilities/VULN-003-root-domain-tenant-bypass.md`.
  - **NIGDY** `TenantFeature::currentTenant()` w `RequireTenant` — ma session fallback zapisywany dla KAŻDEGO gościa PRZED `canAccessTenant()` (privilege-escalation przez stale session). Zawsze `$request->attributes->get('tenant')`.
  - **Layer 2 (2026-07-03, defense-in-depth):** `RequireTenant` to teraz TYLKO pierwsza linia obrony — `BelongsToOrganization` sam fail-closuje (zwraca 0 wierszy) gdy `ResolveTenant` faktycznie zadziałał i nic nie znalazł (`tenant_resolution_attempted` request attribute, ustawiany jako pierwsza linia `ResolveTenant::handle()`). Trasa BEZ `RequireTenant` już nie leakuje cross-tenant danych — serwuje puste wyniki zamiast 404. Szczegóły: `.claude/rules/models.md` (`tenant_resolution_attempted`), `app/docs/security/vulnerabilities/VULN-003-root-domain-tenant-bypass.md` (sekcja Layer 2).
  - Laravel `$middlewarePriority` wymusza `Authenticate` przed niesklasyfikowanym middleware bez względu na kolejność deklaracji (`route:list -vvv`). NIE naprawiaj tego globalnym `prependToPriorityList()` — to nadal zły mechanizm, bo działa na całą aplikację naraz i po cichu.
  - **Layer 7 (2026-08-30): `ResolveTenant` jest w bazowej grupie `web`, PRZED `SubstituteBindings`.** `bootstrap/app.php` robi `web(remove: [SubstituteBindings])`, potem `web(append: [ResolveTenant, SubstituteBindings, CheckMaintenanceMode])`. Powód: `SubstituteBindings` należy do grupy `web`, a middleware trasy biegnie PO grupie — więc route-model-binding po slugu rozwiązywał się, **zanim wiadomo było, czyj to tenant**. Siedem tras storefrontu (`{service:slug}`, `{category:slug}`, w tym dwa endpointy API dostępności) serwowało **cudzy zasób z HTTP 200**. Ta sama wada dotyczyła wiązania po ID (`orders.show`, `orders.extension.*`), gdzie chronił je wyłącznie ręczny `abort_unless` w kontrolerze.
    - **Kolejność jest trwała:** `SortedMiddleware::sortMiddleware()` przestawia wyłącznie pary middleware obecne na liście `$middlewarePriority`. `ResolveTenant` na niej nie jest, więc nic go nie przeskoczy. Zweryfikowane `route:list -vvv` na wielu trasach, nie tylko analitycznie. **Nie dodawaj `ResolveTenant` do listy priorytetów** — straciłby tę własność.
    - **Świadomy skutek uboczny: granica TENANTA odpowiada teraz 404, nie 403.** Model obcego tenanta nie znajduje się już przy wiązaniu, więc `abort_unless(..., 403)` w `OrderController`/`RentalExtensionController` jest nieosiągalny. To **poprawa spójności**: `RequireTenant` i `OrderProtocolController:78` używały 404 dla tej granicy od dawna, a 404 nie potwierdza istnienia cudzego zasobu. **Granica per-UŻYTKOWNIK w obrębie jednego tenanta nadal odpowiada 403** — sprawdź, którą granicę testujesz, zanim zmienisz asercję.
    - `/admin` i `/platform` **nietknięte** — panele Filamenta mają własne tablice middleware i nie odwołują się do grupy `web` (`loadRoutesFrom()`, nie `Route::middleware('web')`).
  - **Layer 3 (2026-07-03):** `booking.*`/`appointments.*`/`profile.*` (`routes/web.php:232`) — jedyny celowy wyjątek bez `RequireTenant` — teraz go mają. Zamykało to session-fallback cross-tenant write (`Appointment::create()`). Szczegóły: VULN-003 doc, sekcja Layer 3.
  - **Layer 4 (2026-07-03):** `cart.*`/`checkout.*`/`orders.*` (`routes/web.php:135`) + `dev/fake-pay` — identyczna luka jak Layer 3, teraz naprawiona. `Cart`/`Checkout`/`OrderController` polegały na `abort_unless(TenantFeature::currentTenant() !== null, 404)` — session fallback omijał to na root domain (cross-tenant write: `CartItem`/`Order`). Szczegóły: VULN-003 doc, sekcja Layer 4.
  - **Layer 5 (2026-07-03/04):** home route (`GET /`) dostał `RequireTenant` w Layer 1 mimo że root domain **z założenia** nigdy nie ma tenanta — trasa zaczęła twardo 404ować na głównym lokalnym URL (regresja, nie luka). Naprawa: **NIE** usuwaj `RequireTenant` polegając na `TenantFeature::currentTenant()` (session fallback — złapane przez review!) — gate bezpośrednio na `$request->attributes->get('tenant')`; gdy null, od razu `home-fallback`, bez dotykania `SettingsManager::get()`/modeli. To 3. wystąpienie tej samej klasy błędu (po Layer 3/4): `TenantFeature::currentTenant()` NIGDY nie powinien decydować o dostępie/danych na trasie osiągalnej z root domain. Znany follow-up tej samej klasy: `CheckRegistrationEnabled`. Szczegóły: VULN-003 doc, sekcja Layer 5.
  - **Layer 6 (2026-07-05):** `POST /livewire/update` (Livewire's own shared AJAX route) nigdy nie przechodził przez `ResolveTenant`/`RequireTenant` — niemal cała prawdziwa interakcja w `/admin` (filtry, zapisy) dzieje się przez tę trasę, więc `session('tenant_id')` (poisoned przez inną kartę/subdomenę) był jedynym źródłem tenanta. Fix: `Livewire::addPersistentMiddleware([ResolveTenant::class, RequireTenant::class])` w `AppServiceProvider::boot()` — Livewire's `PersistentMiddleware` replay'uje te middleware na fake-request zbudowanym z REALNEGO Host + tamper-proof `memo.path` z checksummed snapshotu. `/platform` niedotknięty (jego trasy nigdy nie rejestrują tych middleware). Szczegóły: `app/docs/security/patterns/livewire-tenant-isolation.md`.
  - **Stack-per-tenant pinning (2026-08-08):** przy `config('app.tenant_slug')` (TENANT_SLUG) ustawionym, `ResolveTenant::handle()` idzie od razu w `handlePinnedTenant()` — rozwiązuje tenanta ze zmiennej środowiskowej zamiast z Hosta. RÓWNOLEGLE, niezależnie: `config('app.tenant_hosts')` (TENANT_HOSTS) to fail-closed allowlist — Host spoza niej to 404, nawet gdy slug rozwiązuje się poprawnie; pusty/brak TENANT_HOSTS = 404 na każdym Hoście (pinowanie samo w sobie NIE autoryzuje Hosta). `TENANT_SLUG` puste → gałąź w ogóle nieużywana, zero zmian w shared-stack. Szczegóły: `app/docs/features/tenant-stack-provisioning.md`.

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

## TrustHosts / TrustProxies (2026-08-08)

`bootstrap/app.php` wcześniej nie konfigurował żadnego z nich — luka: nieskonfigurowany `TrustHosts`
nie waliduje w ogóle Hosta na poziomie frameworka (poza sprawdzeniem samego `ResolveTenant`), a
`TrustProxies` (zawsze w globalnym stosie, niezależnie od konfiguracji) bez zaufanych proxy ignoruje
`X-Forwarded-*` w całości — bezpieczne domyślnie, ale ślepe też na PRAWDZIWY edge po przeniesieniu TLS.

- `TrustHosts` rejestrowany przez `$middleware->trustHosts(at: fn () => TrustedTenantHosts::patterns())`
  — closure, nie gotowa tablica, bo `withMiddleware()` odpala się PRZED `LoadEnvironmentVariables`/
  `LoadConfiguration` (ten sam timing co `PestBrowserHostBugWorkaround`). `shouldSpecifyTrustedHosts()`
  jest no-opem poza `local`/`testing` (Laravel core) — dev i `tests/Browser` nietknięte.
- `TrustedTenantHosts::patterns()` (`app/Support/TrustedTenantHosts.php`) dokłada `TENANT_HOSTS` do
  domyślnego `config('app.url')`+subdomeny (`subdomains: true`).
- `TrustProxies` NIGDY nie konfigurowany przez `trustProxies(at: ...)` w bootstrapie (ten sam timing
  hazard) — zamiast tego `config/trustedproxy.php` (`TRUSTED_PROXIES_CIDR`), czytany przy REQUEST, nie
  przy boot. Domyślnie `null` = zero zaufanych proxy = `X-Forwarded-Host` zawsze ignorowany. NIGDY `*`.
