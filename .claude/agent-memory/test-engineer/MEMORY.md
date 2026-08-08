# Test Engineer — Project Memory

## Pre-Existing Failures (drifts over time — check `php artisan test` yourself before trusting this)
- As of 2026-08-08: 3 failed, 5 skipped, 1051 passed on default suite (Unit+Feature) — 2x
  `CustomerOrdersTest` (cancel flow, email-template lookup), 1x `TenantFeatureTest` (booking wizard
  step 4 404). Older note below (5 failures: BookingServiceAreaBypassTest x4 + TenantFeatureTest x1)
  no longer matches current repo state — don't trust either count blindly, always re-run baseline.

## E2E Browser Testing (Pest v4 + pest-plugin-browser, added 2026-08-08, workaround rewritten same day)
- [project_e2e_browser_foundation_2026-08-08.md](project_e2e_browser_foundation_2026-08-08.md) — `tests/Browser/SmokeTest.php` foundation. Cookie bug fixed upstream by v4.3.0 (was a local vendor patch, now gone — vendor is CLEAN). Host/tenant bug is still open upstream (pest#1734) and is now worked around app-side by `App\Http\Middleware\Testing\PestBrowserHostBugWorkaround` (`APP_ENV=testing`-gated, `bootstrap/app.php`) — NOT a vendor patch, survives `composer update`. Read this file before touching Browser tests again.
- Selector gotcha: Filament login inputs render `id="form.email"`/`id="form.password"` (dotted statePath), NOT `data.email`. Must use explicit attribute selector `[id="form.email"]` — the bare dotted string gets misread as CSS `tag.class` by `Selector::isExplicit()` and HANGS (not a fast failure).

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

## Test Count (as of 2026-03-29)
- Total: 397 passed, 5 failed (pre-existing)
- Sprint 1 model tests: 41 (Cart: 9, CartItem: 10, OrderItem: 13, Order: 19)
- Sprint 2 service tests: 43 (CartService: 13, RentalAvailabilityService: 11, OrderService: 12, Przelewy24Service: 7)
- Sprint 3 feature tests: 30 (AddToCartTest: 11, CheckoutFlowTest: 9, CustomerOrdersTest: 10)
- Sprint 4 tests: 28 (DeprecatedRentalRoutesTest: 11, CleanupExpiredOrdersTest: 8, CleanupAbandonedCartsTest: 9)
- Sprint 5 tests: 10 (CustomerOrdersTest cancellation: 6, Przelewy24WebhookTest: 4)

## SQLite Gotchas
- No ENUM column type — use string (SQLite accepts enums as varchar, no crash)
- No lockForUpdate() — skip concurrency tests or mock
- JSON column queries differ from MySQL
- Timestamp precision differs
- `ALTER TABLE ... ADD CONSTRAINT ... CHECK` NOT supported — guard with `if (DB::getDriverName() !== 'sqlite')` in migrations
- [feedback_sqlite_check_constraint.md](feedback_sqlite_check_constraint.md) — details on this pattern

## Models Requiring HasFactory
- Order model was missing `HasFactory` — added 2026-03-26. Always check new models for this trait before writing factories.

## SQLite lockForUpdate() Behaviour
- SQLite silently ignores `lockForUpdate()` inside DB transactions — does not throw
- CartService::convertToOrder() calls it — tests work fine without mocking

## PHP 8.3 Carbon::diffInDays() Type Change
- `Carbon::diffInDays($other)` now returns `float` in PHP 8.3
- Passing result directly to `int $param` causes TypeError in strict-typed services
- Fix: cast with `(int) $start->diffInDays($end)`
- Also: argument order matters — `$end->diffInDays($start)` returns negative when end > start; always use `$start->diffInDays($end)`
- Bug found and fixed in CartService.php line 54 during Sprint 2 testing

## Przelewy24Service Testing Pattern
- `client()` must be `protected` (not `private`) to allow test subclassing
- Use anonymous subclass pattern to override `client()`:
  `new class($p24Mock) extends Przelewy24Service { protected function client(): Przelewy24 { return $this->mockedClient; } }`
- `TransactionStatusNotification` can be built from real payload array + real `Config` object
- `isSignValid()` can be tested with `Przelewy24::createSignature()` — no real API needed
- `Przelewy24Exception` extends GuzzleHttp's `BadResponseException` (needs PSR-7 objects)
  Use `$this->createMock(\Psr\Http\Message\RequestInterface::class)` + `ResponseInterface::class` to build it
- Service file: app/Services/Payment/Przelewy24Service.php

## Service Bug Fixes Found During Sprint 2 Testing
- CartService.php:54 — `$end->diffInDays($start)` was wrong direction AND missing `(int)` cast
  Fixed to: `(int) $start->diffInDays($end) + 1`

## Artisan Command Testing Pattern
- Use `$this->artisan('command:name')->assertSuccessful()` for exit code check
- Use `->expectsOutputToContain('text')` to assert $this->info() output
- Commands needing DB still use RefreshDatabase (they're in Unit/ for isolation, not for no-DB)
- `updated_at` manipulation: use `DB::table()->where()->update()` AFTER factory creation — Eloquent model events re-touch the timestamp on save

## Deprecated Route 410 Testing Pattern
- 410 routes use `{service:slug}` route model binding — BelongsToOrganization global scope will 404 without tenant
- Must stub ResolveTenant (same `actingAsTenant()` pattern as AddToCartTest/CheckoutFlowTest)
- API availability endpoints (/api/rental/...) share the same route model binding — stub is needed there too
- Use `assertNotEquals(410, ...)` + `assertNotEquals(500, ...)` for "must stay alive" API tests

## State Machine Testing Pattern
- Use `$order->status()->transitionTo('target')` — calls State::transitionTo()
- Forbidden transitions throw `Asantibanez\LaravelEloquentStateMachines\Exceptions\TransitionNotAllowedException`
- `$order->status()->canBe('target')` returns bool without side effects (safe for assertion)
- Factory states bypass the state machine by setting status directly in DB — test `transitionTo()` from those states

## WebhookController Testing Pattern (Przelewy24)
- Route: `POST /webhooks/przelewy24` → name `webhooks.p24`; uses ResolveTenant middleware — must stub it with `actingAsTenant()`
- Controller ALWAYS returns `response('OK', 200)` — it catches ALL exceptions, so there is no 4xx from the controller itself
- Mock `Przelewy24Service` via `$this->mock(Przelewy24Service::class, fn($mock) => ...)` — Laravel's built-in mock() binds to the container
- For "marks order as paid" test: mock `handleWebhook()` with `andReturnUsing()` that calls the actual state transition on the order — this tests the integration path without P24 API
- Idempotency test: mock returns null (no-op) to simulate "already paid" guard in service
- GET on a POST-only route returns 405 — use `assertContains($response->status(), [404, 405])` for safety
- ThrottleRequests must be disabled in setUp() for webhook tests (same as other controller tests)
