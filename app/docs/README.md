# Registro Documentation

## Architecture Overview

Registro to multi-tenant SaaS dla rezerwacji i wypożyczeń. Każdy tenant (Organization) działa na własnej subdomenie (`slug.registro.app`).

### Kluczowe koncepty

| Koncept | Opis | Pliki |
|---------|------|-------|
| **Organization** | Tenant — firma klienta z booking_type + industry | `app/Models/Organization.php` |
| **Industry** | Branża (equipment_rental, auto_detailing, general_services) | `app/Enums/Industry.php` |
| **BelongsToOrganization** | Trait izolujący dane per tenant (global scope) | `app/Traits/BelongsToOrganization.php` |
| **TenantFeature** | Helper sprawdzający feature flags per tenant | `app/Support/TenantFeature.php` |
| **TenantUrl** | Generator URL z subdomeną tenanta | `app/Support/TenantUrl.php` |
| **ResolveTenant** | Middleware rozwiązujący tenant z subdomeny | `app/Http/Middleware/ResolveTenant.php` |

### Tenant resolution flow

```
Request → nginx (*.registro.local) → ResolveTenant middleware
  → Extract subdomain from Host header
  → Find Organization by slug (cached)
  → Set on request attributes + app binding
  → BelongsToOrganization global scope filters all queries
```

### booking_type vs Industry

```
Industry (user-facing)          →  booking_type (technical)
─────────────────────────────────────────────────────────
equipment_rental                →  item_rental
auto_detailing                  →  time_slot
general_services                →  time_slot
```

Industry DERIVE'uje booking_type. Nie ustawiaj booking_type ręcznie.

### Feature flags & Modules

**Features** (boolean toggles): `settings.features.X` — priorytet: explicit > industry > booking_type
**Modules** (resource groups, Phase 6): `settings.modules.X` — priorytet: explicit > industry > booking_type

Modules gatują widoczność Resources w Filament, Features gatują pola w formularzach.

---

## Documentation Index

| Section | Lokalizacja | Opis |
|---------|-------------|------|
| **Architecture** | `architecture/` | Mermaid diagrams: panel/data isolation, infrastructure, tenant provisioning, request flow |
| **Analytics** | `analytics/` | Tech reference + client guide dla systemu analitycznego |
| **Features** | `features/` | Dokumentacja funkcjonalności |
| **Guides** | `guides/` | How-to guides i best practices |
| **Decisions** | `decisions/` | Architecture Decision Records (ADR) |
| **Security** | `security/` | Baseline, audits, vulnerabilities |
| **Legal** | `legal/` | GDPR assessments (analytics-gdpr-lia.md) |
| **Deployment** | `deployment/` | Deploy scripts, pre-deployment checks. **Start here for installing a tenant: [`deployment/instalacja-tenanta-od-zera.md`](deployment/instalacja-tenanta-od-zera.md)** — the operator path from an unprepared server to a working client, in Polish, one line of explanation per command. Every other deployment doc is a deep-dive on one piece of it. |
| **Dependencies** | `dependencies.md` | External packages i wersje |

### Architecture

Mermaid diagrams, verified against current code — the reference set for "how does this actually
work," not just "how was it designed to work." See also `guides/multi-tenancy-architecture.md`
for the conceptual overview these deep-dive.

| Diagram | Plik | Covers |
|---------|------|--------|
| Panel Isolation | `architecture/panel-isolation.md` | `/platform` vs `/admin` split, authorization layering, the boundary behind VULN-003 and the EditOrder PII leak |
| Data Isolation | `architecture/data-isolation.md` | `organization_id` scoping end-to-end — `ResolveTenant` → `BelongsToOrganization` fail-closed scope |
| Infrastructure | `architecture/infrastructure.md` | Docker Compose topology across dev/staging/prod, real drift between the 4 compose files |
| Tenant Provisioning | `features/tenant-stack-provisioning.md` | `registro:tenant-provision` CLI → active tenant; automatic vs. manual `onboarding:seed-vertical`. (Old public self-serve wizard doc archived: `docs/archive/features/tenant-provisioning-wizard.md`.) |
| Request Flow | `architecture/request-flow.md` | Checkout sequence diagram (thin-controller pattern) + the sync/async queue boundary |

### Features

| Feature | Plik | Status |
|---------|------|--------|
| Onboarding & Registration | `features/onboarding-and-registration.md` | Phase 5 complete |
| Tenant-Stack Provisioning | `features/tenant-stack-provisioning.md` | CLI provisioning + singleton lock for dedicated per-client stacks |
| CMS Page Menu | `features/cms-page-menu.md` | Stable |
| SMS System | `features/sms-system/` | Stable |
| SEO Meta Tags | `features/seo-meta-tags.md` | Phase A complete (Post/Portfolio/Page/Service) |
| Order Handover/Return Protocols | `features/order-protocols.md` | Download-only PDFs, generated on demand |

### Guides

| Guide | Plik |
|-------|------|
| Multi-Tenancy Architecture | `guides/multi-tenancy-architecture.md` |
| Booking vs Rental Models | `guides/booking-vs-rental.md` |
| Filament v4 Best Practices | `guides/filament-v4-best-practices.md` |
| Filament v4 Migration | `guides/filament-v4-migration-guide.md` |
| Filament v4 Components | `guides/filament-v4-component-architecture.md` |
| Filament v4 Widgets | `guides/filament-v4-widgets-guide.md` |
| CMS Layouts | `guides/cms-layouts.md` |

---

## Multi-Tenancy Phases

| Phase | Status | Opis |
|-------|--------|------|
| 1. Foundation | ✅ Complete | Organization model, tenant isolation, Platform panel |
| 2. Feature Flags | ✅ Complete | hasFeature(), TenantFeature, conditional visibility |
| 3. Item Rental Models | ✅ Complete | RentalCategory, Service (item_rental), Rental |
| 4. Subdomain Resolution | ✅ Complete | ResolveTenant, auth flow, EnsureSuperAdmin |
| 5. Onboarding + Verticals | ✅ Complete | Industry enum, 3-step wizard, vertical seeders |
| 6. Module System + Security | ✅ Complete | Module gating, permission namespacing, tenant isolation |
| 7. Public Rental Booking | 🔲 Future | UI for item_rental tenants |
| 8. Marketplace | 🔲 Future | Root domain tenant listings |
| 9. Billing | 🔲 Future | Stripe, subscriptions, trials |
| 10. Branding | 🔲 Future | Custom logos, colors, domains |

See `memory/phases-roadmap.md` for details.
