---
name: project-setup-ttl-and-wizard-removal
description: Task 7 (final, stack-per-tenant epic) — password-setup TTL raised to 24h via User::PASSWORD_SETUP_TTL_HOURS; public self-serve registration wizard removed entirely, TenantRegistered now dispatched from registro:tenant-provision
metadata:
  type: project
---

Branch `feature/setup-ttl-and-drop-public-wizard` (2026-08-08) closed out the stack-per-tenant epic
with two independent changes.

**Why:** the product model changed to "we sign a contract and provision from the CLI" — a public
signup path was one of only three ways a stack-per-tenant container's database could ever end up
holding more than one organization.

**How to apply / where things live now:**
- TTL is a single class constant, `User::PASSWORD_SETUP_TTL_HOURS = 24` (`app/Models/User.php`) —
  not `config()`, deliberately: fixed security policy tied to this model's own method, needs no
  per-environment override, and must be readable directly from Blade/Filament strings
  (`User::PASSWORD_SETUP_TTL_HOURS`) without a SettingsManager round-trip. Completely separate from
  Laravel's own password-*reset* TTL (`config/auth.php` → `passwords.users.expire`, still 60 min) —
  don't conflate the two flows again.
- `BusinessRegisterController`, `CreateOrganizationWithOwner`, `OnboardingData`, `GenerateUniqueSlug`
  (and their views/tests) are gone. Do not recreate a public `/register` route without re-opening
  that product decision — see `app/docs/features/tenant-stack-provisioning.md` ("Why this exists")
  and the archived design at `docs/archive/features/tenant-provisioning-wizard.md`.
- `App\Events\TenantRegistered` (owner welcome + operator heads-up) is now dispatched from
  `ProvisionTenantCommand::dispatchTenantRegistered()` — only on genuine first creation, never on an
  idempotent rerun, wrapped in try/catch so a mail failure never fails the command (the stdout setup
  link is the actual deliverable). `--no-email` flag opts out.
- Fixed two latent pre-existing bugs found while auditing every `route('register')` call site:
  `resources/views/services/index.blade.php` (tenant storefront CTA) was pointing at the *business*
  wizard route instead of `customer.register` — would have thrown `RouteNotFoundException` on any
  dedicated tenant-stack container even before this removal, since that route only existed when
  `TENANT_SLUG` was unset.
- Root-domain header/login CTAs (`components/nav/header.blade.php`, `auth/login.blade.php`) now show
  a `mailto:` contact link (`$contactEmail`, shared globally via `AppServiceProvider::shareFeatureFlags()`
  composer) instead of a dead sign-up button when no tenant resolves.
- `RegisterController::showRegistrationForm()` redirects to `route('login')` instead of the removed
  `route('register')` when no tenant resolves.
- Full test accounting: baseline 1081 passed/1 pre-existing-failed(`TenantFeatureTest`)/5 skipped →
  1060 passed/same 1 failed/5 skipped (net −21: removed 24+6 wizard-only tests, −3 across
  SessionRegenerationTest/TenantSlugGatingDisabledTest/TenantRegistrationEmailTest for
  subjects that no longer exist, +12 new: SetPasswordControllerTest×7,
  TenantProvisionCommandTest dispatch/no-email/failure-resilience coverage×5 net). Browser suite
  unchanged at 9 passed.

See also [[feedback-pendingcommand-lazy-execution]] for a testing gotcha hit while writing the
dispatch-failure-resilience test.
