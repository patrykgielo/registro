---
paths:
  - "app/Services/**"
---

# Service Layer Rules

## Architecture Pattern

Services są warstwą logiki biznesowej. Controllers powinny być "cienkie" i delegować do Services.

## Constructor Dependency Injection

```php
// ✅ PRAWIDŁOWO
public function __construct(
    protected SettingsManager $settings,
    protected StaffScheduleService $staffScheduleService
) {}

// ❌ ŹLE - new w metodzie
public function someMethod() {
    $service = new OtherService(); // NIE!
}
```

## Naming Conventions

- `{Domain}Service.php` - główna logika domeny (AppointmentService, BookingService)
- `{Feature}Service.php` - konkretna funkcjonalność (CalendarService, ProfileService)
- Subdirectories dla grouped services: `Email/`, `Sms/`, `Coupon/`

## Interface Pattern (dla wymienianych implementacji)

```php
// app/Services/Email/EmailGatewayInterface.php
interface EmailGatewayInterface {
    public function send(string $to, string $subject, string $body): bool;
}

// app/Services/Email/SmtpMailer.php - produkcja
// app/Services/Email/FakeEmailGateway.php - testing
```

## SettingsManager Integration

```php
// Używaj SettingsManager dla runtime config
public function __construct(protected SettingsManager $settings) {}

public function getSlotDuration(): int {
    return $this->settings->get('booking.slot_duration', 30);
}
```

## SettingsManager — Tenant-Aware Feature Flags

`SettingsManager` udostępnia dwie metody do sprawdzania aktywnych trybów rezerwacji tenanta:

```php
// Czy tenant obsługuje rezerwacje czasowe (time_slot)?
$settings->isBookingEnabled(): bool
// → false dla org z booking_type === 'item_rental'
// → true dla 'time_slot' i 'both'

// Czy tenant obsługuje wynajem sprzętu (item_rental)?
$settings->isRentalEnabled(): bool
// → true dla 'item_rental' i 'both'
// → false dla 'time_slot'
```

### Użycie w kontrolerach / middleware

```php
// Middleware (np. CheckRentalEnabled):
if (! app(SettingsManager::class)->isRentalEnabled()) {
    return redirect()->route('home')->with('info', 'Wynajem sprzętu jest niedostępny.');
}

// Widoki — flagi są dostępne globalnie przez AppServiceProvider::shareFeatureFlags():
// @if($rentalEnabled) ... @endif
// @if($bookingEnabled) ... @endif
```

### Globalne zmienne Blade (shareFeatureFlags)

`AppServiceProvider::shareFeatureFlags()` udostępnia `$rentalEnabled` i `$bookingEnabled` we WSZYSTKICH widokach Blade. Nie trzeba ich przekazywać ręcznie do każdego widoku — wyjątkiem są komponenty anonimowe (`@props`), które muszą je jawnie deklarować:

```blade
{{-- Komponent anonimowy MUSI zadeklarować prop --}}
@props(['rentalEnabled' => false, 'bookingEnabled' => false])

@if($rentalEnabled)
    <a href="{{ route('cart.show') }}">Koszyk</a>
@endif
@if($bookingEnabled)
    <a href="{{ route('booking.step', 1) }}">Zarezerwuj</a>
@endif
```

**UWAGA:** `booking_type` może być: `time_slot`, `item_rental`, `both`. Sprawdzaj zawsze przez `SettingsManager`, nie przez bezpośredni dostęp do organizacji.

## Return Type Declarations

```php
// ✅ Zawsze deklaruj typy zwracane
public function checkStaffAvailability(...): bool
public function getAvailableSlots(...): Collection
public function createAppointment(...): Appointment
```

## DocBlock Documentation

```php
/**
 * Check if staff member is available for given time slot
 *
 * Uses new calendar-based availability system (Option B):
 * - Checks vacation periods
 * - Checks date exceptions
 * - Falls back to base schedule
 */
public function checkStaffAvailability(...): bool
```

## Cache Keys — MUSZĄ nieść identyfikator tenanta (shared stack)

Scope'owanie ZAPYTANIA (`BelongsToOrganization`) chroni WYNIK, nie to, komu ten wynik zostanie
podany — o tym decyduje klucz cache. `NavigationService` cache'ował menu pod
`"navigation.pages.{location}"` bez tenanta; `CACHE_PREFIX` nie jest ustawiony w `.env`, więc
`config/cache.php` wyprowadza go z `APP_NAME` — identyczny dla wszystkich tenantów. Pierwszy
tenant, który rozgrzał cache, narzucał swoje menu wszystkim pozostałym na cały TTL.

```php
// ❌ ŹLE — brak identyfikatora tenanta w kluczu
Cache::remember("navigation.pages.{$location}", $ttl, fn () => ...);

// ✅ DOBRZE — wzorzec z ServiceAreaValidator::cacheKey()
private function cacheKey(string $location): string
{
    $tenantId = $this->requestTenantId(); // patrz niżej — NIE TenantFeature::currentTenant()
    return "navigation.pages.{$location}.".($tenantId ?? 'none');
}
```

**Fallback dla braku tenanta:** jeśli komponent renderuje się TYLKO za `RequireTenant`
(np. `ServiceAreaValidator`), `TenantFeature::currentTenant()` jest bezpieczny. Jeśli renderuje
się też na **domenie głównej** (np. `NavigationService` w layoucie — stopka/nagłówek na każdej
publicznej stronie) — NIE używaj `TenantFeature::currentTenant()`. Jego 3. gałąź czyta
`session('tenant_id')`, które `ResolveTenant` zapisuje przy KAŻDEJ wizycie na subdomenie. Gość,
który wcześniej odwiedził subdomenę tenanta A, a potem trafia na domenę główną, dostanie
wciąż-aktywną sesję A → menu A wyrenderuje się na "neutralnej" stronie głównej (ta sama klasa
błędu co VULN-003 Layers 1/2/5, `models.md` → `tenant_resolution_attempted`). Zamiast tego czytaj
`$request->attributes->get('tenant')` bezpośrednio (wzorzec z `routes/web.php`'s home route,
`RequireTenant`, `CheckRegistrationEnabled`) — `null` na domenie głównej to poprawna, nie
fallbackowa, odpowiedź.

**Czyszczenie cache musi nadążyć za zmianą klucza.** Trzy miejsca muszą się zgadzać: metoda
czytająca (`remember`), metoda czyszcząca wywoływana z panelu/testów (`clearCache()`), i observer
modelu (`Model::booted()`'s `saved`/`deleted`). Zmiana formatu klucza w jednym, a nie w pozostałych
dwóch, to CICHY błąd — `Cache::forget()` na starym formacie nie trafia w nic, zapis rekordu
przestaje odświeżać cache, a nikt nie dostaje wyjątku. Observer modelu wywołuj z **jawnym** id
tenanta z samego rekordu (`$model->organization_id`), nie z ambient kontekstu — observer może
odpalić się z konsoli/kolejki/seedera, gdzie "bieżący" tenant nie istnieje, a rekord i tak ma
swój własny, poprawny `organization_id`.

**Martwy `Cache::forget()` jest gorszy niż jego brak** — wygląda jak działająca inwalidacja, więc
nikt go nie podejrzewa przy debugowaniu stale cache. Dwa znalezione przykłady: `forget('home.page')`
w `PageObserver` (nic nigdy nie zapisywało pod tym kluczem — usunięty) i
`forget("settings:{$group}")` w `HasGroupedSettings` (format niezgodny z prawdziwym kluczem
`SettingsManager` — `settings:tenant:{id}:{group}`; `SettingsManager::set()` i tak czyści
poprawnie per klucz, więc usunięty jako redundantny). Znajdując taki `forget()`: sprawdź, czy
ktokolwiek KIEDYKOLWIEK zapisuje pod dokładnie tym stringiem, zanim uznasz go za działający.

## Single Responsibility

- Jeden service = jedna domena/odpowiedzialność
- AppointmentService nie powinien wysyłać emaili - deleguj do EmailService
- Jeśli service robi zbyt wiele - rozbij na mniejsze

## Queue Integration

```php
// Dla długich operacji używaj Jobs
dispatch(new SendAppointmentConfirmation($appointment));

// NIE wykonuj długich operacji synchronicznie w service
```

## Error Handling

```php
// Używaj custom exceptions dla domenowych błędów
throw new StaffNotAvailableException($staffId, $date);
throw new InvalidBookingTimeException($reason);
```

## Carbon vs String Comparison in Collections — CRITICAL (Bug 2026-05-10)

**Problem:** `Collection->where('date', $today->toDateString())` uses loose `==` comparison. In PHP 8, comparing a Carbon object with a string (`Carbon == '2026-05-10'`) ALWAYS returns `false`.

```php
// ❌ BŁĄD — Carbon cast column vs string: always returns empty
$todayRows = $rows->where('date', Carbon::today()->toDateString());

// ✅ POPRAWNIE — explicit filter with toDateString() on both sides
$todayRows = $rows->filter(fn ($r) => (
    $r->date instanceof \Illuminate\Support\Carbon
        ? $r->date->toDateString()
        : (string) $r->date
) === Carbon::today()->toDateString());
```

**Dotyczy:** Każdej Collection gdzie model ma kolumnę z `'date' => 'date'` cast.
Kolumna wraca jako Carbon → `->where('date', 'string')` nie znajdzie nic.

**Uwaga na ->toBase():** Po `->toBase()` kolekcja nadal zawiera obiekty Eloquent z castami.
stdClass z `liveToCollection()` ma `$obj->date = $date->copy()` (Carbon) — obsłuż oba przypadki.

---

## Pliki `config/*.php` to adaptery typów, nie worek na `env()` (2026-08-16)

`config/przelewy24.php` mapował `P24_POS_ID` na `int`, a SDK deklaruje `?string $posId`. Pod
`declare(strict_types=1)` w `Przelewy24Service` → `TypeError` przy KAŻDYM checkoutcie online na
UAT. Wyzwalacz: `.env.production.example` wysyła klucze **obecne, ale puste** — `env()` zwraca `''`,
nie `null`, więc `env(...) !== null ? (int) env(...) : null` dawało `int(0)`.

```php
// ❌ pusty env cicho staje się prawidłowo wyglądającym zerem
'pos_id' => env('P24_POS_ID') !== null ? (int) env('P24_POS_ID') : null,

// ✅ typ SDK + pusty/brak = wartość „nie ustawiono" wg SDK
'pos_id' => ($v === null || trim((string) $v) === '') ? null : (string) $v,
```

Dwie zasady dla każdej wartości w `config/`:
1. Rzutuj na typ, który deklaruje konsument (podpis konstruktora/metody), nie zostawiaj surowego `env()`.
2. Pusty/nieobecny env → wartość „nie ustawiono" konsumenta (zwykle `null`), nigdy skoercowane
   `0`/`''` — one wyglądają na poprawną konfigurację i wysypują się dopiero u klienta.

**„Czy usługa jest w ogóle skonfigurowana" ma mieć JEDNO miejsce** (`Przelewy24Service::isConfigured()`),
czytane zarówno przez samą usługę, jak i przez warstwę, która ją oferuje użytkownikowi
(`SettingsManager::availableSettlementMethods()`) — nieskonfigurowanej bramki nie pokazuj w formularzu,
zamiast pozwalać klientowi wybrać ścieżkę, która na pewno padnie.

**Metoda „czy skonfigurowane" jako `static`, nie instancyjna** — pytanie dotyczy globalnego
env/config, a nie instancji, i wołają ją miejsca spoza własnego przepływu. Instancyjna oznaczałaby
`app(Service::class)` na każdym renderze checkoutu, a suity feature podmieniają ten serwis na mocka
bez oczekiwania na tę metodę → wybuch w testach niezwiązanych z konfiguracją. `static` omija
kontener i czyta ten sam `config()`.

---

## Istniejące Services (reference)

- `AppointmentService` - rezerwacje, dostępność staff
- `StaffScheduleService` - harmonogramy, urlopy, wyjątki
- `CalendarService` - generowanie kalendarza
- `MaintenanceService` - tryb maintenance
- `ProfileService` - profil użytkownika
- `Email/EmailService` - wysyłka emaili
- `Sms/SmsService` - wysyłka SMS
- `Coupon/CouponService` - kupony rabatowe
