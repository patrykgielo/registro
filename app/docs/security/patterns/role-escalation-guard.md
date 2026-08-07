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

## Resolved in feature/tenant-admin-access (2026-08-07)

The three gaps below were closed (or deliberately left closed) in the follow-up
PR that opened access to tenant admins. Kept here as the historical record of
what the original "still open" list meant and how each item was actually
resolved — see also `app/docs/security/vulnerabilities/` style write-ups and
the per-resource docblocks referenced below for the live source of truth.

**1. `UserResource` organization scoping — FIXED.** `UserResource::getEloquentQuery()`
now mirrors `EmployeeResource`/`CustomerResource`'s manual
`whereHas('organizations', ...)` pattern, nested inside the same
`RoleAssignmentGuard::canGrant()` check the protected-role filter already used
(scoped on the *actor's* privilege, not merely tenant presence, so a
super-admin browsing via any tenant's `/admin` subdomain keeps today's
platform-wide reach — the only interface that offers it). `canViewAny()` and
`canCreate()` opened to `['super-admin', 'admin']`; `canDelete()`/`canDeleteAny()`
stay closed, because deletion has no future-appointment or multi-org guard the
way `EmployeeResource` does.

Creation was closed in an earlier pass of this PR on the grounds that the
generic form never attaches `organization_user`. That was accurate about the
defect and wrong about the remedy — closing it would have left the client unable
to create a second admin at all, which is the headline reason this resource is
being unlocked (`CreateEmployee::afterCreate()` hardcodes `assignRole('staff')`,
so it can never mint an admin). The attach is now written by
`CreateUser::afterCreate()`.

**`canDelete()` alone enforces nothing — this bit us in review.** Filament asks
`getDeleteAuthorizationResponse()`/`getDeleteAnyAuthorizationResponse()` when a
`DeleteAction` runs, not `canDelete()`; with no `UserPolicy` and strict
authorization off, the default returns `allow()` for everybody. Review proved it
by actually deleting a co-admin as a tenant admin while `canDelete()` returned
`false` — the guard read correctly and did nothing. Both response methods are now
overridden, the actions carry `->visible()`, and
`tests/Feature/Filament/UserResourceDeleteGuardTest.php` fails if either is
reverted (verified by mutation, not assumed). **Any resource that opens
`canViewAny()` while keeping deletion closed needs the same treatment.**

**The navigation badge counted every tenant.** `getNavigationBadge()` used
`getModel()::count()` — harmless while the resource was super-admin-only, a
platform-wide headcount in each client's sidebar once opened. It now counts
through `getEloquentQuery()`.

**The same audit turned up a live bug, fixed here.** `CreateEmployee` had the
identical gap while *already* being open to tenant admins: it assigned the Spatie
role and never wrote the pivot, and `canAccessTenant()` reads the pivot and
nothing else — so `ResolveTenant` bounced every employee a client created straight
back off `/admin`. The "add an employee" flow was broken end to end for anyone
who tried it. Covered by
`tests/Feature/Filament/TenantMemberCreationPivotTest.php`.

**2 & 3. `RoleResource` — deliberately left closed, not fixed.** Unlike
`UserResource`, roles have no scoping fix available: they are global by design
(`config/permission.php:134` → `'teams' => false`), so `canViewAny()` stays
`super-admin`-only. The actual self-service need ("assign an existing role to
my own user") was already solved by `UserResource`'s `AssignableRole`-guarded
picker; editing what a role *means* platform-wide is a genuinely platform-level
concern, not a tenant one. The unguarded `permissions` CheckboxList (gap #2)
and the missing `canCreate()`/`canEdit()`/`canDelete()`/`canDeleteAny()`
overrides (gap #3) are therefore still moot in practice — but #3 was added
anyway as explicit belt-and-suspenders (matching the pattern already used by
`AuditLogResource`/`EmailEventResource`/`ServiceAreaWaitlistResource`), so a
future change to `canViewAny()` can't silently inherit Filament's
allow-by-default and reopen gap #2/#3 without a reviewer noticing. See
`app/Filament/Resources/RoleResource.php` and
`tests/Feature/Filament/RoleResourceEscalationGuardTest.php`.

## Wider resource audit (feature/tenant-admin-access, 2026-08-07)

The same PR promoted several other `super-admin`-only resources to `admin`,
using the same "does the model scope safely?" test applied above. Full
per-resource reasoning lives in the PR description; summary:

- **Promoted** (all gained real tenant scoping as part of this change, not
  just a flipped `canViewAny()`): `AuditLogResource` + its
  `AuditLogsRelationManager` (already `BelongsToOrganization` — no code
  change needed beyond the gate), `EmailEventResource`, `SmsEventResource`
  (both models gained `BelongsToOrganization`; `organization_id` is copied
  from the owning `EmailSend`/`SmsSend` at creation time in `EmailService`/
  `SmsService`/`SmsApiWebhookController` — NOT auto-populated from ambient
  tenant context, because most rows are created from webhook/queue paths
  with no tenant HTTP request in flight).
- **Left `super-admin`-only, with reasoning recorded in each resource's own
  docblock:** `MaintenanceEventResource` (platform-wide operational log, no
  natural tenant owner), `EmailSuppressionResource` / `SmsSuppressionResource`
  (the full list is every tenant's bounced/opted-out customer contacts —
  intentionally global because suppression protects the platform's shared
  send reputation, not a per-tenant concern). **Suppression stays entirely out
  of tenant hands, including through the event resources.** An earlier pass of
  this PR added a `removeFromSuppression` action there, arguing that reaching an
  address through your own org-scoped event row made unsuppressing it safe
  self-service. That conflated *seeing an event* with *owning the suppression*:
  `email_suppressions`/`sms_suppressions` are keyed by address with no
  `organization_id`, so tenant B — who merely shares a customer contact with
  tenant A — could undo A's bounce suppression platform-wide and resume delivery
  to a complaint-flagged address on A's behalf. The action was removed, and the
  pre-existing `addToSuppression` (which this PR made reachable for the first
  time by opening `canViewAny()`) was gated to `super-admin` for the mirror
  reason: suppressing an address silences it for every tenant sharing it.
  Restoring tenant self-service here needs a tenant-scoped suppression model,
  not a narrower button),
  `ServiceAreaWaitlistResource` (unchanged — one submission can belong to
  several nearby tenants at once, no clean single-tenant scoping possible,
  see its own docblock and `VULN-003-root-domain-tenant-bypass.md`), and the
  `custom_html` `Forms\Components\Builder\Block` in
  `app/Filament/Support/BuilderBlocks.php` (unchanged — raw, unescaped
  HTML/JS in tenant-facing CMS pages is a stored-XSS primitive; opening it
  was judged disproportionate to the benefit and was not part of any
  explicit ask).

Vehicle-related resources (`VehicleTypeResource`, `CarBrandResource`,
`CarModelResource`) were explicitly NOT touched — a separate, already-planned
PR removes the vehicle subsystem entirely, so promoting them now would be
work to throw away.

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
