---
paths:
  - "app/Models/**"
---

# Eloquent Model Rules

## Required Traits

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable;
}
```

## User Model - CRITICAL Pattern

**UWAGA:** User model używa `first_name` i `last_name`, NIE `name`!

```php
// ✅ PRAWIDŁOWO
$user->first_name  // "Jan"
$user->last_name   // "Kowalski"
$user->name        // "Jan Kowalski" (accessor)

// ❌ ŹLE - kolumna 'name' nie istnieje!
$user->name = "Jan Kowalski"; // ERROR
```

**Accessor w User model:**
```php
public function getNameAttribute(): string
{
    return trim("{$this->first_name} {$this->last_name}");
}
```

### User Model — Checkout Legal Data Fields (dodane 2026-03-28)

```php
$user->customer_type  // 'natural_person' | 'business' | null
$user->pesel          // 11 cyfr (opcjonalny profil) — DANE WRAŻLIWE!
$user->regon          // 9 lub 14 cyfr (opcjonalny profil)
$user->krs            // do 20 znaków (opcjonalny profil)
```

**KRYTYCZNE — PESEL to dane wrażliwe (PII):**
- Nigdy nie loguj PESEL w logach aplikacji
- Nigdy nie zwracaj PESEL w komunikatach błędów
- Nigdy nie eksponuj PESEL w odpowiedziach API (chyba że uwierzytelniony właściciel konta)

Pola te są opcjonalne w profilu — użytkownik może je uzupełnić podczas checkout wybierając "Zapisz dane do profilu". Walidacja: `ValidPolishPESEL`, `ValidPolishREGON` (patrz `.claude/rules/polish-tax-ids.md`).

## Mass Assignment Protection

```php
// ✅ Zawsze definiuj $fillable
protected $fillable = [
    'first_name',
    'last_name',
    'email',
    // ...
];

// ✅ LUB $guarded (rzadziej używane)
protected $guarded = ['id'];

// ❌ NIGDY nie zostawiaj pustego $guarded!
protected $guarded = []; // SECURITY RISK!
```

## Relationships - Return Types

```php
// ✅ Zawsze z return type
public function appointments(): HasMany
{
    return $this->hasMany(Appointment::class);
}

public function address(): HasOne
{
    return $this->hasOne(UserAddress::class);
}

// ❌ Bez return type
public function appointments()  // NIE!
```

## Event Dispatching

```php
// Dla event-driven architecture
protected $dispatchesEvents = [
    'created' => AppointmentCreated::class,
    'cancelled' => AppointmentCancelled::class,
];
```

## Casts

```php
protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'settings' => 'array',
    ];
}
```

## Scopes

```php
// ✅ Nazwane scopes dla reusable queries
public function scopeActive(Builder $query): Builder
{
    return $query->where('is_active', true);
}

public function scopePending(Builder $query): Builder
{
    return $query->where('status', 'pending');
}

// Użycie: User::active()->get()
```

## DocBlock for Relations

```php
/**
 * @return HasMany<Appointment, $this>
 */
public function appointments(): HasMany
{
    return $this->hasMany(Appointment::class);
}
```

## FilamentUser Interface (dla Admin Panel)

```php
class User extends Authenticatable implements FilamentUser, HasName
{
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole(['admin', 'staff']);
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }
}
```

## Multi-Tenant Models (BelongsToOrganization)

Modele tenant-aware MUSZĄ używać trait `BelongsToOrganization`:

```php
use App\Traits\BelongsToOrganization;

class Service extends Model
{
    use BelongsToOrganization, HasFactory;
}
```

**Trait automatycznie:**
- Dodaje global scope filtrujący po `organization_id`
- Auto-assigns `organization_id` z `TenantFeature::currentTenant()` przy tworzeniu
- Pomija scope w console (bez testów)

**Aby ominąć scope (np. w seederach):**
```php
Service::withoutGlobalScope('organization')->create([...]);
```

## Organization Model — kluczowe pola i metody

```php
// Pola
'name', 'slug', 'booking_type', 'industry', 'owner_id', 'is_active', 'settings', 'trial_ends_at'

// Casts
'industry' => Industry::class  // App\Enums\Industry (backed enum, nullable)
'settings' => 'array'          // JSON z features, modules, location, itp.

// Kluczowe metody — FEATURES (boolean toggles)
$org->hasFeature('vehicles')   // Priorytet: override > industry > booking_type
$org->enableFeature('x')       // Zapisuje override w settings.features
$org->disableFeature('x')

// Kluczowe metody — MODULES (Phase 6, feature sets)
$org->hasModule('services')    // Priorytet: override > industry > booking_type
$org->enableModule('staff')    // Zapisuje override w settings.modules
$org->disableModule('staff')

// Inne metody
$org->term('service')          // Terminologia branżowa (np. "przedmiot" dla rental)
$org->supportsRentals()        // booking_type in [item_rental, both]
$org->supportsAppointments()   // booking_type in [time_slot, both]
```

**Industry vs booking_type:**
- `booking_type` = techniczny typ rezerwacji (time_slot, item_rental, both)
- `industry` = branża biznesowa (equipment_rental, auto_detailing, general_services)
- Industry DERIVE'uje booking_type — nie ustawiaj booking_type ręcznie jeśli jest industry

## Module System (Phase 6) — gating widoczności zasobów

**Moduły vs Feature flags — TO SĄ DWA RÓŻNE SYSTEMY:**

| System | Metoda | Cel | Przykłady |
|--------|--------|-----|-----------|
| **Modules** | `hasModule()` | Włączanie/wyłączanie CAŁYCH grup zasobów | services, bookings, rentals, staff, customers, communication, website, service_area, vehicles |
| **Features** | `hasFeature()` | Boolean toggles na POLA w formularzach | vehicles, mobile_service, service_area |

### MODULE_DEFAULTS per booking_type

```php
private const MODULE_DEFAULTS = [
    'time_slot' => ['services', 'bookings'],
    'item_rental' => ['rentals'],
    'both' => ['services', 'bookings', 'rentals'],
];
```

### hasModule() — 3-level priority (identycznie jak hasFeature)

```
1. Explicit override → settings.modules.{module}  (najwyższy priorytet)
2. Industry defaults → $this->industry->defaultModules()
3. booking_type defaults → MODULE_DEFAULTS[booking_type]
```

### Industry::defaultModules()

```php
EquipmentRental  → ['services', 'rentals']
AutoDetailing    → ['services', 'bookings']
GeneralServices  → ['services', 'bookings']
```

**Wszystko poza core jest OFF domyślnie.** Super-admin włącza moduły per tenant w Platform panel.

### BaseResource.$module — gating w Filament

```php
// Każdy Resource ma property $module:
protected static ?string $module = 'services';  // gated
protected static ?string $module = null;          // zawsze widoczny (core)

// BaseResource::shouldRegisterNavigation() automatycznie sprawdza hasModule()
```

### Mapowanie modułów → Resources

| Moduł | Resources |
|-------|-----------|
| `services` | ServiceResource |
| `bookings` | AppointmentResource |
| `rentals` | RentalCategoryResource, RentalResource |
| `staff` | EmployeeResource, StaffScheduleResource, StaffVacationPeriodResource, StaffDateExceptionResource |
| `customers` | CustomerResource |
| `vehicles` | VehicleTypeResource, CarBrandResource, CarModelResource |
| `communication` | EmailTemplateResource, SmsTemplateResource, EmailSendResource, SmsSendResource, ReminderConfigResource |
| `website` | PageResource, PostResource, PortfolioItemResource, PromotionResource, CategoryResource |
| `service_area` | ServiceAreaResource, ServiceAreaWaitlistResource |
| `null` (core) | Dashboard, SystemSettings, MaintenanceSettings |

### Security — tenant isolation (Phase 6)

```php
// EmployeeResource — scoped via organizations pivot
->when($tenant, fn ($q) => $q->whereHas('organizations', fn ($q2) => $q2->where('organizations.id', $tenant->id)))

// CustomerResource — scoped via appointments/rentals
->when($tenant, fn ($q) => $q->where(function ($q2) use ($tenant) {
    $q2->whereHas('customerAppointments', fn ($q3) => $q3->where('organization_id', $tenant->id))
       ->orWhereHas('rentalsAsCustomer', fn ($q3) => $q3->where('organization_id', $tenant->id));
}))

// UserResource, RoleResource → super-admin only
// SmsEvent, EmailEvent, Suppressions, MaintenanceEvent → super-admin only
// VehicleType/CarBrand/CarModel → read-only dla non-super-admin
```

## Industry Enum (`app/Enums/Industry.php`)

```php
Industry::EquipmentRental  // → booking_type: item_rental
Industry::AutoDetailing    // → booking_type: time_slot, features: vehicles+mobile+area
Industry::GeneralServices  // → booking_type: time_slot

// Metody na każdym case:
$industry->bookingType()      // string
$industry->defaultFeatures()  // array<string, bool>
$industry->defaultModules()   // array<int, string> (Phase 6)
$industry->label()            // PL label
$industry->terminology()      // ['service' => 'przedmiot', ...]
$industry->seederClass()      // FQCN vertical seedera
```

## Service — unified model (time_slot + item_rental)

**UWAGA: RentalItem model został usunięty!** Wypożyczenia to teraz Service z `service_type = 'item_rental'`.

```php
// ServiceType enum (app/Enums/ServiceType.php)
ServiceType::TimeSlot     // Klasyczna usługa (detailing, fryzjer)
ServiceType::ItemRental   // Wypożyczenie (sprzęt, narzędzia)

// Helper methods (dodane 2026-03-31)
ServiceType::ItemRental->isRental()   // true
ServiceType::TimeSlot->isTimeSlot()   // true

// Pola rental na Service (nullable, tylko dla item_rental)
'service_type'           // ServiceType enum (default: time_slot)
'rental_category_id'     // FK do rental_categories (nullable)
'quantity_total'         // Ilość w magazynie
'price_per_day'          // Stawka dzienna
'price_per_hour'         // Stawka godzinowa (nullable)
'price_per_week'         // Stawka tygodniowa (nullable)
'price_per_day_long'     // Stawka po przekroczeniu progu (nullable)
'price_threshold_days'   // Próg dni dla niższej ceny (nullable)
'price_on_request'       // boolean default false — ukrywa cenę, pokazuje inquiry form
'deposit_amount'         // Kaucja (nullable)
'brand'                  // Marka/producent (nullable)

// Specifications → metadata JSON
'metadata' => ['specs' => ['power_w' => 800, 'weight_kg' => 4.2]]

// Bootable Traits (dodane 2026-03-31)
// Service uses: HasRentalBehavior + HasTimeSlotBehavior
// HasRentalBehavior: zeruje price_on_request=false na creating/updating gdy service_type !== ItemRental
// HasTimeSlotBehavior: marker trait dla time_slot logiki

// price_on_request — tylko dla item_rental
// Gdy true: ukrywa cenę i koszyk, pokazuje "Zapytaj o cenę" CTA + modal
// Guard w HasRentalBehavior: TimeSlot service ZAWSZE ma price_on_request=false
// Metoda: $service->isRentalPriceOnRequest(): bool

// Scopes
Service::rentable()     // where service_type = item_rental
Service::bookable()     // where service_type = time_slot

// Availability (item_rental only — throws LogicException on time_slot!)
$service->availableQuantity(Carbon $start, Carbon $end): int
$service->isAvailable(Carbon $start, Carbon $end, int $qty = 1): bool
Service::availableBetween($start, $end, $qty) // auto-filters to item_rental

// Immutability: service_type cannot be changed after creation (model guard)
// Cross-tenant: rental_category_id validated against organization_id on update
// FK: rentals.service_id uses restrictOnDelete (not cascade!)

// Accessors return null safely:
$service->formatted_rental_price  // null when price_per_day is null
$service->formatted_duration      // null when duration_minutes is null

// Factory
Service::factory()->itemRental()->create()
```

## Service — metadata JSON (per-industry)

```php
// Nowe (2026-03-14) — używane przez auto detailing
'metadata' => [
    'prices_by_size' => ['A' => 150, 'B' => 180, 'C' => 220, 'D' => 270],
    'durations_by_size' => ['A' => 60, 'B' => 70, 'C' => 80, 'D' => 90],
    'available_for_mobile' => true,
]
// price = cena bazowa (kat. A), duration_minutes = czas bazowy (kat. A)
```

## Factory Reference

Każdy model powinien mieć factory w `database/factories/`:

```php
// W modelu:
/** @use HasFactory<\Database\Factories\UserFactory> */
use HasFactory;
```

**OrganizationFactory states:**
```php
Organization::factory()->equipmentRental()->create();  // industry + booking_type
Organization::factory()->autoDetailing()->create();
Organization::factory()->generalServices()->create();
Organization::factory()->onTrial()->create();
Organization::factory()->inactive()->create();
```

## Soft Deletes (jeśli potrzebne)

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use SoftDeletes;
}
```

---

## Order Model — Legal Fields & Deposit Lifecycle (dodane 2026-03-28)

`Order` zawiera kompletne dane prawne wymagane przez polskie przepisy (Art. 659 KC, KPC, RODO).

### Typ klienta

```php
$order->customer_type  // 'natural_person' (B2C) | 'business' (B2B)
```

### B2C — natural_person
```php
$order->customer_first_name
$order->customer_last_name
$order->customer_email
$order->customer_phone
$order->customer_pesel       // 11 cyfr — DANE WRAŻLIWE
$order->customer_street
$order->customer_building
$order->customer_apartment   // nullable
$order->customer_city
$order->customer_postal_code
```

### B2B — business (dodatkowe)
```php
$order->invoice_company_name
$order->invoice_nip          // walidowany NIP
$order->company_regon        // walidowany REGON
$order->company_krs          // nullable
$order->company_contact_name // osoba upoważniona do podpisania umowy
$order->invoice_street
$order->invoice_street_number
$order->invoice_postal_code
$order->invoice_city
```

### Zgody RODO (immutable po ustawieniu — NIGDY nie nadpisuj!)
```php
$order->rodo_accepted_at                      // Carbon|null
$order->rodo_accepted_ip                      // string|null
$order->terms_accepted_at                     // Carbon|null
$order->withdrawal_exclusion_accepted_at      // Carbon|null
```

### Kaucja (security deposit — NIE jest VAT-owalna!)

```
deposit_amount = 0          → deposit_status = 'not_required'
deposit_amount > 0          → deposit_status = 'pending'
                                    ↓ admin: "Pobrano kaucję"
                               'collected' (deposit_collected_at set)
                                    ↓ admin: "Zwrócono" lub "Przepadła"
                        'returned' (deposit_returned_at set) | 'forfeited'
```

```php
$order->deposit_amount        // DECIMAL(10,2) — NIE wliczać do total_amount!
$order->deposit_status        // Enum: not_required|pending|collected|returned|partial_return|forfeited
$order->deposit_collected_at  // Carbon|null
$order->deposit_returned_at   // Carbon|null
$order->deposit_notes         // string|null
```

**KRYTYCZNE:** `deposit_amount` NIE jest częścią `total_amount`. Kaucja to zwrotny depozyt, nie płatność — nie podlega VAT i nie trafia na fakturę.

Zarządzanie kaucją: admin panel → `OrderResource` → akcje row: **Pobrano kaucję** / **Zwrócono kaucję** / **Kaucja przepadła**.

---

## OrderItem Model — Deposit Tracking (dodane 2026-03-28)

```php
$orderItem->deposit_amount  // DECIMAL(10,2) — kaucja za tę pozycję
                            // = service->deposit_amount * quantity (snapshot przy tworzeniu)
```

Suma `order_items.deposit_amount` = `orders.deposit_amount` (obliczone w `CartService::convertToOrder()`).

---

## Organization — Lifecycle State (Faza 5.0+5.1, 2026-06-29)

```php
// Cast → App\Enums\OrganizationLifecycleState
$org->lifecycle_state          // OrganizationLifecycleState enum instance
$org->lifecycle_state->value   // 'active' | 'suspended' | 'closing' | 'closed'

// Helpers
$org->lifecycle_state->allowsPublicSite()   // true tylko dla Active
$org->lifecycle_state->allowsNewBookings()  // true tylko dla Active
$org->lifecycle_state->isTerminal()         // true dla Closed

// Daty lifecycle (set automatically by OrganizationObserver)
$org->closing_initiated_at  // Carbon|null — set when → Closing, cleared when Closing → Active
$org->closed_at             // Carbon|null — set when → Closed
$org->purge_after           // Carbon|null (Faza 5.3) — cleared when Closing → Active
$org->closure_requested_at  // Carbon|null

// Transient flags (not persisted — reset after save)
$org->forceLifecycleTransition = true;  // bypasses obligation check (observer updating()); auto-reset by updated()
$org->bypassDeleteGuard = true;         // bypasses all checks (observer deleting()); auto-reset by deleted()
```

**KRYTYCZNE — lifecycle_state jest autorytatywny; is_active jest fully derived (Faza 5.1 + code-review hardening):**
- `lifecycle_state` NIE JEST w `$fillable` — nie można ustawiać przez mass-assignment!
  - ❌ `Organization::create(['lifecycle_state' => 'closed'])` → ignorowane (MassAssignmentGuard)
  - ✅ Przy tworzeniu: ustaw bezpośrednio przed `save()` lub użyj factory state (`->closed()`)
  - ✅ Przy aktualizacji: `$org->lifecycle_state = State::Foo; $org->save();`
- Nie ustawiaj `is_active` bezpośrednio — NIE jest w `$fillable`, ustawiane WYŁĄCZNIE przez `OrganizationObserver`
- `OrganizationObserver` egzekwuje: state machine + obligacje + is_active sync + timestamps + flag reset
- Flagi transient (nie persystowane): `forceLifecycleTransition` resetowany przez `updated()`, `bypassDeleteGuard` przez `deleted()`
- State machine: `app/StateMachines/OrganizationLifecycleStateMachine.php` — `transitions()` jest PRIVATE
- Wyjątki: `InvalidLifecycleTransitionException` (nielegalne przejście), `OrganizationNotClosedException` (delete gdy nie Closed), `OrganizationHasActiveObligationsException` (blokada przez in-flight obligacje)
- `completed` order NIE jest in-flight — nie blokuje zamknięcia org! Tylko: pending_payment/paid/confirmed/in_progress
- Factory states: `->inactive()` (Suspended), `->closing()` (Closing), `->closed()` (Closed) — wszystkie używają `afterMaking`
- `is_active` nadal używane przez ResolveTenant (zmiana w Fazie 5.2)

## Organization — Billing Fields (NIE w fillable!)

`subscription_status`, `monthly_fee`, `subscribed_at`, `subscription_expires_at` są celowo wykluczone z `$fillable`.

```php
// ❌ ZAKAZANE — mass-assignment billing fields
Organization::create(['subscription_status' => 'active']);  // IGNOROWANE
$org->update(['monthly_fee' => 999]);  // IGNOROWANE

// ✅ Tylko bezpośrednie przypisanie (super-admin actions)
$org->subscription_status = 'active';
$org->monthly_fee = 999;
$org->save();
```

## TenantPayment — organization_id i recorded_by NIE w fillable

```php
// ❌ ZAKAZANE
TenantPayment::create(['organization_id' => $org->id, 'recorded_by' => auth()->id()]);

// ✅ Przez relację + bezpośrednie przypisanie
$payment = $org->tenantPayments()->create(['amount' => 599, 'currency' => 'PLN', 'paid_at' => now()]);
$payment->recorded_by = auth()->id();
$payment->save();
```

## Order — Auditable + Immutable Fields (2026-06-29)

Order model używa `App\Traits\Auditable` z explicit `$auditInclude` (PII + status) i `$auditExclude` (p24_*, expires_at, cart_id, ip_address).

### Immutable fields (booted() guard)

Poniższe pola rzucają `\LogicException` przy próbie zmiany przez `update()`:

```
organization_id, order_number, total_amount, subtotal, discount_amount,
tax_amount, deposit_amount, rodo_accepted_at, rodo_accepted_ip,
terms_accepted_at, withdrawal_exclusion_accepted_at
```

```php
// ❌ LogicException!
$order->update(['total_amount' => 999]);

// ✅ Mutable — dozwolone
$order->update(['customer_city' => 'Kraków']);        // dane adresowe
$order->update(['deposit_status' => 'collected']);    // kaucja
$order->update(['notes' => 'Admin note']);             // notatka
$order->status()->transitionTo('confirmed');           // state machine
```

### OrderService::cancel() — allowed statuses

`pending_payment`, `paid`, **`confirmed`** — wszystkie trzy mogą być anulowane przez admina.
State machine potwierdza: `confirmed → cancelled` jest legalnym przejściem.
