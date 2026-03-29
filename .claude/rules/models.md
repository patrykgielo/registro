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

// Pola rental na Service (nullable, tylko dla item_rental)
'service_type'           // ServiceType enum (default: time_slot)
'rental_category_id'     // FK do rental_categories (nullable)
'quantity_total'         // Ilość w magazynie
'price_per_day'          // Stawka dzienna
'price_per_hour'         // Stawka godzinowa (nullable)
'price_per_week'         // Stawka tygodniowa (nullable)
'price_per_day_long'     // Stawka po przekroczeniu progu (nullable)
'price_threshold_days'   // Próg dni dla niższej ceny (nullable)
'deposit_amount'         // Kaucja (nullable)
'brand'                  // Marka/producent (nullable)

// Specifications → metadata JSON
'metadata' => ['specs' => ['power_w' => 800, 'weight_kg' => 4.2]]

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
