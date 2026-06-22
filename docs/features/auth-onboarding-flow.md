# Authentication & Onboarding Flow

## Registration Flow

### Business Registration (root domain only — 3-step wizard)

```mermaid
flowchart TD
    START([Guest]) --> DOM{Domain?}
    DOM -- "registro.local" --> S1
    DOM -- "slug.registro.local" --> CRCHECK

    S1["POST /register — 10 req/min\norg_name · slug · industry\nSession: business_register.step1"]
    S1 --> S2["POST /register/step/2 — 5 req/min\nfirst_name · last_name · email\npassword min-8 · terms accepted"]
    S2 --> TX[["CreateOrganizationWithOwner — DB Transaction"]]

    TX --> U1["Create User\nemail_verified_at = now()"]
    U1 --> U2["Role::firstOrCreate admin\nassignRole(admin)"]
    U2 --> U3["Create Organization\ntrial_ends_at = now +14 days\nsubscription_status = trial\nbooking_type from Industry enum\nis_active = true"]
    U3 --> U4["organization_user pivot\nrole = owner"]
    U4 --> SEED["SeedOrganizationDefaults::execute()"]

    SEED --> SD1["Default Settings\nvat_rate=23 · booking hours\nslot_interval · reg_enabled"]
    SEED --> SD2["Industry::defaultFeatures()\nfeature flag seeds"]
    SEED --> SD3{Vertical Seeder}
    SD3 -- EquipmentRental --> VE["7 categories · 13 items"]
    SD3 -- AutoDetailing --> VA["8 services + metadata"]
    SD3 -- GeneralServices --> VG["1 placeholder service"]

    SD1 & SD2 & VE & VA & VG --> AUTOLOGIN["Auto-login user\nsession: business_register.organization_id"]
    AUTOLOGIN --> S3["POST /register/step/3 optional — 10 req/min\ncity · address · mobile_service\nservice_radius_km 1-200\nSaved to org settings"]
    S3 --> WELCOME["GET /register/welcome\nShows admin URL\nAuto-redirect to /admin after 5 s"]
    WELCOME --> FIRST["First login\ntenant.registro.local/admin"]

    NOTE_VER[/"Business owners: auto-verified email_verified_at=now()\nCustomers: MustVerifyEmail commented out — not enforced"/]
    U1 -. auto-verified .-> NOTE_VER

    CRCHECK{"auth.registration_enabled\norg setting?"}
    CRCHECK -- No --> BLOCKED(["Registration blocked"])
    CRCHECK -- Yes --> CRREG["POST /customer/register\nfirst_name · last_name · email · password\nMiddleware: guest + ResolveTenant + CheckRegistrationEnabled"]
    CRREG --> CRA["assignRole(customer)"]
    CRA --> CRP["organization_user pivot\nrole = customer"]
    CRP --> CRE["Fire UserRegistered event\nwelcome email queued"]
    CRE --> CRD["Redirect to tenant /"]
```

**Step 1** (`GET/POST /register`) — guest only; GET unrestricted, POST throttled at 10/min
- Fields: `org_name` (max 100), `slug` (ValidOrganizationSlug + unique), `industry` (Industry enum)
- Stored in session: `business_register.step1`
- AJAX helpers: `GET /register/check-slug` (30/min), `GET /register/generate-slug` (30/min)

**Step 2** (`GET/POST /register/step/2`) — guest only, POST throttled at 5/min
- Fields: `first_name`, `last_name`, `email` (unique), `password` (min 8, confirmed), `terms` (accepted)
- On POST: executes `CreateOrganizationWithOwner::execute()` in a DB transaction (see Onboarding Wizard below)
- Auto-logs in user, stores `business_register.organization_id` in session
- Redirects to Step 3

**Step 3** (`GET/POST /register/step/3`) — auth required, POST throttled at 10/min
- Optional fields: `city`, `address`, `mobile_service` (boolean), `service_radius_km` (1–200)
- Saves to `org->settings` (`location.*`, `features.*`)
- Redirects to welcome page

**Welcome** (`GET /register/welcome`) — auth required
- Shows admin URL, auto-redirects to `/admin` after 5 seconds
- Session key `business_register.organization_id` still active

### Customer Registration (tenant subdomain only)

Route: `GET/POST /customer/register`
- Middleware: `guest`, `ResolveTenant`, `CheckRegistrationEnabled` (org setting `auth.registration_enabled`)
- Fields: `first_name`, `last_name`, `email` (unique), `password` (min 8, confirmed)
- On registered: `$user->assignRole('customer')`, attaches to org via `organization_user` pivot with `role = 'customer'`
- Fires `UserRegistered` event (welcome email queued)
- No email verification required
- Redirects to `/` (tenant homepage)

Backwards compatibility: `/get-started` → 301 redirect to `/register`

---

## Onboarding Wizard (Organization Setup)

`app/Actions/Onboarding/CreateOrganizationWithOwner.php`

All steps execute inside a single DB transaction:

1. User created with `email_verified_at = now()` (auto-verified)
2. `Role::firstOrCreate(['name' => 'admin'])` then `$user->assignRole('admin')`
3. Organization created: `name`, `slug`, `booking_type` (derived from `Industry::bookingType()`), `industry`, `owner_id`, `is_active = true`, `trial_ends_at = now()->addDays(14)`, `subscription_status = 'trial'`
4. `organization_user` pivot inserted: `role = 'owner'`
5. `SeedOrganizationDefaults::execute($org)`:
   - Seeds default `Settings` records: booking hours, slot interval, `registration_enabled`, `vat_rate = 23`
   - Seeds industry feature flags via `Industry::defaultFeatures()`
   - Runs vertical seeder based on industry:
     - `EquipmentRental` → 7 categories + 13 items
     - `AutoDetailing` → 8 services with metadata
     - `GeneralServices` → 1 placeholder service

Module resolution is automatic — `hasModule()` resolves from industry at runtime, no explicit module seeding.

---

## Role Hierarchy

```mermaid
flowchart LR
    subgraph ASSIGN["Role Assignment Sources"]
        direction TB
        BIZ["Business Registration\nStep 2"] -->|assignRole| AR(["admin"])
        CUST_S["Customer Registration"] -->|assignRole| CR(["customer"])
        STAFF_ADD["Admin creates staff\nFilament panel"] -->|assignRole + password setup email| SR(["staff"])
        PLAT_SEED["Platform bootstrap\nmanual seeder"] -->|assignRole| SAR(["super-admin"])
    end

    SAR -->|EnsureSuperAdmin — abort 403 otherwise| PLAT_P["Platform Panel\nregistro.local/platform\nAll tenants · SaaS KPIs\nMRR · TenantPayments"]
    SAR -->|canAccessTenant| ADMIN_P

    AR -->|hasAnyRole + canAccessTenant| ADMIN_P["Admin Panel\ntenant-subdomain/admin\nBookings · Rentals · Services\nStaff · Customers · Analytics\nSettings"]
    SR -->|hasAnyRole + canAccessTenant + module perms| ADMIN_P

    CR --> PUB["Public Frontend\ntenant-subdomain\nCatalogue · Cart\nCheckout · Orders\n/moje-konto"]

    ADMIN_P -. "BaseResource.\$module\nshouldRegisterNavigation()" .-> MOD[/"Module-gated Permissions\nservices.view · bookings.create\nstaff.manage_availability\nformat: module.action"/]

    subgraph PIVOT["organization_user pivot role (separate from Spatie roles)"]
        direction TB
        P1["owner — business registration"]
        P2["customer — customer registration"]
        P3["staff — admin-created users"]
    end
```

| Role | Who | Panel Access | Key Abilities |
|------|-----|-------------|---------------|
| `super-admin` | Platform operator (Registro team) | `/platform` (Filament) | Everything; `EnsureSuperAdmin` middleware; can access any tenant |
| `admin` | Business owner / org admin | `/admin` on their subdomain | Full tenant Filament panel; all module resources |
| `staff` | Employees added by admin | `/admin` on their org subdomain | Filament panel if added to org via `organization_user`; scope varies by module permissions |
| `customer` | End customers on tenant subdomains | None (frontend only) | Bookings, cart, orders, profile at `/moje-konto` |

**`User::canAccessPanel(Panel $panel)`:**
- `platform` panel: `hasRole('super-admin')` only
- `admin` panel: `hasAnyRole(['super-admin', 'admin', 'staff'])` + session fixation detection (session `user_id` vs `auth()->id()`)

**`LoginController::authenticated()` — post-login redirect logic:**
- `super-admin` → `/platform`
- `admin`/`staff` on tenant subdomain → `/admin` (checks `canAccessTenant`)
- `admin`/`staff` on root domain → first org's subdomain `/admin`
- `customer` → `appointments.index`

**Pivot role** (`organization_user.role`): `'owner'` (business registration), `'customer'` (customer registration). Staff members added via the admin panel receive their own pivot role. This is separate from Spatie roles.

**Module-gated permissions** (namespaced, Phase 6):
- Format: `module.action` — e.g. `services.view`, `bookings.create`, `staff.manage_availability`
- Resources self-gate via `BaseResource.$module` checked in `shouldRegisterNavigation()`

---

## Platform vs Admin Panel Routing

```mermaid
flowchart LR
    REQ([HTTP Request]) --> HOST{Host header}
    HOST -- "registro.local" --> ROOT{Path?}
    HOST -- "slug.registro.local" --> RESOLVE

    ROOT -- "/platform/*" --> SACHK{hasRole\nsuper-admin?}
    SACHK -- Yes --> PLAT["Platform Filament Panel\nAll tenant management\nNo tenant context needed"]
    SACHK -- No --> F403["abort(403)"]

    ROOT -- "/register/*" --> GUESTCHK{guest?}
    GUESTCHK -- Yes --> BIZWIZ["Business Registration Wizard\n3 steps · root domain only"]
    GUESTCHK -- No --> REDIR_ADM["Redirect /admin"]

    ROOT -- "other" --> STD["Standard routes\n/login · /password/* · frontend"]

    RESOLVE["ResolveTenant middleware\nExtract slug from Host header\nValidate regex — prevents Host injection\nCache: tenant:slug:{slug} 5 min"] --> ORGCHK{Org exists\n& is_active=true?}
    ORGCHK -- No/unknown --> REDIR_ROOT1["Redirect to root domain\nfail-closed"]
    ORGCHK -- Yes --> SETCTX["Set tenant context\nrequest.attributes.tenant\nsession.tenant_id for Livewire"]

    SETCTX --> SUB{Path?}

    SUB -- "/admin/*" --> AUTHCHK{Authenticated?}
    AUTHCHK -- No --> LOGINP["Redirect to /login"]
    AUTHCHK -- Yes --> ROLECHK{hasAnyRole\nadmin/staff/super-admin?}
    ROLECHK -- No --> REDIR_ROOT2["Redirect to root"]
    ROLECHK -- Yes --> TENCHK{canAccessTenant?}
    TENCHK -- No --> REDIR_ROOT2
    TENCHK -- Yes --> SESCHK{Session fixation check\nsession.user_id == auth.id?}
    SESCHK -- No --> FORCEOUT["Force logout\n+ invalidate session"]
    SESCHK -- Yes --> ADMINP["Admin Filament Panel\nModule-gated resources"]

    SUB -- "/customer/register" --> REGEN{registration_enabled\norg setting?}
    REGEN -- No --> BLOCKED(["Blocked"])
    REGEN -- Yes --> CUSTREG["Customer Registration Form"]

    SUB -- "other" --> TENFE["Tenant Frontend\nCatalogue · Cart · Checkout\n/moje-konto"]

    subgraph POSTLOGIN["LoginController::authenticated() — post-login redirect"]
        direction TB
        LC1["super-admin → /platform"]
        LC2["admin/staff on tenant subdomain → /admin"]
        LC3["admin/staff on root domain → first org subdomain /admin"]
        LC4["customer → appointments.index"]
    end

    LOGINP -.-> POSTLOGIN
```

| URL Pattern | Panel | Who | Middleware |
|-------------|-------|-----|------------|
| `registro.local:8444` (root) | None (frontend) | Guests/customers | `ResolveTenant` (no tenant set) |
| `registro.local:8444/register` | — | Business registration wizard | `guest` |
| `registro.local:8444/platform` | Platform (Filament) | `super-admin` only | `Authenticate`, `EnsureSuperAdmin` |
| `{slug}.registro.local:8444/admin` | Admin (Filament) | `admin`, `staff` (must belong to tenant) | `Authenticate`, `AdminMaintenanceCheck`, `ResolveTenant` |
| `{slug}.registro.local:8444/customer/register` | — | Customer self-registration | `guest`, `ResolveTenant`, `CheckRegistrationEnabled` |

**`ResolveTenant` middleware:**
- Extracts subdomain from `Host` header
- Validates slug format via regex (prevents Host-header injection)
- Looks up active org with 5-min cache keyed `tenant:slug:{slug}`
- Unknown or inactive subdomain → redirect to root (fail-closed)
- Sets `request->attributes->set('tenant', $org)` + `session->put('tenant_id', $org->id)` (needed for Livewire)
- On `/admin*` routes (excluding login): admin/staff must pass `canAccessTenant()` or are redirected to root

**Platform panel:** `EnsureSuperAdmin` hard-gates with `abort(403)` for non-super-admin. No tenant resolution needed — platform operates across all tenants. Resources discovered from `app/Filament/Platform/Resources/`.

---

## Trial & Subscription Flow

Fields on `Organization`:

| Column | Type | Notes |
|--------|------|-------|
| `trial_ends_at` | datetime | Set to `now()->addDays(14)` on org creation |
| `subscription_status` | enum | `trial` \| `active` \| `paused` \| `cancelled` — default `trial` |
| `monthly_fee` | decimal | Nullable |
| `subscribed_at` | datetime | Nullable |
| `subscription_expires_at` | datetime | Nullable |

**Methods on `Organization`:**
- `onTrial()` → `trial_ends_at !== null && trial_ends_at->isFuture()`
- `trialExpired()` → `trial_ends_at !== null && trial_ends_at->isPast()`
- `isTrial()` → `subscription_status === 'trial'` (column-based)
- `isSubscribed()` → `subscription_status === 'active'`

**No automated enforcement exists.** The `subscription_status` column and model methods are in place, but no middleware blocks access after trial expiry. Subscription management is manual via the Platform panel (`TenantPayment` model). Inactive orgs (`is_active = false`) are backfilled to `subscription_status = 'cancelled'` via migration.

---

## Password Reset

### Standard Reset

Routes via `Auth::routes(...)` — grouped under `throttle:5,1` + `ResolveTenant`:
- `GET /password/reset` — show email form
- `POST /password/email` — send reset link
- `GET /password/reset/{token}` — show reset form
- `POST /password/reset` — process reset
- After reset: redirects to `/home`

Uses standard Laravel `SendsPasswordResetEmails` + `ResetsPasswords` traits with no customization.

### Password Setup (admin-created staff users)

`GET /password/setup/{token}` — `SetPasswordController::show()`
`POST /password/setup` — `SetPasswordController::store()` — 6/min throttle

Flow:
1. Admin creates a staff user in the Filament panel → `User::initiatePasswordSetup()` generates `password_setup_token` (64-char random, 30-min expiry)
2. Setup email sent with tokenized link
3. User opens link → token + expiry validated → form shown
4. `User::completePasswordSetup()` hashes and saves password, clears token fields
5. Redirects to `/login` with success flash

Expired or invalid token: renders `auth.passwords.token-expired` view.

---

## Email Verification

`VerificationController` uses standard Laravel `VerifiesEmails` trait:
- Middleware: `auth` + `signed` (on verify route) + `throttle:6,1`
- After verification: redirects to `/home`

**Business owners are auto-verified** — `email_verified_at = now()` is set in `CreateOrganizationWithOwner`. No verification email is sent.

**Customer registration does not enforce verification** — `MustVerifyEmail` is commented out on the `User` model (`// use Illuminate\Contracts\Auth\MustVerifyEmail;`), so even though `email_verified_at` is not set for customers, the verification gate is never triggered.

### Profile Email Change (separate flow)

- `POST /moje-konto/email/zmien` → `User::requestEmailChange()` — stores `pending_email`, `pending_email_token` (64-char), expires in 24h
- `GET /moje-konto/email/potwierdz/{token}` → `User::confirmEmailChange()` — validates token + expiry, swaps `email` with `pending_email`
