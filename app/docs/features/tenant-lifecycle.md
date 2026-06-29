# Tenant Lifecycle — Workstream Overview

## Status: Faza 5.0 merged (fundament schema)

Workstream introduces an explicit lifecycle state for Organization, replacing the implicit boolean `is_active` as the authoritative source of truth for tenant health. The migration is additive and non-breaking — `is_active` remains and is preserved for backward compatibility until all consumers are migrated.

---

## Phases

| Faza | Zakres | Status |
|------|--------|--------|
| 5.0 | Enum + state machine + schema + backfill | Done (this doc) |
| 5.1 | Guards: block public site / new bookings based on lifecycle_state | Planned |
| 5.2 | Filament platform actions: Suspend / Reactivate / Initiate Closing | Planned |
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

The backfill migration (`2026_06_29_130000`) set initial values. Faza 5.1 will sync `is_active` from `lifecycle_state` after each transition, at which point `is_active` becomes fully derived and `ResolveTenant` middleware can be updated.

### What is NOT in Faza 5.0

- No observers, no event dispatching, no policy guards
- No changes to `ResolveTenant` (still uses `is_active`)
- No `SoftDeletes` trait on Organization (added in Faza 5.3 together with `deleted_at` column)
- No Filament actions for lifecycle management (Faza 5.2)

---

## Notes for Future Phases

- **Faza 5.1**: Add guard in public route middleware that checks `$org->lifecycle_state->allowsPublicSite()`. Update `ResolveTenant` or a new `CheckOrganizationLifecycle` middleware.
- **Faza 5.2**: Platform panel actions — `SuspendOrganizationAction`, `ReactivateOrganizationAction`, `InitiateClosingAction`. Each calls `OrganizationLifecycleStateMachine::transition()` then saves.
- **Faza 5.3**: Add `SoftDeletes` to Organization, schedule a `PurgeClosedOrganizationsJob` that runs nightly, checks `purge_after <= now()` and soft-deletes.
- **Faza 5.4**: Hard-delete job + GDPR purge of user PII after retention period.
