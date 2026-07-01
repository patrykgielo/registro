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

### Incident 2026-07-01: `$shouldRegisterNavigation = false` ignorowane przez BaseResource

**Problem:** `BaseResource::shouldRegisterNavigation()` całkowicie nadpisywał metodę z Filament core (`HasNavigation` trait, która zwraca `static::$shouldRegisterNavigation`) bez w ogóle sprawdzania tej właściwości. Skutek: `StaffDateExceptionResource` (`$shouldRegisterNavigation = false` — celowo dostępny WYŁĄCZNIE przez header actions w `StaffScheduleResource`, nie przez sidebar) i tak pojawiał się w nawigacji grupy "Personel".

**Przyczyna:** Moduł-gating override (Phase 6) zastąpił metodę bazową zamiast ją rozszerzyć — zgubił krótkie spięcie na `static::$shouldRegisterNavigation`.

**Rozwiązanie:** `shouldRegisterNavigation()` w `BaseResource` najpierw sprawdza `! static::$shouldRegisterNavigation → return false`, dopiero potem stosuje `$module` gating. `shouldRegisterNavigation()` wpływa TYLKO na widoczność w sidebarze — trasy (`route:list`) rejestrują się niezależnie.

**Zapobieganie:** Gdy nadpisujesz metodę z Filament core (`shouldRegisterNavigation`, `shouldRegisterNavigationItem` itp.) w bazowej klasie — ZAWSZE zachowaj oryginalny short-circuit z core PRZED dodaniem własnej logiki, nie zastępuj go całkowicie.

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

---

## ZASADA: Admin → Frontend Impact Check (CRITICAL)

**Każda zmiana w Filament Resource MUSI być zweryfikowana na frontendzie!**

### Reguła

Gdy zmieniasz formularz w panelu admina (nowe pola, zmiana formatu danych, Repeater, KeyValue):

1. **ZNAJDŹ WSZYSTKIE widoki** które renderują te dane na frontendzie
2. **SPRAWDŹ edge cases**: puste wartości, null, zmiana formatu (object→array)
3. **PRZETESTUJ** stronę publiczną z danymi które admin może wpisać

### Incident 2026-03-22: htmlspecialchars() crash na stronie produktowej

**Problem:** Zmiana specs z KeyValue `{key: value}` na Repeater `[{label, value, unit}]` spowodowała crash `htmlspecialchars(): Argument #1 must be string, array given` gdy admin dodał puste wpisy repeatera (label=null, value=null).

**Przyczyna:** Frontend (`show.blade.php`) nie filtrował pustych wpisów ani nie castował null na string. Admin dodał puste wiersze repeatera → frontend crash.

**Zapobieganie:**
- **ZAWSZE** filtruj puste wpisy z Repeater/KeyValue przed renderowaniem
- **ZAWSZE** castuj do `(string)` wartości z JSON które mogą być null
- **ZAWSZE** sprawdź `empty()` przed wyświetleniem sekcji
- Przy zmianie formatu danych → BACKWARD COMPATIBLE rendering (obsługa starego i nowego formatu)

### Checklist przy zmianach admin form:

```
[ ] Zidentyfikowano WSZYSTKIE Blade views renderujące zmienione dane
[ ] Przetestowano z pustymi/null danymi
[ ] Przetestowano z danymi w starym formacie (backward compat)
[ ] Przetestowano stronę publiczną po zapisie z admin panelu
```
