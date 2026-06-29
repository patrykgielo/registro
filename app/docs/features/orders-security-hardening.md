# Orders Security Hardening

**Branch:** `feature/fix-services-tenant-unique` (retro-audit PR)
**Date:** 2026-06-29

## Findings Fixed

### 1. [CRITICAL] Cross-tenant PII leak — OrderResource user Select

**File:** `app/Filament/Resources/OrderResource.php`

`Select::make('user_id')->relationship('user', 'email')` was querying ALL users in the database. An admin of tenant A could see emails, PESELs, NIPs, and addresses of users who belong to tenant B.

**Fix:** Scoped relationship query with `modifyQueryUsing` to only show users who have at least one order in the current tenant:
```php
->relationship(
    name: 'user',
    titleAttribute: 'email',
    modifyQueryUsing: fn (Builder $query) => $query->whereHas(
        'orders',
        fn ($q) => $q->where('organization_id', TenantFeature::currentTenant()?->id)
    ),
)
```

The `afterStateUpdated` callback now also validates tenant membership before auto-filling PII fields.

### 2. [HIGH] Missing Polish ID validation on admin edit form

**File:** `app/Filament/Resources/OrderResource.php`

The checkout flow validates PESEL/NIP/REGON via `App\Rules\Valid*`. The admin edit form accepted any string.

**Fix:** Added `['nullable', new ValidPolishPESEL]`, `['nullable', new ValidPolishNIP]`, `['nullable', new ValidPolishREGON]` rules. The `nullable` prefix ensures empty fields skip validation.

Added `->minLength(9)->maxLength(11)` to `signatory_id_number` and `pickup_person_id_number` (dowód = 9 chars, PESEL = 11 chars).

### 3. [HIGH] Order model not audited

**File:** `app/Models/Order.php`

The `Order` model was not using the `Auditable` trait, so PII changes (PESEL, NIP, addresses) by admins left no audit trail.

**Fix:** Added `use Auditable` with explicit `$auditInclude` (all PII + status + user_id) and `$auditExclude` (p24_*, expires_at, cart_id, ip_address, rodo_accepted_ip).

### 4. [HIGH] Logic bug — OrderService::cancel() blocked confirmed orders

**File:** `app/Services/Order/OrderService.php`

The state machine permits `confirmed → cancelled` and the UI shows "Anuluj" action for confirmed orders. But the service-layer guard only allowed `pending_payment` and `paid`, throwing LogicException for confirmed — silently swallowed by the Filament error notification.

**Fix:** Added `'confirmed'` to the allowed statuses list.

### 5. [HIGH] Stale state — visible() closures used `$record` not live form state

**File:** `app/Filament/Resources/OrderResource.php`

Three section visibility closures used `fn (?Order $record)` to check `customer_type`. When an admin changed the user via the Select (triggering `afterStateUpdated` → `$set('customer_type', ...)`), the sections did not re-render because `$record` is the stale DB value.

**Fix:** Changed to `fn (Get $get): bool => $get('customer_type') === '...'` on all three sections.

### 6. [HIGH] Mass-assignment hardening

**Organization:** Removed `subscription_status`, `monthly_fee`, `subscribed_at`, `subscription_expires_at` from `$fillable`. These are billing-critical fields that must only be set by super-admin via direct property assignment, never via mass-assignment from request data.

**TenantPayment:** Removed `organization_id` (set via relationship) and `recorded_by` (must be set explicitly to `auth()->id()`, never from user input).

**Order:** Added `booted()` + `updating()` guard that throws `\LogicException` if any of these immutable fields are dirty: `organization_id`, `order_number`, `total_amount`, `subtotal`, `discount_amount`, `tax_amount`, `deposit_amount`, `rodo_accepted_at`, `rodo_accepted_ip`, `terms_accepted_at`, `withdrawal_exclusion_accepted_at`.

## Tests Added / Updated

- `tests/Feature/Orders/OrderSecurityTest.php` — 16 new tests covering:
  - Cross-tenant user isolation (3 tests)
  - Order immutable field guard (5 tests)
  - Audit log creation (4 tests)
  - OrderService cancel with confirmed (2 tests)

- `tests/Unit/Services/OrderServiceTest.php` — replaced `test_cancel_throws_for_confirmed_order` with `test_cancel_transitions_confirmed_order_to_cancelled`

## Architecture Rules Updated

See `.claude/rules/models.md` additions.
