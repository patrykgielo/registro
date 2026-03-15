---
paths:
  - "app/Filament/Resources/**"
---

# Filament Resources - Save Behavior

## ZASADA: Pozostań na stronie po zapisie (Content Resources)

Resources z kategorii Content Management (Pages, Posts, Promotions, Portfolio) powinny używać traitów:

### EditRecord - StaysOnPageAfterSave

```php
use App\Filament\Traits\StaysOnPageAfterSave;

class EditPage extends EditRecord
{
    use StaysOnPageAfterSave;

    // Trait zapewnia:
    // - getRedirectUrl() -> null (pozostań na stronie)
    // - getSavedNotification() -> "Zapisano pomyślnie"
    // - getFormActions() -> "Zapisz" + "Zapisz i zamknij"
}
```

### CreateRecord - CreatesAndRedirectsToEdit

```php
use App\Filament\Traits\CreatesAndRedirectsToEdit;

class CreatePage extends CreateRecord
{
    use CreatesAndRedirectsToEdit;

    // Trait zapewnia:
    // - getRedirectUrl() -> edit page nowego rekordu
    // - getCreatedNotification() -> "Utworzono pomyślnie"
}
```

## NIE UŻYWAJ getRedirectUrl() do listy

```php
// ❌ ŹLE: Przekierowanie do listy (frustrujące UX)
protected function getRedirectUrl(): string
{
    return $this->getResource()::getUrl('index');
}

// ✅ DOBRZE: Użyj trait
use StaysOnPageAfterSave;  // dla EditRecord
use CreatesAndRedirectsToEdit;  // dla CreateRecord
```

## Resources z traitami (Content Management)

| Resource | EditRecord | CreateRecord |
|----------|------------|--------------|
| Pages | StaysOnPageAfterSave | CreatesAndRedirectsToEdit |
| Posts | StaysOnPageAfterSave | CreatesAndRedirectsToEdit |
| Promotions | StaysOnPageAfterSave | CreatesAndRedirectsToEdit |
| PortfolioItems | StaysOnPageAfterSave | CreatesAndRedirectsToEdit |

## Traity - lokalizacja

- `app/Filament/Traits/StaysOnPageAfterSave.php` - dla EditRecord
- `app/Filament/Traits/CreatesAndRedirectsToEdit.php` - dla CreateRecord

## Kiedy NIE używać traitów

Traity są przeznaczone dla Content Management. Inne Resources (np. Customer, Employee, Appointment) mogą mieć inne wymagania biznesowe i mogą potrzebować własnej logiki przekierowań.

## Zachowanie przycisków

### EditRecord z StaysOnPageAfterSave:
- **Zapisz** - zapisuje i pozostaje na stronie
- **Zapisz i zamknij** - zapisuje i wraca do listy
- **Anuluj** - wraca do listy bez zapisu

### CreateRecord z CreatesAndRedirectsToEdit:
- **Utwórz** - tworzy i przekierowuje do edycji nowego rekordu

---

## Module Visibility Gating (Phase 6)

**BaseResource auto-gatuje widoczność Resources na podstawie modułów organizacji.**

### Pattern: `$module` property

```php
// Resource z modułem — widoczny tylko gdy moduł aktywny
protected static ?string $module = 'services';

// Core resource — zawsze widoczny
protected static ?string $module = null;
```

### `shouldRegisterNavigation()` w BaseResource

```php
public static function shouldRegisterNavigation(): bool
{
    if (static::$module === null) return true;        // core = always
    $tenant = TenantFeature::currentTenant();
    if ($tenant === null) return true;                // platform/CLI = show all
    return $tenant->hasModule(static::$module);       // tenant = check module
}
```

### NIGDY nie override'uj `shouldRegisterNavigation()` na Resource

```php
// ❌ ŹLE — stary pattern (Phase 2-5), USUNIĘTY w Phase 6
public static function shouldRegisterNavigation(): bool
{
    return TenantFeature::active('vehicles');
}

// ✅ DOBRZE — Phase 6: użyj $module property
protected static ?string $module = 'vehicles';
```

### Moduły vs Features (WAŻNE ROZRÓŻNIENIE)

| System | Gating | Użycie |
|--------|--------|--------|
| `$module` | Widoczność CAŁEGO Resource w nawigacji | `protected static ?string $module = 'staff';` |
| `TenantFeature::active()` | Widoczność POLA w formularzu | `->visible(fn () => TenantFeature::active('vehicles'))` |

Moduły nie zastępują feature flags — oba systemy współistnieją.

---

## Warunkowa Walidacja: Create vs Edit (Bug z 2026-02-16)

**Filament waliduje WSZYSTKIE pola formularza przy zapisie, nie tylko zmienione!**

Jeśli DatePicker ma `->minDate(now())`, to edycja rekordu z przeszłą datą ZAWSZE fail — nawet gdy zmienisz tylko status.

```php
// ❌ BŁĄD: blokuje edycję starych rekordów
Forms\Components\DatePicker::make('appointment_date')
    ->minDate(now())  // 2026-02-16

// Appointment #6: appointment_date = 2026-01-30
// Zmiana status → "completed" → WALIDACJA ODRZUCA!
// "The data wizyty field must be a date after or equal to 2026-02-16"

// ✅ POPRAWNIE: walidacja warunkowa
Forms\Components\DatePicker::make('appointment_date')
    ->minDate(fn (?Model $record): ?string => $record ? null : now()->toDateString())
```

**Pattern ogólny:**
```php
->rule(fn (?Model $record) => $record ? null : 'required_validation')
->minDate(fn (?Model $record) => $record ? null : now())
->maxDate(fn (?Model $record) => $record ? null : now()->addYear())
```

`$record === null` → create mode, `$record !== null` → edit mode.
