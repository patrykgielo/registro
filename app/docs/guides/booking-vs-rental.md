# Booking vs Rental: Two Reservation Models

## Overview

Registro supports two distinct reservation models based on the tenant's `booking_type`:

| Model | booking_type | Use case | Key entity |
|-------|-------------|----------|------------|
| **Appointment** (time_slot) | `time_slot` | Staff-based services with calendar slots | Service (time_slot) → Appointment |
| **Rental** (item_rental) | `item_rental` | Equipment/item rental with date ranges | Service (item_rental) → Rental |

A tenant can also have `booking_type = both` to support both models simultaneously.

---

## How Industry Determines booking_type

```
Industry enum                    →  booking_type (derived)
─────────────────────────────────────────────────────────
equipment_rental                 →  item_rental
auto_detailing                   →  time_slot
general_services                 →  time_slot
```

Industry is set during onboarding (step 1). The `Industry::bookingType()` method derives the technical booking_type. **Never set booking_type manually** — it's always derived from industry.

---

## Appointment Model (time_slot)

**Files:** `app/Models/Service.php`, `app/Models/Appointment.php`

### Concept

A customer books a **time slot** for a **service** performed by a **staff member**. Think: hair salon, auto detailing, medical appointment.

### Service

- Has `price`, `duration_minutes`, `staff` (many-to-many)
- Rich content: `body`, `content` (JSON blocks), `featured_image`, SEO fields
- `metadata` JSON — used by auto-detailing for vehicle-size pricing matrix
- Scopes: `active()`, `published()`, `ordered()`

### Appointment

- Links: `service_id`, `staff_id`, `customer_id`
- **Time fields:** `appointment_date` (date), `start_time` / `end_time` (time HH:i)
- **Snapshot fields:** `service_price_at_booking`, `service_name_at_booking`, `service_duration_at_booking` — preserved even if service is later edited
- **Location fields:** `location_address`, `location_latitude/longitude`, `service_location_type` — for mobile services
- **Vehicle fields:** `vehicle_type_id`, `car_brand_id`, `car_model_id`, etc. — conditionally visible via `TenantFeature::active('vehicles')`
- **Contact snapshot:** `first_name`, `last_name`, `email`, `phone`

### Status Lifecycle

```
pending → confirmed → completed
                    → cancelled
```

Each transition auto-sets timestamp (`confirmed_at`, `completed_at`, `cancelled_at`).

### Events

| Event | Trigger |
|-------|---------|
| `AppointmentCreated` | On `created` |
| `AppointmentConfirmed` | Status → confirmed |
| `AppointmentCancelled` | Status → cancelled |
| `AppointmentRescheduled` | Date/time changed |

### Cancellation Policy

`can_be_cancelled` accessor checks `SettingsManager::cancellationHours()` (per-org setting, default 24h) against `appointment_date + start_time`.

---

## Rental Model (item_rental)

**Files:** `app/Models/Service.php` (with `service_type = item_rental`), `app/Models/RentalCategory.php`, `app/Models/Rental.php`

> **NOTE (2026-03-17):** RentalItem model was removed. Rental items are now `Service` records with `service_type = ServiceType::ItemRental`. This eliminates duplication in CMS blocks, cards, routes, and SEO — one universal model for all offerings.

### Concept

A customer rents **physical items** for a **date range**. Think: equipment rental, tool rental, vehicle rental. Items are represented as `Service` records with `service_type = 'item_rental'`.

### ServiceType Enum

```php
// app/Enums/ServiceType.php
ServiceType::TimeSlot     // Classic appointment-based service
ServiceType::ItemRental   // Inventory-based rental item
```

### RentalCategory

- Organizational grouping: `name`, `slug`, `description`, `icon`, `sort_order`
- `services(): HasMany` — Service records linked via `rental_category_id`

### Service (item_rental)

- Has `service_type = ServiceType::ItemRental`
- Optionally belongs to `RentalCategory` via `rental_category_id`
- **Stock:** `quantity_total` — total units available
- **Pricing:** `price_per_day`, `price_per_hour`, `price_per_week`, `deposit_amount`
- **Tiered pricing (long rentals):** `price_per_day_long` (reduced rate) + `price_threshold_days` (e.g., 7 days)
- **Brand:** separate column for filtering
- **Specifications:** stored in `metadata` JSON with `specs` key (e.g., `{'specs': {'power_w': 800}}`)
- **Inherits all Service CMS fields:** `body`, `content`, `featured_image`, SEO fields, `published_at`
- Scopes: `rentable()`, `active()`, `ordered()`

### Availability Logic

Availability has exactly one entry point: `RentalAvailabilityService::getAvailableQuantity()`
(see `app/docs/features/lokalizacje/kontrakt-dostepnosci.md`). `Service::availableQuantity()`,
`Service::isAvailable()` and `Service::scopeAvailableBetween()` were removed in Faza 0.1 of the
multi-location rollout — they had zero production callers and had already drifted from the truth
(`scopeAvailableBetween()` only counted `rentals`, ignoring `order_items`; `availableQuantity()`
skipped `RentalStatus::Held`).

```php
use App\Services\RentalAvailabilityService;

app(RentalAvailabilityService::class)->getAvailableQuantity($service, $start, $end);
```

### Data Integrity Guards (added 2026-03-20)

- **service_type immutable** — cannot be changed after creation (model `updating` guard)
- **rentals.service_id** — `restrictOnDelete` (admin cannot delete Service with active Rentals)
- **rental_category_id** — cross-tenant validation on update (category must belong to same org)
- **Rental status timestamps** — NOT in `$fillable`, set automatically by status transitions only

### Rental

- Links: `service_id` (NOT NULL, restrictOnDelete), `customer_id`
- **Date range:** `start_date`, `end_date` (dates, not times)
- **Quantity:** how many units rented
- **Pricing snapshot:** `pricing_unit` (hourly/daily/weekly), `unit_price_at_booking`, `total_price`, `deposit_amount`
- **Contact + invoice snapshot:** full address and NIP fields

### Status Lifecycle

```
pending → confirmed → active (picked up) → returned
                                          → cancelled
                    → cancelled
```

Each transition auto-sets timestamp. `is_overdue` accessor: status = `active` AND `end_date` in the past.

### Computed Fields

- `duration_days` — `end_date->diffInDays(start_date) + 1`
- `customer_name` — prefers snapshot data over FK relationship

### Database Indexes

- Composite: `[service_id, start_date, end_date]` — optimizes availability queries
- `[customer_id, start_date]`
- `status`

---

## Feature Flag Control

Feature flags control which UI elements are visible per tenant:

| Feature | Appointment fields affected | Rental affected |
|---------|---------------------------|-----------------|
| `vehicles` | Vehicle type/brand/model fields visible | N/A |
| `mobile_service` | Service location type visible | N/A |
| `service_area` | Service area settings | N/A |

Rental resources (RentalResource, RentalCategoryResource) are gated by `$module = 'rentals'`. ServiceResource (which manages both time_slot and item_rental services) is gated by `$module = 'services'`. Equipment rental tenants have both modules enabled by default.

Appointment resources are always visible (all tenants can have appointments). Vehicle/mobile fields within appointments are conditionally shown via feature flags.

---

## Comparison Table

| Aspect | Appointment (time_slot) | Rental (item_rental) |
|--------|------------------------|---------------------|
| **Unit** | Time slot (date + start/end time) | Date range (start/end date) |
| **Resource** | Service (time_slot) + Staff | Service (item_rental) + RentalCategory |
| **Pricing** | Fixed per service | Per day/hour/week + tiered + deposit |
| **Availability** | Staff calendar slots | Stock quantity minus active rentals |
| **Vehicle data** | Optional (feature flag) | N/A |
| **Location** | Optional (mobile service) | N/A (pickup point) |
| **Status flow** | pending→confirmed→completed/cancelled | pending→confirmed→active→returned/cancelled |
| **Quantity** | Always 1 | Variable (multiple units) |
| **Industries** | auto_detailing, general_services | equipment_rental |

---

## Adding a New Industry

When adding a new industry that needs a different booking model:

1. Add case to `Industry` enum with all required methods
2. Set `bookingType()` to `time_slot`, `item_rental`, or `both`
3. Create vertical seeder in `app/Actions/Onboarding/Seeders/`
4. Define `defaultFeatures()` for which feature flags to enable
5. Define `terminology()` for industry-specific labels
6. Return seeder FQCN in `seederClass()`
7. Add test in `tests/Unit/Actions/VerticalSeederTest.php`
