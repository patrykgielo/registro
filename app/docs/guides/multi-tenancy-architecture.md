# Multi-Tenancy Architecture Guide

## Overview

Registro uses subdomain-based multi-tenancy. Each `Organization` is a tenant with its own subdomain (`slug.registro.local`). All tenant-scoped data is automatically filtered via global query scopes.

---

## Core Components

### Organization as Tenant Root

**File:** `app/Models/Organization.php`

Organization is the central tenant entity. Every tenant-scoped model belongs to an Organization.

**Key fields:**
- `slug` — unique, used as subdomain (e.g., `demo` → `demo.registro.local`)
- `booking_type` — enum: `time_slot | item_rental | both`
- `industry` — nullable, cast to `Industry` enum (added Phase 5)
- `owner_id` — FK to User who created the org
- `settings` — JSON column for feature flags and per-tenant config
- `trial_ends_at` — 14-day trial set on creation
- `is_active` — indexed, checked during tenant resolution

**Key methods:**

| Method | Purpose |
|--------|---------|
| `hasFeature(string)` | Check feature flag (3-level priority chain) |
| `enableFeature(string)` / `disableFeature(string)` | Write explicit feature override |
| `hasModule(string)` | Check module activation (3-level priority chain, Phase 6) |
| `enableModule(string)` / `disableModule(string)` | Write explicit module override |
| `getSetting(string, mixed)` | Read from settings JSON |
| `supportsRentals()` | booking_type in [item_rental, both] |
| `supportsAppointments()` | booking_type in [time_slot, both] |
| `term(string)` | Industry-specific terminology |
| `onTrial()` / `trialExpired()` | Trial status |

**Relationships:** `owner`, `members` (pivot with role), `services`, `appointments`, `rentalCategories`, `rentalItems`, `rentals`, `settingRecords`.

---

### BelongsToOrganization Trait

**File:** `app/Traits/BelongsToOrganization.php`

Applied to every tenant-scoped model (~20 models). Provides two automatic behaviors:

1. **Global scope** — adds `WHERE organization_id = ?` to every query using `TenantFeature::currentTenant()`. Data isolation is automatic and cannot be accidentally bypassed.

2. **Auto-assign on create** — if `organization_id` is not set, the `creating` event fills it from `TenantFeature::currentTenant()`.

**Bypass mechanism:**

```php
// For admin/platform operations, seeders, artisan commands:
Model::withoutGlobalScope('organization')->get();
```

The scope is also skipped when `app()->runningInConsole() && !app()->runningUnitTests()` to allow seeders and commands to work without a tenant context.

All vertical seeders (e.g., `SeedEquipmentRental`) use `withoutGlobalScope('organization')` and explicitly set `organization_id`.

---

### ResolveTenant Middleware

**File:** `app/Http/Middleware/ResolveTenant.php`

Extracts the tenant from the request's subdomain.

**Resolution flow:**

```
Request (Host: demo.registro.local)
  → Compare against config('app.domain')
  → If host == base domain → root domain request, no tenant, pass through
  → Extract slug: strip base domain suffix → "demo"
  → Validate slug format (regex: ^[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$)
  → Cache::remember("tenant:slug:demo", 300, ...) → Organization query
  → Organization found + is_active → set on request
  → Not found / inactive → redirect to root domain
```

**Security:**
- Strict regex validation prevents Host header injection
- Fail-closed: unknown/inactive slugs redirect to root domain
- Null results are never cached (prevents cache poisoning)
- Preserves scheme and non-standard ports on redirect

**Where tenant is stored:**
```php
$request->attributes->set('tenant', $tenant);  // request-scoped, safe
```

**Applied to routes:** homepage `/`, CMS pages `/{slug}`, CMS content (`/aktualnosci/`, `/promocje/`, `/portfolio/`), service pages (`/uslugi/`), auth login, customer registration, all authenticated routes, API routes.

**CRITICAL:** ALL public routes that render tenant-scoped content (pages, posts, services) MUST have `ResolveTenant` middleware. Without it, `BelongsToOrganization` scope returns no tenant, `SettingsManager` reads global settings instead of tenant settings, and CMS pages may leak across tenants.

---

### TenantFeature Helper

**File:** `app/Support/TenantFeature.php`

Resolves the current tenant from **dual context** (Filament admin panel or public request).

**`currentTenant(): ?Organization`** tries in order:

1. **Filament context:** `filament()->getTenant()` — populated when admin browses `/admin/{slug}/...`
2. **Request context:** `app('request')->attributes->get('tenant')` — set by ResolveTenant middleware

Both wrapped in `try/catch (\Throwable)` to avoid crashes in console/test contexts.

**`active(string $feature): bool`** — shorthand:
```php
return TenantFeature::currentTenant()?->hasFeature($feature) ?? false;
```

Used throughout Filament resources for conditional navigation and field visibility.

---

### TenantUrl Helper

**File:** `app/Support/TenantUrl.php`

Generates tenant-specific URLs with correct subdomain.

| Method | Output |
|--------|--------|
| `url($tenant, '/path')` | `https://demo.registro.local:8444/path` |
| `route($tenant, 'route.name')` | `https://demo.registro.local:8444/generated-path` |
| `admin($tenant)` | `https://demo.registro.local:8444/admin/demo` |

Used in `BusinessRegisterController` for post-onboarding redirects and welcome screen.

---

## Feature Flags

### Priority Chain

`Organization::hasFeature(string $feature): bool` resolves via 3-level priority:

```
1. Explicit override  → settings.features.{feature}  (highest priority)
2. Industry defaults  → $this->industry->defaultFeatures()
3. booking_type defaults → FEATURE_DEFAULTS[booking_type]  (lowest priority)
```

**Level 1 — Explicit override:** Written by `enableFeature()` / `disableFeature()` into the `settings` JSON column. If set (even `false`), this wins unconditionally. Allows per-tenant customization.

**Level 2 — Industry defaults:** Each `Industry` enum case defines default features via `defaultFeatures()`. AutoDetailing enables all three features; others disable all.

**Level 3 — booking_type defaults:** Fallback constant `FEATURE_DEFAULTS`. Currently all false, so industry is the primary driver.

### Defined Features

| Feature | Controls |
|---------|----------|
| `vehicles` | Vehicle fields in appointments, VehicleType/CarBrand/CarModel resources |
| `mobile_service` | Mobile service fields, service_location_type in appointments |
| `service_area` | Service area functionality |

### Industry Defaults

| Industry | vehicles | mobile_service | service_area |
|----------|----------|----------------|--------------|
| EquipmentRental | false | false | false |
| AutoDetailing | true | true | true |
| GeneralServices | false | false | false |

---

## Module System (Phase 6)

Modules are feature **sets** that control visibility of entire resource groups in Filament.

### Organization::hasModule()

Same 3-level priority as `hasFeature()`:

```
1. Explicit override  → settings.modules.{module}  (highest priority)
2. Industry defaults  → $this->industry->defaultModules()
3. booking_type defaults → MODULE_DEFAULTS[booking_type]  (lowest priority)
```

### MODULE_DEFAULTS

```php
private const MODULE_DEFAULTS = [
    'time_slot' => ['services', 'bookings'],
    'item_rental' => ['rentals'],
    'both' => ['services', 'bookings', 'rentals'],
];
```

### Industry Default Modules

| Industry | Modules |
|----------|---------|
| EquipmentRental | `['rentals']` |
| AutoDetailing | `['services', 'bookings']` |
| GeneralServices | `['services', 'bookings']` |

All other modules (staff, customers, vehicles, communication, website, service_area) are OFF by default. Super-admin enables them per tenant in Platform panel.

### Modules vs Features

| Aspect | Modules (`hasModule`) | Features (`hasFeature`) |
|--------|----------------------|------------------------|
| **Purpose** | Enable/disable entire resource groups | Toggle specific fields/behaviors |
| **Examples** | services, bookings, rentals, staff | vehicles, mobile_service, service_area |
| **Filament** | Gates `shouldRegisterNavigation()` | Gates field `->visible()` |
| **Storage** | `settings.modules.*` | `settings.features.*` |

### Permission Naming (Phase 6)

Permissions follow `module.action` format:

```
services.view, services.create, services.edit, services.delete
bookings.view, bookings.create, bookings.edit, bookings.delete
bookings.view_own, bookings.cancel_own
staff.manage_availability, staff.view_availability
communication.manage_templates, communication.view_logs
settings.manage
```

Migration `2026_03_15_000001_rename_permissions_to_module_namespaced.php` renamed old flat format automatically.

---

## Filament Panels

### Admin Panel (`/admin`)

**File:** `app/Providers/Filament/AdminPanelProvider.php`

- Tenant-aware: `->tenant(Organization::class, slugAttribute: 'slug')`
- URLs: `/admin/{slug}/resource/...`
- `filament()->getTenant()` returns current Organization
- Resources auto-discovered from `app/Filament/Resources`

### Platform Panel (`/platform`)

**File:** `app/Providers/Filament/PlatformPanelProvider.php`

- No multi-tenancy — SaaS operator panel
- Protected by `EnsureSuperAdmin` middleware (requires `super-admin` Spatie role)
- Resources from `app/Filament/Platform/Resources`
- Color: Indigo (vs Teal for Admin)

### Resource Gating (Phase 6 — Module System)

Resources control their visibility via `$module` property on BaseResource:

```php
// BaseResource auto-gates navigation:
protected static ?string $module = 'services';  // gated by hasModule()
protected static ?string $module = null;          // always visible (core)

// shouldRegisterNavigation() in BaseResource checks:
// 1. $module === null → always visible
// 2. No tenant context (Platform/CLI) → always visible
// 3. Tenant context → $tenant->hasModule(static::$module)
```

**Module assignments:**
| Module | Resources |
|--------|-----------|
| `services` | ServiceResource |
| `bookings` | AppointmentResource |
| `rentals` | RentalCategoryResource, RentalItemResource, RentalResource |
| `staff` | EmployeeResource, StaffScheduleResource, StaffVacationPeriodResource |
| `customers` | CustomerResource |
| `vehicles` | VehicleTypeResource, CarBrandResource, CarModelResource |
| `communication` | EmailTemplateResource, SmsTemplateResource, EmailSendResource, SmsSendResource, ReminderConfigResource |
| `website` | PageResource, PostResource, PortfolioItemResource, PromotionResource, CategoryResource |
| `service_area` | ServiceAreaResource, ServiceAreaWaitlistResource |

Form fields still use feature flags for conditional visibility:
```php
TextInput::make('vehicle_type_id')
    ->visible(fn () => TenantFeature::active('vehicles'));
```

**Security hardening (Phase 6):**
- UserResource, RoleResource → super-admin only (`canViewAny`)
- SmsEvent, EmailEvent, Suppressions, MaintenanceEvent → super-admin only
- EmployeeResource — scoped via `organizations` pivot
- CustomerResource — scoped via `customerAppointments` / `rentalsAsCustomer`
- VehicleType/CarBrand/CarModel — read-only for non-super-admin

---

## EnsureSuperAdmin Middleware

**File:** `app/Http/Middleware/EnsureSuperAdmin.php`

Guards the Platform panel. Checks `$request->user()?->hasRole('super-admin')` (Spatie Permission). Returns 403 if not authorized. Applied as `authMiddleware` on `PlatformPanelProvider`.

---

## ValidOrganizationSlug Rule

**File:** `app/Rules/ValidOrganizationSlug.php`

Validates organization slugs for DNS compatibility:

1. Format: `^[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$` (or `^[a-z0-9]{1,2}$` for short slugs)
2. Minimum 3 characters
3. Maximum 63 characters (RFC 1035 DNS label limit)
4. No double hyphens (`--`) — prevents punycode conflicts
5. Not in reserved list (40+ words: `www`, `api`, `admin`, `platform`, `registro`, etc.)

---

## DNS / Subdomain Setup (Local Development)

For local development, tenants use `*.registro.local` subdomains.

### Requirements:

1. **Wildcard DNS** — `/etc/hosts` or dnsmasq must resolve `*.registro.local` to `127.0.0.1`
2. **Nginx wildcard server_name** — `*.registro.local` in the server block
3. **Wildcard SSL cert** — self-signed cert for `*.registro.local` (browsers show ERR_CERT_AUTHORITY_INVALID until trusted)

### Adding a new tenant locally:

```bash
# Option 1: /etc/hosts (manual per tenant)
echo "127.0.0.1 demo.registro.local" | sudo tee -a /etc/hosts

# Option 2: dnsmasq (wildcard, recommended)
echo "address=/registro.local/127.0.0.1" | sudo tee /etc/dnsmasq.d/registro.conf
sudo systemctl restart dnsmasq
```

### Known Issues:

- Self-signed wildcard cert → ERR_CERT_AUTHORITY_INVALID. Must trust cert in browser/OS keystore.
- Vite HMR/assets on subdomains may need `APP_URL` or Vite config adjustment for correct asset URLs.
- New tenants created via onboarding need DNS resolution before their subdomain works.
