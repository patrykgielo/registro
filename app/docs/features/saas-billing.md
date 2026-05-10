# SaaS Billing Model

**Branch:** `feature/statistics-analytics`
**Date:** 2026-05-10
**Status:** Implemented (Phase 11b — billing foundations)

---

## Overview

Adds SaaS subscription tracking to the platform so super-admins can monitor tenant billing status and MRR without a third-party payment processor integration.

---

## Database Changes

### `organizations` — new columns

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `subscription_status` | ENUM | `trial` | trial / active / paused / cancelled |
| `monthly_fee` | DECIMAL(8,2) | NULL | Monthly charge for this tenant |
| `subscribed_at` | TIMESTAMP | NULL | When the subscription became active |
| `subscription_expires_at` | TIMESTAMP | NULL | Hard expiry for fixed-term agreements |

**Migration:** `2026_05_10_100001_add_subscription_fields_to_organizations.php`

**Backfill logic:** Organizations with `is_active = false` are set to `subscription_status = cancelled`. All others default to `trial`.

### `tenant_payments` — new table

Manual SaaS payment register (off-band payments — bank transfer, card on file, etc.).

| Column | Type | Description |
|--------|------|-------------|
| `organization_id` | FK | Tenant |
| `amount` | DECIMAL(8,2) | Amount paid |
| `currency` | CHAR(3) | Default PLN |
| `period_month` | VARCHAR(7) | "2026-05" format |
| `notes` | VARCHAR | Optional note |
| `recorded_by` | FK → users | Staff who recorded it |
| `paid_at` | TIMESTAMP | Actual payment date |

**Migration:** `2026_05_10_100002_create_tenant_payments_table.php`

---

## Model Changes

### `App\Models\Organization`

New fillable fields: `subscription_status`, `monthly_fee`, `subscribed_at`, `subscription_expires_at`

New methods:
- `isSubscribed(): bool` — true when `subscription_status === 'active'`
- `isTrial(): bool` — true when `subscription_status === 'trial'`
- `tenantPayments(): HasMany` — relation to `TenantPayment`

### `App\Models\TenantPayment` (new)

Simple model with `organization()` and `recorder()` BelongsTo relations.

---

## Platform Statistics Page Rewrite

**File:** `app/Filament/Platform/Pages/Statistics.php`

**Old:** Cross-tenant revenue aggregates from `statistics_daily_snapshots` via `StatisticsService::platformAggregate()` and `perTenant()`.

**New:** SaaS billing KPIs sourced directly from `organizations` and `tenant_payments`.

### KPI Cards

1. **Tenanci łącznie** — total org count with paused/cancelled breakdown
2. **Aktywni** — `subscription_status = active` count
3. **Na trialu** — `subscription_status = trial` count, highlights tenants expiring in 7 days
4. **MRR** — sum of `monthly_fee` for active orgs; new registrations in selected period as subtext

### Chart

Single-series bar chart: new registrations per day in the selected period.
Uses the same `revenueChart` Alpine.js component as `/admin/statystyki` (single series in array).

### Tables

- **Wygasające triale** — orgs with trial expiring within 14 days (shown only when non-empty)
- **Wszyscy tenanci** — full list ordered by status (active → trial → paused → cancelled), then by creation date descending. Columns: Organizacja, Status (badge), Miesięczna opłata, Aktywny od, Trial do.

Status badge colours: active=green, trial=cyan, paused=amber, cancelled=red.

---

## StatisticsService Cleanup

Removed `platformAggregate(Carbon $from, Carbon $to)` and `perTenant(Carbon $from, Carbon $to)` — both were only called from the old platform statistics page which has been replaced.

Remaining public methods (still in use by `/admin/statystyki`):
- `forTenant(Organization $org, Carbon $from, Carbon $to)`
- `liveForDate(Organization $org, Carbon $date)`
- `periodToRange(string $period)`

---

## What Is NOT in scope

- Filament Resource for managing `TenantPayment` — separate phase
- Adding subscription fields to existing Organization Filament resource — separate phase
- Payment gateway integration — separate phase
