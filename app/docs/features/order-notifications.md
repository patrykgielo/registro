# Order Email Notifications

**Implemented:** 2026-03-29
**Extended:** 2026-08-12 (`feature/handover-return-emails`) — handover + return
**Extended:** 2026-09-20 (`feature/maile-wlasciciel-i-logo`) — owner notified for offline
(pay-at-pickup) orders too; every order email now goes through a shared branded layout

---

## Overview

Transactional email notifications for the full Cart → Order lifecycle.
Follows the existing `AppointmentCreatedNotification` pattern exactly:
`EmailServiceChannel` + DB templates + `ShouldBeUnique` + `'emails'` queue.

Until 2026-08-12, a customer received exactly one email in the entire rental
lifecycle (payment confirmation) — the two transitions where equipment
physically changes hands (`confirmed → in_progress`, `in_progress →
completed`) fired nothing at all, both for the customer and as a company-side
send record. Handover and return notifications close that gap.

---

## Notification Matrix

| Trigger | Recipient | Template Key | Notification Class |
|---------|-----------|-------------|-------------------|
| Checkout completed, "pay at pickup" chosen | Customer | `order-accepted-offline` | `OrderAcceptedOfflineNotification('customer')` |
| Checkout completed, "pay at pickup" chosen | Org owner (admin) | `admin-new-order` | `OrderAcceptedOfflineNotification('admin')` |
| Payment confirmed (P24 webhook) | Customer | `order-paid` | `OrderPaidNotification('customer')` |
| Payment confirmed (P24 webhook) | Org owner (admin) | `admin-new-order` | `OrderPaidNotification('admin')` |
| Admin confirms order (`paid → confirmed`) | Customer | `order-confirmed` | `OrderConfirmedNotification` |
| Admin hands over equipment (`confirmed → in_progress`, "Wydano klientowi") | Customer | `order-handed-over` | `OrderHandedOverNotification` |
| Admin accepts return (`in_progress → completed`, "Sprzęt zwrócony") | Customer | `order-returned` | `OrderReturnedNotification` |
| Order cancelled (`* → cancelled`) | Customer | `order-cancelled` | `OrderCancelledNotification` |

**No admin copy for handover/return** — unlike `OrderPaid`/`OrderAcceptedOffline`, both
transitions are triggered by the admin themselves through the Filament UI, so there is no
new information reaching them that they didn't already cause. This mirrors
`OrderConfirmed`/`OrderCancelled` (also admin-triggered, customer-only) rather
than `OrderPaid`/`OrderAcceptedOffline` (customer-triggered on the storefront, genuinely
new to the admin — hence the `'admin'` variant on both).

### Owner notification for offline (pay-at-pickup) orders (2026-09-20, ClickUp 123k99cvc55)

Before this, `AppServiceProvider`'s `OrderAcceptedOffline` listener notified the customer
only — the owner had NO email path for a new order placed with `settlement_method =
'offline'`, only for one paid via Przelewy24. Since offline is the only settlement method
live on UAT today, the owner had to watch the panel for every single order.

**Decision: reuse `admin-new-order`, not a new key.** `OrderPaidNotification`'s existing
`'admin'` branch already sends this key for "a new order needs your attention" — that
sentence is equally true regardless of settlement method, and the body itself never
claimed anything about payment status. A `{{payment_note}}` token (new, see below)
distinguishes "Zapłacono online" from "Płatność przy odbiorze (gotówka lub przelew)" so the
owner can tell the two apart without a second template to keep in sync. This is different
from the customer-facing `order-accepted-offline` vs `order-paid` split (`ORDER_ACCEPTED_OFFLINE`
docblock, `OrderAcceptedOfflineNotification.php`) — that split exists because the CUSTOMER
body makes a factual claim ("zostało opłacone") that would be false for an unpaid
reservation. The owner-facing body makes no such claim, so no split is needed there.

`OrderAcceptedOfflineNotification` gained a `$recipientType` constructor param (`'customer'`
default, mirrors `OrderPaidNotification`'s own signature) — the `'admin'` branch sends
`admin-new-order` with `buildRentalVariables($order)` (same items/pickup fields the
customer template already uses) plus `payment_note`. `AppServiceProvider`'s listener now
loads `organization.owner` and notifies it, mirroring `OrderPaid`'s existing pattern exactly.

**`admin-new-order`'s body itself was enriched** (previously: customer name, order number,
total only) to add `{{payment_note}}`, `{{items_list_html}}`/`{{items_list_text}}`,
`{{pickup_address}}`/`{{pickup_phone}}` — both `OrderPaidNotification`'s admin branch and
`OrderAcceptedOfflineNotification`'s now populate all of these, so the owner sees WHAT was
ordered and WHERE it will be picked up, not just that an order exists.
`database/migrations/2026_09_20_100000_enrich_admin_new_order_email_template.php` patches
this onto already-provisioned stacks (exact-value match, same pattern as
`2026_08_14_100000_fix_order_paid_pickup_html_separator.php`) — `EmailTemplateSeeder.php`
was updated in the same change so fresh installs seed the enriched body directly. See
"Existing-tenant provisioning" below for why this migration exists at all.

---

## Event Flow

```
Przelewy24Service::handleWebhook()
  └─ $order->status()->transitionTo('paid')
  └─ event(new OrderPaid($order))            ← dispatched directly

OrderStatusStateMachine::afterTransitionHooks()
  └─ 'confirmed'   → event(new OrderConfirmed($model))
  └─ 'in_progress' → event(new OrderHandedOver($model))
  └─ 'completed'   → [1] if (completed_at === null) $model->update(['completed_at' => now()])
                      [2] event(new OrderReturned($model))
                      — TWO independent callables, deliberately not sharing a guard; see that
                      hook's own comment for why coupling them would silently drop the email
                      the moment anything sets completed_at outside this hook (backfill, import,
                      data migration)
  └─ 'cancelled'   → event(new OrderCancelled($model))

AppServiceProvider::registerEventListeners()
  └─ OrderPaid       → user->notify(OrderPaidNotification('customer'))
                     → org->owner->notify(OrderPaidNotification('admin'))
  └─ OrderConfirmed  → user->notify(OrderConfirmedNotification)
  └─ OrderHandedOver → user->notify(OrderHandedOverNotification)
  └─ OrderReturned   → user->notify(OrderReturnedNotification)
  └─ OrderCancelled  → user->notify(OrderCancelledNotification)
```

---

## Files

**New (2026-03-29):**
- `app/Events/OrderPaid.php`
- `app/Events/OrderConfirmed.php`
- `app/Events/OrderCancelled.php`
- `app/Notifications/OrderPaidNotification.php`
- `app/Notifications/OrderConfirmedNotification.php`
- `app/Notifications/OrderCancelledNotification.php`

**New (2026-08-12):**
- `app/Events/OrderHandedOver.php`
- `app/Events/OrderReturned.php`
- `app/Notifications/OrderHandedOverNotification.php`
- `app/Notifications/OrderReturnedNotification.php`
- `database/migrations/2026_08_12_120000_seed_order_handover_return_email_templates.php` —
  production data migration for the 2 new keys; see "Existing-tenant provisioning" below for why
  this is required in addition to `EmailTemplateSeeder`
- `tests/Feature/Orders/OrderHandoverReturnNotificationTest.php`
- `tests/Feature/Database/OrderHandoverReturnEmailTemplateMigrationTest.php` — pins the migration's
  `up()`/`down()`, that it never touches unrelated rows or a tenant's own override, and that both
  keys resolve from production migrations ALONE (i.e. without `EmailTemplateSeeder`, which every
  other test in this suite already has thanks to `TestReferenceDataSeeder` running once per test
  process via `Tests\TestCase::$seeder`, and which would otherwise mask exactly this class of bug)

**New (2026-09-20):**
- `app/Support/Email/EmailBrandedLayout.php`
- `resources/views/emails/branded-layout.blade.php`
- `database/migrations/2026_09_20_100000_enrich_admin_new_order_email_template.php`
- `tests/Feature/Notifications/OrderAcceptedOfflineAdminNotificationTest.php`
- `tests/Feature/Notifications/OrderEmailBrandedLayoutTest.php`
- `tests/Feature/Database/AdminNewOrderEmailTemplateMigrationTest.php`

**Modified (2026-09-20):**
- `app/Notifications/OrderAcceptedOfflineNotification.php` — `$recipientType` param, `'admin'` branch
- `app/Providers/AppServiceProvider.php` — `OrderAcceptedOffline` listener notifies `organization.owner` too
- `app/Services/Email/EmailService.php` — `sendFromTemplate(..., ?Organization $organization = null)`, wraps via `EmailBrandedLayout`
- `app/Support/Settings/SettingsManager.php` — `emailBrandingFor(?Organization)`
- `app/Models/EmailTemplate.php` — `resolveActive(..., ?Organization $organization = null)`
- `app/Notifications/OrderPaidNotification.php`, `OrderConfirmedNotification.php`,
  `OrderCancelledNotification.php`, `OrderHandedOverNotification.php`, `OrderReturnedNotification.php`,
  `RentalReturnDueSoonNotification.php`, `RentalReturnOverdueNotification.php` — all now pass
  `$order->organization` into `sendFromTemplate()`
- `database/seeders/EmailTemplateSeeder.php` — enriched `admin-new-order` body (both languages)

**Modified (earlier):**
- `app/Enums/TemplateKey.php` — 6 order-lifecycle cases: `ORDER_PAID`, `ORDER_CONFIRMED`,
  `ORDER_CANCELLED`, `ORDER_HANDED_OVER`, `ORDER_RETURNED`, `ADMIN_NEW_ORDER`
- `app/Providers/AppServiceProvider.php` — event listeners in `registerEventListeners()`
- `app/Services/Payment/Przelewy24Service.php` — `event(new OrderPaid($order))` after `transitionTo('paid')`
- `app/StateMachines/OrderStatusStateMachine.php` — `afterTransitionHooks()` for `confirmed`,
  `in_progress`, `completed`, and `cancelled`
- `database/seeders/EmailTemplateSeeder.php` — 12 templates (6 keys × 2 languages) — dev/test only,
  see "Existing-tenant provisioning" below
- `tests/Browser/OrderLifecycleEmailTest.php` — updated to assert the new handover/return emails
  instead of pinning their absence (see that file's own docblock for the full before/after)

---

## Existing-tenant provisioning (why there's a data migration too)

`EmailTemplateSeeder` runs exactly once per stack, at first-tenant provisioning
(`ProvisionTenantCommand::runGlobalSeedersOnce()`, gated by `TenantProvisioningState` so a re-run
never overwrites a tenant's customized templates — see that method's own docblock). Every
already-provisioned stack — including UAT's `budowlana` — never runs it again, so adding a key only
to the seeder means that stack's first handover/return attempt fails with "template not found"
straight into `failed_jobs`, unmonitored (the exact class of bug behind the 2026-08-08
tenant-scope incident, just one seeding-mechanism removed).

`database/migrations/2026_08_12_120000_seed_order_handover_return_email_templates.php` follows the
established pattern (`2025_12_02_224732_seed_email_templates.php`,
`2026_07_07_000001_seed_rental_extension_email_templates.php`,
`2026_08_02_000001_seed_tenant_registration_email_templates.php`): `insertOrIgnore()` with explicit
`organization_id => null`, so it only ever inserts the two new global rows and never touches an
existing row (including a tenant's own override of the same key, should one already exist) —
`down()` mirrors that, deleting only `key IN (...) AND organization_id IS NULL`.

**A repo-wide audit while building this found the same gap, pre-existing, for `order-paid`,
`order-confirmed`, `order-cancelled`, `admin-new-order`, `rental-cancelled` and
`service-area-available`** — none of them are in any production data migration either, only in
`EmailTemplateSeeder`. Not fixed here (out of this branch's scope — six unrelated keys, each
deserving its own reviewed change, not a drive-by); reported separately.

---

## `ShouldBeUnique` + `message_key` — can handover/return collide within 5 minutes?

No, for two independent reasons:

1. **Different lock identity, different dedup identity.** `uniqueId()` returns
   `'order-handed-over:'.$order->id` vs `'order-returned:'.$order->id` — different strings, and
   `EmailService`'s `message_key = md5(template_key:recipient:metadata)` differs too, since
   `template_key` itself differs (`order-handed-over` vs `order-returned`). Neither mechanism has
   any shared key for these two notifications to collide on, regardless of timing.
2. **`ShouldBeUnique` on a `Notification` subclass has no effect in this Laravel version anyway**
   (verified empirically, not just by reading the source: a throwaway test spied on
   `EmailService::sendFromTemplate` and called `notify()` twice in a row with the *same*
   notification instance — the spy was invoked twice, not once). `Illuminate\Notifications\NotificationSender::queueNotification()`
   dispatches the queued job via a direct `Bus::dispatch()` call on a manually-built
   `SendQueuedNotifications` instance — which does **not** itself implement `ShouldBeUnique` — and
   `Illuminate\Bus\UniqueLock::acquire()` is only ever invoked from
   `Illuminate\Foundation\Bus\PendingDispatch` (the `SomeJob::dispatch()` static helper), a path
   notifications never go through. This appears to make `ShouldBeUnique` inert for **every**
   notification in this codebase today, not just these two — the actual protection against a
   duplicate resend of the *same* notification has always been `EmailService`'s `message_key`
   UNIQUE constraint + `isRetryable()`, not Laravel's queue-level lock. Pre-existing, systemic,
   unrelated to handover/return specifically; not fixed here — flagged separately, since "fixing"
   it touches 8+ existing notification classes and this rule file's own guidance and deserves its
   own investigation and consensus on the right replacement mechanism.

---

## Known gap — no admin-facing visibility into whether a customer actually got these emails

There is no way for an admin to answer "did the customer get the handover email" from the UI today.
`email_sends` (via `EmailSend::metadata->order_id`) is the only evidence it happened at all —
`EmailSendResource` has no filter on `metadata->order_id`, and neither `OrderResource` nor
`EditOrder` has a relation manager or infolist section showing the order's related sends. This
predates handover/return (the same gap already existed for `order-paid`/`order-confirmed`/
`order-cancelled`) and is not introduced here — noted so it is not rediscovered from scratch, not
attempted in this branch.

---

## Template Variables

| Key | Variables |
|-----|-----------|
| `order-accepted-offline` | `customer_name`, `order_number`, `total_amount`, `hold_until`, `orders_url`, `app_name`, `items_list_html`, `items_list_text`, `deposit_amount`, `pickup_address`, `pickup_phone` |
| `order-paid` | `customer_name`, `order_number`, `total_amount`, `orders_url`, `app_name`, `items_list_html`, `items_list_text`, `deposit_amount`, `pickup_address`, `pickup_phone` |
| `order-confirmed` | `customer_name`, `order_number`, `orders_url`, `app_name` |
| `order-handed-over` | `customer_name`, `order_number`, `orders_url`, `app_name`, `items_list_html`, `items_list_text` |
| `order-returned` | `customer_name`, `order_number`, `orders_url`, `app_name`, `items_list_html`, `items_list_text` |
| `order-cancelled` | `customer_name`, `order_number`, `reason`, `orders_url`, `app_name` |
| `admin-new-order` | `customer_name`, `order_number`, `total_amount`, `payment_note`, `admin_url`, `app_name`, `items_list_html`, `items_list_text`, `pickup_address`, `pickup_phone` |

---

## Branded layout wrapper (2026-09-20, ClickUp 123k99cvc56)

Every order email above is a DB-templated send through `EmailService::sendFromTemplate()`,
which — until now — handed the rendered `html_body` straight to `SmtpMailer::send()`
(`$message->html($htmlBody)`, no layout at all). A tenant's configured header logo/brand
color (`design.use_logo_in_emails`, `design.use_color_in_emails`, `appearance.header_logo`,
`design.brand_color` — already used by `vendor.mail.*` view composer for `MailMessage`-based
mails like the service-area inquiry notification) never reached any DB-templated mail.

**Fix, at the `EmailService` layer, not the transport:** `sendFromTemplate()` gained an
optional `?Organization $organization` parameter. When given, the rendered body is wrapped
by `App\Support\Email\EmailBrandedLayout::wrap()` — a Blade view
(`resources/views/emails/branded-layout.blade.php`) with a logo/brand-color header and a
contact-details footer (`SettingsManager::contactDetailsFor($organization)`) — BEFORE the
result is stored in `email_sends.body_html` and handed to the gateway. Every order
notification listed above now passes `$order->organization` (loaded via `loadMissing()`)
into this parameter. No stored `email_templates.html_body`/`text_body` was touched — a
tenant's own template override still renders exactly as before, just inside the shared
shell now.

**Branding must be resolved WITHOUT ambient tenant state** — `EmailService` runs inside a
Horizon queue worker (`architecture-models.md`'s "Kolejka nie ma kontekstu żądania"), which
has no request, no Filament tenant, nothing `SettingsManager::get()`/`headerLogo()`/
`brandColor()` (all ambient-`TenantFeature::currentTenant()`-based) could resolve. New
method `SettingsManager::emailBrandingFor(?Organization $organization): array` mirrors the
already-established explicit-organization pattern (`getForOrganization()`,
`contactDetailsFor()`, `pickupDetailsFor()`) instead.

**Same-class bug found and fixed in the same change:** `EmailTemplate::resolveActive()` had
the identical ambient-tenant dependency — a tenant's OWN override of `order-paid` (or any
order template) never applied to a real queued send, only the global row, because
`TenantFeature::currentTenant()` is always null in a worker. This was previously documented
as "deliberate, accepted" (see `PasswordResetNotification`'s docblock — still true for
callers that don't have an `Organization` to name). Since every order notification now
already carries `$order->organization` for branding, `resolveActive()` gained the same
optional `?Organization $organization = null` parameter and `EmailService` passes it
through — closing the gap for every order template as a direct consequence, at zero extra
plumbing cost. Every OTHER caller (SMS, any notification not yet passing an organization)
is completely unaffected — the parameter defaults to `null`, preserving today's ambient
resolution exactly.

**A tenant's own `html_body` may be a full HTML document** (the column is a free-text
Filament field) — `EmailBrandedLayout::wrap()` detects a literal `<html` (case-insensitive)
in the rendered body and returns it untouched instead of nesting `<html>` inside `<html>`.

**Logo resolution depends on a file existing on the SAME disk the caller reads from** —
`SettingsManager::emailBrandingFor()` calls the same path-validation helper `headerLogo()`
uses, which does `Storage::disk('public')->exists($normalized)` before returning a URL.
`docker-compose.prod.yml` used to not mount the `storage-app-public` volume on `horizon` at
all (ClickUp `123k99ct3za`) — this existence check always returned `false` inside the worker
even for a tenant with a real, configured logo, and the email silently rendered the clean
text-brand header instead (never a broken `<img>` — the "no broken image" requirement held),
with no exception or log line anywhere. **Fixed** (devops, same ClickUp ticket): `horizon`
now mounts `storage-app-public` read-only, same target path as `app`/`nginx` — read-only
because every `ShouldQueue` job was grepped for a `Storage::disk('public')` write and none
exist; `app` remains the sole writer. `scheduler` still mounts nothing, deliberately: every
`Schedule::job(...)` that renders branded mail actually executes inside `horizon` (queued),
and every `Schedule::command(...)` that runs inline in `scheduler` was grepped for
EmailService/notify() usage with zero hits. **Requires a redeploy of `docker-compose.prod.yml`
on UAT to take effect** — editing the file alone does not change the running `horizon`
container; see `ci-cd-troubleshooting.md` and `tenant-compose-stack.md`. The rest of the
branding (brand color, brand name, contact footer) reads only from the `settings` DB table
and was never affected by this gap.

Files: `app/Support/Email/EmailBrandedLayout.php`,
`resources/views/emails/branded-layout.blade.php`,
`app/Support/Settings/SettingsManager.php::emailBrandingFor()`. Tests:
`tests/Feature/Notifications/OrderEmailBrandedLayoutTest.php`.

`order-handed-over`/`order-returned` reuse `OrderPaidNotification::buildRentalVariables()`'s item-table
approach (own copy per notification, same style as the rest of this file — see `models.md`'s "no
unnecessary abstractions" convention) but omit `deposit_amount`/`pickup_address`/`pickup_phone`: the
deposit lifecycle and pickup logistics are out of scope for these two emails (deliberately untouched
— see the design decisions below), and the equipment is already with/returned from the customer by
the time either fires.

**Correction (2026-08-14, `feature/settings-store-disconnect`):** `pickup_address`/`pickup_phone`
resolved to empty strings in every `order-paid` email ever sent, since inception. The comment above
`buildRentalVariables()` said this was deliberate ("queue-safe, no SettingsManager") and read
`$order->organization->settings` (the `organizations.settings` JSON column) directly — but that
column only ever holds `modules`/`features`/`location`; nothing writes `contact.*` into it. The
tenant's actual contact info (what `SystemSettings`' Contact tab saves) lives in the `settings`
table, read via `SettingsManager::getForOrganization($path, $organization, $default)` — which takes
the organization explicitly rather than resolving `TenantFeature::currentTenant()`, so it is
equally queue-safe. Fixed to use that instead. A related caching bug in `getForOrganization()`
itself (a tenant inheriting a global `contact.*` value could keep serving a stale value for up to
the cache TTL after a platform-global correction) was found and fixed in the same change — see
`tenant-branding.md`'s "two settings stores" section for that half. One rough edge remains,
deliberately not fixed here: the `order-paid` template's "Miejsce odbioru sprzętu:" label is
unconditional, so a tenant with no contact info configured still shows the label with nothing
under it — fixing it means editing a seeded DB template row, out of scope for this change (see
`order-protocols.md` §5's "known residual rough edge" note). Details and the parallel fix in the
handover/return protocol PDFs: `order-protocols.md` §5, `tenant-branding.md`'s "two settings
stores" section.

**Second correction (same day, from code review of the first correction):** the "rough edge" note
above was itself incomplete in a way that mattered. The `order-paid` HTML body concatenated
`{{pickup_address}}{{pickup_phone}}` with **no separator at all** — dormant only because both
variables were always empty before this branch's fix. Once real values started flowing through,
this rendered `…00-100 Warszawa+48123123123` glued together in every HTML confirmation email
— a regression this branch introduced, not merely a cosmetic pre-existing gap, so it was fixed
here rather than deferred: `EmailTemplateSeeder.php` now separates the two with `<br>`, and
`database/migrations/2026_08_14_100000_fix_order_paid_pickup_html_separator.php` applies the same
correction (exact-value match, tenant customisations untouched) to already-provisioned tenants'
stored rows — `order-paid` is not seeded by any migration otherwise, only by `EmailTemplateSeeder`
at first-tenant provisioning (the same gap `OrderHandoverReturnEmailTemplateMigrationTest`'s
docblock already flagged for this and five other keys). `text_body` was never affected — it
already put each on its own labeled line.

**Decision on the unconditional label, made explicit:** `EmailTemplate::render()` is deliberately
literal-substitution-only (see its own docblock) — no conditionals, and every substituted value was
(at the time of this correction) unconditionally HTML-escaped, so a variable could not smuggle in
its own `<br>`/`<p>` markup to hide itself either (confirmed: `items_list_html` — an existing,
unrelated variable — was ALSO escaped at the time, so the item table rendered as visible HTML
source text rather than an actual table in every sent order-paid email; a real, separate bug,
reported here and fixed in `feature/email-template-html-vars`, see "items_list_html rendered as
escaped text, not a table" below). Making the "Miejsce odbioru sprzętu:" heading disappear when a
tenant has no contact info configured would still need engine conditionals — the escaping-exemption
half of that tradeoff no longer applies verbatim now that `TrustedHtml` exists, but conditionals
were never built, so the heading stays unconditional. Only the glued-values regression is fixed here.

**`items_list_html` rendered as escaped text, not a table (fixed in `feature/email-template-html-vars`,
2026-08-14):** the bug flagged above. `EmailTemplate::render()` HTML-escaped every substituted
value with no exception, so the rental item `<table>` built by `OrderPaidNotification`,
`OrderHandedOverNotification` and `OrderReturnedNotification` showed up as literal `&lt;table&gt;…`
source in the customer's inbox in every paid/handover/return confirmation email, since each of
those templates was seeded. Fixed with `App\Support\Email\TrustedHtml` — a value wrapper, not a
variable-name allowlist living in `EmailTemplate` (see that class's docblock for why: a name-keyed
list drifts the moment a second notification reuses the same key with a different trust level,
and `items_list_html` is already built independently by three notification classes). Each
notification wraps the item-table string it builds in `new TrustedHtml($itemsListHtml)` at the
exact point it finishes assembling it; `EmailTemplate::substitutePlaceholders()` inserts a
`TrustedHtml` value verbatim only when rendering `html_body` (`render()`, `$escape === true`) and
strips its tags when rendering `subject`/`text_body` (no legitimate markup in either). Every other
value — including a plain string passed under the SAME key name, e.g. if a future caller forgets
to wrap it — is still escaped exactly as before; the trust decision travels with the value, not
the key. Safety of each wrapped value rests on the notification code escaping every interpolated
field (a service name — tenant-admin-set, not code-controlled) via `htmlspecialchars()` BEFORE
concatenating it into the markup, same as before this change; `TrustedHtml` does not weaken that,
it only stops the surrounding markup those escaped fragments sit inside from being escaped a
second time. `deposit_amount` was inspected and left as a plain (still-escaped) string — despite
sitting directly before an unwrapped `<hr>` in the template with no `<p>` of its own, it carries no
markup, only a formatted amount, so escaping it is correct as-is; wrapping it would have expanded
the trusted-HTML surface for no reason.

**A third call site, found by the same review round (item 3):** `resources/views/orders/show.blade.php`
(customer's own order page) computed its "Miejsce odbioru sprzętu" section from
`$order->organization?->settings` directly, in a `@php` block — the same JSON-column bug, missed by
the first sweep because that sweep only grepped `app/`, not `resources/views/`. `$hasPickupInfo` was
therefore always `false`: this section has never rendered for any tenant. Fixed by moving the
extraction into `OrderController::show()` and passing a `$pickup` array to the view. Re-swept
`resources/views/`, `resources/js/`, `database/`, `routes/` in addition to `app/` — clean, no
further hits.

**Root-cause follow-up (same round):** all three call sites — this notification, the two protocol
PDFs, and `OrderController::show()` — had independently hand-rolled the same five-key `contact.*`
lookup, each with its own "read via `getForOrganization()`, not the JSON column" docblock. Two of
the three had gotten that docblock's own advice wrong. Consolidated into
`SettingsManager::contactDetailsFor(?Organization): array`, the one place that decides which
store — see `tenant-branding.md`'s "two settings stores" section, "Root-cause follow-up"
subsection, for the full reasoning and why the per-caller display-shape combining was
deliberately NOT folded into the same method.

---

## Design Decisions

- **OrderPaid dispatched in service, not state machine** — the P24 webhook also needs to `update(['paid_at' => now()])` after transition; keeping both calls together in the service is simpler and avoids the `paid` hook firing for any future programmatic `transitionTo('paid')` in tests.
- **`afterTransitionHooks()` for confirmed/in_progress/completed/cancelled** — all four transitions always come from admin UI actions (`OrderResource` row actions / `EditOrder` header actions, same call sites for both), so hooking the state machine is the single source of truth; it covers any future Artisan commands or API calls that trigger the same transition, rather than duplicating the dispatch in both Filament call sites.
- **`in_progress` has no timestamp column** — deliberately out of scope for this change (no `handed_over_at` was added); the `OrderHandedOver` event dispatch itself is the only record of when the transition happened, recoverable from `order_status_history` (the state machine's own audit trail) if needed.
- **`completed`'s event and its `completed_at` write deliberately do NOT share a guard** — see that hook's own comment in `OrderStatusStateMachine.php`. Coupling "was completed_at already set" with "should we email" would silently skip the email if anything ever set `completed_at` outside this hook (backfill, import, data migration) before a genuine `transitionTo('completed')` call. The timestamp write keeps its own null-guard as defense-in-depth for the (currently unreachable) re-entry case; the email dispatch has none, matching every other hook in this method.
- **No admin copy for handover/return** — see the Notification Matrix section above for the full reasoning (admin already knows, since they triggered it).
- **Null-safe user check** — orders in theory always have a `user_id` (checkout requires auth), but each listener logs a warning and skips rather than crashing if the relation is missing.
- **Email only, no SMS** — rental orders are email-only per project spec.
- **`total_amount` formatted as `number_format(..., 2, ',', ' ')`** — Polish locale formatting.
