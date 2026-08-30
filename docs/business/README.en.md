# Business Flows — Overview

This section documents Registro's customer-facing and admin-facing business
processes: what a customer experiences end-to-end, and what a tenant
admin/staff member does day to day to run their business on the platform.

Unlike the Development section (which documents *how the
system is built*), these pages document *what happens* — the customer
journey, the sales funnel, and the operational processes an admin executes.
Real Mermaid diagrams are included throughout with route names, controller
methods, and status values, so this section also doubles as an engineering
reference for anyone building or debugging these flows.

Ported 2026-07 from a deprecated repo-root `docs/` tree (`architecture/user-journeys.md`,
`features/{checkout-order-flow,payment-flow,booking-wizard-flow,rental-flow,auth-onboarding-flow,admin-panel-flows}.md`)
and corrected against current code (`app/StateMachines/OrderStatusStateMachine.php`,
`app/Filament/Resources/OrderResource.php`, `app/Http/Controllers/OrderController.php`,
`app/Http/Controllers/AppointmentController.php`) — see each page for what changed during the port.

## Pages

| Page | Covers |
|------|--------|
| [Customer Journey — Booking](customer-journey-booking.md) | `ServiceType::TimeSlot` appointment booking wizard (4–5 steps) |
| [Customer Journey — Rental](customer-journey-rental.md) | `ServiceType::ItemRental` cart → checkout → payment → pickup → return |
| [Customer Journey — Inquiry](customer-journey-inquiry.md) | `price_on_request` services — no self-serve price, inquiry modal instead |
| [Cancellation](customer-journey-cancellation.md) | Customer-initiated and admin-initiated cancellation, for both appointments and orders |
| [Customer Journey — Choosing a Branch](customer-journey-locations.en.md) | **Partially implemented (phases 0-2).** Branches with address, hours and gallery are live on the website. Branch selection and per-site availability are on hold |
| [Staff Journey — Working at a Branch](staff-journey-locations.en.md) | **Planned (phase 8, on hold).** Branch assignment, handover and return, moving equipment between sites |
| [Guest vs Authenticated](guest-vs-authenticated.md) | What a visitor can do before vs after registering — there is no guest checkout |
| [Purchase Process (funnel view)](purchase-process.md) | End-to-end sales funnel: catalogue → product page → checkout → P24 → confirmation |
| [Onboarding & Registration](onboarding-registration.md) | Business registration wizard (new tenant), customer registration, roles, trial/subscription |
| [Admin Business Overview](admin-business-overview.md) | What an admin/staff user does day to day: confirm, cancel, refund, deposit management |

For the raw state-transition reference tables across all entities (orders,
appointments, rentals, carts, payments), see
[Development → Status Machines](../architecture/status-machines.md).
