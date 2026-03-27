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
- `Cart::factory()->active()` / `->abandoned()` / `->converted()`
- `Order::factory()->pendingPayment()` / `->paid()` / `->confirmed()` / `->inProgress()` / `->cancelled()` / `->completed()` / `->expired()`
- `CartItem::factory()` — creates cart + itemRental service automatically
- `OrderItem::factory()` — creates order + itemRental service automatically

## Test Count (as of 2026-03-26)
- Total: 285 passed, 5 failed (pre-existing)
- New Sprint 1 model tests: 41 (Cart: 9, CartItem: 10, OrderItem: 13, Order: 19)

## SQLite Gotchas
- No ENUM column type — use string (SQLite accepts enums as varchar, no crash)
- No lockForUpdate() — skip concurrency tests or mock
- JSON column queries differ from MySQL
- Timestamp precision differs
- `ALTER TABLE ... ADD CONSTRAINT ... CHECK` NOT supported — guard with `if (DB::getDriverName() !== 'sqlite')` in migrations
- [feedback_sqlite_check_constraint.md](feedback_sqlite_check_constraint.md) — details on this pattern

## Models Requiring HasFactory
- Order model was missing `HasFactory` — added 2026-03-26. Always check new models for this trait before writing factories.

## State Machine Testing Pattern
- Use `$order->status()->transitionTo('target')` — calls State::transitionTo()
- Forbidden transitions throw `Asantibanez\LaravelEloquentStateMachines\Exceptions\TransitionNotAllowedException`
- `$order->status()->canBe('target')` returns bool without side effects (safe for assertion)
- Factory states bypass the state machine by setting status directly in DB — test `transitionTo()` from those states
