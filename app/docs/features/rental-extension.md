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
| `app/Models/OrderItem.php` | Added `extensionRequests(): HasMany` |
| `app/Models/Order.php` | Added `extensionRequests(): HasManyThrough` |
| `app/Support/Settings/SettingsManager.php` | Added `isRentalExtensionEnabled()` |
| `app/Enums/TemplateKey.php` | Added RENTAL_EXTENSION_REQUESTED/APPROVED/REJECTED |
| `app/Filament/Pages/SystemSettings.php` | Added Wypożyczalnia tab + rentals settings group |
| `app/Http/Controllers/OrderController.php` | Pass $rentalExtensionEnabled + eager load extensionRequests |
| `routes/web.php` | Added extension check (GET) + store (POST) routes |
| `resources/views/orders/show.blade.php` | Extension section per OrderItem |

## Flow

1. Setting enabled in admin panel (System Settings → Wypożyczalnia)
2. Customer on `/moje-zamowienia/{order}` sees per-item extension form (for paid/confirmed/in_progress orders)
3. Customer picks new end date → AJAX checks availability (`GET /api/zamowienia/...`)
4. Customer submits → `POST /moje-zamowienia/{order}/pozycje/{orderItem}/przedluz`
5. `RentalExtensionService::requestExtension()` creates pending request + notifies admin
6. Admin reviews at `/admin/extension-requests` (badge shows pending count)
7. Admin approves → item.end_date updated, order totals incremented, customer notified
8. Admin rejects → rejection_reason stored, customer notified

## Race condition handling

`approve()` uses `lockForUpdate()` on request + item rows. On approval, competing pending requests for same item are auto-rejected.

## Availability math

Extension window starts at `end_date + 1 day`. The current OrderItem does NOT overlap this window (its end_date < extensionStart), so no self-exclusion logic is needed.

## Setting toggle safety

Toggling the setting mid-flight:
- Disabling hides the UI but does NOT affect already-approved extensions (already baked into order_item.end_date)
- Pending requests remain processable in admin panel (queue agnostic)
- No data corruption risk
