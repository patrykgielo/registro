# Resource Authorization Layer (`BaseResource`)

**Branch:** `feature/resource-authorization-layer` (2026-08-08)
**Status:** Fixed for the `admin` panel's 34 Resources + `OrderItemsRelationManager`.
Platform panel and strict mode deliberately left out — see "What's still open" below.

## The bug

Filament's table/page actions never call `canDelete()`/`canEdit()`/`canCreate()`
directly. They call `getDeleteAuthorizationResponse()`,
`getEditAuthorizationResponse()`, `getCreateAuthorizationResponse()`, etc. —
see `vendor/filament/filament/src/Resources/Pages/Page.php:298-311`
(`getDefaultActionAuthorizationResponse()`), and the equivalent match
statement in `RelationManager.php:346-361` for relation managers.

Those `get*AuthorizationResponse()` methods, inherited unmodified from
`Filament\Resources\Resource\Concerns\HasAuthorization`, resolve through a
Laravel Policy via the Gate:

```php
// vendor/filament/filament/src/helpers.php
function get_authorization_response(...): Response
{
    // ... policy lookup ...
    if (Filament::isAuthorizationStrict()) { throw new LogicException(...); }
    // no policy, no strict mode:
    $response = invade(Gate::forUser($user))->callBeforeCallbacks($user, $action, [$model]);
    if ($response === false) return Response::deny();
    if (! $response instanceof Response) return Response::allow(); // <-- here
    return $response;
}
```

`app/Policies/` does not exist in this project. Spatie's own `Gate::before`
(`PermissionRegistrar.php:123-135`) never denies — it returns `true` or
`null`, both of which fall through to `Response::allow()` above. Strict mode
is off. Net result: **every destructive action on every one of the 34
Resources under `App\Filament\Resources\BaseResource` was open to anyone who
could reach the panel**, regardless of what that Resource's own `canDelete()`
said — because nothing was asking `canDelete()`.

`app/Filament/Resources/UserResource.php` had already been individually
patched for this (`getDeleteAuthorizationResponse()` override wired to
`canDelete()`) after a code review caught a tenant admin actually deleting a
co-admin (`tests/Feature/Filament/UserResourceDeleteGuardTest.php`). This
change generalizes that fix to a single point in `BaseResource` and removes
the now-redundant local override.

## The fix: `BaseResource`

`app/Filament/Resources/BaseResource.php` now:

1. Defines **concrete, non-recursive** `can*()` defaults for every action
   Filament's actions consult (`canViewAny`, `canView`, `canCreate`, `canEdit`,
   `canDelete`, `canDeleteAny`, `canReplicate`, `canRestore`, `canRestoreAny`,
   `canForceDelete`, `canForceDeleteAny`, `canReorder`).
2. Overrides every corresponding `get*AuthorizationResponse()` to call the
   matching `can*()` method and translate the boolean into a `Response`.

```php
protected static function isDefaultManagingActor(): bool
{
    return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
}

public static function canViewAny(): bool { return false; }
public static function canDelete(Model $record): bool { return static::isDefaultManagingActor(); }
// ... same shape for every other can*() ...

public static function getDeleteAuthorizationResponse(Model $record): Response
{
    return static::canDelete($record) ? Response::allow() : Response::deny();
}
// ... same shape for every other get*AuthorizationResponse() ...
```

A child Resource overriding `canDelete()` now changes real, enforced
behavior, because `getDeleteAuthorizationResponse()` — the method Filament's
`DeleteAction`/`DeleteBulkAction` actually call — is `static::canDelete()`
through late static binding, and is never itself overridden except where a
Resource needs genuinely different *wiring* (none do; every Resource-level
override in this codebase is at the `can*()` level, which is exactly the
design point).

### The recursion trap

The one thing that makes this design non-obvious: `canDelete()` must **never**
call `getDeleteAuthorizationResponse()` back. If it did, the default
`canDelete()` (`isDefaultManagingActor()`) would be fine, but a *naive*
implementation of "make `can*()` the source of truth" — e.g. `canDelete()`
falling back to `parent::canDelete()` which itself calls
`getDeleteAuthorizationResponse()` — creates an infinite loop for any
Resource that doesn't override `canDelete()`. `BaseResource`'s defaults are
concrete role checks with no delegation back into the `get*` family.

## Choosing the default posture

**`canViewAny()` defaults to deny.** Every one of the 34 Resources already
overrides it (verified by grep before starting) — the default only matters
for a Resource with *no* `can*()` at all, which existed exactly once
(`ServiceAreaResource`, see below). Deny-by-default means a forgotten
override locks a Resource out entirely (loud, breaks in dev/QA immediately)
rather than opening it to everyone (silent, the exact bug this fixes).

**Mutating actions default to `hasAnyRole(['admin', 'super-admin'])`.** This
was chosen by auditing what every existing `canViewAny()` override in the
codebase actually gates on:

| Pattern | Count | Examples |
|---|---|---|
| `canViewAny()` = `admin`/`super-admin`, no other `can*()` override | ~13 | `CustomerResource`, `EmployeeResource`, `CategoryResource`, `PageResource`, `PostResource`, `PromotionResource`, `PortfolioItemResource`, `ReminderConfigResource`, `RentalCategoryResource`, `RentalResource`, `ServiceResource`, `ExtensionRequestResource`, `StaffScheduleResource` |
| Full `can*()` set already defined (super-admin-only, permission-based, or per-record) | ~18 | `UserResource`, `RoleResource`, `StaffVacationPeriodResource`, `CarBrandResource`/`CarModelResource`/`VehicleTypeResource` (super-admin only), `EmailSuppressionResource`/`SmsSuppressionResource`/`EmailTemplateResource`/`SmsTemplateResource` (Spatie permission-based), `AuditLogResource`/`EmailEventResource`/`SmsEventResource`/`EmailSendResource`/`SmsSendResource`/`ServiceAreaWaitlistResource` |
| `canViewAny()` = `admin`/`super-admin`/`staff`, no other override | 1 | `AppointmentResource` — fixed by adding explicit overrides (see below) |
| No `can*()` at all | 1 | `ServiceAreaResource` — fixed by adding `canViewAny()` |

For the ~13-resource majority, the default (`admin`/`super-admin`) is
*identical* to what `canViewAny()` already declares — the fix tightens
mutation from "anyone" to "the same audience that could already view the
list", with zero behavioral change for that audience. That is the "narrowing
but not breaking" requirement: nothing that worked for an admin stops
working; everything that worked for a customer/guest/unrelated role (which
should never have worked) stops.

## Resources needing explicit action beyond the default

### `AppointmentResource` — staff run the calendar

`canViewAny()` allows `staff` in addition to `admin`/`super-admin`. Staff
create, edit, and delete appointments as their normal job — that was, in
effect, staff's *actual* usage before this fix (everything was open). The
`BaseResource` default (`admin`/`super-admin` only) would have silently taken
`EditAction`/`DeleteAction`/the "New" button away from every staff member the
moment authorization actually started being enforced — the one regression
this task explicitly called out as unacceptable.

Fixed by adding explicit `canCreate()`/`canEdit()`/`canDelete()`/
`canDeleteAny()`/`canView()` overrides, all delegating to `canViewAny()`.
There is no finer business rule here today (unlike `StaffVacationPeriodResource`
below) — this mirrors the resource's actual, intentional current behavior.

Verified with `tests/Feature/Filament/AppointmentResourceStaffAccessTest.php`
(staff retains full CRUD; a role with no business here — modeled as
`customer`, since `customer` cannot even reach `/admin` per
`User::canAccessPanel()` — is denied).

### `StaffVacationPeriodResource` — per-record ownership, missing `canDeleteAny()`

Already had correct, genuinely per-record `canDelete()` logic (staff may
delete their own *pending* vacation, never an approved one; admins delete
any) — this resource's business logic was always right, it just was never
being *asked*. The only gap: no `canDeleteAny()` override, so
`DeleteBulkAction`'s overall visibility would have fallen to `BaseResource`'s
`admin`/`super-admin`-only default, hiding bulk delete from staff even though
every record they could legally select is still checked individually.

**An earlier version of this document said Filament's `DeleteBulkAction` always
resolves each selected record through `getDeleteAuthorizationResponse($record)`.
That is false, and the mistake was caught by review actually deleting a record.**

`CanBeAuthorized::shouldAuthorizeIndividualRecords()` returns `false` unless
`->authorizeIndividualRecords()` is chained onto the action, and
`InteractsWithSelectedRecords::getIndividuallyAuthorizedSelectedRecords()` then
returns the **whole selection unfiltered**. So by default a bulk delete asks
`canDeleteAny()` once and deletes everything selected — the per-record rule is
never consulted. Repo-wide there were **zero** uses of the opt-in.

For this resource that meant a staff member could bulk-delete their own
**approved** leave, which `canDelete()` forbids per record. The row action was
guarded; the bulk path was not.

**Rule: whenever `canDelete($record)` is stricter than `canDeleteAny()`, the bulk
action must carry `->authorizeIndividualRecords()`.** Applied here and on
`Platform\OrganizationResource` (whose `canDelete()` additionally demands
`lifecycle_state = Closed`). Added:

```php
public static function canDeleteAny(): bool
{
    return auth()->user()?->hasAnyRole(['admin', 'super-admin', 'staff']) ?? false;
}
```

Verified with `tests/Feature/Filament/StaffVacationPeriodResourceDeleteGuardTest.php`.
The interesting case there is **not** "staff can't delete a colleague's
vacation" — `getEloquentQuery()` already scopes staff to their own records at
the query level, so a colleague's row was never reachable through the table
UI at all (confirmed empirically: `callTableAction('delete', $othersRecord)`
throws `ActionNotResolvableException`, not an authorization denial — the row
simply isn't in the scoped result set). The real, previously-exploitable case
is a staff member's **own approved** vacation: still in their scoped query,
reachable, and only `canDelete()`'s `! $record->is_approved` check stood
between it and the (unenforced, pre-fix) `DeleteAction`.

### `MaintenanceEventResource` — tightened for consistency

`canViewAny()` was already `super-admin` only ("global model, not
tenant-scoped"); `canCreate()`/`canEdit()` were already hardcoded `false`
(system-generated, no UI to create/edit one). No `canDelete()`/`canDeleteAny()`
override existed, so `BaseResource`'s wider `admin`/`super-admin` default
would apply to bulk-delete and the `ViewAction`. Unreachable in practice
(canViewAny already excludes admin from the page), but added explicit
`super-admin`-only `canView()`/`canDeleteAny()` for defense in depth and
consistency with the resource's own stated intent.

### `ServiceAreaResource` — had no `can*()` at all

Confirmed via grep before starting: zero `can*()` overrides. Under
`BaseResource`'s new deny-by-default `canViewAny()`, this would have locked
*everyone*, including super-admin, out of a page real tenants use to
configure their delivery/mobile-service radius — the single Resource where
doing nothing would have broken the app outright. Added:

```php
public static function canViewAny(): bool
{
    return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
}
```

Same gate as its settings-group siblings (`CategoryResource`,
`RentalCategoryResource`); create/edit/delete/deleteAny fall through to
`BaseResource`'s matching default. Verified with
`tests/Feature/Filament/ServiceAreaResourceAuthorizationTest.php`.

### `UserResource` — local fix removed, now redundant

`getDeleteAuthorizationResponse()`/`getDeleteAnyAuthorizationResponse()`
overrides removed — `BaseResource` now provides byte-identical generic logic
(`canDelete($record) ? allow() : deny()`). `canDelete()`/`canDeleteAny()`
themselves (the actual business logic — deletion is super-admin only, never
opened to tenant admins) are untouched.
`tests/Feature/Filament/UserResourceDeleteGuardTest.php` passes unmodified —
same behavior, same test, different (shared) implementation underneath.

### `OrderItemsRelationManager` — same hole, different class hierarchy

`Filament\Resources\RelationManagers\RelationManager` has the identical
`get*AuthorizationResponse()` ↔ `can*()` split as `Resource`
(`Concerns\InteractsWithRelationshipTable`, instance methods instead of
static). `OrderItemsRelationManager` had `canCreate()`/`canEdit()`/
`canDelete()` all hardcoded `false` — but, same bug, nothing was asking them.

Currently unreachable in practice: this relation manager's table registers
*zero* actions (`recordActions([])`, `headerActions([])`, `toolbarActions([])`
— order line items are read-only snapshots of what was ordered). Fixed
anyway, locally (no shared base exists for relation managers — this is the
only one in the codebase with real `can*()` logic, so a shared abstraction
would be premature):

```php
public function getCreateAuthorizationResponse(): Response
{
    return $this->canCreate() ? Response::allow() : Response::deny();
}
// ... same for getEditAuthorizationResponse() / getDeleteAuthorizationResponse() ...
```

So a later "let staff edit a line item" feature can't silently reopen this by
just adding an action. Verified with
`tests/Feature/Filament/OrderItemsRelationManagerAuthorizationTest.php`
(direct instantiation + method calls, no HTTP/Livewire needed — nothing to
mount).

## What's still open

### Platform panel — deliberately not covered

`App\Filament\Platform\Resources\RoleResource` extends
`Filament\Resources\Resource` directly, not `BaseResource`, and has zero
`can*()` overrides — same shape as `ServiceAreaResource` before its fix.
`App\Filament\Platform\Pages\Statistics` (a custom Page, not a Resource) also
has none; custom Pages default to `canAccess() => true` for any authenticated
panel user (`Filament\Pages\Concerns\CanAuthorizeAccess`).

**Decision: leave both alone.** `User::canAccessPanel()`
(`app/Models/User.php:287-296`) gates the entire `/platform` panel to
`super-admin` at the door, before any resource or page is reached — checked
on every panel request, not bypassable through a nested resource action.
Since `super-admin` is the maximal privilege level in this application (no
higher role exists, no policy differentiates within super-admins), per-action
`can*()` on `Platform\RoleResource` would authorize a super-admin against a
super-admin — no real boundary crosses.

**Revisit this if:** a second role ever gets `/platform` access (e.g. a
read-only auditor role). At that point `Platform\RoleResource` and
`Platform\Pages\Statistics` need the same treatment as `BaseResource`
Resources — likely a `PlatformBaseResource` mirroring the pattern here, since
`Platform\RoleResource` extends `Filament\Resources\Resource` directly today.

### `Gate::before` for `super-admin` — deliberately not added

Considered and rejected. Two reasons:

1. **No behavioral gap it would close.** Every `can*()` override in this
   codebase — the `BaseResource` default included — already includes
   `super-admin` explicitly in its role check. `Gate::before` would be
   redundant with code that already exists everywhere it matters.
2. **It would mask, not fix, the one place it *would* change something:**
   `resources/views/home-fallback.blade.php:13`'s `@can('viewAny',
   \App\Models\Page::class)` (the public homepage fallback view — unrelated
   Laravel-level Gate check against the CMS `Page` model, not this Resource
   authorization system at all). That directive is dead today because there's
   no `PagePolicy` and no `Gate::before` — it evaluates `deny` for everyone,
   guest or not. A `Gate::before` bypass would "revive" it only for a
   super-admin who happens to be browsing the public site while logged in —
   an edge case nobody asked for — while permanently short-circuiting every
   Laravel Policy this project adds in the future, for every model, before
   the policy is even consulted. Not worth it for a dead link on a public
   page nobody super-admin-browses.

### Strict mode (`$panel->strictAuthorization()`) — not enabled

Two independent blockers:

1. **It would be inert for the `/admin` panel specifically**, not just safe.
   `BaseResource` now overrides every `get*AuthorizationResponse()` — the only
   method that ever calls the strict-mode-checking helper
   (`get_authorization_response()` in `vendor/filament/filament/src/helpers.php`).
   With that call site gone for all 34 Resources, strict mode has nothing left
   to check on this panel; enabling it would satisfy a config flag without
   adding any enforcement.
2. **It would break the `/platform` panel outright.** `Platform\RoleResource`
   has no `can*()` overrides and inherits the *unmodified* Filament default,
   which does call `get_authorization_response()`. With no `PagePolicy`/
   `RolePolicy` in `app/Policies/` (still doesn't exist) and strict mode on,
   every action there — including the list page's own mount — throws
   `LogicException` immediately.

**Revisit this if:** `app/Policies/` gets introduced for real (this task
didn't add any — `BaseResource` bypasses the Gate/policy path entirely rather
than using it), or `Platform\RoleResource` gets the equivalent
`can*()`/`get*AuthorizationResponse()` coverage described above.

### Untouched, orthogonal to this fix

`HasAuthorization::can(string $action, ?Model $record)` — the generic,
string-keyed variant (distinct from `getDeleteAuthorizationResponse()` etc.)
— still resolves through the Gate/policy path unchanged. Confirmed unused
anywhere in this codebase (`grep -rn "Resource::can("` — no hits) at the time
of this fix. Left alone rather than speculatively wired, since there was
nothing to verify it against; flag this if a future change starts calling it.

## Verification

- Pint: clean across all 769 files.
- Full suite: same 3 pre-existing failures as `develop`
  (`CustomerOrdersTest::test_customer_can_cancel_pending_payment_order`,
  `CustomerOrdersTest::test_cancel_sets_cancelled_at_timestamp`,
  `TenantFeatureTest::test_booking_wizard_has_4_steps_without_vehicles` — all
  three unrelated to authorization, confirmed identical before/after this
  change), 5 skipped, otherwise fully green. 18 new tests added across 6
  files, all passing.
- Every new authorization test in this change was mutation-verified by hand:
  the guard it exercises was temporarily inverted, the test was re-run and
  confirmed to fail, then the guard was restored and the test re-confirmed
  green. This includes the architectural test
  (`tests/Feature/Filament/ResourceAuthorizationWiringTest.php`), which was
  verified by temporarily reverting `BaseResource::getDeleteAuthorizationResponse()`
  to unconditional `Response::allow()` (the exact pre-fix shape) and
  confirming every one of the 34 Resources' assertions failed.
