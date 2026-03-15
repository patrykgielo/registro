# Booking vs Rental: Two Reservation Models

## Overview

Registro supports two distinct reservation models based on the tenant's `booking_type`:

| Model | booking_type | Use case | Key entity |
|-------|-------------|----------|------------|
| **Appointment** (time_slot) | `time_slot` | Staff-based services with calendar slots | Service → Appointment |
| **Rental** (item_rental) | `item_rental` | Equipment/item rental with date ranges | RentalItem → Rental |

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

**Files:** `app/Models/RentalCategory.php`, `app/Models/RentalItem.php`, `app/Models/Rental.php`

### Concept

A customer rents **physical items** for a **date range**. Think: equipment rental, tool rental, vehicle rental.

### RentalCategory

- Organizational grouping: `name`, `slug`, `description`, `icon`, `sort_order`
- `rentalItems(): HasMany`

### RentalItem

- Belongs to `RentalCategory`
- **Stock:** `quantity_total` — total units available
- **Pricing:** `price_per_day`, `price_per_hour`, `price_per_week`, `deposit_amount`
- **Tiered pricing (long rentals):** `price_per_day_long` (reduced rate) + `price_threshold_days` (e.g., 7 days)
- **Brand:** separate column for filtering
- **Specifications:** `specifications` JSON with `specs` (typed keys per category) + `custom_specs` (free-form key/value)

### Availability Logic

```php
// How many units are free in a date range?
$item->availableQuantity(Carbon $start, Carbon $end): int
// Counts active/pending/confirmed rentals overlapping the range
// Subtracts from quantity_total

// Is at least 1 unit available?
$item->isAvailable(Carbon $start, Carbon $end, int $quantity = 1): bool

// Query scope for filtering
RentalItem::availableBetween($start, $end)->get();
```

### Rental

- Links: `rental_item_id`, `customer_id`
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

- Composite: `[rental_item_id, start_date, end_date]` — optimizes availability queries
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

Rental resources (RentalResource, RentalCategoryResource, RentalItemResource) are gated by `$tenant->supportsRentals()` — i.e., `booking_type` must be `item_rental` or `both`.

Appointment resources are always visible (all tenants can have appointments). Vehicle/mobile fields within appointments are conditionally shown via feature flags.

---

## Comparison Table

| Aspect | Appointment (time_slot) | Rental (item_rental) |
|--------|------------------------|---------------------|
| **Unit** | Time slot (date + start/end time) | Date range (start/end date) |
| **Resource** | Service + Staff | RentalItem (physical stock) |
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
