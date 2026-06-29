# Tenant Lifecycle — Workstream Overview

## Status: Faza 5.3b done (2026-06-30) — Tenant data export (Art. 28(3)(g) RODO)

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
| 5.3b | Tenant data export — full org data copy for owner (Art. 28(3)(g) RODO) | Done |
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
| Order | first_name→`Anonimizowane`, last_name→`Anonimizowane`, email→`anon_{id}@anonymized.local`, phone, PESEL, address fields, signatory_id, pickup_person_*, IP, rodo_accepted_ip, notes, company_contact_name, deposit_notes; for `customer_type=natural_person`: company_regon, company_krs (JDG REGON identifies the person) | order_number, amounts, dates, customer_type, invoice_* (NIP/KRS address), deposit_amount/status/timestamps, rodo_accepted_at, terms_accepted_at, p24_*; for `business`: company_regon, company_krs |
| Appointment | first_name→`Anonimizowane`, last_name→null, email→`anon_{id}@anonymized.local`, phone, location_address/lat/lng/components/place_id/service_location_type (CRITICAL — mobile service client address), registration_number (vehicle plate = PII per UODO), notes, cancellation_reason | invoice_*, amounts, dates, status |
| Rental | first_name→`Anonimizowane`, last_name→null, email→`anon_{id}@anonymized.local`, phone, notes, cancellation_reason | invoice_*, amounts, dates, status |
| Payment | webhook_payload→null | p24_session_id, p24_order_id, amount, currency, status |

**Implementation notes:**
- All updates use `DB::table()` (NOT Eloquent) to bypass Order's `booted() updating()` immutable guard that protects `rodo_accepted_ip` and accounting fields.
- `chunkById(500)` loop for per-row unique email placeholder.
- `customer_last_name` uses `'Anonimizowane'` placeholder (NOT NULL column in schema).
- For orders: `customer_type` is checked per row — `natural_person` gets `company_regon/krs` nulled; `business` retains them.
- Wrapped in `DB::transaction()`. Idempotent — safe to re-run.

### PurgeClosedOrganizationsCommand (`organizations:purge`)

`app/Console/Commands/PurgeClosedOrganizationsCommand`

Signature: `organizations:purge {--dry-run} {--force}`

Eligibility query: `lifecycle_state = closed AND purge_after <= now() AND deleted_at IS NULL` (SoftDeletes global scope auto-excludes already-purged orgs).

Per eligible org (in `DB::transaction`, `catch \Throwable → Log::error + continue to next org`):
1. `OrganizationAnonymizationService::anonymize($org)` — PII cleared (nested transaction via SAVEPOINT — safe)
2. Hard-delete ephemeral: `carts`, `analytics_events`, `statistics_daily_snapshots`
3. Legal records (orders, payments, tenant_payments) — **NOT deleted** (retain ≥6 yrs)
4. Soft-delete org: `$org->bypassDeleteGuard = true; $org->delete()`

Audit log: `Log::info` (start/completed with `failed` count), `Log::warning` (before each purge).
Dry-run: prints what would be purged (payment count uses `whereNotNull('webhook_payload')` for consistency with what actually anonymizes), makes zero changes.
Confirm gate: `isInteractive() && !--force → confirm('Continue?')`.
Failure behavior: one failing org logs error and `continue`s — the cohort is not blocked. Returns `FAILURE` at the end if `$failed > 0`.

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

`tests/Feature/Organizations/OrganizationPurgeTest` — 16 tests, 128 assertions.

Covers: PII cleared / accounting preserved (incl. deposit_notes, company_regon/krs per customer_type, location/registration for appointments, notes/cancellation_reason for appointments+rentals), business customer retains company_regon/krs, payment webhook_payload, idempotence, cross-org isolation (Org B untouched when anonymizing Org A), observer sets purge_after, observer does not overwrite existing purge_after, soft-delete exclusion from normal queries, soft-delete retrievable with `withTrashed()`, command processes eligible org, command skips future purge_after, command skips non-Closed, dry-run makes no changes.

**SQLite note:** `assertSame()` fails for decimal columns — SQLite returns numeric int (`500`), not string (`'500.00'`). All decimal assertions use `assertEquals()`.

---

## Faza 5.3a Follow-ups / DPO Review (dług techniczny)

These items were identified during 5.3a implementation but deferred — each requires either a DPO legal opinion or a scale-related architectural decision before proceeding.

### FU-1 — JDG REGON/KRS on invoices (DPO opinion needed)

**Status:** FIXME comment in `OrganizationAnonymizationService::anonymizeOrders()`.

**Problem:** For sole traders (JDG — jednoosobowa działalność gospodarcza), `customer_type = 'natural_person'` but the order may carry `invoice_company_name` (the trader's name, typically "Jan Kowalski") and `invoice_nip`. These are retained because of Art. 112 VAT obligation on invoice data.

**Open question for DPO:** After the Art. 112 retention period expires, is retention of JDG `invoice_company_name` / `invoice_nip` still proportionate (RODO art. 5(1)(c)), or should those also be anonymized? The current implementation retains them in all cases per a safe-default policy.

**Action:** DPO review → update `PRESERVED` comment in service if policy changes.

### FU-2 — `order_status_history.properties` (potential PII)

**Status:** Not anonymized (not in scope for 5.3a).

**Problem:** `order_status_history` stores a `properties` JSON column. Depending on application code, staff may log customer details (names, addresses) in status history entries when transitioning orders.

**Action:** Audit all callers that write `properties` to `order_status_history`. If PII is found, add `anonymizeOrderStatusHistory()` method to `OrganizationAnonymizationService` and clear `properties` (retain `from_status`, `to_status`, `created_at`, `user_id`).

### FU-3 — `customer_id` FK = pseudonymization, not anonymization

**Status:** By design (5.3a decision).

**Note:** Orders, appointments, rentals keep `customer_id` (FK to `users`). This is pseudonymization — the link to a real user row is preserved. True anonymization would require setting `customer_id = null` (requires making the column nullable first). Per 5.3a scope, this is acceptable because the `users` table is tenant-scoped and the user row is not deleted by the purge. **DPO should review** whether `customer_id` must be nulled for full Art. 17 compliance or whether pseudonymization is sufficient given the Art. 112 retention basis.

### FU-4 — `lazyById` for large-scale tenants

**Status:** `chunkById(500)` currently used (adequate for early production).

**Problem:** At scale (tenants with 10k+ orders), `chunkById` in a long-running transaction can cause lock contention or long GC pauses.

**Action:** When tenant P95 order count exceeds ~5,000, switch to `lazyById(500)` (PHP generator, no intermediate Collection allocation) and move purge to a dedicated `purge` queue so it doesn't block Horizon's default queue.

---

## Faza 5.3b — Tenant Data Export (Art. 28(3)(g) RODO)

### Legal Basis

Art. 28 ust. 3 lit. g RODO: the processor (Registro) must return all personal data to the controller (tenant/organization owner) upon termination of processing services. Art. 12 ust. 3 RODO: deadline for responding to such requests is 1 month.

The 30-day signed URL validity maps directly to the 1-month RODO deadline.

### Architecture

**Service:** `app/Services/Lifecycle/OrganizationDataExportService.php`

- Method `generate(Organization $org): string` — returns relative path on disk `local`
- Uses `DB::table()` with `chunk(500)` for each dataset (bypasses Eloquent global scopes; all queries include explicit `WHERE organization_id = ?`)
- Writes streaming JSON (array format, one row per line) + CSV (UTF-8 BOM, semicolons) to temp files, then bundles them into a ZIP via `ZipArchive`
- ZIP path: `storage/app/exports/org-{id}/{Ymd_His}.zip` — disk `local` (PRIVATE, not `public`)
- ZIP contents: `manifest.json` + `{dataset}.json` + `{dataset}.csv` for: `orders`, `appointments`, `rentals`, `payments`, `tenant_payments`, `settings`
- Temp files are deleted after `$zip->close()` (in `finally` block)

**CRITICAL: disk `local` only.** Export data contains full customer PII (names, emails, PESEL, addresses). It MUST NOT be placed on disk `public`. The `FILESYSTEM_DISK=public` global rule applies only to user-facing uploads; export files use the private local disk explicitly.

**Controller:** `app/Http/Controllers/Platform/OrganizationDataExportController.php`

- `download(Request $request, Organization $organization): StreamedResponse`
- Authorization: either valid signed URL (`$request->hasValidSignature()`) OR authenticated super-admin (`$user->hasRole('super-admin')`)
- Defense-in-depth: validates `file` query param starts with `exports/org-{org->id}/` and contains no `..`
- Uses `Storage::disk('local')->download($filePath, ...)` to stream ZIP without loading into memory

**Route:** `GET /platform/organizations/{organization}/data-export`
Named: `platform.organization.data-export`
No auth middleware — signed URL is the authorization mechanism (owner may not have a login session).
The `/platform/` prefix is explicitly excluded from the CMS catch-all route `/{slug}`.

**Signed URL Generation:**
```php
URL::temporarySignedRoute(
    'platform.organization.data-export',
    now()->addDays(30),
    ['organization' => $org->id, 'file' => $relativePath]
)
```
The `file` parameter is included in the signature — cannot be tampered without invalidating the signature.

**Notification:** `app/Notifications/OrganizationDataExportReadyNotification.php`

- `implements ShouldQueue`, `onQueue('emails')`, `via=['mail']`
- Constructor: `(string $downloadUrl, string $organizationName)`
- Email: PL, MailMessage with `->action('Pobierz dane firmy', $url)`, mentions art. 28(3)(g) RODO, 30-day link validity
- Sent to `$org->owner`

**Command:** `app/Console/Commands/ExportOrganizationDataCommand.php`

```bash
php artisan organizations:export-data {organization}   # ID or slug
```

- Resolves org (ctype_digit → by ID, else → by slug)
- Calls `OrganizationDataExportService::generate($org)` → gets relative path
- Generates signed URL (30 days) → sends `OrganizationDataExportReadyNotification` to `$org->owner`
- Audit log: `Log::info(start)` + `Log::info(completed)` (includes path, owner email, expiry)
- Outputs the direct URL to stdout (for super-admin use)
- Returns `Command::FAILURE` if org not found or owner is null

### Security Notes

- Export files are on disk `local` (not `public`) → not served by webserver directly
- Signed URLs: HMAC-based, include expiry timestamp; tampered params invalidate signature
- Path traversal defense: `str_contains($filePath, '..')` check even for signed requests
- Cross-org isolation: file path must start with `exports/org-{org->id}/` (enforced in controller)
- Super-admin bypass: allows platform ops to re-download exports without re-generating signed URL

### Tests

`tests/Feature/Organizations/OrganizationDataExportTest.php` (12 test cases):
- Service: ZIP structure, manifest metadata, cross-tenant isolation, path scoping
- Route: valid signed URL → 200, expired → 403, tampered → 403, unauthenticated no-sig → 403, regular user → 403, super-admin → 200, missing file → 404, path traversal → 403, cross-org path → 403
- Command: generates file + sends notification, accepts slug, fails on unknown org

---

## Notes for Future Phases

- **Faza 5.4**: Hard-delete legal records (orders/payments) after `legal_records_years` (6) from `closed_at`. Requires `closed_at + 6yr <= now()` check. FK RESTRICT will be dropped for these tables before hard-delete.
