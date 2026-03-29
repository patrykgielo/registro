# System Settings — Module-Aware Tab Visibility

**Implemented:** 2026-03-28, branch `feature/system-settings-module-tabs`

---

## Overview

`SystemSettings` (`/admin/{slug}/system-settings`) shows settings tabs conditionally based on
which modules are active for the current tenant. This mirrors the existing navigation-item gating
via `BaseResource.$module` + `Organization.hasModule()`.

**Principle:** if a module's nav items are hidden → its settings tab is hidden too.

---

## Tab → Module Mapping

| Tab | Required module | Always visible? |
|-----|----------------|-----------------|
| Ogólne | — | ✅ always |
| Booking | `bookings` | only time_slot / both tenants |
| System rezerwacji | `bookings` | only time_slot / both tenants |
| Map | `website` | only tenants with website module |
| Kontakt | — | ✅ always |
| Wygląd | — | ✅ always |
| Marketing | `website` | only tenants with website module |
| Email | `communication` | only tenants with communication module |
| SMS | `communication` | only tenants with communication module |
| CMS | `website` | only tenants with website module |
| Integrations | `website` | only tenants with website module |
| Checkout | `rentals` | only item_rental / both tenants |

---

## Default Modules per Industry

| Industry | Modules | Visible tabs |
|----------|---------|--------------|
| EquipmentRental | services, rentals, website | Ogólne, Map, Kontakt, Wygląd, Marketing, CMS, Integrations, Checkout |
| AutoDetailing | services, bookings, website | Ogólne, Booking, System rezerwacji, Map, Kontakt, Wygląd, Marketing, CMS, Integrations |
| GeneralServices | services, bookings, website | (same as AutoDetailing) |

Super-admins (no tenant context) always see **all 12 tabs**.

---

## Implementation

**File:** `app/Filament/Pages/SystemSettings.php`

```php
private const TAB_MODULE_MAP = [
    'booking'        => 'bookings',
    'booking_wizard' => 'bookings',
    'map'            => 'website',
    'marketing'      => 'website',
    'email'          => 'communication',
    'sms'            => 'communication',
    'cms'            => 'website',
    'integrations'   => 'website',
    'checkout'       => 'rentals',
];

private function isTabVisible(string $tab): bool
{
    $module = self::TAB_MODULE_MAP[$tab] ?? null;
    if ($module === null) return true;

    $tenant = TenantFeature::currentTenant();
    if ($tenant === null) return true; // super-admin sees all

    return $tenant->hasModule($module);
}
```

Each gated tab has `->visible(fn () => $this->isTabVisible('key'))`.

---

## Adding a New Settings Tab

When adding a new tab that belongs to a module:

1. Add the tab key → module mapping to `TAB_MODULE_MAP`
2. Add `->visible(fn () => $this->isTabVisible('your_key'))` to the tab
3. Update the table above in this doc

---

## Super-Admin Permission Analysis (2026-03-28)

Findings from codebase audit — known limitations, not yet addressed:

| Issue | Impact | Priority |
|-------|--------|----------|
| Roles are global (not per-tenant) | Changing admin role permissions affects ALL tenants | Future sprint |
| `communication` module bundles email + SMS | Can't enable one without the other | Low — split if needed |
| No audit trail for permission changes | Security gap | Future sprint |
| Feature flags not Spatie-gated | Minor inconsistency | Low |

These are **out of scope** for the current sprint. The module-toggle UI in OrganizationResource
(super-admin platform panel) already provides sufficient per-tenant control for the current phase.
