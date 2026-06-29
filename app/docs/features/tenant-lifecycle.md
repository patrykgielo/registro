# Tenant Lifecycle — Workstream Overview

## Status: Faza 5.3a done (2026-06-30) — SoftDeletes + PII anonymization + purge command

Workstream introduces an explicit lifecycle state for Organization, replacing the implicit boolean `is_active` as the authoritative source of truth for tenant health. The migration is additive and non-breaking — `is_active` is now a fully derived field, synced automatically from `lifecycle_state` by the observer.

---

## Phases

| Faza | Zakres | Status |
|------|--------|--------|
| 5.0 | Enum + state machine + schema + backfill | Done |
| 5.1 | Guards + observer (is_active sync, obligation checks, delete guard) + Platform lifecycle actions | Done |
| 5.1-cr | Code-review hardening | Done |
| 5.2 | FK onDelete policy + public site middleware | Done |
| 5.3a | SoftDeletes on Organization + PII anonymization + purge command | Done |
| 5.3b | Offboarding email / grace-period notifications | Planned |
| 5.4 | Hard-delete legal records after retention period (6 yrs) | Planned |

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

## Faza 5.2 — FK backstop + lifecycle resolution (Done)

DB-level enforcement behind the 5.1 application guards, plus the lifecycle authority switch.

**FK onDelete policy** (migration `2026_06_30_000001_fix_lifecycle_fk_constraints.php`):

| Category | Tables (`organization_id` unless noted) | onDelete | Why |
|---|---|---|---|
| Legal records | `orders`, `payments`, `tenant_payments`, `rentals` | **restrict** | Must survive org deletion — retain ≥5–6 yrs (Art. 112 VAT / Art. 70 Ordynacja). Last-resort backstop behind the observer guard. |
| Staff link | `appointments.staff_id` (was NOT NULL cascade) | **nullable + nullOnDelete** | Deleting a staff member preserves historical appointments (`staff_id = null`). Aligns with the 5.1 guard that blocks only *future* appointments. |
| Ephemeral | `carts`, `statistics_daily_snapshots`; `analytics_events` already null | cascade / null (unchanged) | OK to drop with the org. |

`down()` leaves `staff_id` nullable on rollback (restoring NOT NULL would fail if any staff was deleted while applied; nullable is a safe superset).

**Observer legal-records guard:** `OrganizationObserver::deleting()` throws `OrganizationHasLegalRecordsException` when the org still has any order/payment/rental/tenant_payment — a human-readable error *before* the DB RESTRICT FK fires. `bypassDeleteGuard = true` skips the app guard, leaving the FK as the final backstop (for the Faza 5.3 purge tool, used only after records are anonymised/archived).

**ResolveTenant:** resolution gates on `lifecycle_state = Active` (authoritative) instead of `is_active`. `is_active` stays as a derived column (kept in sync by the observer) for the platform panel column/filter. Cache key `tenant:slug:{slug}` (300 s TTL) unchanged.

**5.1 follow-ups closed here:** `forceLifecycleTransition` reset moved to `saved()` (fires on no-op saves too); `->authorize(super-admin)` on Suspend/Reactivate/InitiateClosing; `canDelete()` hides delete for non-Closed orgs; `closed_at` timestamp test added.

---

## Faza 5.3a — SoftDeletes + PII Anonymization + Purge Command

### SoftDeletes on Organization

`database/migrations/2026_06_30_100001_add_soft_deletes_to_organizations.php` adds `deleted_at` (nullable timestamp) to `organizations`.

`Organization` model gains `use SoftDeletes`. Behavioral change:
- `$org->delete()` = `UPDATE organizations SET deleted_at = now()` (NOT a hard DELETE)
- `Organization::all()` / `find()` / `where()` automatically exclude soft-deleted rows
- FK `RESTRICT` onDelete does **not** fire on soft-delete (it's an UPDATE, not DELETE) — the FK remains the backstop for future hard-delete (Faza 5.4)
- `Organization::withTrashed()->find($id)` retrieves soft-deleted rows
- `$org->bypassDeleteGuard = true; $org->delete()` — still a soft-delete after this migration; `forceDelete()` would be the hard-delete (reserved for Faza 5.4)

`OrganizationObserver::deleting()` guards against accidental deletion; `bypassDeleteGuard = true` bypasses the app guard (FK remains). `deleted()` resets the flag.

### config/retention.php

`config/retention.php` centralizes all retention periods with legal basis:

| Key | Value | Legal basis |
|---|---|---|
| `legal_records_years` | 6 | Art. 112 VAT / Art. 70 Ordynacja Podatkowa — invoices/payments |
| `claims_b2c_years` | 6 | KC art. 118 — B2C claim limitation |
| `claims_b2b_years` | 3 | KC art. 118 — B2B claim limitation |
| `purge_grace_days` | 30 | Offboarding grace window (Closing → Closed → purge_after) |
| `analytics_months` | 13 | Ephemeral analytics data |
| `carts_days` | 7 | Abandoned cart retention |
| `statistics_days` | 365 | Statistics snapshots |

All commands and observers read from this config — no magic numbers.

### purge_after set automatically on Closed transition

`OrganizationObserver::updating()` when `$to === Closed`: sets `purge_after = now()->addDays(config('retention.purge_grace_days'))` if not already set. This ensures every org that reaches `Closed` has a deterministic purge window, even without an explicit offboarding flow.

### OrganizationAnonymizationService

`app/Services/Lifecycle/OrganizationAnonymizationService`

Method `anonymize(Organization $org): array` — returns `['orders' => int, 'appointments' => int, 'rentals' => int, 'payments' => int]`.

**PII vs accounting distinction (RODO art. 5(1)(e) + Art. 112 VAT):**

| Model | ANONYMIZED (PII) | PRESERVED (accounting/legal) |
|---|---|---|
| Order | first_name→`Anonimizowane`, last_name→`Anonimizowane`, email→`anon_{id}@anonymized.local`, phone, PESEL, address fields, signatory_id, pickup_person_*, IP, rodo_accepted_ip, notes, company_contact_name | order_number, amounts, dates, customer_type, invoice_* (NIP/REGON/KRS/address), rodo_accepted_at, terms_accepted_at, p24_* |
| Appointment | first_name→`Anonimizowane`, last_name→null, email→`anon_{id}@anonymized.local`, phone | invoice_*, amounts, dates, status |
| Rental | first_name→`Anonimizowane`, last_name→null, email→`anon_{id}@anonymized.local`, phone | invoice_*, amounts, dates, status |
| Payment | webhook_payload→null | p24_session_id, p24_order_id, amount, currency, status |

**Implementation notes:**
- All updates use `DB::table()` (NOT Eloquent) to bypass Order's `booted() updating()` immutable guard that protects `rodo_accepted_ip` and accounting fields.
- `chunkById(500)` loop for per-row unique email placeholder.
- `customer_last_name` uses `'Anonimizowane'` placeholder (NOT NULL column in schema).
- Wrapped in `DB::transaction()`. Idempotent — safe to re-run.

### PurgeClosedOrganizationsCommand (`organizations:purge`)

`app/Console/Commands/PurgeClosedOrganizationsCommand`

Signature: `organizations:purge {--dry-run} {--force}`

Eligibility query: `lifecycle_state = closed AND purge_after <= now() AND deleted_at IS NULL` (SoftDeletes global scope auto-excludes already-purged orgs).

Per eligible org (in `DB::transaction`, `catch \Throwable → Log::error + FAILURE`):
1. `OrganizationAnonymizationService::anonymize($org)` — PII cleared
2. Hard-delete ephemeral: `carts`, `analytics_events`, `statistics_daily_snapshots`
3. Legal records (orders, payments, tenant_payments) — **NOT deleted** (retain ≥6 yrs)
4. Soft-delete org: `$org->bypassDeleteGuard = true; $org->delete()`

Audit log: `Log::info` (start/completed), `Log::warning` (before each purge).
Dry-run: prints what would be purged, makes zero changes.
Confirm gate: `isInteractive() && !--force → confirm('Continue?')`.

FUTURE (Faza 5.4): hard-delete legal records after `legal_records_years` — not implemented here.

### Schedule

`routes/console.php`:
```php
Schedule::command('organizations:purge --force')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->name('organizations:purge')
    ->onOneServer();
```

### Tests

`tests/Feature/Organizations/OrganizationPurgeTest` — 14 tests, 69 assertions.

Covers: PII cleared / accounting preserved, payment webhook_payload, appointment PII, rental PII, idempotence, observer sets purge_after, observer does not overwrite existing purge_after, soft-delete exclusion from normal queries, soft-delete retrievable with `withTrashed()`, command processes eligible org, command skips future purge_after, command skips non-Closed, dry-run makes no changes.

**SQLite note:** `assertSame()` fails for decimal columns — SQLite returns numeric int (`500`), not string (`'500.00'`). All decimal assertions use `assertEquals()`.

---

## Notes for Future Phases

- **Faza 5.3b**: Offboarding email sent when org transitions to `Closing`; reminder before `purge_after`.
- **Faza 5.4**: Hard-delete legal records (orders/payments) after `legal_records_years` (6) from `closed_at`. Requires `closed_at + 6yr <= now()` check. FK RESTRICT will be dropped for these tables before hard-delete.
