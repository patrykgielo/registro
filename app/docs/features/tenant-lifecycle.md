# Tenant Lifecycle — Workstream Overview

## Status: Faza 5.1 merged (guards + lifecycle actions)

Workstream introduces an explicit lifecycle state for Organization, replacing the implicit boolean `is_active` as the authoritative source of truth for tenant health. The migration is additive and non-breaking — `is_active` is now a fully derived field, synced automatically from `lifecycle_state` by the observer.

---

## Phases

| Faza | Zakres | Status |
|------|--------|--------|
| 5.0 | Enum + state machine + schema + backfill | Done |
| 5.1 | Guards + observer (is_active sync, obligation checks, delete guard) + Platform lifecycle actions | Done |
| 5.2 | Public site / new-booking middleware based on lifecycle_state | Planned |
| 5.3 | SoftDeletes on Organization + purge scheduler | Planned |

---

## Faza 5.0 — Schema Foundation

### OrganizationLifecycleState enum

`app/Enums/OrganizationLifecycleState.php`

| Case | Value | allowsPublicSite | allowsNewBookings | isTerminal |
|------|-------|-----------------|-------------------|------------|
| Active | `active` | true | true | false |
| Suspended | `suspended` | false | false | false |
| Closing | `closing` | false | false | false |
| Closed | `closed` | false | false | true |

- `Closing` represents the grace-period window before permanent closure. Existing orders/rentals may continue being fulfilled, but no new intake is allowed and the public catalog is hidden.
- `Closed` is terminal — no outgoing transitions exist.

### State Machine

`app/StateMachines/OrganizationLifecycleStateMachine.php`

Allowed transitions:

```
Active    ──→ Suspended    (admin suspend)
Active    ──→ Closing      (closure request accepted)
Suspended ──→ Active       (reactivate)
Suspended ──→ Closing      (suspend → initiate closure)
Closing   ──→ Active       (restore during grace period)
Closing   ──→ Closed       (grace period expired / confirmed)
Closed    (terminal — no exits)
```

The state machine is a plain PHP class (not Eloquent-integrated). It validates transitions and throws `InvalidLifecycleTransitionException` on illegal moves. Callers are responsible for persisting `lifecycle_state` to the database.

**Public API (Faza 5.1 changes: W4/W6/W7):**
- `canTransition($from, $to): bool` — accepts enum or string; invalid string `$from` throws `\ValueError`
- `assertTransitionAllowed($from, $to): void` — throws `InvalidLifecycleTransitionException` on illegal transition (renamed from `transition()`)
- `transitions()` is now **private** — use `canTransition()` to probe allowed moves

### Schema additions (organizations table)

| Column | Type | Default | Notes |
|--------|------|---------|-------|
| `lifecycle_state` | string(20) | `'active'` | Indexed. Cast to `OrganizationLifecycleState`. |
| `closing_initiated_at` | timestamp nullable | null | Set when entering Closing state |
| `closed_at` | timestamp nullable | null | Set when entering Closed state |
| `purge_after` | timestamp nullable | null | Scheduled date for hard-delete (Faza 5.3) |
| `closure_requested_at` | timestamp nullable | null | Timestamp of tenant's self-service closure request |

### Authoritative truth principle

`lifecycle_state` is the authoritative field. `is_active` is now a derived signal:

- `is_active = true` ↔ `lifecycle_state = 'active'`
- `is_active = false` ↔ `lifecycle_state in ('suspended', 'closing', 'closed')`

The backfill migration (`2026_06_29_130000`) set initial values. `is_active` is now kept in sync automatically by `OrganizationObserver` (Faza 5.1). `ResolveTenant` middleware still reads `is_active`; changing it to use `lifecycle_state` directly is deferred to Faza 5.2.

### What is NOT in Faza 5.0

- No observers, no event dispatching, no policy guards
- No changes to `ResolveTenant` (still uses `is_active` — updated in Faza 5.2)
- No `SoftDeletes` trait on Organization (added in Faza 5.3 together with `deleted_at` column)
- No Filament lifecycle actions (those ship in Faza 5.1)

---

## Faza 5.1 — Guards + Observer + Platform Lifecycle Actions

### TenantObligationService

`app/Services/TenantObligationService.php`

Counts active obligations for a given Organization, bypassing the `BelongsToOrganization` global scope via `withoutGlobalScope('organization')`.

```php
$service->activeObligations(Organization $org): array
// Returns: ['appointments' => int, 'orders' => int, 'rentals' => int, 'total' => int]
// Counts: Pending+Confirmed appointments; pending_payment/paid/confirmed/in_progress orders;
//         Held/Pending/Confirmed/Active rentals

$service->hasActiveObligations(Organization $org): bool
// Returns true when total > 0
```

### OrganizationObserver

`app/Observers/OrganizationObserver.php` — registered in `AppServiceProvider::boot()`.

**`updating()` hook** (fires when lifecycle_state changes):
1. Calls `StateMachine::assertTransitionAllowed($from, $to)` — throws `InvalidLifecycleTransitionException` on illegal transitions
2. For `Closing` and `Closed` destinations: checks `TenantObligationService::hasActiveObligations()` unless `$org->forceLifecycleTransition === true` — throws `OrganizationHasActiveObligationsException`
3. Syncs `is_active` from `lifecycle_state` (F003): `$org->is_active = ($to === Active)`
4. Sets lifecycle timestamps (W8):
   - `→ Closing`: `closing_initiated_at = now()`
   - `→ Closed`: `closed_at = now()`
   - `Closing → Active`: `closing_initiated_at = null`, `purge_after = null`

**`deleting()` hook**:
- Blocked unless `$org->lifecycle_state === Closed` AND `!$org->hasActiveObligations()` OR `$org->bypassDeleteGuard === true`
- Throws `OrganizationHasActiveObligationsException` on violation

**Transient model flags** (not persisted — set before save, reset after):

```php
$org->forceLifecycleTransition = true;  // bypasses obligation check in updating()
$org->bypassDeleteGuard = true;         // bypasses all checks in deleting()
```

### F003 — is_active is now fully derived

`is_active` removed from `Organization::$fillable`. Setting it directly is a no-op (mass assignment guard). It is exclusively set by `OrganizationObserver::updating()` as a side-effect of lifecycle_state transitions.

**Never set `$org->is_active` directly** — change `lifecycle_state` instead.

### Platform Filament lifecycle actions (F004)

`app/Filament/Platform/Resources/OrganizationResource.php`

Replaced the `Toggle::make('is_active')` form field with:
- A read-only `Placeholder::make('lifecycle_state')` in the edit form
- A `lifecycle_state` SelectFilter + badge TextColumn in the table
- Three row `Action`s:

| Action | Label | Transition | Obligation check |
|--------|-------|-----------|-----------------|
| `suspend` | Zawieś | Active → Suspended | No |
| `reactivate` | Reaktywuj | Suspended/Closing → Active | No |
| `initiateClosing` | Zamknij | Active/Suspended → Closing | Yes (via `->before()`, then `forceLifecycleTransition = true`) |

The `initiateClosing` action checks obligations in its own `->before()` callback and halts with a Filament notification if any exist. When proceeding, it sets `forceLifecycleTransition = true` before saving to avoid the observer running the check a second time.

### Staff delete guard

`app/Filament/Resources/EmployeeResource.php`

`DeleteAction` and `DeleteBulkAction` are guarded by `hasFutureActiveAppointments(User $staff): bool` (private static method). Blocks deletion when staff has future `Pending` or `Confirmed` appointments. Uses `withoutGlobalScope('organization')` so it works cross-tenant for super-admin.

### Exceptions

| Class | Path | When thrown |
|-------|------|-------------|
| `InvalidLifecycleTransitionException` | `app/Exceptions/` | Illegal state transition |
| `OrganizationHasActiveObligationsException` | `app/Exceptions/` | Lifecycle/delete blocked by obligations |

### Tests

| Test class | Count | Covers |
|------------|-------|--------|
| `TenantObligationServiceTest` | 10 | Appointment/Order/Rental counts, cross-org isolation |
| `OrganizationObserverTest` | 13 | Transitions, obligations, is_active sync, timestamps, delete guard |
| `StaffDeleteGuardTest` | 7 | Staff delete guard (past/future, status filters, isolation) |
| `OrganizationLifecycleStateMachineTest` | 13 | Legal/illegal transitions, string API, W7 ValueError |

**Test patterns introduced:**
- `setLifecycleState(Organization $org, OrganizationLifecycleState $state)` helper — direct property assignment (lifecycle_state is fillable but updating() validation requires a prior save; helper sets it post-create)
- Shared `$this->vehicleType` in setUp to avoid VehicleTypeFactory unique slug collisions
- `Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web'])` in setUp (AppointmentObserver requires staff role)

---

## Notes for Future Phases

- **Faza 5.2**: Public route middleware — add `CheckOrganizationLifecycle` that checks `$org->lifecycle_state->allowsPublicSite()`. Update `ResolveTenant` to use `lifecycle_state` directly instead of `is_active`.
- **Faza 5.3**: Add `SoftDeletes` to Organization, schedule a `PurgeClosedOrganizationsJob` that runs nightly, checks `purge_after <= now()` and soft-deletes.
- **Faza 5.4**: Hard-delete job + GDPR purge of user PII after retention period.
