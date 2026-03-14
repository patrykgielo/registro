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
'settings' => 'array'          // JSON z features, location, itp.

// Kluczowe metody
$org->hasFeature('vehicles')   // Priorytet: override > industry > booking_type
$org->term('service')          // Terminologia branżowa (np. "przedmiot" dla rental)
$org->supportsRentals()        // booking_type in [item_rental, both]
$org->supportsAppointments()   // booking_type in [time_slot, both]
$org->enableFeature('x')       // Zapisuje override w settings.features
```

**Industry vs booking_type:**
- `booking_type` = techniczny typ rezerwacji (time_slot, item_rental, both)
- `industry` = branża biznesowa (equipment_rental, auto_detailing, general_services)
- Industry DERIVE'uje booking_type — nie ustawiaj booking_type ręcznie jeśli jest industry

## Industry Enum (`app/Enums/Industry.php`)

```php
Industry::EquipmentRental  // → booking_type: item_rental
Industry::AutoDetailing    // → booking_type: time_slot, features: vehicles+mobile+area
Industry::GeneralServices  // → booking_type: time_slot

// Metody na każdym case:
$industry->bookingType()      // string
$industry->defaultFeatures()  // array<string, bool>
$industry->label()            // PL label
$industry->terminology()      // ['service' => 'przedmiot', ...]
$industry->seederClass()      // FQCN vertical seedera
```

## RentalItem — tiered pricing (standard PL rynku)

```php
// Istniejące
'price_per_day', 'price_per_hour', 'price_per_week', 'deposit_amount'

// Nowe (2026-03-14)
'price_per_day_long'     // Stawka po przekroczeniu progu (nullable)
'price_threshold_days'   // Próg dni dla niższej ceny (nullable)
'brand'                  // Marka/producent (nullable, osobna kolumna — filtrowalne)

// Hybrid specifications JSON
'specifications' => [
    'specs' => ['power_w' => 800, 'weight_kg' => 4.2],      // Suggested keys per kategoria
    'custom_specs' => [['key' => 'Kolor', 'value' => 'Red']] // Repeater key:value
]
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
