# Guest vs Authenticated

**For customers:** you can browse everything — the full catalogue, product
pages, availability calendars — without an account. As soon as you want to
actually buy or book something, you'll be asked to register or log in first.
There is no guest checkout.

## Access matrix

| Page / action | Guest | Authenticated |
|---------------|-------|----------------|
| Rental catalogue (`/wypozyczalnia/*`) | Full access | Full access |
| Service catalogue (`/uslugi`) | Full access | Full access |
| Item/service detail | Full access | Full access |
| Availability calendar API | Full access | Full access |
| Price-on-request inquiry | Full access (throttled 5/min) | Full access |
| Add to cart | Redirected to `/login` | Allowed |
| Cart & checkout | Redirected to `/login` | Allowed |
| Booking wizard | Redirected to `/login` | Allowed |
| Profile, orders, appointments | Redirected to `/login` | Allowed |

There is **no guest checkout**. Customers must register or log in before
adding items to the cart or starting a booking. Registration is instant
(email + password, no email verification enforced for customers — see
[Onboarding & Registration](onboarding-registration.md)) and the customer is
immediately redirected to their intended destination (the product page or
wizard step they came from).

## Why this matters for the business

- Every paying customer has an account — no anonymous orders to reconcile, no
  guest-checkout abandonment-vs-friction tradeoff to manage.
- Marketing/analytics can track guest browsing behavior (catalogue,
  inquiries) separately from the authenticated purchase funnel — see the
  funnel steps in [Purchase Process](purchase-process.md).
- The inquiry flow (`price_on_request`) is the one purchase-adjacent action
  guests *can* complete without registering — useful for high-value or
  custom-quote items where forcing registration upfront would lose the lead.

## Password reset (any authenticated flow)

`GET /password/reset` → `POST` → email with link → `GET /password/reset/{token}`
→ `POST`. Uses `PasswordResetNotification` (`EmailServiceChannel`, `ShouldQueue`
+ `ShouldBeUnique`).

## Key files

`app/Http/Middleware/Authenticate.php` (redirect-to-login behavior),
`routes/web.php` (`auth` middleware groups on cart/checkout/booking routes),
`app/Http/Controllers/ServiceInquiryController.php` (the one guest-accessible
purchase-adjacent action).
