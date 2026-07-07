# Rental Extension Feature

## Overview

Customers can request to extend an active rental order item. Admin must approve each request. Payment is settled at return time.

## Feature flag

`rentals.rental_extension_enabled` (default: false) — toggle in Admin → System Settings → Wypożyczalnia tab.

## Architecture

### New files

| File | Purpose |
|------|---------|
| `database/migrations/2026_06_11_100001_create_order_item_extension_requests_table.php` | New table |
| `app/Enums/ExtensionRequestStatus.php` | Pending / Approved / Rejected |
| `app/Models/OrderItemExtensionRequest.php` | Model with BelongsToOrganization |
| `app/Services/RentalExtensionService.php` | canRequestExtension, requestExtension, approve, reject |
| `app/Filament/Resources/ExtensionRequestResource.php` | Admin inbox (list-only, badge for pending count) |
| `app/Http/Controllers/RentalExtensionController.php` | AJAX check + form submit |
| `app/Notifications/RentalExtensionRequestedNotification.php` | ShouldQueue+ShouldBeUnique to admin |
| `app/Notifications/RentalExtensionApprovedNotification.php` | ShouldQueue to customer |
| `app/Notifications/RentalExtensionRejectedNotification.php` | ShouldQueue to customer |

### Modified files

| File | Change |
|------|--------|
| `app/Models/OrderItem.php` | Added `extensionRequests(): HasMany` + adopted `Auditable` (end_date/rental_days/total_price) |
| `app/Models/Order.php` | Added `extensionRequests(): HasManyThrough` + new `applyFinancialAdjustment()` escape hatch (see below) |
| `app/Support/Settings/SettingsManager.php` | Added `isRentalExtensionEnabled()` |
| `app/Enums/TemplateKey.php` | Added RENTAL_EXTENSION_REQUESTED/APPROVED/REJECTED |
| `app/Filament/Pages/SystemSettings.php` | Added Wypożyczalnia tab + rentals settings group |
| `app/Http/Controllers/OrderController.php` | Pass $rentalExtensionEnabled + eager load extensionRequests |
| `routes/web.php` | Added extension check (GET) + store (POST) routes, named throttle buckets |
| `resources/views/orders/show.blade.php` | Extension section per OrderItem |

## Flow

1. Setting enabled in admin panel (System Settings → Wypożyczalnia)
2. Customer on `/moje-zamowienia/{order}` sees per-item extension form (for paid/confirmed/in_progress orders)
3. Customer picks new end date → AJAX checks availability (`GET /api/zamowienia/...`)
4. Customer submits → `POST /moje-zamowienia/{order}/pozycje/{orderItem}/przedluz`
5. `RentalExtensionService::requestExtension()` creates pending request + notifies admin
6. Admin reviews at `/admin/extension-requests` (badge shows pending count)
7. Admin approves → item.end_date updated, order totals adjusted via `Order::applyFinancialAdjustment()`, customer notified
8. Admin rejects → rejection_reason stored, customer notified

## Race condition handling (hardened 2026-07-06)

`requestExtension()` and `approve()` both lock the `Service` row (`Service::lockForUpdate()`) AND re-run the availability count as a locking read (`getAvailableQuantity(..., forUpdate: true)`) inside the same transaction — matching `RentalAvailabilityService::createHold()`'s established pattern. Locking the `Service` row alone is **not** sufficient: under MySQL's default REPEATABLE READ, a transaction queued on that lock can still resolve a plain (non-locking) availability read against a stale snapshot from before the winning transaction committed, allowing both to "see" capacity and both succeed (oversell). Forcing the `rentals`/`order_items` count queries themselves to be locking reads (`forUpdate: true`) is what actually closes the race — see `RentalAvailabilityService::getAvailableQuantity()`'s docblock for the full mechanism. Regression coverage: `RentalExtensionServiceTest::test_approve_rejects_when_a_competing_reservation_commits_after_the_request_was_created()`.

On approval, competing pending requests for the same item are auto-rejected.

## Order financial mutation — `applyFinancialAdjustment()`

`Order::total_amount`/`subtotal` are normally immutable (guarded in `Order::booted()`). `approve()` needs to add the extension's `additional_amount` to both — it does this through `Order::applyFinancialAdjustment(['subtotal' => $amount, 'total_amount' => $amount], 'rental_extension_approved')`, a small, reusable, first-class API on `Order` (not rental-extension-specific):

- Sets a transient `$allowFinancialAdjustment = true` flag (mirrors `Organization::$forceLifecycleTransition`)
- Applies each delta additively and calls `save()` — **not** `saveQuietly()` — so `Auditable::bootAuditable()` still logs the change (`subtotal`/`total_amount` are both in `Order::$auditInclude`)
- A `static::saved()` listener resets the flag immediately after every save (including no-op saves), so it can never leak into an unrelated later save on the same model instance

**Lost-update fix (code review, 2026-07-06):** `approve()` re-fetches the `Order` with `Order::where('id', ...)->lockForUpdate()->first()` before calling `applyFinancialAdjustment()`, instead of reusing the lazy-loaded `$extensionRequest->order` — matches the row-lock pattern already used in `Przelewy24Service`. Without this, two approvals for different items on the same multi-item order could both read the same starting `subtotal`/`total_amount` and the second save would silently overwrite the first's increment. Coverage: `RentalExtensionServiceTest::test_approve_accumulates_totals_correctly_across_two_items_on_the_same_order()` — note this test proves the accumulation logic is correct but, per SQLite's `compileLock()` being a no-op, cannot itself prove the MySQL-level lock closes concurrent-connection interleaving; that guarantee rests on the same `lockForUpdate()` precedent as `Przelewy24Service`, unverifiable by this test suite by construction.

**Reject/approve error-handling parity:** the Filament `approve` action catches both `RentalUnavailableException` and `\RuntimeException` (the latter thrown when a request is no longer Pending — e.g. two admins acting on the same request) — mirrors the `reject` action's existing catch, so a TOCTOU double-action surfaces as a clean notification instead of an uncaught 500.

## Availability math

Extension window starts at `end_date + 1 day`. The current OrderItem does NOT overlap this window (its end_date < extensionStart), so no self-exclusion logic is needed.

## Setting toggle safety

Toggling the setting mid-flight:
- Disabling hides the UI but does NOT affect already-approved extensions (already baked into order_item.end_date)
- Pending requests remain processable in admin panel (queue agnostic)
- No data corruption risk

## Demo data (unrelated cluster, ported alongside)

`app/Actions/Demo/*`, `app/Support/Demo/AnalyticsDemoData.php`, `config/demo.php`, `app/Console/Commands/SeedDemoDataCommand.php` — a `demo:seed` CLI command for seeding realistic analytics sample data per tenant. Unrelated to rental extension; ported from the same original commit for convenience, not because it belongs to this feature.
