# Admin Business Overview

**For business owners/staff:** this page is a day-to-day operational guide —
not a Filament architecture doc. It covers the actions an admin or staff
member actually clicks: confirming an order, marking an item picked up,
approving/cancelling an appointment, handling a security deposit, and
managing customer refunds. For the underlying technical detail of each
model's status machine, see [Development → Status Machines](../architecture/status-machines.md).

## Panels

Two Filament panels exist. `/platform` is super-admin only and manages
cross-tenant concerns (organizations, SaaS billing, platform-wide
statistics). `/admin` is the per-tenant panel where day-to-day business
operations happen — access scoped to the authenticated organization.

Which resources an admin/staff user sees in `/admin` depends on the tenant's
**modules** (`Organization::hasModule($key)` — invisible in nav + 403 on
routes if the module is off for that tenant):

| Module | Resources gated | Default on for |
|--------|-----------------|-----------------|
| `rentals` | Orders, Rentals | Equipment-rental industry; `booking_type: item_rental` or `both` |
| `bookings` | Appointments | Auto-detailing, general-services; `booking_type: time_slot` or `both` |
| `customers` | Customers | Explicit override or industry default |
| `services` | Services | All industries |
| *(none — core)* | Dashboard, System Settings, Maintenance Settings | Always visible |

`Statistics` and `AnalyticsOverview` bypass module gating entirely and rely
only on role checks (admin + super-admin).

## Order processing (the `rentals` module)

This is the core "approve and fulfil a rental" workflow. `OrderResource` at
`/admin`: orders **cannot** be created manually (`canCreate: false` — they
only originate from public checkout) or deleted, and cannot be edited once
terminal (`completed`, `cancelled`, `refunded`).

| Business action | Available when order status is | Result | Customer notified? |
|---|---|---|---|
| Confirm order | `paid` | → `confirmed` | Yes — `OrderConfirmedNotification` |
| Mark in progress | `confirmed` | → `in_progress` | No |
| Mark completed (item returned) | `in_progress` | → `completed` | No |
| Cancel order | `pending_payment`, `paid`, or `confirmed` | → `cancelled` (requires a reason) | Yes — `OrderCancelledNotification` |
| Process refund | `completed` | → `refunded` (terminal) | No automated notification; manual admin process, no P24 refund API call |

The Filament cancel button intentionally does **not** show for `in_progress`
— that state's cancellation path is reserved for exceptional
forced-offboarding scenarios (e.g. closing a tenant account) and is invoked
programmatically via `OrderService::cancel()` rather than through the
standard row action. See [Cancellation](customer-journey-cancellation.md) for
the full breakdown of who can cancel from where.

### Corrected order state diagram (business view)

```mermaid
flowchart TD
    START([Public checkout]) --> PP[pending_payment]

    PP -->|P24 webhook| PAID[paid]
    PP -->|Admin: Anuluj| CANCELLED[cancelled]

    PAID -->|Admin: Potwierdź| CONF[confirmed]
    PAID -->|Admin: Anuluj| CANCELLED

    CONF -->|Admin: Oznacz w toku| IP[in_progress]
    CONF -->|Admin: Anuluj| CANCELLED

    IP -->|Admin: Zakończ| COMP[completed]
    IP -.->|Forced offboarding — exceptional,\nnot exposed in standard UI| CANCELLED
    COMP -.->|manual| REF[refunded]

    CANCELLED -.->|Reconciliation ONLY — requires existing\nPayment(status=success) row| PAID

    subgraph DEPOSIT ["Deposit track — active from 'paid' onward"]
        direction LR
        DP[pending] -->|collect_deposit| DC[collected]
        DC -->|return_deposit| DR[returned]
        DC -->|"forfeit_deposit (requires reason)"| DF[forfeited]
    end

    PAID -.->|deposit lifecycle starts| DP

    PAID -.->|fires OrderPaid event| NP["OrderPaidNotification\nCustomer + Org Owner"]
    CONF -.->|fires OrderConfirmed event| NC["OrderConfirmedNotification\nCustomer"]
    CANCELLED -.->|fires OrderCancelled event| NK["OrderCancelledNotification\nCustomer"]

    style PP fill:#64748b,color:#fff
    style PAID fill:#2563eb,color:#fff
    style CONF fill:#7c3aed,color:#fff
    style IP fill:#d97706,color:#fff
    style COMP fill:#16a34a,color:#fff
    style CANCELLED fill:#dc2626,color:#fff
    style REF fill:#ea580c,color:#fff
```

This corrects the two transitions the original admin-panel diagram was
missing (confirmed against `app/StateMachines/OrderStatusStateMachine.php`):
the dotted `in_progress → cancelled` forced-offboarding path, and the dotted
`cancelled → paid` reconciliation path (guarded — only allowed when a
verified `Payment` record already exists).

## Deposit (kaucja) management

Kept off the invoice total deliberately — it's a returnable security deposit,
never charged through Przelewy24, collected physically at pickup.

| Business action | Requires | Result |
|---|---|---|
| "Pobrano kaucję" | `deposit_status = pending` | → `collected`, timestamp recorded |
| "Zwrócono kaucję" | `deposit_status = collected` | → `returned`, timestamp recorded |
| "Kaucja przepadła" | `deposit_status = collected` | → `forfeited` (irreversible) |
| Partial return | `deposit_status = collected` | → `partial_return` |

## Appointment processing (the `bookings` module)

`AppointmentResource`: admin/staff can view; only admin can edit status.
Inline customer creation is supported directly from the appointment form.

| Business action | Effect | Customer notified? |
|---|---|---|
| Confirm appointment | `pending → confirmed` | SMS only (`APPOINTMENT_CONFIRMED`) — **no email exists for this transition** |
| Cancel appointment | any active status → `cancelled`, no policy restriction for admin | Email + SMS (`AppointmentCancelledNotification`) |
| Mark completed | any active status → `completed`, sets `completed_at` | No |
| Reschedule (change date/time field) | fires `AppointmentRescheduled` | Email + SMS — **subject to a confirmed runtime bug, see below** |

`mutateFormDataBeforeSave` validates on every save: if the staff member
lacks the `staff` role, or if the new date/time/staff conflicts with an
existing booking, the save is blocked with a persistent danger notification
(`$this->halt()`) — the form stays open with the invalid data visible so the
admin can correct it.

**Known bug (confirmed present, not fixed here):** `Appointment::booted()`
fires `event(new AppointmentRescheduled($appointment))` on reschedule, but
the event constructor requires `(Appointment, Carbon $oldDate, Carbon $newDate)`
— the two `Carbon` arguments are missing. This throws a `TypeError` at
runtime every time an admin reschedules an appointment via Filament. See
[Customer Journey — Booking](customer-journey-booking.md) (see "Known bug —
reschedule TypeError" section) for the full note.

## Rental processing (the `rentals` module, physical item lifecycle)

`RentalResource`: admin/super-admin only.

| Business action | Available when | Result |
|---|---|---|
| Confirm | `Held` or `Pending` | → `Confirmed` |
| Mark picked up | `Confirmed` | → `Active` |
| Mark returned | `Active` | → `Returned` |
| Cancel | not already `Returned`/`Cancelled`/`Expired` | Requires a reason → `Cancelled` |

All rental state changes are bare `$record->update()` calls — **no events
fire and no customer notifications are sent for any rental admin action.**
Deposit handling for these rows happens through the linked `Order` (see
above), not the `Rental` record itself.

## Customer management (the `customers` module)

`CustomerResource`: a user appears in this list only if they have at least
one appointment, rental, or order under the current tenant. Admin can edit
name/contact/limits (`max_vehicles`, `max_addresses`, 1–10 each) but the
`customer` role itself is locked — there's no UI path to promote a customer
to staff/admin from this screen.

## Notification summary (who gets emailed on which admin action)

| Admin action | Recipient | Notification |
|---|---|---|
| Order → confirmed | Customer | `OrderConfirmedNotification` |
| Order → cancelled | Customer | `OrderCancelledNotification` |
| Order → in_progress / completed / any deposit action | Nobody | — |
| Appointment → confirmed | Customer | SMS only |
| Appointment → cancelled | Customer | Email + SMS |
| Appointment reschedule | Customer | Email + SMS (bug above) |
| Any rental status change | Nobody | — |

## Statistics & analytics (read-only, admin-facing)

`Statistics` (`/admin/statystyki`) — revenue/count breakdown across
orders/appointments/rentals, top services by revenue, CSV/PDF export.
`AnalyticsOverview` (`/admin/analityka`) — funnel conversion, cart
abandonment, traffic sources, session quality. Both bypass module gating and
are read-only except for the two export actions on `Statistics`.

## Key files

`app/Filament/Resources/OrderResource.php`, `app/Filament/Resources/AppointmentResource.php`,
`app/Filament/Resources/RentalResource.php`, `app/Filament/Resources/CustomerResource.php`,
`app/Services/Order/OrderService.php`, `app/Support/BaseResource.php` (module gating).
