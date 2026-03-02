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

## Factory Reference

Każdy model powinien mieć factory w `database/factories/`:

```php
// W modelu:
/** @use HasFactory<\Database\Factories\UserFactory> */
use HasFactory;
```

## Soft Deletes (jeśli potrzebne)

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use SoftDeletes;
}
```
