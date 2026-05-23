# Package Upgrades Log

## 2026-05-23 — Laravel 12.46→12.60.2 + Filament 4.5.2→4.11.5

**Branch:** `feature/package-upgrade-may-2026`
**Commit:** `5ab0d6a`

### Versions

| Package | Before | After |
|---------|--------|-------|
| `laravel/framework` | 12.46.0 | 12.60.2 |
| `filament/filament` (+ all sub-packages) | 4.5.2 | 4.11.5 |
| `livewire/livewire` | 3.7.3 | 3.8.0 |

### Security Fixes Applied

**Filament:**
- CVE-2026-33080: Stored XSS via unsanitized field values
- User enumeration via password reset timing
- 2FA code reuse after successful login
- `ImageColumn` XSS via unsanitized alt text
- Temp upload auth bypass (unauthenticated file access)
- `AttachAction`/`AssociateAction` scope bypass on Select field (4.11.4)

**Laravel:**
- No CVEs; see bug fixes below.

### Bug Fixes (Laravel 12.46→12.60.2)

- **12.54** — Queue deadlock on job reservation exception in high-concurrency scenarios
- **12.55.1** — `trans_choice()` float argument not working for Polish-locale pluralization
- **12.57** — Rate limiter infinite TTL when using custom `incrementBy` value
- **12.59** — Infinite recursion: Eloquent model scopes + private property access

### Bug Fixes (Filament 4.5.2→4.11.5)

- **4.6.3** — `MorphToSelect` with `afterStateUpdated()` fired callback multiple times
- **4.9.3 / 4.11.2** — Multi-tenant dashboard/home route precedence conflicts
- **4.9.4** — `Group` component: hidden sibling caused shared `statePath` data to be silently dropped on save
- **4.11.3** — `Select` field retained stale cached relation options after record save
- **4.11.3** — Re-authorization during Livewire hydration triggered incorrectly in multi-tenant context
- **4.11.4** — `AttachAction`/`AssociateAction` did not enforce scope on inner Select field

### Audit Findings (pre-upgrade grep)

| Risk | Status |
|------|--------|
| `Group::make()` with hidden siblings | Not used — no action needed |
| `AttachAction`/`AssociateAction` | 2 usages (ServicesRelationManager, MembersRelationManager) — no custom scope modifiers, no behavioral change |
| `MorphToSelect` | Not used — no action needed |
| `assertFormExists()` in tests | Not used — no action needed |

### Test Results

```
Tests: 7 failed, 418 passed (863 assertions)
```

All 7 failures are **pre-existing** (known before this upgrade):
- `BookingServiceAreaBypassTest` × 4
- `CustomerOrdersTest` × 2
- `TenantFeatureTest` × 1

No new failures introduced.

### Build

- `npm run build`: success (60 modules, 2.95s)
- `./vendor/bin/pint --test`: PASS (588 files)
- `php artisan filament:upgrade`: assets published, caches cleared
