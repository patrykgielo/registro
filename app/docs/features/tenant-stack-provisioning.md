# Tenant-Stack Provisioning

**Scope:** The `registro:tenant-provision` CLI command, the schema/route/panel gating that supports
it, and the request-time gating (`ResolveTenant` slug pinning, `TrustHosts`/`TrustProxies`) for the
dedicated tenant-stack architecture — one Docker container + one MySQL database per client,
`TENANT_SLUG` set in that container's environment.
**Last verified:** 2026-08-08 against `feature/setup-ttl-and-drop-public-wizard` (public self-serve
wizard removed entirely — this is now the ONLY tenant-provisioning path; see "Why this exists"
below).
**Related:** [Tenant Lifecycle](tenant-lifecycle.md), `.claude/rules/spatie-roles.md`,
`.claude/rules/models.md`, `.claude/rules/middleware.md`. The old self-serve-wizard architecture doc
is archived: `docs/archive/features/tenant-provisioning-wizard.md`.

---

## Why this exists

This command was originally added as an operator-run alternative alongside the public self-serve
wizard (`BusinessRegisterController`) — before it existed, `CreateOrganization` (the `/platform`
Filament page) was the only way to create an organization outside that wizard, and it's a bare
`CreateRecord`: it inserts a row into `organizations` and nothing else — no owner user, no
`organization_user` pivot row — so `User::canAccessTenant()` (which checks the pivot exclusively)
returns `false` for everyone, and the newly-created organization is unreachable.

The wizard itself is gone now (removed along with `CreateOrganizationWithOwner` and
`OnboardingData` — see the archived doc linked above): the product model is "we sign a contract and
provision from the CLI," and a public path that could mint a second `Organization` was one of only
three ways a stack-per-tenant container's database could ever end up holding more than one.
`registro:tenant-provision` is therefore now the **only** working path to a new organization on
every stack type, not just dedicated tenant-stack containers.

Separately, the project is moving dedicated clients onto one-container-per-tenant Docker stacks,
each with its own database holding **exactly one** organization. That changes what "safe" means at
the model layer: `BelongsToOrganization`'s global scope disables tenant filtering entirely inside
`runningInConsole()` (see the trait), and every scheduled job/worker (Horizon, `RecalculateDailyStatisticsJob`,
`ProcessRemindersJob`, `SendAdminDigestJob`, `MarkCartsAbandonedJob`, …) runs as console and iterates
organizations with **no** tenant scoping. On a dedicated stack this is safe only as long as there is
structurally nothing else in the table to iterate into — see the singleton lock below.

## `registro:tenant-provision`

```
php artisan registro:tenant-provision \
    --slug=acme --name="Acme Sp. z o.o." --industry=equipment_rental \
    --owner-email=owner@acme.pl --owner-name="Jan Kowalski"
```

- Builds on existing onboarding primitives rather than duplicating them: `SeedOrganizationDefaults`
  (settings + feature/module flags) is reused as-is via a new action,
  `App\Actions\Onboarding\ProvisionTenantOrganization`. It was deliberately **not**
  `CreateOrganizationWithOwner` (the wizard's action, since removed) — that action unconditionally
  inserted and required a password; this command needs idempotent `firstOrCreate`-by-slug semantics
  and a passwordless owner.
- **Owner has no password.** Access is via the existing invite-link mechanism
  (`User::initiatePasswordSetup()`, the same one `Filament\Resources\UserResource` uses for
  admin-created staff) — the command always prints the `password.setup` URL to stdout, regardless of
  what happens with e-mail.
- **Dispatches `TenantRegistered`** (owner welcome + operator heads-up, `TenantWelcomeNotification` /
  `NewTenantRegisteredNotification`) on genuine first creation only — never re-announced on an
  idempotent rerun. Kept off the critical path deliberately: a dispatch failure (SMTP down, queue
  connection refused) is caught, logged, and reported in the command's own output table, but never
  fails the command or withholds the stdout link — that link is the actual deliverable. `--no-email`
  opts out of the dispatch entirely.
- **Idempotent by slug/e-mail.** Re-running the command (container restart, re-applied stack config)
  finds the existing organization/owner via `firstOrCreate` instead of duplicating them, refreshes
  the password-setup token, and does not re-run the audit-log write or the global seeders (below).
- **Safety check:** if this container has `TENANT_SLUG` set (`config('app.tenant_slug')`) and it
  doesn't match `--slug`, the command refuses to run — protects against provisioning the wrong
  organization into the wrong container.
- Roles: `Role::firstOrCreate('admin')` before `assignRole()` (per `.claude/rules/spatie-roles.md`),
  but permissions come from `RolePermissionSeeder` (below) — `firstOrCreate` alone creates the role
  with zero permissions.
- Audit trail: `OrganizationLifecycleLog::record($org, 'provisioned', null, [...])`, actor `null`
  (CLI/system event, same convention as the `closed` event from the scheduled purge command),
  written only when the organization is actually newly created — not on idempotent reruns.

## Global seeders — provisioned once per stack, not per run

`RolePermissionSeeder`, `SettingSeeder`, `EmailTemplateSeeder` seed global (`organization_id = NULL`)
lookup data. They already run together, once, in `scripts/deploy-init.sh` for the legacy stack. This
command needs the same "exactly once" guarantee for a dedicated stack, gated by a **durable marker**
— not "does `organizations` have a row":

- The seeders must run **before** the organization exists.
- `EmailTemplateSeeder` uses `updateOrCreate` — re-running it after a tenant has customized their
  templates would silently blow the customization away. The other two are structurally idempotent
  (safe to rerun), but are gated by the same marker for simplicity and to match the existing
  deploy-init.sh grouping — see `App\Console\Commands\ProvisionTenantCommand::runGlobalSeedersOnce()`.
- A dump of a pre-provisioning database restored onto an already-live stack must not silently
  re-arm the seeders. An `organizations` row alone can't express "seeders already ran" across a
  restore; a dedicated table can and does.

`App\Models\TenantProvisioningState` (table `tenant_provisioning_state`) is that marker: one row,
written once, `provisioned_at` set. No FK to `organizations` — deliberately decoupled from the org's
own lifecycle (a later soft-delete/purge of the org must not make the stack forget it was already
provisioned and re-arm the seeders).

```php
TenantProvisioningState::isProvisioned(): bool
TenantProvisioningState::markProvisioned(Organization $org): self
```

## `registro:tenant-provisioned`

Side-effect-free status check (no DB writes) for shell tooling deciding whether to call
`registro:tenant-provision` — exit `0` + prints `provisioned` when already done, exit `1` + prints
`not-provisioned` otherwise. Wiring this into an `apply`-style deploy script is out of scope here
(see `.claude/rules/_INDEX.md` → `scripts/**` is a separate concern from `app/**`).

### `--assert` — the only thing that notices when the gates go quiet

Every isolation gate in this feature keys on one scalar, `config('app.tenant_slug')`: the
`/register` routes, the `/platform` panel, the singleton lock, and the command's own slug guard. If
a dedicated stack ever boots with it blank — a typo in the stack's `.env`, an unset shell var
interpolated into compose — **all of them relax to shared-stack behaviour at once, silently**. This
project has already been bitten by that exact shape (MCP servers declared under a `settings.json`
key that is never read: ignored for weeks, no error).

`--assert` cross-checks the container's environment against what the **database** says about itself,
which is the independent signal, and exits non-zero on any of:

| Condition | What it means |
|---|---|
| marker row present, `TENANT_SLUG` blank | dedicated stack lost its slug — registration and `/platform` are live here |
| marker slug ≠ `TENANT_SLUG` | container is pointed at another tenant's database |
| `TENANT_SLUG` set (MySQL), no `organizations.singleton` | the lock migration ran *before* the slug existed and will never retry |
| `TENANT_SLUG` blank (MySQL), `singleton` present | leftover tenant lock on a container running shared |

Run it after boot in `apply` and from cron. A gate nobody verifies is a gate that has already failed
once without telling anyone.

### Owner-collision guard

`--owner-email` matching an account that already exists makes the command **fail** unless
`--attach-existing-owner` is passed. On a tenant stack this is moot (one org, one owner); it matters
on the shared stack, where `TENANT_SLUG` is blank so the slug guard no-ops and the address could
belong to some other tenant's customer — provisioning would otherwise hand that account the `admin`
role and owner rights while reporting success. Re-running against the organization's *own* owner
stays allowed without the flag, because idempotency is this command's whole contract.

## DB-level singleton lock — `organizations.singleton`

Migration `2026_08_06_100002_add_singleton_lock_to_organizations_table` adds a `STORED GENERATED`
column that always evaluates to `1`, with a `UNIQUE` index on it — MySQL rejects any `INSERT` past
the first row with a duplicate-key error, from **any** code path (a bug, a bad migration/restore, a
future script, `tinker`), not just the ones this PR wrote. Optimistic checks in PHP (like the
`--slug` vs `TENANT_SLUG` guard above) can't close that gap by themselves.

Conditional on `DB::getDriverName() === 'mysql' && filled(config('app.tenant_slug'))`:

- **Dev and the shared legacy stack** run `TENANT_SLUG` unset and host many organizations by
  design — the lock would break them outright. Verified by
  `tests/Feature/Onboarding/OrganizationSingletonLockMigrationTest.php` (asserts the column is
  absent under the test suite's actual driver/config).
- **SQLite** (`.env.testing`) has no portable equivalent of `GENERATED ALWAYS AS (...) STORED` +
  matching unique-index story, and the test suite never sets `TENANT_SLUG` — so guarding on driver
  keeps this migration a clean no-op for every test run rather than needing SQLite-specific syntax.

**Soft-delete interaction (deliberate, not a gap):** a soft-deleted organization still occupies the
unique slot — MySQL's `UNIQUE KEY` has no notion of `deleted_at`; only Eloquent's read-time global
scope does. This is intentional: legal records (orders, payments, tenant_payments, rentals) tied to
that organization are retained for 5–6 years (Art. 112 VAT / Art. 70 Ordynacja) in the **same**
database via `restrictOnDelete` FKs (see `.claude/rules/models.md` → Organization SoftDeletes). The
entire point of one-database-per-tenant is that the database is scoped to exactly one tenant's data
for the life of that retention window; the correct operation when a tenant's org is closed for good
is decommissioning the whole stack, not re-provisioning a different tenant into the same database.
The lock continuing to block a 2nd `INSERT` after soft-delete is what enforces that.

**Verified operationally** (against a real MySQL 8.0 instance, not just read): the migration only
succeeds on a table with 0 or 1 existing row. Run against a table that already has 2+ organizations
(confirmed on this project's own shared dev database, which currently has 8), MySQL rejects the `ADD
UNIQUE` step with `Duplicate entry '1' for key ...` — every existing row collides on the new
always-`1` column. Because MySQL DDL is not transactional, the preceding `ADD COLUMN` half commits
anyway, so a failed attempt leaves the bare `singleton` column behind without its index or a recorded
migration row; `migrate:status` still reports the migration `Pending`, and re-running it fails
differently (`Duplicate column name 'singleton'`) until that leftover column is dropped by hand
(`ALTER TABLE organizations DROP COLUMN singleton`). This is a deliberate fail-loud property, not a
defect — in the real deployment order `organizations` is empty when migrations run (this command
creates the one row afterwards), so it only ever fires if the migration is run against the wrong
database.

## Route removal — public registration

`/register/*` and `/get-started*` (`BusinessRegisterController` routes, AJAX slug endpoints, legacy
redirects) used to be gated behind `if (! config('app.tenant_slug')) { ... }` in `routes/web.php` --
registered only on the shared legacy stack, absent entirely on a dedicated tenant-stack container.
That whole block, its controller, its two supporting actions (`CreateOrganizationWithOwner`,
`OnboardingData`), and its views are gone now (see "Why this exists" above) -- there is no config
condition left to read, on either stack type. Every `route('register')` call site was audited and
either repointed (`customer.register`, a login redirect, a `mailto:` contact link) or left dead-code
guarded (`welcome.blade.php`'s `@if (Route::has('register'))`). Verified in
`tests/Feature/TenantSlugGatingTest.php::test_business_registration_route_names_are_not_registered`,
which now holds regardless of `TENANT_SLUG`.

`/customer/register` (tenant-subdomain self-registration, gated separately by
`CheckRegistrationEnabled`) is untouched — it's a different flow (existing tenant's own customers
registering an account), not a 2nd-organization risk.

## Panel gating — `/platform`

`bootstrap/providers.php` registers `App\Providers\Filament\PlatformPanelProvider` only when
`config('app.tenant_slug')` is empty. `LoadConfiguration` runs before `RegisterProviders` in the
framework's bootstrapper order (`Illuminate\Foundation\Http\Kernel::$bootstrappers`), so `config()`
is safe to read this early — verified by reading the framework's `RegisterProviders::bootstrap()`.
Not registering the provider means its `register()`/`boot()` never run, so the panel's routes are
never added to the router at all (`route:list` shows no `platform.*` route) — confirmed in
`TenantSlugGatingTest::test_platform_panel_is_not_registered`.

**Residual, deliberately not fixed here:** the compiled `platform.css`/`platform.js` Vite assets
remain physically present in the built image and fetchable as inert static files, because the Docker
image is built once (`npm run build`) before any specific deployed stack's `TENANT_SLUG` is known —
stripping per-stack assets would need per-tenant image variants, a Docker/build-tooling change
explicitly out of scope for this work. The files contain only styling, no logic or secrets.

**`config:cache` correctness:** this project's deploy scripts run `php artisan config:cache` via
`docker compose exec` against an already-running container with its real per-stack `.env` already
loaded (`scripts/deploy-init.sh`, `scripts/deploy-update.sh`) — not baked into the image at build
time. That means the cached `app.providers`/`app.tenant_slug` values are correct for that specific
stack. This assumption is load-bearing for the panel gating above; if a future change ever bakes
`config:cache` into the Docker image build itself, this gating would freeze on whatever
`TENANT_SLUG` was set (or unset) at build time for every deployed stack — worth a note if that
changes.

## Request-time gating — `ResolveTenant`, `TrustHosts`, `TrustProxies`

Provisioning creates the organization; this section is what actually serves it once the container
starts taking traffic. Two independent layers, not one:

**`ResolveTenant::handlePinnedTenant()`** — when `config('app.tenant_slug')` (`TENANT_SLUG`) is set,
`ResolveTenant::handle()` branches immediately, before the Host-derived subdomain logic runs at all.
It resolves the one Active organization by slug from the container's own environment instead of
deriving it from the Host header — there is nothing to derive on a dedicated stack; the database holds
exactly one organization (`organizations.singleton`, above).

`handlePinnedTenant()` finishes by calling `forceTenantOriginUrls()`, exactly like the
host-derived branch. On a dedicated stack `APP_URL` usually already equals the tenant's own
domain, so this looks like a no-op and is easy to delete as dead code — it is not. When
`TENANT_HOSTS` carries **more than one** name (the subdomain plus a client's custom domain),
`route()` and `Storage::url()` must follow the host the visitor actually used, not whichever
one happens to sit in `APP_URL`. Removing the call silently breaks every link and every image
for the host that is not `APP_URL`.

Pinning the slug alone is not enough: with nothing else checking the Host, this container would answer
`200` to literally any Host that reaches it (a stray DNS record, a scanner hitting the bare IP, a
Host that doesn't match the client's actual domain at all) — there is no other tenant on this stack to
fall back to or redirect toward, so an unchecked Host would silently serve this tenant's data under it.
`config('app.tenant_hosts')` (`TENANT_HOSTS`, comma-separated) is the independent, fail-closed second
layer: a Host outside the allowlist gets `404` even though the slug resolves fine. **Empty/unset
`TENANT_HOSTS` denies every Host on purpose** — an operator who sets `TENANT_SLUG` but forgets
`TENANT_HOSTS` gets a 404ing stack, not a silently wide-open one. A pinned slug that fails to resolve
an Active organization (never provisioned yet, wrong slug) also fails closed to `404` — deliberately
not wired into the closed/suspended-org pages the Host-derived branch below it uses; out of scope here.

**`TrustHosts` / `TrustProxies`** (`bootstrap/app.php`, `config/trustedproxy.php`,
`App\Support\TrustedTenantHosts`) — `bootstrap/app.php` previously configured neither. That mattered
independently of tenant-stack pinning: once TLS moves to an edge proxy (a later task) in front of this
stack, a client-supplied `X-Forwarded-Host` header, forwarded unfiltered by a naively-configured edge
and trusted by Laravel, can redirect any absolute URL this app generates (a password-reset link, for
instance) to an attacker's host. Both are now wired, config/env-driven, safe by default:

- `TrustHosts`, registered via `$middleware->trustHosts(at: fn () => TrustedTenantHosts::patterns())`
  — a closure, not a resolved array, because `withMiddleware()`'s closure runs *before*
  `LoadEnvironmentVariables`/`LoadConfiguration` (same timing hazard documented on
  `PestBrowserHostBugWorkaround`), so reading `config()` has to be deferred to request time. Laravel's
  own `shouldSpecifyTrustedHosts()` gate is a no-op outside `local`/`testing` — dev and `tests/Browser`
  are unaffected by construction, not by anything this feature added. `TrustedTenantHosts::patterns()`
  adds `TENANT_HOSTS` on top of Laravel's own default (`config('app.url')`'s host + subdomains, via
  `subdomains: true`), so a pinned stack answering on a client's own custom domain is trusted too.
- `TrustProxies` is always in Laravel's global middleware stack regardless of configuration; what it
  trusts is driven by `config('trustedproxy.proxies')` (`TRUSTED_PROXIES_CIDR`) — read at request time
  from `config/trustedproxy.php`, not passed via `trustProxies(at: ...)` in the bootstrap closure (same
  timing hazard). **Unset (the default) trusts nothing** — `X-Forwarded-*` is ignored entirely, which
  is what already happened implicitly before this feature and is exactly what keeps
  `X-Forwarded-Host` from overriding the real Host today. No tenant sits behind an edge network yet
  (task 5, [Edge Stack](../deployment/edge-stack.md), built the ingress but nothing is attached to it)
  — once one does, its `TRUSTED_PROXIES_CIDR` becomes that tenant's `tenant-<slug>-edge` subnet, never
  `*`.

Covered by `tests/Feature/Middleware/ResolveTenantPinnedTest.php`,
`tests/Feature/Security/TrustedProxiesAndHostsTest.php`,
`tests/Unit/Support/TrustedTenantHostsTest.php`.

## Files

| File | Purpose |
|------|---------|
| `config/app.php` | `tenant_slug` (`TENANT_SLUG`), `tenant_hosts` (`TENANT_HOSTS`) config keys |
| `config/trustedproxy.php` | `proxies` config key (`TRUSTED_PROXIES_CIDR`) |
| `app/Http/Middleware/ResolveTenant.php` | `handlePinnedTenant()` — slug pinning + `TENANT_HOSTS` fail-closed check |
| `app/Support/TrustedTenantHosts.php` | Builds `TrustHosts`' extra patterns from `TENANT_HOSTS` |
| `bootstrap/app.php` | `TrustHosts`/`TrustProxies` wiring |
| `.env.production.example`, `.env.staging.example` | `TENANT_SLUG`/`TENANT_HOSTS`/`TRUSTED_PROXIES_CIDR` documented alongside `APP_DOMAIN` |
| `database/migrations/2026_08_06_100001_create_tenant_provisioning_state_table.php` | Marker table |
| `database/migrations/2026_08_06_100002_add_singleton_lock_to_organizations_table.php` | DB-level 2nd-org lock |
| `app/Models/TenantProvisioningState.php` | Marker model |
| `app/Actions/Onboarding/ProvisionTenantOrganization.php` | Idempotent org+owner creation |
| `app/Console/Commands/ProvisionTenantCommand.php` | `registro:tenant-provision` |
| `app/Console/Commands/TenantProvisioningStatusCommand.php` | `registro:tenant-provisioned` |
| `routes/web.php` | Route gating |
| `bootstrap/providers.php` | Panel gating |
| `tests/Feature/Onboarding/TenantProvisionCommandTest.php` | Command idempotency, marker, seeder gating |
| `tests/Feature/Onboarding/OrganizationSingletonLockMigrationTest.php` | Migration no-op on SQLite |
| `tests/Feature/TenantSlugGatingTest.php` + `TenantSlugGatingDisabledTest.php` | Route/panel gating both ways |
| `tests/Feature/Onboarding/TenantProvisioningGuardsTest.php` | Owner-collision guard, `--assert` mismatches, marker atomicity |

## Known gaps / explicitly out of scope

- **Done** — `scripts/server/apply.sh` (task 6, see `app/docs/deployment/tenant-apply.md`) now calls
  `registro:tenant-provisioned` before deciding whether to run `registro:tenant-provision`, and
  documents a real bug this surfaced: `--assert` returning non-zero does not by itself mean
  "inconsistent" — it also returns non-zero, printing exactly `not-provisioned`, for any stack that
  simply hasn't been provisioned yet.
- Docker/nginx changes: task 5 (`docker-compose.edge.yml`, see
  [Edge Stack](../deployment/edge-stack.md)) builds the shared ingress this architecture needs, but
  it proxies to a `tenant-<slug>-nginx` container that task 4 (rebuilding the tenant compose itself)
  has not produced yet. Per-tenant image variants and wildcard cert automation remain untouched.
- `handlePinnedTenant()` does not reuse the closed/suspended-organization pages the Host-derived
  branch above it renders (`errors.business-closed`, `errors.business-suspended`) — a pinned tenant
  that is not `Active` (never provisioned yet, or later suspended/closed) fails closed to a plain
  `404` instead. Deliberate scope cut to keep the pinning change minimal; revisit if a
  Suspended/Closing/Closed pinned stack ever needs its own dedicated status page instead of a bare 404.
- No `apply`/deploy-script wiring yet for `TENANT_HOSTS`/`TRUSTED_PROXIES_CIDR` — an operator
  provisioning a real dedicated stack has to set both by hand today; there is no CLI helper analogous
  to `registro:tenant-provision` that also writes these.
- If an operator re-runs the command with a different `--owner-email` against an already-provisioned
  slug, the new user is linked as an **additional** owner (pivot `role = owner`) rather than
  replacing `organizations.owner_id` — the command prints a warning; it does not silently change
  which user the organization considers its primary owner.
- **A provisioned tenant stack cannot enable its optional modules.** `communication`, `customers`,
  `staff`, `service_area` and `vehicles` are off by default for every industry
  (`Industry::defaultModules()` / `Organization::MODULE_DEFAULTS`), and the only writer for the
  override is `Organization::enableModule()`, wired solely into `/platform` →
  `OrganizationResource` — which this feature removes from client containers. So `CustomerResource`,
  `EmployeeResource` and `EmailTemplateResource` are unreachable there with no CLI alternative. Note
  the irony worth fixing early: the provisioning marker exists to protect e-mail templates the
  client customised through a resource that is currently unreachable on that stack type. Needs an
  artisan writer (or `--modules=` on provisioning), plus a decision on whether tenant stacks should
  default differently from the shared stack.
- The password-setup link is printed to stdout on **every** run, and its token is valid for
  `User::PASSWORD_SETUP_TTL_HOURS` (24h, raised from the original 30 minutes — see
  `app/Models/User.php::initiatePasswordSetup()` for why 24h and not 7 days). The "stdout is safe
  because whoever runs this already has shell" reasoning holds for interactive operator runs only —
  if `apply` ever calls this non-interactively and captures stdout into a deploy log or CI artifact,
  the exposure moves from shell access to log-storage access, and the longer TTL widens that window
  correspondingly.
