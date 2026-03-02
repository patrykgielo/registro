# Architecture Analysis: Multi-Service Booking — Single-Service Coupling Points

**Data:** 2026-02-02
**Cel:** Mapa wszystkich miejsc w kodzie sprzężonych z single-service flow, wymagających modyfikacji dla multi-service booking (main + addons)

---

## 1. BookingController (16 coupling points)

**Plik:** `app/Http/Controllers/BookingController.php` (745 linii)

| # | Linia | Typ | Opis | Zmiana |
|---|-------|-----|------|--------|
| 1 | 47 | session | `session(['booking.service_id' => $service->id])` — single service | Cart-based: `booking.cart` z primary + addons |
| 2 | 57-59 | validation | `'service_id' => 'required\|exists:services,id'` — single | `'primary_service_id' => 'required'`, `'addon_service_ids' => 'array'` |
| 3 | 61 | query | `$service = Service::findOrFail($request->service_id)` — single | Load primary + addons, pass to BookingCartService |
| 4 | 82-84 | availability | `getAvailableSlotsAcrossAllStaff(serviceId, duration)` — single duration | Cumulative duration: primary + sum(addons) |
| 5 | 112 | check | `if (empty($booking['service_id']))` — single check | Check cart: `empty($booking['cart'])` |
| 6 | 133 | session | `$existingServiceId = session('booking.service_id')` — reads single | Read from cart object |
| 7 | 134 | validation | `if ($existingServiceId && Service::find($existingServiceId))` | Validate all services in cart exist |
| 8 | 144-145 | step2 | `$serviceId = session('booking.service_id')` + `Service::findOrFail($serviceId)` | Load cart, pass all services to view |
| 9 | 205 | step5 | `$service = Service::findOrFail($booking['service_id'])` — review | Load all services, show itemized breakdown |
| 10 | 226 | cleanup | `session()->forget('booking.service_id')` | Forget `booking.cart` |
| 11 | 244 | validateStep1 | `'service_id' => 'required\|exists:services,id'` | Cart validation: primary required, addons optional |
| 12 | 247 | storeStep | `session(['booking.service_id' => $validated['service_id']])` | Store cart in session |
| 13 | 510 | confirm | `if (! $booking \|\| empty($booking['service_id']))` | Check cart presence |
| 14 | 530 | idempotency | `->where('service_id', $booking['service_id'])` — duplicate check | Check by primary_service_id + date + time (composite) |
| 15 | 568 | confirm | `$service = Service::findOrFail($booking['service_id'])` — single | Load all services, sum durations/prices |
| 16 | ~625 | create | `'service_id' => $booking['service_id']` w Appointment::create | Keep `service_id` (primary), add pivot insert for all services |

### Strategia refactoringu

```php
// PRZED (single service):
session(['booking.service_id' => $service->id]);
$service = Service::findOrFail(session('booking.service_id'));
$duration = $service->duration_minutes;

// PO (cart-based multi-service):
$cart = app(BookingCartService::class);
$cart->setPrimary($primaryServiceId);
$cart->addAddon($addonServiceId);
// session('booking.cart') = { primary_service_id: 5, addon_service_ids: [2, 8] }

$services = $cart->getAllServices(); // Collection
$totalDuration = $cart->getTotalDuration(); // sum of all durations
$totalPrice = $cart->getTotalPrice(); // sum with promotional pricing
```

---

## 2. AppointmentService (8 coupling points)

**Plik:** `app/Services/AppointmentService.php` (607 linii)

| # | Linia | Typ | Opis | Zmiana |
|---|-------|-----|------|--------|
| 1 | 28 | param | `int $serviceId` w checkStaffAvailability | Accept `int $primaryServiceId`, check staff competency for primary only |
| 2 | 41 | competency | `canPerformService($staff, $serviceId)` — single | Check primary service competency (addons are simple, no separate check) |
| 3 | 84 | param | `int $serviceId` w getAvailableSlotsAcrossAllStaff | Accept `int $primaryServiceId, int $totalDurationMinutes` |
| 4 | 196 | query | `'service_id', $serviceId` — staff filter | Filter staff by primary service competency |
| 5 | 343 | param | `int $serviceId` w getBulkAvailability | Accept `int $primaryServiceId, int $totalDurationMinutes` |
| 6 | 349 | query | `'service_id', $serviceId` — bulk filter | Filter by primary, use cumulative duration |
| 7 | 425 | load | `$service = Service::find($serviceId)` | Load primary service, use cumulative duration param |
| 8 | 501 | duration | `$serviceDurationMinutes = $service->duration_minutes` | Use `$totalDurationMinutes` param instead |

### Strategia refactoringu

```php
// PRZED:
public function getAvailableSlotsAcrossAllStaff(
    int $serviceId, Carbon $date, int $serviceDurationMinutes
)

// PO (zachowaj backward compatibility):
public function getAvailableSlotsAcrossAllStaff(
    int $serviceId, Carbon $date, int $serviceDurationMinutes
)
// $serviceId = primary service (dla staff competency check)
// $serviceDurationMinutes = cumulative duration (primary + addons)
// Sygnatury NIE muszą się zmieniać — caller przekazuje zsumowany czas
```

**Ważne:** Sygnatury metod mogą pozostać bez zmian. Caller (BookingController) przekazuje:
- `$serviceId` = primary service ID (do sprawdzenia kompetencji staff)
- `$serviceDurationMinutes` = cumulative duration (primary + addons)

To minimalizuje refaktor AppointmentService — logika wewnętrzna bez zmian.

---

## 3. Appointment Model (2 coupling points)

**Plik:** `app/Models/Appointment.php`

| # | Linia | Typ | Opis | Zmiana |
|---|-------|-----|------|--------|
| 1 | 24 | fillable | `'service_id'` w $fillable | Zachować (primary service shortcut) |
| 2 | 114-116 | relationship | `service()` → belongsTo(Service::class) | Zachować + dodać `services()` BelongsToMany |

### Nowe elementy modelu

```php
// Zachowane (backward compatible):
public function service(): BelongsTo
{
    return $this->belongsTo(Service::class);
}

// Nowe:
public function services(): BelongsToMany
{
    return $this->belongsToMany(Service::class, 'appointment_services')
        ->withPivot('is_primary', 'price_snapshot', 'duration_snapshot', 'sort_order')
        ->orderByPivot('sort_order');
}

public function primaryService(): BelongsTo
{
    return $this->belongsTo(Service::class, 'service_id');
}

public function addonServices(): BelongsToMany
{
    return $this->belongsToMany(Service::class, 'appointment_services')
        ->wherePivot('is_primary', false)
        ->withPivot('price_snapshot', 'duration_snapshot', 'sort_order');
}

public function totalPrice(): float
{
    return $this->services->sum(fn($s) => $s->pivot->price_snapshot);
}

public function totalDuration(): int
{
    return $this->services->sum(fn($s) => $s->pivot->duration_snapshot);
}
```

---

## 4. Service Model (0 coupling points — needs expansion)

**Plik:** `app/Models/Service.php`

| # | Linia | Typ | Opis | Zmiana |
|---|-------|-----|------|--------|
| 1 | 61-63 | relationship | `appointments()` → hasMany | Zachować + dodać BelongsToMany via pivot |

### Nowe elementy modelu

```php
// Nowe pola w $fillable:
'is_addon', 'is_promotional', 'promotional_price'

// Nowe relacje:
public function suggestedAddons(): BelongsToMany
{
    return $this->belongsToMany(Service::class, 'service_addons', 'service_id', 'addon_service_id')
        ->withPivot('sort_order', 'is_active')
        ->wherePivot('is_active', true)
        ->orderByPivot('sort_order');
}

public function addonOf(): BelongsToMany
{
    return $this->belongsToMany(Service::class, 'service_addons', 'addon_service_id', 'service_id')
        ->withPivot('sort_order', 'is_active');
}

// Helpers:
public function isAddon(): bool
{
    return (bool) $this->is_addon;
}

public function effectivePrice(): float
{
    if ($this->is_promotional && $this->promotional_price !== null) {
        return $this->promotional_price;
    }
    return $this->price;
}
```

---

## 5. Notifications (1 coupling point)

**Plik:** `app/Notifications/AppointmentCreatedNotification.php`

| # | Linia | Typ | Opis | Zmiana |
|---|-------|-----|------|--------|
| 1 | 76, 85 | relationship | `$appointment->load(['service', 'customer'])` + `$appointment->service->name` | Load `services` (plural), display list |

### Zmiana

```php
// PRZED:
$appointment->load(['service', 'customer']);
'service_name' => $appointment->service->name,

// PO:
$appointment->load(['services', 'customer']);
'services' => $appointment->services->map(fn($s) => [
    'name' => $s->name,
    'price' => $s->pivot->price_snapshot,
    'duration' => $s->pivot->duration_snapshot,
    'is_primary' => $s->pivot->is_primary,
]),
'total_price' => $appointment->totalPrice(),
'total_duration' => $appointment->totalDuration(),
```

Analogicznie: `AppointmentRescheduledNotification`, `AppointmentCancelledNotification`.

---

## 6. AppServiceProvider — Event Listeners (2 coupling points)

**Plik:** `app/Providers/AppServiceProvider.php`

| # | Linia | Typ | Opis | Zmiana |
|---|-------|-----|------|--------|
| 1 | 150-174 | event | 3 listenery: AppointmentCreated/Rescheduled/Cancelled | Notifications muszą obsłużyć multi-service context |
| 2 | 275 | sms | `'service_name' => $appointment->service?->name ?? 'N/A'` | Aggregate: `$appointment->services->pluck('name')->join(', ')` |

---

## 7. Filament Admin — AppointmentResource (4 coupling points)

**Plik:** `app/Filament/Resources/AppointmentResource.php`

| # | Linia | Typ | Opis | Zmiana |
|---|-------|-----|------|--------|
| 1 | 41-48 | form | `Select::make('service_id')` — single select | Zachować (primary), dodać Repeater/CheckboxList dla addonów |
| 2 | 97-105 | afterStateUpdated | Recalculates `end_time` from `$service->duration_minutes` | Sum durations: primary + selected addons |
| 3 | 243 | table | `TextColumn::make('service.name')` — single | Show primary + addon count: "Powłoka ceramiczna (+2)" |
| 4 | 313-315 | filter | `SelectFilter::make('service')` | Filter by primary service (zachować) |

---

## 8. Filament Admin — ServiceResource (0 coupling points — needs expansion)

**Plik:** `app/Filament/Resources/ServiceResource.php`

Nowe elementy:
- Pola formularza: `is_addon` toggle, `is_promotional` toggle, `promotional_price` (warunkowy)
- Relation Manager: `AddonsRelationManager` — konfiguracja sugerowanych addonów per usługa
- Kolumna tabeli: badge "Addon" / "Main" / "Promo"

---

## 9. Blade Views (9 coupling points)

### service.blade.php (REWRITE)

| # | Element | Obecny | Nowy |
|---|---------|--------|------|
| 1 | Selection UI | Radio buttons, single select | Main service card + addon upsell cards |
| 2 | Session data | `service_id` (scalar) | Cart object: primary + addons |
| 3 | Price display | Single price | Itemized: primary price + addon prices + total |

### datetime.blade.php (4 zmiany)

| # | Linia | Opis | Zmiana |
|---|-------|------|--------|
| 1 | ~16 | `{{ $service->duration_minutes }} min` — single | Sum: `{{ $totalDuration }} min` |
| 2 | ~40-54 | Service info box (single) | List of services or "Primary + N addons" |
| 3 | ~70-74 | Calendar receives `service-id` | Pass `primary-service-id` + `total-duration` |
| 4 | ~88-93 | Time grid receives `service-id` | Pass cumulative duration for slot sizing |

### review.blade.php (2 zmiany)

| # | Linia | Opis | Zmiana |
|---|-------|------|--------|
| 1 | ~54-69 | Single service details box | Loop over all services: primary (highlighted) + addons |
| 2 | ~253-256 | Single price summary | Itemized breakdown: each service + total (brutto) |

### layout.blade.php (1 zmiana)

| # | Element | Zmiana |
|---|---------|--------|
| 1 | Header area | Dodać cart component (persistent, AJAX-updated) |

### confirmation.blade.php (1 zmiana)

| # | Element | Zmiana |
|---|---------|--------|
| 1 | Service summary | Show all services with prices, total |

---

## 10. Routes — web.php (2 nowe trasy)

### Nowe trasy (cart AJAX):

```php
// Cart management (AJAX, session-based)
Route::prefix('booking/cart')->middleware('throttle:30,1')->group(function () {
    Route::post('/add-addon', [BookingController::class, 'addAddon'])
        ->name('booking.cart.add-addon');
    Route::post('/remove-addon', [BookingController::class, 'removeAddon'])
        ->name('booking.cart.remove-addon');
});
```

---

## 11. Database — Migrations

### Migration 1: `appointment_services` pivot

```php
Schema::create('appointment_services', function (Blueprint $table) {
    $table->id();
    $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
    $table->foreignId('service_id')->constrained()->cascadeOnDelete();
    $table->boolean('is_primary')->default(false);
    $table->decimal('price_snapshot', 10, 2);
    $table->unsignedInteger('duration_snapshot');
    $table->unsignedTinyInteger('sort_order')->default(0);
    $table->timestamps();

    $table->unique(['appointment_id', 'service_id']);
    $table->index('is_primary');
});
```

### Migration 2: `service_addons` pivot

```php
Schema::create('service_addons', function (Blueprint $table) {
    $table->id();
    $table->foreignId('service_id')->constrained()->cascadeOnDelete();
    $table->foreignId('addon_service_id')->constrained('services')->cascadeOnDelete();
    $table->unsignedTinyInteger('sort_order')->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->unique(['service_id', 'addon_service_id']);
});
```

### Migration 3: Addon fields na `services`

```php
Schema::table('services', function (Blueprint $table) {
    $table->boolean('is_addon')->default(false)->after('is_active');
    $table->boolean('is_promotional')->default(false)->after('is_addon');
    $table->decimal('promotional_price', 10, 2)->nullable()->after('is_promotional');
});
```

### Migration 4: Backfill existing appointments

```php
// Dla każdego istniejącego appointmentu → wstaw 1 wiersz do appointment_services
DB::table('appointments')->orderBy('id')->chunk(500, function ($appointments) {
    foreach ($appointments as $appointment) {
        $service = DB::table('services')->find($appointment->service_id);
        if ($service) {
            DB::table('appointment_services')->insert([
                'appointment_id' => $appointment->id,
                'service_id' => $appointment->service_id,
                'is_primary' => true,
                'price_snapshot' => $service->price ?? $service->price_from ?? 0,
                'duration_snapshot' => $service->duration_minutes,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
});
```

---

## 12. Podsumowanie zmian

### Pliki do MODYFIKACJI (existing)

| Plik | Coupling points | Złożoność |
|------|----------------|-----------|
| `BookingController.php` | 16 zmian | KRYTYCZNA |
| `AppointmentService.php` | 8 zmian (ale sygnatury mogą zostać) | ŚREDNIA |
| `Appointment.php` | 2 zmian + 5 nowych relacji/metod | ŚREDNIA |
| `Service.php` | 0 zmian + 4 nowe relacje/metody | NISKA |
| `AppointmentResource.php` | 4 zmian | ŚREDNIA |
| `ServiceResource.php` | 0 zmian + 3 nowe elementy | NISKA |
| `AppServiceProvider.php` | 2 zmian | NISKA |
| `AppointmentCreatedNotification.php` | 1 zmiana | NISKA |
| `AppointmentRescheduledNotification.php` | 1 zmiana | NISKA |
| `AppointmentCancelledNotification.php` | 1 zmiana | NISKA |
| `service.blade.php` | REWRITE | KRYTYCZNA |
| `datetime.blade.php` | 4 zmian | ŚREDNIA |
| `review.blade.php` | 2 zmian | ŚREDNIA |
| `layout.blade.php` | 1 zmiana (cart header) | NISKA |
| `confirmation.blade.php` | 1 zmiana | NISKA |
| `web.php` | 2 nowe trasy | NISKA |

### Pliki NOWE

| Plik | Cel |
|------|-----|
| Migration: `appointment_services` | Pivot table |
| Migration: `service_addons` | Admin-configurable addons |
| Migration: addon fields na services | `is_addon`, `is_promotional`, `promotional_price` |
| Migration: backfill existing | Data integrity |
| `BookingCartService.php` | Cart CRUD, totals, validation |
| `AddonsRelationManager.php` | Filament: manage addons per service |
| `booking-cart.blade.php` | Persistent cart component (header) |
| `appointments/show.blade.php` | Enhanced client panel view |
| Tests (5 files) | Feature + unit tests |

---

## 13. Kluczowy insight: minimalizacja refaktoru AppointmentService

Sygnatury publicznych metod AppointmentService **nie muszą się zmieniać**:

```php
// Metoda zachowuje sygnaturę:
getAvailableSlotsAcrossAllStaff(int $serviceId, Carbon $date, int $serviceDurationMinutes)
```

Caller (BookingController) przekazuje:
- `$serviceId` = primary service ID → do sprawdzenia kompetencji staff
- `$serviceDurationMinutes` = **cumulative duration** (primary + addons) → do obliczenia dostępnych slotów

To oznacza że AppointmentService wymaga minimalnych zmian wewnętrznych — cała logika multi-service jest w BookingCartService i BookingController.
