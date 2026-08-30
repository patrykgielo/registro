# Security Audit Specialist — Project Memory

## Incidents
- 2026-03-14: RoleDoesNotExist — assignRole('admin') crash on fresh DB, fix: Role::firstOrCreate()
- 2026-03-17: RefreshDatabase wiped dev MySQL — fix: .env.testing, deny permissions, hook
- 2026-08-07: tinker test harness mutated real dev super-admin account's roles (reverted) — see [feedback_tinker_dev_db_safety.md](feedback_tinker_dev_db_safety.md)

## Audits
- [project_registro_role_escalation_guard.md](project_registro_role_escalation_guard.md) — feature/user-role-escalation-guard audit (2026-08-07): guard is sound, one hygiene gap (case-sensitivity) confirmed non-exploitable
- [project_post_login_return_audit.md](project_post_login_return_audit.md) — feature/post-login-return audit (2026-08-17): IntendedDestination/CustomerLandingUrl safe; security rests entirely on consume()'s independent host+path recheck, capture()'s auth-chain refresh branch skips it (fragile, not exploitable today)

## Security Controls
- PreToolUse hook blocks: migrate:fresh/reset/refresh, db:wipe, FILESYSTEM_DISK=local
- .env.testing committed — forces SQLite for tests
- Spatie Roles: firstOrCreate() pattern mandatory
- CSRF protection on all booking endpoints

## Architecture Security
- Multi-tenant: BelongsToOrganization trait scopes all queries
- Module gating: TenantFeature::active() checks
- Panels: /platform (super-admin) vs /admin (tenant-scoped)
- No direct main/develop commits (hook enforced)

## Known Vulnerabilities (pre-existing)
- 5 test failures in BookingServiceArea/TenantFeature — CSRF related
- CI/CD workflows disabled (workflow_dispatch only)
