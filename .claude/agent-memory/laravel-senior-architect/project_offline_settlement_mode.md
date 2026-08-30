---
name: project-offline-settlement-mode
description: Faza 1 payment-settlement-modes.md — offline (pay-at-pickup) checkout, implemented 2026-08-16 on feature/offline-settlement-mode
metadata:
  type: project
---

Faza 1 of `app/docs/features/payment-settlement-modes.md` shipped 2026-08-16
(`feature/offline-settlement-mode`, PR pending). Full detail lives in the doc
itself — this is the non-obvious part worth remembering for next time.

**Why:** UAT tenant `budowlana` could not close a single order — the only two
code paths that ever set `status=paid` required either live P24 credentials
or `APP_ENV!=production`. Any cash-at-pickup rental business was fully
blocked. See [[project_two_machines_uat_preprod]].

**The TTL decoupling turned out to need ZERO changes to `Order::scopeExpired()`
or `OrderItem::scopeBlockingAvailability()`.** Both scopes' "no P24 token"
branch already reads only `expires_at` — the fix was entirely in
`CartService::convertToOrder()` writing a different `expires_at` at creation
time (20 min fixed for online, unchanged; `SettingsManager::offlineReservationHoldHours()`
for offline, default 48h, clamped 1-168h). The two scopes staying in sync was
never actually at risk here — don't over-rotate on that risk next time this
area is touched; the real risk is always in the WRITE path (CartService), not
the two read-side scopes.

**New `TemplateKey` case needs a production data migration, not just the
seeder** — `EmailTemplateSeeder` only runs once, at first-ever tenant
provisioning. Already-provisioned stacks (UAT) never see a seeder-only key.
Pattern: `insertOrIgnore` + `organization_id => null`, `down()` scoped to that
key + null org only. Documented in `.claude/rules/migrations.md` now — a
recurring bug class (order-handed-over/order-returned, rental-return-*, and
this one all needed it; order-paid/order-confirmed/order-cancelled/
admin-new-order/rental-cancelled/service-area-available are STILL missing
their production migration — pre-existing, not fixed here, out of scope each
time it's been found).

**`OrderService::recordOfflinePayment()` dispatches `OrderPaid` OUTSIDE its
`DB::transaction()`** — deliberately different from `Przelewy24Service::handleWebhook()`'s
pre-existing pattern (which dispatches `event(new OrderPaid($order))` as the
last line INSIDE its transaction, undocumented pre-existing risk per
[[feedback_notify_inside_transaction]] if that memory exists, else see
`.claude/rules/notifications.md`). Do not copy Przelewy24Service's inside-transaction
placement as "the pattern" — it predates the notify()-inside-transaction rule
and was left alone (out of scope) rather than fixed on this branch.

**Key design choice:** `orders.settlement_method` (online/offline, checkout-time
customer choice) is a SEPARATE column from `payments.method` (p24/cash/bank_transfer,
what actually happened on a given Payment row) — collapsing these into one
concept would have broken the existing P24 reconciliation flow reading
`payments.status`. Also: no partial payments / amount validation on the
"odnotuj wpłatę" panel action — deliberately out of scope per product owner
("Bez zaliczek"), the amount field is staff-trusted free entry, not validated
against `order.total_amount`.

**File map for next session:** `app/Events/OrderAcceptedOffline.php`,
`app/Notifications/OrderAcceptedOfflineNotification.php` +
`app/Notifications/Concerns/BuildsOrderRentalEmailVariables.php` (extracted
from `OrderPaidNotification` so both notifications share the items-table/
deposit/pickup-address rendering), `OrderService::recordOfflinePayment()`,
`SettingsManager::availableSettlementMethods()`/`offlineReservationHoldHours()`,
panel action `record_offline_payment` duplicated in `OrderResource.php` (table)
and `Pages/EditOrder.php` (header) per the project's existing duplication
convention for Order actions.

**Not done in this phase (explicitly deferred):** settlement method annotation
on the handover protocol PDF; `frontend-ui-architect` review of the new
settlement-method radio picker in `checkout/show.blade.php`. Fazy 2-4
(gateway abstraction, multi-provider, marketplace onboarding) are unstarted —
see the doc for the P24/PayU/Tpay sandbox research already done for Faza 2.

**Faza 1a superseded on 2026-08-22 (`feature/offline-settlement-default`):**
first cut of Faza 1a wrote `checkout.settlement_offline_enabled => true` from
`SeedOrganizationDefaults` (new tenants only). Product owner rejected it same
day — reverted, replaced with a straight default flip in
`SettingsManager::isOfflineSettlementEnabled()` itself (`get(..., true)`
instead of `get(..., false)`). One source of truth instead of two, and it
retroactively covers organizations that already existed (incl. `budowlana`),
which the seeder approach structurally could not. Reason it mattered: no real
tenant exists yet, so "seeder won't clobber an existing tenant's deliberate
choice" was defending an empty set. **If asked to gate a new default by
provisioning-time again, ask first whether a real tenant exists yet** — that
premise is what makes seeder-vs-default-flip the right call either way.
Guard test moved from `tests/Feature/Onboarding/` (seeder-scoped) to
`tests/Unit/Support/Settings/SettingsManagerOfflineSettlementDefaultTest.php`
(asserts the SettingsManager fallback directly, no seeder invocation).
Non-obvious test fallout from the flip: any Feature test posting
`settlement_method => 'online'` without configuring P24 credentials in its
own `setUp()` was silently relying on `availableSettlementMethods()`'s
`['online']` fail-safe (both methods false → fallback) — flipping the offline
default to true breaks that fail-safe path and turns those into real
`Rule::in` validation failures. Fixed by adding real-looking P24 config
(`merchant_id`/`reports_key`/`crc`) to each such suite's `setUp()`
(`CheckoutFlowTest`, `CheckoutSubmitThrottleTest`, `PeselPerTenantToggleTest`)
so 'online' is deliberately available, not accidentally. Conversely,
`CheckoutGatewayUnconfiguredTest`'s tests that specifically exercise the
*unconfigured-gateway compensation path* (order created then cancelled) had
to gain an explicit `disableOfflineSettlement($this->org)` call each — they
need 'online' submittable while P24 stays unconfigured, which the new default
alone no longer produces.

**Correction, code-review follow-up (`feature/offline-settlement-default`, 2026-08-22, same
day):** the `get('checkout.settlement_offline_enabled', true)` flip described above was
**missing from the actual working tree** when this follow-up session started — present in every
downstream test's `setUp()` (assumed it), present in this doc, but NOT in `SettingsManager.php`
itself. Root cause unknown (lost/uncommitted in the original session); re-applied first, before
anything else, once `git diff`/`git log -S` proved it was never actually committed on this branch
or `develop`. **Lesson: a memory file describing a code change is a CLAIM about the state at
write time, not a fact about now — verify with `git diff`/`grep` on the actual file before
trusting it, especially "flip a default" one-liners that are trivial to lose.**

**Second, much bigger correction:** the review's fix for `SystemSettings.php`'s Toggle
(`->default(true)`) does NOTHING — Filament v4 only consults `->default()` when the whole form
is filled with `null` (Create-page case); `mount()` here always fills with a real array, so
absent keys hydrate to raw `null`, and `Toggle`'s own `BooleanStateCast(isNullable: false)`
unconditionally coerces that to `false` DURING `hydrateState()`, before `afterStateHydrated` (or
any hook) ever sees it. Real fix: `afterStateHydrated` checking
`app(SettingsManager::class)->get($key)` with NO default (returns `null` only when truly no row
exists) BEFORE the cast collapses "no row" and "tenant chose false" into the same value — see
`.claude/rules/filament-settings-pages.md` for the full mechanism and
`SystemSettingsCheckoutOfflineDefaultTest.php` for the pattern. **This same bug (unfixed) also
hits `settlement_online_enabled`** (confirmed empirically: fresh tenant + real P24 credentials
still gets `isOnlineSettlementEnabled() === false` after any save of this tab) **and
`offline_reservation_hold_hours`** (lands as `1h` not `48h`) — `pesel_required` is coincidentally
unaffected (its own code default is also `false`). None of the three fixed — out of scope,
flagged for a future task.

**Third, independent finding:** `saveCheckoutSettings()` (the real Livewire save action) cannot
currently succeed for ANY tenant, in ANY state — `HasGroupedSettings::saveSettingsGroup()`
validates `$this->data['checkout']` directly (bypassing Filament's state-casting pipeline,
deliberately, to avoid full-form validation), but the 4 `RichEditor` fields in this same group
always hold the raw Tiptap JSON document there, never the HTML string the `'string'` rule
expects — so validation always fails first. See [[project_richeditor_grouped_settings_validation]]
(not written yet — worth its own memory if this gets picked up).
