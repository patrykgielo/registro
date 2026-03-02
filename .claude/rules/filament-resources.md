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
