# Exploration Log

Dated log of significant findings and investigations across the codebase.

---

## 2026-06-29 — Multi-tenancy unique constraint bug (fix/fix-services-tenant-unique)

**Trigger:** Registering a 2nd equipment-rental tenant 500s with `UniqueConstraintViolationException` on `services.services_name_unique` because `SeedEquipmentRental` seeds "Wiertarka udarowa" and another tenant already owns a service with that name.

**Root cause:** 11 tables have `organization_id` (tenant isolation via `BelongsToOrganization`) but their unique constraints were single-column (global), not composite. Any two tenants sharing the same slug or name would violate them.

**Analysis — email/sms templates (skipped):**
- `email_templates` and `sms_templates` have `organization_id` (nullable) but all 40/14 rows are seeded as NULL-org global system templates.
- MySQL treats NULL as distinct in UNIQUE indexes, so converting to `(organization_id, key, language)` composite would allow duplicate NULL-org rows on re-seed and break idempotency of seed migrations that use `insertOrIgnore`.
- Decision: leave `(key, language)` global unique intact. Flagged for future handling (per-tenant template customization requires a separate override pattern).

**Constraints converted (9 total):**

| Table | Old | New |
|-------|-----|-----|
| `services` | `services_name_unique (name)` | `services_org_name_unique (organization_id, name)` |
| `services` | `services_slug_unique (slug)` | `services_org_slug_unique (organization_id, slug)` |
| `categories` | `categories_slug_unique (slug)` | `categories_org_slug_unique (organization_id, slug)` |
| `pages` | `pages_slug_unique (slug)` | `pages_org_slug_unique (organization_id, slug)` |
| `posts` | `posts_slug_unique (slug)` | `posts_org_slug_unique (organization_id, slug)` |
| `portfolio_items` | `portfolio_items_slug_unique (slug)` | `portfolio_items_org_slug_unique (organization_id, slug)` |
| `promotions` | `promotions_slug_unique (slug)` | `promotions_org_slug_unique (organization_id, slug)` |
| `service_areas` | `unique_service_area (lat,lng,radius_km)` | `service_areas_org_coords_unique (organization_id, lat, lng, radius_km)` |
| `orders` | `orders_order_number_unique (order_number)` | `orders_org_order_number_unique (organization_id, order_number)` |

**Left unchanged (globally unique by design):** `orders.p24_session_id`, `payments.p24_session_id`, `email_sends.message_key`, `sms_sends.message_key`.

**Files:** `database/migrations/2026_06_29_120000_fix_tenant_scoped_unique_constraints.php`, `tests/Feature/Onboarding/MultiTenantUniqueConstraintsTest.php`

**Live end-to-end verification (browser):** After the fix, walked the full business registration in Chrome (slug `qatest`, equipment_rental) → completed Krok 1→2→3→"Gratulacje" with no 500. DB: `orgs=2`, `qatest` org + `qa.tester` user created. Logged into the new tenant's `/admin` (Filament v4.11.5) and confirmed the **Usługi** list seeded the full equipment-rental catalog including **"Wiertarka udarowa"** — the exact record that previously collided. It now coexists with grent's identical service. Bug → fix → onboarding → catalog → panel all green.

**Browser/env notes for next time:**
- New tenant subdomains need a `/etc/hosts` entry (no wildcard DNS) + self-signed cert click-through (Advanced→Proceed; extension can't attach to the interstitial) + the claude-in-chrome **per-site permission**.
- The extension's "Permission denied" on a brand-new host was resolved by **opening a fresh MCP tab** (`tabs_create_mcp`) and navigating there, rather than reusing the old tab.
- Admin entry point is `/admin` (302 → `/admin/login`); the welcome page's `/admin/{slug}` 404s when unauthenticated.
