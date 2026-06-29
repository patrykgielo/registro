# Tenant Lifecycle — Workstream Overview

## Status: Faza 5.1 merged + code-review hardening (2026-06-29)

Workstream introduces an explicit lifecycle state for Organization, replacing the implicit boolean `is_active` as the authoritative source of truth for tenant health. The migration is additive and non-breaking — `is_active` is now a fully derived field, synced automatically from `lifecycle_state` by the observer.

---

## Phases

| Faza | Zakres | Status |
|------|--------|--------|
| 5.0 | Enum + state machine + schema + backfill | Done |
| 5.1 | Guards + observer (is_active sync, obligation checks, delete guard) + Platform lifecycle actions | Done |
| 5.1-cr | Code-review hardening (see below) | Done |
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

**Public API:**
- `canTransition($from, $to): bool` — accepts enum or string; invalid string `$from` throws `\ValueError`
- `assertTransitionAllowed($from, $to): void` — throws `InvalidLifecycleTransitionException` on illegal transition
- `transitions()` is **private** — use `canTransition()` to probe allowed moves

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

---

## Faza 5.1 — Guards + Observer + Platform Lifecycle Actions

### TenantObligationService

`app/Services/TenantObligationService.php`

Counts **active (in-flight)** obligations for a given Organization, bypassing the `BelongsToOrganization` global scope via `withoutGlobalScope('organization')`.

```php
$service->activeObligations(Organization $org): array
// Returns: ['appointments' => int, 'orders' => int, 'rentals' => int, 'total' => int]

$service->hasActiveObligations(Organization $org): bool
// Returns true when total > 0
```

**Obligation definitions (what counts as in-flight):**

| Domain | In-flight states | Notes |
|--------|-----------------|-------|
| Appointments | `pending`, `confirmed` | cancelled/completed are terminal |
| Orders | `pending_payment`, `paid`, `confirmed`, `in_progress` | `completed` is terminal — does NOT block closure |
| Rentals | `held`, `pending`, `confirmed`, `active` | returned/cancelled/expired are terminal |

**CRITICAL:** `completed` order does NOT block closure. A completed order is a finished transaction.
Only in-flight orders (pending_payment → in_progress) block the org from being wound down.

### OrganizationObserver

`app/Observers/OrganizationObserver.php` — registered in `AppServiceProvider::boot()`.

**`creating()` hook:**
- Derives `is_active` from `lifecycle_state` on INSERT. Defaults to Active if not set.

**`updating()` hook** (fires when lifecycle_state changes):
1. Calls `StateMachine::assertTransitionAllowed($from, $to)` — throws `InvalidLifecycleTransitionException` on illegal transitions
2. For `Closing` and `Closed` destinations (and `!$org->forceLifecycleTransition`): calls `activeObligations()` once and throws `OrganizationHasActiveObligationsException` if total > 0
3. Syncs `is_active` from `lifecycle_state` (F003)
4. Sets lifecycle timestamps (W8)

**`updated()` hook:**
- Resets `$org->forceLifecycleTransition = false` after every successful save. Prevents flag from leaking when the same model instance is reused.

**`deleting()` hook** — guards (in order):
1. `bypassDeleteGuard = true` → skip all checks
2. `lifecycle_state !== Closed` → throw `OrganizationNotClosedException`
3. Active obligations exist → throw `OrganizationHasActiveObligationsException`

**`deleted()` hook:**
- Resets `$org->bypassDeleteGuard = false` after deletion.

**Transient model flags** (`app/Models/Organization.php`, not persisted):

```php
$org->forceLifecycleTransition = true;  // bypass obligation check in updating(); reset by updated()
$org->bypassDeleteGuard = true;         // bypass all delete checks; reset by deleted()
```

### F003 — lifecycle_state is NOT in $fillable

`lifecycle_state` was removed from `Organization::$fillable` (code-review hardening).

- Setting it via mass-assignment (`Organization::create(['lifecycle_state' => ...])`) is a no-op.
- **On create:** set directly on the model instance before `save()` — observer `creating()` picks it up.
- **On update:** set directly (`$org->lifecycle_state = State::Foo; $org->save()`) — observer `updating()` fires.
- **In factories:** use `->afterMaking(fn ($o) => $o->lifecycle_state = State)` or named factory states.

**Factory states available:**

```php
Organization::factory()->create()          // Active (default)
Organization::factory()->inactive()->create()  // Suspended
Organization::factory()->closing()->create()   // Closing
Organization::factory()->closed()->create()    // Closed
```

**Never do:** `Organization::create(['lifecycle_state' => 'closed'])` — lifecycle_state not fillable.

**Model default attribute (Faza 5.1-cr2, 2026-06-29):**

`Organization` now declares `protected $attributes = ['lifecycle_state' => 'active']` to mirror the DB column default. This ensures `getOriginal('lifecycle_state')` is never `null` on a freshly inserted model instance, which prevented `OrganizationObserver::updating()` from deriving a valid `$from` state when the observer was called immediately after `factory()->create()` (no lifecycle state set explicitly). Without this default, `syncOriginal()` after INSERT copied `null` for `lifecycle_state`, causing a `TypeError` in `assertTransitionAllowed()`.

**Observer null-safe guard (defense-in-depth):**

`OrganizationObserver::updating()` derives `$from` via `match(true)` with a `default → Active` arm, so that even if `getOriginal('lifecycle_state')` returns `null` (e.g., via `forceFill` bypassing the default), the guard falls back to `Active` rather than crashing.

### Platform Filament lifecycle actions (F004)

`app/Filament/Platform/Resources/OrganizationResource.php`

Replaced the `Toggle::make('is_active')` form field with:
- A read-only `Placeholder::make('lifecycle_state')` in the edit form
- A `lifecycle_state` SelectFilter + badge TextColumn in the table
- Three row `Action`s (all visible only to super-admin):

| Action | Label | Transition | Obligation check |
|--------|-------|-----------|-----------------|
| `suspend` | Zawieś | Active → Suspended | No |
| `reactivate` | Reaktywuj | Suspended/Closing → Active | No |
| `initiateClosing` | Zamknij | Active/Suspended → Closing | Yes (via `->before()`, then `forceLifecycleTransition = true`) |

**Delete guard in Filament:**
- `EditOrganization::DeleteAction::before()`: checks lifecycle_state === Closed first, then checks obligations
- `OrganizationResource::DeleteBulkAction::before()`: per-record, checks lifecycle_state then obligations
- Both halt with Notification on violation — no 500 errors from observer throwing into unhandled context.

**Authorization (defense-in-depth):**
- `OrganizationResource::canViewAny()` and `canDelete()` require `super-admin` role
- Lifecycle action `->visible()` callbacks also check `auth()->user()?->hasRole('super-admin')`

### Staff delete guard

`app/Filament/Resources/EmployeeResource.php`

`DeleteAction` and `DeleteBulkAction` guarded by `hasFutureActiveAppointments(User $staff)`:
- When `TenantFeature::currentTenant() === null`, returns `false` immediately (no cross-tenant leak)
- Requires explicit `organization_id` scope (no `->when($tenant, ...)` which would silently scan all orgs if tenant is null)

**Testing `hasFutureActiveAppointments()` in isolation:**

The method reads `TenantFeature::currentTenant()`, which resolves from Filament context → request attributes → session. Tests must set the tenant on the request before invoking the method:

```php
$this->app['request']->attributes->set('tenant', $org);
```

Without this, `currentTenant()` returns `null` and the guard immediately returns `false`, masking any business-logic assertions.

### Exceptions

| Class | Path | When thrown |
|-------|------|-------------|
| `InvalidLifecycleTransitionException` | `app/Exceptions/` | Illegal state transition |
| `OrganizationNotClosedException` | `app/Exceptions/` | Delete attempted when lifecycle_state !== Closed |
| `OrganizationHasActiveObligationsException` | `app/Exceptions/` | Lifecycle/delete blocked by in-flight obligations |

### Tests

| Test class | Count | Covers |
|------------|-------|--------|
| `TenantObligationServiceTest` | 12 | Appointment/Order/Rental counts, completed order excluded, cross-org isolation |
| `OrganizationObserverTest` | 17 | Transitions, obligations, is_active sync, timestamps, delete guard, force flag reset |
| `StaffDeleteGuardTest` | 7 | Staff delete guard (past/future, status filters, isolation) |
| `OrganizationLifecycleStateMachineTest` | 13 | Legal/illegal transitions, string API, W7 ValueError |
| `OrganizationLifecycleCastTest` | 4 | Enum cast, factory states |
| `ResolveTenantTest` | 7 | Middleware: active/inactive/unknown tenants |

**Test patterns:**
- Factory states (`->inactive()`, `->closing()`, `->closed()`) instead of `create(['lifecycle_state' => ...])`
- `setLifecycleState()` helper — sets attribute directly on model, triggers `updating()` observer
- `Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web'])` in setUp
- Shared `$this->vehicleType` in setUp to avoid VehicleType unique slug collisions

---

## Faza 5.2 Acceptance Criteria (Planned)

- `ResolveTenant.php` updated to check `lifecycle_state->allowsPublicSite()` instead of `is_active`
- `CheckOrganizationLifecycle` middleware added for public routes (booking, rental catalogue)
- `is_active` remains for backward compatibility (derived, kept in sync by observer)
- All `ResolveTenantTest` tests updated to use `lifecycle_state`-based assertions

---

## Notes for Future Phases

- **Faza 5.2**: Public route middleware — add `CheckOrganizationLifecycle` that checks `$org->lifecycle_state->allowsPublicSite()`. Update `ResolveTenant` to use `lifecycle_state` directly instead of `is_active`.
- **Faza 5.3**: Add `SoftDeletes` to Organization, schedule a `PurgeClosedOrganizationsJob` that runs nightly, checks `purge_after <= now()` and soft-deletes.
- **Faza 5.4**: Hard-delete job + GDPR purge of user PII after retention period.
