# ADR-016: Per-Tab Validation for Settings Pages

## Status

Accepted

## Date

2026-01-22

## Context

SystemSettings page in Filament admin panel has 10 tabs with approximately 42 required fields across all groups (Booking, Booking Wizard, Map, Contact, Appearance, Marketing, Email, SMS, CMS, Integrations).

### Problem

When clicking "Save" button on any tab, `$this->form->getState()` was called which validates ALL fields in the entire form, not just the active tab's fields.

```php
// Old implementation (problematic)
public function saveBookingSettings(): void
{
    $data = $this->form->getState();  // ← Validates ALL 42+ fields!
    // ...
}
```

### Root Cause

Filament v4's `getState()` internally calls `validate()` which uses `getComponents(withHidden: true)`. This means even hidden tabs (not currently visible) get validated.

### Symptoms

1. Clicking "Save" on "Wygląd" (Appearance) tab with optional fields showed errors from other required tabs
2. User had to fill ALL required fields across ALL tabs to save ANYTHING
3. Global "Save Settings" button had the same issue
4. Poor UX - validation errors for fields user couldn't see

## Decision

Implement `HasGroupedSettings` trait with:

1. **`getSettingsGroups()`** - Abstract method defining groups with labels and validation rules
2. **`saveSettingsGroup($group)`** - Validates and saves only the specified group
3. **Direct `$this->data[$group]` access** - Bypasses `getState()` validation entirely
4. **`Validator::make()`** - Uses Laravel's validator with group-specific rules only

### Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    HasGroupedSettings Trait                      │
├─────────────────────────────────────────────────────────────────┤
│ abstract getSettingsGroups(): array                              │
│   Returns config per group: label, rules                         │
├─────────────────────────────────────────────────────────────────┤
│ saveSettingsGroup(string $group): void                           │
│   1. Get config from getSettingsGroups()                         │
│   2. Extract $this->data[$group] (no getState!)                  │
│   3. Validator::make($groupData, $config['rules'])               │
│   4. Show error notifications if fails                           │
│   5. Throw ValidationException with prefixed field paths         │
│   6. persistSettingsGroup($group, $data)                         │
│   7. Cache::forget("settings:{$group}")                          │
│   8. Success notification with $config['label']                  │
├─────────────────────────────────────────────────────────────────┤
│ persistSettingsGroup(string $group, array $data): void           │
│   Iterates data and calls SettingsManager::set()                 │
└─────────────────────────────────────────────────────────────────┘
```

### Implementation

```php
// SystemSettings.php
class SystemSettings extends Page implements HasForms
{
    use HasGroupedSettings;

    protected function getSettingsGroups(): array
    {
        return [
            'booking' => [
                'label' => 'Ustawienia rezerwacji zapisane',
                'rules' => [
                    'business_hours_start' => ['required', 'date_format:H:i'],
                    // ...
                ],
            ],
            // ... other groups
        ];
    }

    public function saveBookingSettings(): void
    {
        $this->saveSettingsGroup('booking');  // One-liner!
    }
}
```

## Consequences

### Positive

1. **Per-tab validation works correctly** - Each tab validates independently
2. **DRY code** - Save methods reduced from ~15 lines each to 1 line
3. **Reusable** - Trait can be used in other settings pages
4. **Maintainable** - Rules centralized in `getSettingsGroups()`
5. **Senior-level architecture** - Trait pattern, SOLID principles

### Negative

1. **Validation rules in two places** - Form components have their own validation, plus `getSettingsGroups()`. This is intentional: form validation for immediate feedback, group validation for save-time enforcement.
2. **Must keep in sync** - If form field changes, rules in `getSettingsGroups()` must be updated too.

### Neutral

1. **Removed global Save button** - Was broken anyway; per-tab buttons are the correct UX
2. **Removed deprecated `submit()` method** - No longer needed

## Alternatives Considered

### 1. Partial Form Validation in Filament

Filament v4 doesn't provide built-in per-section validation. Would require forking/extending core components.

**Rejected:** Too invasive, maintenance burden.

### 2. Separate Forms per Tab

Each tab could be a separate form with its own `getState()`.

**Rejected:** Major refactoring, breaks current data loading pattern.

### 3. Custom Validation via JavaScript

Frontend validation only for active tab.

**Rejected:** Security issue - backend must validate.

## Related

- `.claude/rules/filament-settings-pages.md` - Implementation rules
- `app/Filament/Traits/HasGroupedSettings.php` - The trait
- `app/Filament/Pages/SystemSettings.php` - Main implementation

## References

- Filament v4 Forms documentation
- Laravel Validator documentation
