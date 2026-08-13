# Order Handover / Return Protocols (PDF)

**Implemented:** 2026-08-13 (`feature/rental-protocols`)

---

## Overview

Two printable, sign-at-the-counter documents for the rental lifecycle:

- **Protokół wydania** (handover protocol) — issued once equipment has left the counter.
- **Protokół zwrotu** (return protocol) — issued once a return has been accepted.

Before this branch, `barryvdh/laravel-dompdf` had exactly one call site in the whole application
(`StatisticsExportService::toPdf()`, an admin-only statistics export) — nothing customer-facing
had ever generated a PDF, and a customer received no document at all for a rental: no agreement,
no handover/return record, no invoice.

---

## Design Decisions

### 1. Generated on demand, never persisted

Mirrors `StatisticsExportService::toPdf()` exactly — `Pdf::loadView(...)->download(...)`, no
storage write, no DB row. Considered and rejected: persisting a generated copy per
handover/return event (would need its own storage location, retention policy, and GDPR export
entry — see `OrganizationDataExportController`'s existing `local` disk + signed-URL pattern for
what that machinery actually costs).

**Why on-demand is safe here specifically:** the PDF is not the legal record — the paper copy,
signed by hand at the counter, is. The protection this gives comes from what the views actually
read: `customer_first_name`, `customer_street`, `invoice_nip`, `$item->service_name` and the rest
are **snapshots**, copied onto `orders`/`order_items` at checkout time, not live reads through the
`user`/`service` relations. Renaming a service in the catalogue, or a customer editing their
profile address next month, does not silently rewrite the text of an already-issued protocol —
that is the actual mechanism, and it holds regardless of what else on `Order` is or isn't mutable.

**This is weaker than "the content is immutable," and that phrasing was wrong in an earlier
version of this document.** `Order::booted()`'s `updating` guard (`models.md`) only covers eleven
fields — money totals and legal-consent timestamps. The snapshot columns the protocol views
actually render — `customer_street`, `service_name`, and in particular `deposit_status` — are
ordinary mutable columns, and `deposit_status` is *designed* to change over an order's lifetime
(`pending → collected → returned/partial_return/forfeited`, admin actions on `OrderResource`). A
protocol reprinted after such a change reflects the **current** row, not the row at the moment the
event (handover/return) it documents actually happened. Both blade views say so explicitly next
to the deposit line ("Stan kaucji podany wyżej odzwierciedla dane w chwili sporządzenia tego
dokumentu.") for exactly this reason — see the "Reprinting after the fact" section below for the
bug this caused and the fix.

**What this trades away:** if an admin needs to prove exactly what a customer saw and signed at a
specific past moment — including deposit status at that moment — this system cannot answer that
from the PDF alone, only from the physical signed paper (or, for the deposit specifically,
`Order::$auditInclude`/`AuditLog`, which does not cover `deposit_status` today — see `models.md`'s
Order audit section). That is an accepted limitation, not an oversight: building an audit trail
for that question is a separate, larger feature (versioned snapshots or a persisted-at-generation-
time copy), not implied by "add a protocol PDF."

### 1a. Reprinting after the fact — the deposit-status bug (fixed 2026-08-13, same day)

The first version of both templates only handled one deposit status explicitly and sent
**everything else** through an unconditional `else`:

```blade
{{-- handover.blade.php, as first written --}}
@if($order->deposit_status === 'collected')
    — pobrana przy wydaniu sprzętu.
@else
    — do pobrania przy wydaniu sprzętu.
@endif
```

Since `deposit_status` is mutable and reprint is a supported use case (§1 above), this meant a
handover protocol regenerated *after* the deposit had already been returned still read "— do
pobrania przy wydaniu sprzętu" (still to be collected) — a false statement about equipment handed
over, and its deposit settled, weeks earlier. Caught in review by generating a real PDF with
`deposit_status = 'returned'` and reading the rendered line.

Fixed by handling all 5 non-`not_required` statuses explicitly in both views (the `deposit_amount
> 0` guard already excludes `not_required`, which pairs with `deposit_amount = 0` — see
`models.md`'s deposit lifecycle table):

| `deposit_status` | Handover protocol says | Return protocol says |
|---|---|---|
| `pending` | do pobrania przy wydaniu sprzętu. | nie została pobrana — do rozliczenia z Najemcą. |
| `collected` | pobrana przy wydaniu sprzętu. | pobrana; rozliczenie zwrotu kaucji w toku. |
| `returned` | pobrana przy wydaniu sprzętu, zwrócona Najemcy po zakończeniu wynajmu. | zwrócona Najemcy. |
| `partial_return` | pobrana przy wydaniu sprzętu, zwrócona częściowo po zakończeniu wynajmu. | zwrócona częściowo. |
| `forfeited` | pobrana przy wydaniu sprzętu, zatrzymana przez Wynajmującego. | zatrzymana przez Wynajmującego. |

Regression coverage: `OrderProtocolPdfServiceTest::handoverDepositStatuses()` /
`returnDepositStatuses()` data providers (all 5 statuses × both documents), plus a dedicated
negative assertion that the old false string can never appear once `deposit_status = 'returned'`.
Verified beyond the Blade-string assertions too — generated a real PDF for all 5 statuses × both
documents (10 files) and read the actual rendered "Kwota kaucji" line back with `pdftotext` from
the host; all 10 matched the table above exactly.

### 1b. Filament actions and the Livewire file-download bug (fixed 2026-08-13, code review)

The first version of the 4 admin buttons (`OrderResource` table row actions x2, `EditOrder` header
actions x2) used `->action(fn (Order $record) => app(OrderProtocolPdfService::class)->...($record))`
— the exact same shape `Statistics::exportPdf()` had already been using, in production, since
before this branch. All 5 crashed identically:

`Pdf::download()` returns `Illuminate\Http\Response`. Livewire's
`SupportFileDownloads::valueIsntAFileResponse()` only recognizes `StreamedResponse` /
`BinaryFileResponse` as "this is a file, stream it" — a plain `Response` falls through and is
treated as the action closure's ordinary return VALUE, which Livewire then `json_encode()`s to
send back to the browser. Encoding raw PDF binary as JSON fails: `InvalidArgumentException:
Malformed UTF-8 characters, possibly incorrectly encoded`. Reproduced independently (not just
taken from the review report) via `Livewire::test(EditOrder::class, [...])->callAction(...)`
against the code as first written — see `tests/Feature/Filament/OrderProtocolFilamentActionTest.php`
and `StatisticsPdfExportActionTest.php`'s class docblocks for the exact captured exception.

**Two different fixes for two different situations, argued separately:**

- **The 4 new buttons → point at the existing route instead of adding a second download path.**
  `->url(fn (Order $record) => route('orders.protocol.handover', $record))->openUrlInNewTab()`.
  One code path (`OrderProtocolPdfService` + `OrderProtocolController`), already tested, and this
  sidesteps the Livewire action-return mechanism entirely rather than trying to make it recognize
  the right response type. Required widening `OrderProtocolController::authorizeAccess()` to also
  accept staff of the order's own tenant (previously customer-only) — both panels sit behind the
  same `web` auth guard (no `authGuard()` override in `AdminPanelProvider`), so an admin's session
  is already valid on this route; see §2 below for the authorization change itself.
- **`Statistics::exportPdf()` (pre-existing, unrelated to this branch, fixed in passing since
  `order-protocols.md` cited it as this feature's own precedent) → fix the closure's return type
  instead.** No route exists for it — the report is computed live from `$this->getStatsData()` and
  `$this->period` (Livewire component state), not addressable by a plain GET. `toCsv()` on the same
  page never had this bug because `response()->streamDownload()` already returns `StreamedResponse`;
  `StatisticsExportService::toPdf()` now does the same instead of `$pdf->download(...)`.

Table row actions (`ListOrders`), not `EditOrder`'s header actions, are used for the
`return_protocol` regression test specifically: `OrderResource::canEdit()` returns `false` for
`completed`/`refunded`/`cancelled` (pre-existing, unrelated to this branch) — a fresh `EditOrder`
mount for a `completed` order, exactly the status `return_protocol` needs to be visible, is denied
before any action can even be tested. Table row actions are not gated by `canEdit()`. Not fixed
here (separate, unrelated design question about whether `EditOrder` should stay reachable
read-only after those transitions); noted so it isn't rediscovered from scratch.

### 2. Authorization returns 404, not 403, for every failure mode

`OrderProtocolController::authorizeAccess()` returns 404 for missing tenant context, wrong tenant,
and "neither the order's own customer nor staff of the order's tenant" — deliberately more
conservative than `OrderController::show()`, which returns 403 for the wrong-customer case (see
`OrderController.php:37`, and the corresponding
`CustomerOrdersTest::test_user_cannot_view_order_belonging_to_different_organization`, which
asserts 403 today). The 403 in `OrderController` already reveals "an order with this ID exists in
this tenant, but isn't yours" — this branch does not fix that pre-existing pattern (out of scope,
flagged separately), but does not repeat it for the new protocol download endpoints, where a wrong
guess is even cheaper to make (sequential order numbers, no rate limit on this route).

The wrong-*state* case (protocol not eligible yet) also returns 404 rather than a more descriptive
4xx, for the same reason: the caller already owns the order and knows its state from the order
page, so no real information is lost, and it keeps every failure path on this controller
indistinguishable from the outside.

**Staff of the order's own tenant are allowed through too** (added alongside §1b's Filament fix,
since the admin buttons now point at this exact route — one authorization point instead of two).
Role check mirrors `OrderResource::canViewAny()` (`hasAnyRole(['admin', 'super-admin'])`). The
tenant check still applies to staff exactly as it does to customers: a staff member of a
*different* tenant gets 404 same as any other stranger — only "who within the tenant" branches.

**Verified, not assumed — and the comment lives on the method itself, not just here,** specifically
so a future "this looks redundant with the model scope" cleanup doesn't quietly remove it:
cross-tenant and cross-customer protection is enforced entirely by `authorizeAccess()`'s manual
checks, not by `Order`'s `BelongsToOrganization` global scope acting on implicit route-model
binding. Confirmed twice — by temporarily removing the ownership check during the initial build
(4 tests red) and again by temporarily removing the newer staff branch (2 tests red, both new
staff-access cases in `OrderProtocolDownloadTest`) — that stripping this method's body down to
just the tenant check serves a cross-tenant order's PDF with a 200. The scope alone does not stop
this in the test harness (`actingAsTenant()` sets the tenant via a request attribute, not through a
real `ResolveTenant` pass — see `models.md`'s `tenant_resolution_attempted` section for why that
matters), and there is no reason to expect production request-model-binding behavior to differ,
since the scope's fail-closed branch depends on the same `TenantFeature::currentTenant()` call
regardless of how the tenant attribute got set.

### 3. Which states produce which document

```php
// OrderProtocolPdfService
HANDOVER_ELIGIBLE_STATUSES = ['in_progress', 'completed', 'refunded'];  // equipment has left the counter
RETURN_ELIGIBLE_STATUSES   = ['completed', 'refunded'];                  // return has been accepted
```

Both sets include the order's terminal states (`completed`, `refunded`) so a protocol remains
downloadable/reprintable after the rental is fully closed out — not just during the narrow window
of the state it was generated for.

**Both eligibility checks are public methods** (`canDownloadHandoverProtocol()` /
`canDownloadReturnProtocol()`), not private — they are the single source of truth for whether the
download BUTTON should be shown, not just whether the download itself succeeds
(`OrderResource`/`EditOrder`'s `->visible()`, and `orders/show.blade.php`'s `@if`, all call
these instead of re-implementing the status check). Do not re-implement either as a plain
`in_array()` at a UI call site — that is exactly the shape of mistake §1a's deposit-status bug
was, one level up (UI and actual download silently disagreeing).

**Forced-cancellation edge case (fixed 2026-08-13, code review): `in_progress -> cancelled` is a
legal transition** (forced offboarding of a closing tenant — see
`OrderStatusStateMachine::transitions()`). Neither eligible-statuses array includes `cancelled`,
so as first written, an order whose equipment genuinely left the counter — then was later
force-cancelled — permanently lost access to the one document proving handover happened. `cancelled`
cannot simply be added to `HANDOVER_ELIGIBLE_STATUSES`: it's also reachable directly from
`pending_payment`/`paid`/`confirmed`, where handover never happened, and that would generate a
false document for those orders — the exact same class of mistake as §1a, just at the status-gate
level instead of the deposit-line level.

No `handed_over_at` column was added for this (deliberately out of scope, matches
`order-notifications.md`'s existing "no timestamp column for this transition" decision for the
same reason). Instead, `canDownloadHandoverProtocol()` falls back to the state machine's own audit
trail — `state_histories`, via `HasStateMachines::stateHistory()` — when status is `cancelled`:

```php
return $order->status === 'cancelled'
    && $order->stateHistory()->where('field', 'status')->where('to', 'in_progress')->exists();
```

This works even for orders whose `in_progress` state came from a factory/seeder `create()` rather
than a real `transitionTo()` call, because `HasStateMachines`'s `created()` hook already records
one `state_histories` row (`from: null, to: <initial status>`) for every order regardless of how it
reached that status — `->where('to', 'in_progress')` matches either way.

`RETURN_ELIGIBLE_STATUSES` needed no equivalent fallback: `completed` has no path to `cancelled` in
the transitions() map (only `completed -> refunded`), so there is no forced-cancellation case on
the return side to account for.

### 4. Not attached to the handover/return emails

`OrderHandedOverNotification`/`OrderReturnedNotification` (PR #176) are unchanged — this branch
does not attach a PDF to either. Attaching a document with the customer's name, address, and
(for `natural_person` orders) PESEL to an email is a GDPR-relevant decision the data-export
system already avoids for exactly this reason (`OrganizationDataExportController`'s signed URL on
the `local` disk instead of an attachment). The recommendation is **download-only**, exposed as
buttons on `/moje-zamowienia/{order}` (customer) gated on the same status sets, and as row/header
actions in `OrderResource`/`EditOrder` (admin, tenant-scoped by the resource itself). Wiring a link
into the email body instead of an attachment is a reasonable small follow-up, but touches DB
template rows in production (see `order-notifications.md`'s "Existing-tenant provisioning"
section for why that is never a drive-by) — not attempted here.

### 5. Company-identification limitation (not fixed here — product decision pending)

`organizations` has **no legal identity fields** — no NIP, REGON, registered legal name, or
registered address. Only `name` and `settings.contact.*` (`address_line`, `postal_code`, `city`,
`phone`, `email` — the same fields `OrderPaidNotification::buildRentalVariables()` already reads
for the pickup-address block in the payment-confirmation email). Both protocol documents render
whatever is present in `settings.contact.*` and print blank space for anything missing — they do
not invent new settings keys or add a settings UI for this. Whether a tenant's legal
identity belongs on the Organization model (and whether it should be mandatory before the first
protocol can be issued) is a product decision, not made in this branch.

### 6. `dompdf.enable_remote` stays `false` — both views are self-contained by design

Neither blade view uses `{!! !!}` (unescaped output), external assets (images, stylesheets,
fonts-by-URL), or anything else that would need dompdf to fetch a remote resource — inline
`<style>`, no `<img src="http://...">`, no `@import`. This matters specifically because
customer-controlled text (`customer_first_name`, `customer_street`, service names entered by the
tenant, etc.) reaches these templates: with `enable_remote` on, a crafted value containing
something dompdf resolves as a remote reference could be used for SSRF probing or exfiltration at
PDF-generation time. `config/dompdf.php` is not published/overridden in this app, so the vendor
default (`enable_remote => false`, `barryvdh/laravel-dompdf`'s `config/dompdf.php:270`) already
applies — this is a "confirm and don't break it" note, not a code change. Do not add `{!! !!}` or
a remote asset to either view without re-reading this section first.

---

## Files

- `app/Services/Order/OrderProtocolPdfService.php` — status gating (`canDownloadHandoverProtocol()`
  / `canDownloadReturnProtocol()`, public — §3) + `Pdf::loadView()` (mirrors
  `StatisticsExportService`), plus `pickupDetails()` (mirrors
  `OrderPaidNotification::buildRentalVariables()`'s organization-settings extraction — own copy,
  not a shared abstraction, matching this codebase's stated convention, see `order-notifications.md`)
- `resources/views/orders/protocols/handover.blade.php`, `.../return.blade.php` — Polish-language,
  print-oriented (DejaVu Sans for PL diacritics, same as `statistics/pdf-report.blade.php`);
  duplicated rather than sharing a partial, matching the two-notification-classes precedent in
  `order-notifications.md`; deposit-status line computed via a `@php` `match()` block per view (§1a)
- `app/Http/Controllers/OrderProtocolController.php` — download route shared by customer AND staff
  (§1b/§2), `handover()` / `returned()` (not `return()` — reserved word)
- `routes/web.php` — `orders.protocol.handover`, `orders.protocol.return`, inside the existing
  `ResolveTenant + RequireTenant + auth + CheckRentalEnabled` group alongside the rest of
  `/moje-zamowienia/*`
- `resources/views/orders/show.blade.php` — download buttons; eligibility computed once via
  `OrderProtocolPdfService`'s public methods (§3), not a re-implemented `in_array()`
- `app/Filament/Resources/OrderResource.php`, `.../Pages/EditOrder.php` — admin row/header actions,
  `->url(fn (Order $record) => route('orders.protocol.handover', $record))->openUrlInNewTab()` (§1b
  — NOT `->action(fn () => $pdf->download(...))`, which crashes)
- `app/Services/Statistics/StatisticsExportService.php` — `toPdf()` fixed in passing (§1b): now
  `response()->streamDownload(...)` like `toCsv()`, instead of `$pdf->download(...)`
- `tests/Unit/Services/OrderProtocolPdfServiceTest.php` — status gating (both documents, both
  directions, including the cancelled-after-handover edge case — §3), content assertions via direct
  Blade `View::make()->render()` (not the PDF bytes — dompdf compresses/encodes content streams, so
  byte-level string assertions are unreliable), empty-organization-settings does not throw,
  `handoverDepositStatuses()`/`returnDepositStatuses()` data providers covering all 5 deposit
  statuses on both documents (§1a)
- `tests/Feature/Orders/OrderProtocolDownloadTest.php` — HTTP-level: happy path (200 +
  `Content-Type: application/pdf`), wrong state (404), cross-tenant (404), cross-customer (404),
  no tenant context (404), guest → redirect to login, staff-of-own-tenant can download (§2),
  staff-of-different-tenant still 404 (§2)
- `tests/Feature/Filament/OrderProtocolFilamentActionTest.php` — the §1b regression: all 4 admin
  buttons exercised through real `Livewire::test()->callAction()`/`callTableAction()` calls, not
  just the service/route directly, plus their `->url()`/`->openUrlInNewTab()` config and
  visibility gating
- `tests/Feature/Filament/StatisticsPdfExportActionTest.php` — the pre-existing `Statistics`
  bug's own regression test (§1b)

## Verification performed

- `docker compose exec -T app ./vendor/bin/pint --test` — 791 files, pass
- `docker compose exec -T app php artisan test` — 1131 passed / 5 skipped / 1 failed
  (`TenantFeatureTest > booking wizard has 4 steps without vehicles`, pre-existing on `develop`,
  unrelated to this branch); re-confirmed after every round below with no change to that count
- Red-then-green, not just written-green, four rounds total:
  1. **Initial build** — temporarily disabled the status gate in `OrderProtocolPdfService` → 3
     gating tests failed as expected; temporarily removed the ownership check in
     `OrderProtocolController::authorizeAccess()` → 4 authorization tests failed as expected
     (including the cross-tenant one).
  2. **§1a deposit-status fix** — reverted `handover.blade.php` to its original single-branch
     `if/else` and re-ran the new data-provider tests: 4 failed, reproducing the exact false "do
     pobrania przy wydaniu sprzętu" string on a `returned` order.
  3. **§1b Filament/Livewire fix** — reverted `OrderResource.php`/`EditOrder.php`'s 4 actions and
     `StatisticsExportService::toPdf()` back to their original `->action(fn () =>
     $pdf->download(...))`/`return $pdf->download(...)` shape and re-ran
     `OrderProtocolFilamentActionTest`/`StatisticsPdfExportActionTest`: 3 of 5 + 1 of 1 failed. A
     separate throwaway test isolating just `->callAction('handover_protocol')` (no preceding
     `assertActionHasUrl`, which short-circuits the chain before reaching the crash) captured the
     exact reported exception independently: `InvalidArgumentException: Malformed UTF-8 characters,
     possibly incorrectly encoded` at `JsonResponse.php:91`, reproduced for BOTH the order-protocol
     action and the Statistics one.
  4. **§2/§3 authorization + cancelled-eligibility fixes** — temporarily removed the staff branch
     from `authorizeAccess()` → 2 new staff-access tests in `OrderProtocolDownloadTest` failed;
     temporarily forced `canDownloadHandoverProtocol()`'s cancelled-fallback to `return false` → 2
     tests failed (including the direct `canDownloadHandoverProtocol()` matches-throw-behavior
     assertion).

  All four rounds reverted via file-copy-and-diff (not `git stash` — a `git stash` mistake earlier
  in this branch's work briefly pulled an unrelated agent's WIP into this working tree; recovered
  with no data loss, but `git stash` is no longer used here), each restore verified byte-identical
  to the fixed version before re-running. Suite green again after every round.
- **Generated an actual PDF and confirmed it opens**, not just that `Pdf::loadView()` returned
  without throwing: wrote both documents' real byte output to disk from inside a test, then from
  the host (outside Docker, where PDF tooling is available — the app container has neither)
  independently verified with three different tools: `pdftotext` (extracted readable Polish text,
  including the order number and item name), `pypdf` (`PdfReader` opened both files, reported 1
  page each), and `gs -sDEVICE=nullpage` (processed both files with zero errors). Repeated twice for
  §1a's deposit-status fix — once right after the fix, once again after §1b/§2/§3's refactoring
  touched the same service, to confirm nothing regressed the deposit-line rendering: all 5 deposit
  statuses × both documents (10 real PDFs each round) read back correctly with `pdftotext`, matching
  the table in §1a exactly both times. All temporary tests and generated files were deleted after
  verification — this doc is the only remaining record.
