# Design Hub — Phase 10

**Status:** Implemented 2026-05-09
**Module gate:** `design` (must be explicitly enabled per tenant in Platform panel)

---

## What it does

Tenants with the `design` module can customize their public-facing brand appearance
from the `/admin/design-hub` page in the Filament admin panel:

- **Brand color** → generates a 10-shade palette (50–900) injected as CSS custom properties
- **Font family** → Inter / System / Roboto / Poppins / Montserrat (Bunny Fonts, GDPR-safe)
- **Brand name override** → display name used in public UI
- **Logos** → header/footer logo (same as Appearance in System Settings)
- **Email branding** → logo and brand color in transactional emails

---

## Architecture

```
Tenant sets brand_color in Design Hub
         ↓
SettingsManager::set('design.brand_color', '#2563eb')
         ↓
ColorScaleGenerator::generate('#2563eb') → 10-shade palette
         ↓
app.blade.php → <style>:root { --primary-50: ...; --primary-500: #2563eb; ... }</style>
         ↓
All components using var(--primary-*) auto-update
```

---

## Files

| File | Purpose |
|------|---------|
| `app/Support/ColorScaleGenerator.php` | Pure PHP HEX → 10-shade HSL palette |
| `app/Filament/Pages/DesignHub.php` | Filament Page (gated, 3 sections) |
| `resources/views/filament/pages/design-hub.blade.php` | Blade view |
| `database/migrations/2026_05_09_000001_add_design_settings_defaults.php` | Global default seeds |
| `resources/views/layouts/app.blade.php` | CSS injection in `<head>` |
| `resources/views/vendor/mail/html/header.blade.php` | Email header with logo |
| `resources/views/vendor/mail/html/button.blade.php` | Email button with brand color |
| `app/Providers/AppServiceProvider.php` | Mail view composer |
| `app/Support/Settings/SettingsManager.php` | Helper methods: brandColor(), fontFamily(), brandName(), useLogoInEmails(), useColorInEmails() |

---

## Settings keys (group `design`)

| Key | Default | Type | Notes |
|-----|---------|------|-------|
| `brand_color` | `#6366f1` | string | Must match `#RRGGBB` or `#RGB` |
| `font_family` | `inter` | string | enum: inter\|system\|roboto\|poppins\|montserrat |
| `brand_name_override` | null | string\|null | Max 100 chars |
| `use_logo_in_emails` | true | boolean | |
| `use_color_in_emails` | true | boolean | |

---

## Module gating

The `design` module is **NOT** in MODULE_DEFAULTS — it's never auto-enabled.
A platform admin must run `$org->enableModule('design')` or use the Platform panel.

Without the module:
- Navigation item hidden (`shouldRegisterNavigation()` returns false)
- Direct URL `/admin/design-hub` returns 403 (`canAccess()` returns false)

---

## ColorScaleGenerator

Pure PHP, no external dependencies.

```php
// Generate 10-shade palette
$shades = ColorScaleGenerator::generate('#6366f1');
// ['50' => '#f1f1fe', ..., '500' => '#6366f1', ..., '900' => '#060846']

// Generate CSS custom properties
$css = ColorScaleGenerator::toCssVariables('#6366f1', 'primary');
// "--primary-50: #f1f1fe;\n    --primary-100: ...\n    --primary-500: #6366f1; ..."
```

Shade 500 is always the **exact input hex** (no rounding from HSL conversion).
Achromatic colors (#fff, #000, #888) handled without division-by-zero.
Invalid input throws `\InvalidArgumentException`.

---

## Email branding

Mail view composer in `AppServiceProvider::shareMailBrandVariables()` injects:
- `$brandColor` — tenant's brand color (or null if `use_color_in_emails = false`)
- `$logoUrl` — header logo URL (or null if `use_logo_in_emails = false`)
- `$brandName` — tenant's display name

Published views at `resources/views/vendor/mail/html/`:
- `header.blade.php` — shows `$logoUrl` if set, fallback to slot content
- `button.blade.php` — uses `$brandColor` for primary button background

---

## Security

- `ColorScaleGenerator` validates strict hex before injecting CSS → no CSS injection possible
- `$fontFamily` comes from a whitelist enum, not free text
- Logo uploads use magic bytes validation + SVG sanitization (same as SystemSettings)
- Mail view composer fails silently (`try/catch`) — email must never be blocked by settings errors

---

## Roadmap (Phase 10b)

- Live preview in Design Hub (mock button/card with current color)
- Custom domain support
- Custom CSS override field (with sanitization)
- Dark mode per-tenant toggle
