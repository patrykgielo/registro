---
name: project-registro-role-escalation-guard
description: Audit outcome for feature/user-role-escalation-guard (2026-08-07) — pre-emptive fix ahead of opening UserResource::canViewAny() to tenant admins
metadata:
  type: project
---

Registro's `super-admin` role is global (Spatie `teams => false`,
`config/permission.php:134`) and gates the entire `/platform` panel
(cross-tenant data) via `User::canAccessPanel()` — `hasRole('super-admin')`
only, no permission-based check. `UserResource`/`RoleResource::canViewAny()`
are currently `super-admin`-only, so today there is no reachable
non-super-admin path to either resource's Create/Edit pages at all (Filament
gates every resource page, not just the list, via
`mountCanAuthorizeResourceAccess()`).

Branch `feature/user-role-escalation-guard` adds `App\Support\RoleAssignmentGuard`
(single source of truth) + `App\Rules\AssignableRole` (UserResource `roles`
field) + `App\Rules\ProtectedRoleName` (RoleResource `name` field) +
`UserResource::getEloquentQuery()` scope hiding `super-admin` accounts from
non-super-admins — all *ahead of* a planned follow-up PR that will open
`canViewAny()` to `admin`. Audited empirically (tinker + full test suite,
16/16 green) on 2026-08-07:

- **Real gap found and confirmed harmless in practice:** `ProtectedRoleName`
  does exact-string comparison only (`super-admin` literal) — case variants
  (`Super-Admin`), whitespace-padded (`super-admin `), and cross-guard
  duplicates all pass the rule undetected. But this is *not* exploitable:
  same-guard case/whitespace variants are independently blocked by Spatie's
  own `Role::create()` existence check, which happens to be
  collation-dependent (MySQL `utf8mb4_unicode_ci` + PAD SPACE — this would
  NOT hold on SQLite, the test DB, which is case-sensitive by default — so
  this protection is invisible to the test suite). Cross-guard variants
  (`Super-Admin`/`api`) *can* be created, but `assignRole()` throws
  `GuardDoesNotMatch` when attaching an `api`-guard role to a `web`-guard
  `User`, and `hasRole('super-admin')` itself does exact-string `==`
  comparison — so even a successfully-created case-variant role never
  satisfies the literal check anywhere in the codebase. Net effect: a real
  code-hygiene gap (rule relies on an accident of MySQL collation + a
  separate Spatie guard exception, not its own logic) but zero live
  escalation path today.
- Editing an existing role's *permissions* (rather than its name) cannot
  reach `/platform` — verified no `Gate::define`/`Gate::before` or
  permission-based check exists anywhere for panel access; it's
  `hasRole('super-admin')` only.
- `UserResource::getEloquentQuery()` scope was verified (via Filament source,
  `HasRoutes::getRecordRouteBindingEloquentQuery()` →
  `getEloquentQuery()`, and `HasBulkActions::getSelectedTableRecordsQuery()`
  → `$table->getQuery()->whereKey(...)`) to cover list, direct-URL edit, and
  bulk actions uniformly — the scope is applied at the query-builder level
  before key filtering, so a spoofed `selectedTableRecords` array can't
  smuggle a hidden operator account into a bulk delete.
- `EmployeeResource`/`CustomerResource` have their own independent
  `getEloquentQuery()` overrides (both on the same `User` model) — confirmed
  Filament Resource static methods are per-class, not shared, so this PR's
  `UserResource` scope doesn't touch them.
- All non-UI role-granting call sites (`RegisterController`,
  `AssignCustomerRole`, `CreateOrganizationWithOwner`,
  `EmployeeResource`/`CustomerResource` create pages, `CreateOwnerCommand`)
  use hardcoded role names, confirmed via grep — no user-input role vector
  outside the two Resources this PR touches.

**How to apply:** When this repo's follow-up PR opens
`UserResource::canViewAny()`/`RoleResource::canViewAny()` to `admin`, re-audit
list of remaining open vectors documented in
`app/docs/security/patterns/role-escalation-guard.md` (search: "Other
role-granting call sites") — that doc is accurate as of this audit and
already lists what's explicitly out of scope. Also worth a small follow-up
fix (not blocking): make `ProtectedRoleName`/`RoleAssignmentGuard::canGrant()`
normalize (`trim` + case-fold) before comparing, so the protection doesn't
depend on MySQL's collation as an accidental second line of defense.

See `[[feedback-tinker-dev-db-safety]]` for a methodology note from this
audit.
