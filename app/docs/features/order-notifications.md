# Order Email Notifications

**Implemented:** 2026-03-29
**Branch:** `feature/order-notifications`

---

## Overview

Transactional email notifications for the full Cart → Order lifecycle.
Follows the existing `AppointmentCreatedNotification` pattern exactly:
`EmailServiceChannel` + DB templates + `ShouldBeUnique` + `'emails'` queue.

---

## Notification Matrix

| Trigger | Recipient | Template Key | Notification Class |
|---------|-----------|-------------|-------------------|
| Payment confirmed (P24 webhook) | Customer | `order-paid` | `OrderPaidNotification('customer')` |
| Payment confirmed (P24 webhook) | Org owner (admin) | `admin-new-order` | `OrderPaidNotification('admin')` |
| Admin confirms order (`paid → confirmed`) | Customer | `order-confirmed` | `OrderConfirmedNotification` |
| Order cancelled (`* → cancelled`) | Customer | `order-cancelled` | `OrderCancelledNotification` |

---

## Event Flow

```
Przelewy24Service::handleWebhook()
  └─ $order->status()->transitionTo('paid')
  └─ event(new OrderPaid($order))            ← dispatched directly

OrderStatusStateMachine::afterTransitionHooks()
  └─ 'confirmed' → event(new OrderConfirmed($model))
  └─ 'cancelled' → event(new OrderCancelled($model))

AppServiceProvider::registerEventListeners()
  └─ OrderPaid    → user->notify(OrderPaidNotification('customer'))
                 → org->owner->notify(OrderPaidNotification('admin'))
  └─ OrderConfirmed → user->notify(OrderConfirmedNotification)
  └─ OrderCancelled → user->notify(OrderCancelledNotification)
```

---

## Files

**New:**
- `app/Events/OrderPaid.php`
- `app/Events/OrderConfirmed.php`
- `app/Events/OrderCancelled.php`
- `app/Notifications/OrderPaidNotification.php`
- `app/Notifications/OrderConfirmedNotification.php`
- `app/Notifications/OrderCancelledNotification.php`

**Modified:**
- `app/Enums/TemplateKey.php` — 4 new cases: `ORDER_PAID`, `ORDER_CONFIRMED`, `ORDER_CANCELLED`, `ADMIN_NEW_ORDER`
- `app/Providers/AppServiceProvider.php` — 3 new event listeners in `registerEventListeners()`
- `app/Services/Payment/Przelewy24Service.php` — `event(new OrderPaid($order))` after `transitionTo('paid')`
- `app/StateMachines/OrderStatusStateMachine.php` — `afterTransitionHooks()` for `confirmed` and `cancelled`
- `database/seeders/EmailTemplateSeeder.php` — 8 new templates (4 keys × 2 languages)

---

## Template Variables

| Key | Variables |
|-----|-----------|
| `order-paid` | `customer_name`, `order_number`, `total_amount`, `orders_url`, `app_name` |
| `order-confirmed` | `customer_name`, `order_number`, `orders_url`, `app_name` |
| `order-cancelled` | `customer_name`, `order_number`, `reason`, `orders_url`, `app_name` |
| `admin-new-order` | `customer_name`, `order_number`, `total_amount`, `admin_url`, `app_name` |

---

## Design Decisions

- **OrderPaid dispatched in service, not state machine** — the P24 webhook also needs to `update(['paid_at' => now()])` after transition; keeping both calls together in the service is simpler and avoids the `paid` hook firing for any future programmatic `transitionTo('paid')` in tests.
- **`afterTransitionHooks()` for confirmed/cancelled** — these transitions always come from admin UI actions, so hooking the state machine is the right place; it covers any future Artisan commands or API calls that trigger the same transition.
- **Null-safe user check** — orders in theory always have a `user_id` (checkout requires auth), but the listener logs a warning and skips rather than crashing if the relation is missing.
- **Email only, no SMS** — rental orders are email-only per project spec.
- **`total_amount` formatted as `number_format(..., 2, ',', ' ')`** — Polish locale formatting.
