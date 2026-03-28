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

## Istniejące Services (reference)

- `AppointmentService` - rezerwacje, dostępność staff
- `StaffScheduleService` - harmonogramy, urlopy, wyjątki
- `CalendarService` - generowanie kalendarza
- `MaintenanceService` - tryb maintenance
- `ProfileService` - profil użytkownika
- `Email/EmailService` - wysyłka emaili
- `Sms/SmsService` - wysyłka SMS
- `Coupon/CouponService` - kupony rabatowe
