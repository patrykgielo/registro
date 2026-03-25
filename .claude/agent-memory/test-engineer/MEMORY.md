# Test Engineer — Project Memory

## Pre-Existing Failures (5 total — IGNORE)
- BookingServiceAreaBypassTest (4 failures) — CSRF related
- TenantFeatureTest (1 failure) — tenant scoping edge case

## Testing Environment
- Docker only: `docker compose exec -T app php artisan test`
- `.env.testing` forces SQLite in-memory
- Local PHP 8.2, Docker PHP 8.3
- ThrottleRequests middleware must be disabled in controller tests

## Factory States Available
- `Service::factory()->itemRental()` — rental-type service
- `Service::factory()->timeSlot()` — booking-type service (if exists)
- `Rental::factory()->held()` — status=Held, held_until=+15min
- `Rental::factory()->pending()` — status=Pending (if exists)

## Test Count (as of 2026-03-25)
- Total: 301 passed, 5 failed (pre-existing)
- Rental tests: 67 (RentalAvailabilityService: 21, RentalBookingController: 31, RentalPricing: 15)

## SQLite Gotchas
- No ENUM column type — use string
- No lockForUpdate() — skip concurrency tests or mock
- JSON column queries differ from MySQL
- Timestamp precision differs
