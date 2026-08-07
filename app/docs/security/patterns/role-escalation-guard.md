# Role Escalation Guard (UserResource / RoleResource)

**Branch:** `feature/user-role-escalation-guard` (2026-08-07)
**Status:** Fixed — pre-emptive hardening ahead of a follow-up PR that opens
`UserResource::canViewAny()` to the `admin` role.

## The vector

`UserResource`'s `roles` field (`app/Filament/Resources/UserResource.php`) is a
`multiple()` Select using `->relationship('roles', 'name')`. The moment
`canViewAny()` opens to tenant admins, this becomes: Właścicielka tenanta →
Użytkownicy → własny profil → dodaje sobie rolę `super-admin` →
`User::canAccessPanel()` przepuszcza do `/platform` → widzi/kasuje organizacje
innych klientów. Two clicks, zero exploit code.

Root cause was `->options(Role::all()->pluck('name', 'id'))` — an unfiltered
list explicitly overriding the field's own relationship-aware options.

## Why the UI filter alone is not enough

`Select::relationship()` internally does:

```php
$this->options(static fn (Select $c) => $c->getOptionsFromRelationship());
$this->dehydrated(fn (Select $c) => (! $c->isMultiple()) && $c->isSaved());
```

For a `multiple()` field, `dehydrated()` always evaluates to `false`. That
means the field's submitted value **never reaches** `$data` in
`mutateFormDataBeforeCreate()`/`mutateFormDataBeforeSave()`, and
`handleRecordCreation()`/`handleRecordUpdate()` never see it either — the
pivot sync happens separately, after record creation/update, via
`$this->form->model($record)->saveRelationships()`, which reads the
component's own retained state directly.

Consequently, filtering `->options()` only changes what a *well-behaved
browser* offers to render — it does nothing to a hand-crafted Livewire
`data.roles` payload sent straight to `/livewire/update`. A `->rule()`
attached to the field is the only enforcement point that actually runs: it's
evaluated inside `$this->form->getState()`, on every `create()`/`save()`
call, against whatever state the client submitted, using the
server-authenticated `auth()->user()` — not anything the client controls.

## The fix

Centralized in `App\Support\RoleAssignmentGuard` (single source of truth, used
by three call sites so the options filter and the validators can't drift
apart the way the original bug happened):

```php
class RoleAssignmentGuard
{
    public const PROTECTED_ROLE = 'super-admin';

    public static function canGrant(string $roleName, ?User $actor = null): bool { ... }
    public static function assignableRolesQuery(?User $actor = null): Builder { ... }
}
```

- `UserResource`'s `roles` Select: `->options(fn () => RoleAssignmentGuard::assignableRolesQuery()->pluck('name', 'id')->all())->rule(new AssignableRole)`
- `App\Rules\AssignableRole` — validates the array of submitted role IDs against `RoleAssignmentGuard::canGrant()`.

## Neighboring vector: RoleResource

`app/Filament/Resources/RoleResource.php` (tenant `/admin` panel) lets
admins create/edit Spatie roles, including their literal `name`. Spatie
resolves roles **by name**, not by a fixed ID — so once this resource opens
to non-super-admins, either of these fully bypasses `AssignableRole` without
ever touching `UserResource`:

1. Rename any role the attacker already holds to `super-admin` — every user
   holding that role (including the attacker) becomes a de-facto
   super-admin at the next `hasRole()` check.
2. Create a brand-new role named `super-admin` (only blocked if the real one
   still exists, by the `unique(ignoreRecord: true)` rule on `name` — not a
   security control, just a side effect).

Fixed the same way: `App\Rules\ProtectedRoleName` on the `name` TextInput,
reusing `RoleAssignmentGuard::canGrant()`.

`app/Filament/Platform/Resources/RoleResource.php` (the `/platform` panel
copy) was checked and left unmodified — `/platform` access itself requires
`hasRole('super-admin')` (`User::canAccessPanel()`), so there is no
reachable non-super-admin path there today.

## The mirror gap: stripping, not granting

`AssignableRole` guards one direction. The opposite one needs **no attacker at
all**, and it fires during ordinary use.

Because `->options()` omits `super-admin` for a non-super-admin actor, opening
the operator's account as a tenant admin leaves that role out of the rendered
picker — and therefore out of the submitted form state. Saving *any* unrelated
change on that account, a corrected phone number included, syncs the roles
relationship to the reduced set and **silently strips `super-admin`**. Nobody
can reach `/platform` afterwards, because reaching it requires the role that
just vanished; recovery is `registro:create-owner` from the CLI.

Fixed in `UserResource::getEloquentQuery()`: accounts holding the protected
role are removed from the query for anyone who cannot grant it. One filter
closes granting, stripping and accidental edits together — a validation rule
could reject the save, but it would then reject *every* save on that account,
which is fail-closed and useless rather than fail-closed and correct.

The scope also fails closed with no authenticated actor (`auth()->user()`
null → cannot grant → protected accounts hidden).

Covered by `tests/Feature/Filament/UserResourceProtectedAccountScopeTest.php`.

## Still open — the follow-up PR must not merge without these

This change closes escalation through the role picker and the role name. It does
**not** make `UserResource`/`RoleResource` safe to open. Both gaps below are
harmless today only because `canViewAny()` still requires `super-admin`.

**1. `UserResource` has no organization scoping.** `User` carries no
`BelongsToOrganization` (`BaseResource::isScopedToTenant()` says so explicitly),
so nothing scopes it automatically — each resource querying `User` must do it
itself. `EmployeeResource:40-50` and `CustomerResource:36-54` both do;
`UserResource::getEloquentQuery()` filters only the protected role. The moment
`canViewAny()` opens to `admin`, every tenant admin sees, edits and deletes every
non-super-admin account on the platform — other tenants' owners and staff
included. Straight cross-tenant leak.

**2. `RoleResource`'s `permissions` CheckboxList is unguarded, and roles are
global** (`config/permission.php:134` → `'teams' => false`): one `admin` row, one
`staff` row, shared by every organization. A tenant admin ticking permissions on
the shared `admin` role changes them for every admin on every tenant. This is
**not** a path to `/platform` — `User::canAccessPanel()` checks the role name, never
a permission, and that was verified — so it is shared-state mutation rather than
privilege escalation. Its practical reach today is the three permissions actually
checked in code (`communication.manage_templates`, `.view_logs`,
`.manage_suppressions`).

**3. Neither resource overrides `canCreate()`/`canEdit()`/`canDelete()`, and there
are no policies.** Filament defaults to allow when no policy exists and strict
mode is off, so `DeleteBulkAction` on `RoleResource` would let a tenant admin
delete the shared `admin`/`staff` rows outright — `model_has_roles` cascades, so
every tenant's role checks break at once.

## Other role-granting call sites (checked, not in scope)

`grep -rn "assignRole\|syncRoles" app/` turns up only internal, hardcoded-role
call sites (`RegisterController::assignRole('customer')`,
`AssignCustomerRole` listener, `CreateOrganizationWithOwner::assignRole('admin')`,
`EmployeeResource`/`CustomerResource` create pages, `CreateOwnerCommand`).
None take a role name from user input — they're trusted internal code paths,
not a system boundary, so they're out of scope for this guard (see
`.claude/rules/self-improvement.md`'s "validate at system boundaries").

## Testing gotcha: `canViewAny()` gates every resource page, not just the list

`Filament\Resources\Pages\Concerns\CanAuthorizeResourceAccess::mountCanAuthorizeResourceAccess()`
runs `abort_unless(Resource::canAccess(), 403)` — where `canAccess() === canViewAny()`
— on **every** resource page's `mount()` (Create, Edit, List), not just the
index. This fires during Livewire component mount itself, not HTTP
middleware, so it also fires under `Livewire::test()` even though Livewire's
test harness disables middleware.

Because `UserResource::canViewAny()`/`RoleResource::canViewAny()` are
currently `super-admin`-only, `Livewire::test(CreateUser::class)` (or
`CreateRole`) as a plain `admin` actor never mounts — `instance()` comes back
`null`, and the response is a genuine 403 (Livewire's `RequestBroker`
explicitly excludes `HttpException`/`AuthorizationException` from the
exceptions it suppresses during initial render, so the abort is rendered as a
real HTTP response instead of being swallowed silently).

**There is currently no live request path — Livewire or otherwise — that a
non-super-admin can drive against these pages.** The escalation tests for
non-privileged actors therefore call the `ValidationRule` objects directly
(`(new AssignableRole)->validate($attribute, $value, $fail)` with
`actingAs($tenantAdmin)`) — the exact object Filament's `$this->form->getState()`
invokes — rather than going through `Livewire::test()`. This needs no rewrite
once the follow-up PR opens `canViewAny()` to `admin`, since it never
depended on that gate in the first place. Super-admin positive controls
(who *can* pass `canViewAny()` today) still exercise the full `Livewire::test()`
create/save flow end-to-end.

See `tests/Feature/Filament/UserRoleEscalationGuardTest.php`,
`tests/Feature/Filament/RoleResourceEscalationGuardTest.php`,
`tests/Feature/Support/RoleAssignmentGuardTest.php`.
