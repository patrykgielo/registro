# User Journeys — Registro

## Journey Map Overview

```mermaid
flowchart TD
    ENTRY([Public entry — tenant subdomain])

    ENTRY --> BROWSE_R[Browse catalogue\n/wypozyczalnia]
    ENTRY --> BROWSE_S[Browse services\n/uslugi]
    ENTRY --> LOGIN[/login]
    ENTRY --> REG_C[/customer/register]

    BROWSE_R --> CAT[Category page\n/wypozyczalnia/kategoria]
    CAT --> DETAIL_R[Item detail\n/uslugi/slug]
    BROWSE_S --> DETAIL_S[Service detail\n/uslugi/slug]

    DETAIL_R --> |price_on_request| INQUIRY[Inquiry modal\nJourney 3]
    DETAIL_S --> |price_on_request| INQUIRY
    DETAIL_R --> |normal price| ADD_CART[Add to cart]
    DETAIL_S --> |booking service| BOOK[Booking wizard\nJourney 2]

    ADD_CART --> |not logged in| LOGIN
    BOOK --> |not logged in| LOGIN
    LOGIN --> ADD_CART
    LOGIN --> BOOK

    ADD_CART --> CART[Cart /koszyk\nJourney 1]
    CART --> CHECKOUT[Checkout form\n/koszyk/zamowienie]
    CHECKOUT --> P24[Przelewy24 gateway]
    P24 --> RETURN[Return page\n/koszyk/powrot]
    P24 -.->|server-side webhook| PAID[Order paid]
    PAID --> EMAIL_PAID[OrderPaidNotification → customer + admin]

    BOOK --> WIZARD[4–5 step wizard\nJourney 2]
    WIZARD --> CONFIRM_APT[Appointment confirmed]
    CONFIRM_APT --> EMAIL_APT[AppointmentCreatedNotification]

    INQUIRY --> EMAIL_INQ[InquiryNotification → admin]

    REG_C --> ACCOUNT[Customer account\nJourney 4]
    ACCOUNT --> MY_APT[/my-appointments]
    ACCOUNT --> MY_ORD[/moje-zamowienia]
    ACCOUNT --> PROFILE[/moje-konto/*]
```

---

## Journey 1: Equipment Rental (Wypożyczalnia)

The rental journey is split into a **public browse phase** (no auth) and a **purchase phase** (auth required).

### Phase A — Public Browse

| Step | Route | Auth | Notes |
|------|-------|------|-------|
| Landing | `GET /wypozyczalnia` | No | Category grid + up to 6 featured items |
| Category | `GET /wypozyczalnia/{category:slug}` | No | Sidebar nav (sticky desktop / pills mobile), item grid |
| Item detail | `GET /uslugi/{service:slug}` | No | 3-column layout: gallery + specs + sticky pricing sidebar |
| Availability calendar | `GET /api/rental/{service:slug}/kalendarz` | No | Alpine.js month-by-month; blocked dates returned as JSON |
| Date-range check | `GET /api/rental/{service:slug}/dostepnosc` | No | Returns `available: bool` for selected start/end |

Both API routes are throttled (`60/min`) via `ResolveTenant` middleware group.

### Phase B — Cart & Checkout (auth required)

> Middleware stack: `ResolveTenant + auth + CheckRentalEnabled`

```
Item detail (date selected)
  → POST /koszyk/dodaj          (cart.add)          — adds item + date range
  → GET  /koszyk                (cart.show)          — review items, update qty, re-check availability
  → GET  /koszyk/zamowienie     (checkout.show)      — single-page checkout form (see below)
  → POST /koszyk/zamowienie     (checkout.submit)    — throttle: 6/min
  → [P24 gateway]
  → GET  /koszyk/powrot         (checkout.return)    — return page (pending webhook)
  ← POST /webhooks/przelewy24                        — server-to-server; order marked paid
```

### Checkout Form (single-page, Alpine.js sections)

The checkout is **not** a wizard — it is one page with dynamic sections:

1. **Customer type** — B2C (PESEL + address) or B2B (NIP + REGON + KRS + signatory + pickup person)
2. **Personal / company data** — pre-filled from user profile
3. **Delivery address** — street, building, city, postal code
4. **Legal consents** — Terms, RODO, withdrawal right (all configurable per tenant via `SettingsManager`)
5. **Kaucja note** — shown when `deposit_amount > 0`; deposit is physically collected at pickup, never via P24

On submit (`checkout.submit`):
- `CartService::convertToOrder()` creates order with `status = pending_payment`
- `Przelewy24Service::registerTransaction()` gets gateway URL
- Analytics event `checkout.submitted` fired
- Customer redirected to P24

### Payment & Order lifecycle

See `app/docs/features/payment-flow.md` for the full P24 sequence diagram, webhook verification, and kaucja state machine.

Order statuses: `pending_payment → paid → confirmed → in_progress → completed → refunded`

Cancellation paths: TTL expiry (every 5 min) or admin cancel (from `pending_payment` or `paid`).

---

## Journey 2: Service Booking (Wizyta/Usługa)

> Middleware: `auth + ResolveTenant + CheckBookingEnabled`

The booking wizard has **4 steps** (base) or **5 steps** when `TenantFeature::active('vehicles') || TenantFeature::active('mobile_service')` is true.

### Wizard steps

| # | Name | Route | Key action |
|---|------|-------|------------|
| 1 | Service selection | `GET /booking/step/1` | Lists active services ordered by `sort_order`; auto-skips if arriving from `/services/{slug}/book` |
| 2 | Date & time | `GET /booking/step/2` | Calendar picker + slot grid (`/booking/available-slots`); unavailable dates via `/booking/unavailable-dates` |
| 2b | Vehicle / location *(conditional)* | injected between steps 2 and 3 | Vehicle lookup via API (`/api/car-brands`, `/api/car-models`); location + service-area check |
| 3 | Contact details | `GET /booking/step/3` | Pre-filled from profile |
| 4 | Review & confirm | `GET /booking/step/4` | Summary; submit via `POST /booking/confirm` (throttled) |

Session key `booking.*` persists wizard state across requests. Progress can be saved (`POST /booking/save-progress`) and restored (`GET /booking/restore-progress`).

### Entry points

| Entry | Route | Effect |
|-------|-------|--------|
| Direct URL | `GET /booking/step/1` | Full wizard from step 1 |
| Service card | `GET /services/{service}/book` | Injects `booking.service_id` into session → auto-skips to step 2 |
| Change service | `GET /booking/change-service` | Resets service_id, goes back to step 1 |

### Confirmation

After `POST /booking/confirm`:
- Appointment record created (`status = pending` or `confirmed` per tenant config)
- `GET /booking/confirmation` — confirmation page
- `GET /booking/ical/{appointment}` — iCal file download available
- `AppointmentCreatedNotification` queued → customer email

### Service-area waitlist (conditional)

When `TenantFeature::active('mobile_service')` is on and the customer's location falls outside the configured service area:
- `POST /api/service-area/waitlist` — adds customer to waitlist
- On area expansion: `ServiceAreaAvailableNotification` queued → customer email

---

## Journey 3: Price-on-Request Inquiry

Applies to any `Service` with `price_on_request = true`.

```
Service detail page (/uslugi/{service:slug})
  → Price hidden; "Zapytaj o cenę" button shown
  → Click → Alpine.js modal opens
  → POST /uslugi/{service:slug}/zapytaj    (throttle: 5/min, ResolveTenant middleware)
  → InquiryNotification → tenant owner email (via standard 'mail' channel)
  → Modal confirms submission
```

Route: `service.inquiry`, controller: `ServiceInquiryController::store`.

`InquiryNotification` is `ShouldQueue + ShouldBeUnique` (dedup by service + notifiable), queue: `emails`.

No follow-up automation — the tenant owner handles the inquiry manually outside the system.

---

## Journey 4: Customer Account

### Registration

| Scenario | Route | Notes |
|----------|-------|-------|
| Customer self-register | `GET /customer/register` | Tenant-scoped; redirects to business register if on root domain |
| Admin-created account | — | Admin creates user in Filament panel; `AdminCreatedUserNotification` sent with password-setup link |
| Password setup (admin flow) | `GET /password/setup/{token}` | One-time link; `SetPasswordController` |

On registration: `UserRegistered` event → `AssignCustomerRole` listener assigns `customer` role.

### Profile (`/moje-konto/*`)

| Sub-page | Route name | Contents |
|----------|-----------|---------|
| Dashboard | `profile.index` | Overview of account |
| Personal data | `profile.personal` | first_name, last_name, phone, PESEL, address; `PATCH /moje-konto/dane-osobowe` |
| Email change | — | `POST /moje-konto/email/zmien` → confirmation link → `GET /moje-konto/email/potwierdz/{token}` |
| Password | `profile.security` | `PATCH /moje-konto/haslo` |
| Vehicle | `profile.vehicle` | Add/edit/delete vehicles; pre-fills booking wizard |
| Address | `profile.address` | Saved addresses; pre-fills checkout |
| Notifications | `profile.notifications` | Email/SMS opt-in preferences; `PATCH /moje-konto/powiadomienia/zapisz` |
| Data export | `profile.eksport-danych` | GDPR; queues `DataExportCompletedNotification` via standard `mail` |
| Account deletion | — | `POST /usun-konto` → confirmation link → `GET /usun-konto/potwierdz/{token}`; cancelable via `POST /usun-konto/anuluj` |

### Order history

`GET /moje-zamowienia` — lists all rental orders for the authenticated user.
`GET /moje-zamowienia/{order}` — order detail.
`POST /moje-zamowienia/{order}/anuluj` — customer self-cancel (limited to `pending_payment` per `OrderCancelled` state machine rules).

On cancel: `OrderCancelledNotification` queued → customer email.

### Appointment history

`GET /my-appointments` — lists all appointments (`AppointmentController::index`).
`POST /appointments/{appointment}/cancel` — customer cancel; `AppointmentCancelledNotification` queued.

---

## Journey 5: Guest vs Authenticated

| Page / action | Guest | Authenticated |
|---------------|-------|---------------|
| Rental catalogue (`/wypozyczalnia/*`) | Full access | Full access |
| Service catalogue (`/uslugi`) | Full access | Full access |
| Item/service detail | Full access | Full access |
| Availability calendar API | Full access | Full access |
| Price-on-request inquiry | Full access (throttled 5/min) | Full access |
| Add to cart | Redirected to `/login` | Allowed |
| Cart & checkout | Redirected to `/login` | Allowed |
| Booking wizard | Redirected to `/login` | Allowed |
| Profile, orders, appointments | Redirected to `/login` | Allowed |

There is **no guest checkout**. Customers must register or log in before adding items to the cart or starting a booking. Registration is instant (email + password) and the customer is immediately redirected to their intended destination.

Password reset: `GET /password/reset` → `POST` → email with link → `GET /password/reset/{token}` → `POST`. Uses `PasswordResetNotification` (`EmailServiceChannel`, `ShouldQueue + ShouldBeUnique`).

---

## Cross-Journey: Notifications Received by Customer

All notifications are `ShouldQueue + ShouldBeUnique` and dispatched on the `emails` queue unless noted.

| Trigger | Notification class | Channel | Timing |
|---------|-------------------|---------|--------|
| Account created (self-register) | `UserRegisteredNotification` | EmailService | Synchronous (via `UserRegistered` event) |
| Account created by admin | `AdminCreatedUserNotification` | EmailService | Queued; includes password-setup link |
| Password reset requested | `PasswordResetNotification` | EmailService | Queued |
| Appointment confirmed | `AppointmentCreatedNotification` | EmailService | Queued after `POST /booking/confirm` |
| Appointment rescheduled (admin) | `AppointmentRescheduledNotification` | EmailService | Queued on admin reschedule action |
| Appointment cancelled | `AppointmentCancelledNotification` | EmailService | Queued after customer or admin cancel |
| Service area now available | `ServiceAreaAvailableNotification` | EmailService | Queued when admin expands service area |
| Order payment confirmed | `OrderPaidNotification` (customer variant) | EmailService | Queued on `OrderPaid` event after P24 webhook verify |
| Order cancelled (TTL or admin) | `OrderCancelledNotification` | EmailService | Queued on `OrderCancelled` event |
| Price inquiry submitted | `InquiryNotification` | Standard `mail` | Queued; sent to tenant owner (not customer) |
| Data export ready | `DataExportCompletedNotification` | Standard `mail` | Queued after export job completes |

`ShouldBeUnique` dedup window is 5 minutes for order notifications. Channel `EmailService` routes through the tenant's configured email provider (`app/Services/Email/EmailService.php` + `EmailServiceChannel`); `mail` channel uses Laravel's default mailer.
