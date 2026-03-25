# Security Audit Specialist — Project Memory

## Incidents
- 2026-03-14: RoleDoesNotExist — assignRole('admin') crash on fresh DB, fix: Role::firstOrCreate()
- 2026-03-17: RefreshDatabase wiped dev MySQL — fix: .env.testing, deny permissions, hook

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
