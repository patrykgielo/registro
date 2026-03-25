# Rental System (Equipment Rental)

**Last Updated:** 2026-03-25
**Status:** Active Development

## Overview

Multi-step rental booking wizard for equipment/item rentals with pessimistic locking, hold pattern, and availability management.

## Architecture

### Hold Pattern
1. Customer selects dates + quantity → **Hold** created (15-min TTL, pessimistic `lockForUpdate()`)
2. Customer fills contact info → session stored
3. Customer confirms → **Hold → Pending** transition via `RentalAvailabilityService::confirmHold()`
4. Admin confirms → **Pending → Confirmed**

### Key Components

| Component | Path | Purpose |
|-----------|------|---------|
| `RentalAvailabilityService` | `app/Services/` | Availability calculation, hold CRUD, pricing |
| `RentalBookingController` | `app/Http/Controllers/` | 5-step booking flow |
| `Rental` model | `app/Models/` | Statuses: Held, Pending, Confirmed, Cancelled, Returned, Expired |
| `RentalResource` | `app/Filament/Resources/` | Admin panel with confirm actions |
| `ReleaseExpiredHolds` | `app/Console/Commands/` | Scheduled every 5 min |

### Routes
- `/wypozyczalnia/{service:slug}` — step 1-3, confirm, confirmation
- `/api/rental/{service:slug}/dostepnosc` — availability JSON
- `/api/rental/{service:slug}/kalendarz` — monthly calendar JSON

### Frontend
- **Mini calendar** on service show page sidebar (Alpine.js, AJAX)
- **Hold countdown timer** on steps 2-3 (auto-redirect on expire)
- **Live availability indicator** on step 1

## Tests (67 tests)

| File | Tests | Covers |
|------|-------|--------|
| `RentalAvailabilityServiceTest` | 21 | Availability, holds, confirmations, monthly calendar |
| `RentalBookingControllerTest` | 31 | Full flow, expired holds, API endpoints, validation |
| `RentalPricingTest` | 15 | Daily/weekly/long-term rates, quantity multiplier |

## Filament Admin Actions

- **confirmHeld** — for `Held` rentals: calls `confirmHold()` service, checks expiry, clears `held_until`
- **confirmPending** — for `Pending` rentals: direct status update to Confirmed
