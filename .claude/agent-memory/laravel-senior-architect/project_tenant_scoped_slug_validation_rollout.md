---
name: project-tenant-scoped-slug-validation-rollout
description: 2026-08-28 — rolled the LocationForm unique(organization_id) fix out to 7 more Filament Resources (services, categories, pages, posts, portfolio items, promotions, rental categories) that had the identical bug
metadata:
  type: project
---

Branch `fix/tenant-scoped-slug-validation` (from `develop`), ClickUp task `86cbb2ft5`. Team lead
reported a browser-verified blocker: editing a Service in `/admin` rejected the save with "slug
already taken" even when the slug wasn't touched — same root cause [[project_location_slug_unique_hotfix]]
already fixed for Locations, but 7 other resources still had the bare `->unique(ignoreRecord: true)`
against a table whose real constraint is `UNIQUE(organization_id, column)`.

**Verified each of the 7 individually against its migration before touching it** (task explicitly
said not to trust the pre-supplied list) — all 7 confirmed composite-unique in
`2026_06_29_120000_fix_tenant_scoped_unique_constraints.php` (services/categories/pages/posts/
portfolio_items/promotions) or the table's own `create` migration (`rental_categories`). Also
re-verified the resources the team lead said were already excluded (`CarBrandResource`,
`VehicleTypeResource`, `CustomerResource`/`EmployeeResource`/`UserResource` — all three point at
`users.email`, a table with no `organization_id` column at all — `RoleResource`, `OrganizationResource`)
plus one they hadn't listed: `EmailSuppressionResource` — `email_suppressions` has no
`organization_id` column either (global do-not-email list by design), so its global unique is
correct, not a bug.

**Extracted a shared helper instead of 7 copies of the closure** —
`App\Filament\Support\TenantScopedUniqueRule::forCurrentTenant()`, same `App\Filament\Support`
namespace `BuilderBlocks`/`BlockStyling` already live in. `LocationForm.php` was deliberately NOT
refactored onto it — team lead's constraint was "don't touch multi-location work" for this task,
and the inline closure there already matches this helper's logic exactly.

**`CategoryResource` has no dedicated Create/Edit pages** (single `ManageCategories` page,
`ManageRecords`, modal table actions) — the other 6 use the normal List/Create/Edit trio. Testing
it needs `Livewire::test(ManageCategories::class)->callTableAction('edit', $record, data: [...])`
/ `->callAction('create', data: [...])` + `assertHasNoTableActionErrors()`/`assertHasTableActionErrors()`,
not `Livewire::test(EditRecord::class)`.

**Test gotcha:** first draft of the Page test used slug `'kontakt'` for the "edit without touching
slug" fixture — collided with `Page::RESERVED_SLUGS` (unrelated validation rule blocking
`kontakt`/`uslugi`/`portfolio`/etc. as page slugs), giving a red result that looked like the fix
wasn't working but was actually an unrelated fixture bug. Second gotcha: `ServiceResource` has no
`description` field at all in its form (assumed by analogy with other resources) — used
`sort_order` instead, a field always present regardless of `service_type`.

**Red-before-fix proof, all 7 at once:** `git stash push -- <7 resource files>` (helper class and
new test files are untracked, unaffected by a path-scoped stash), reran the 24 new + Location tests
— 14 of the 21 new-resource tests failed with `Component has errors: "data.slug"` (exactly the 2 of
3 scenarios per resource that the fix changes: edit-without-touching-slug, create-with-slug-taken-
by-other-tenant; the same-tenant-duplicate-still-rejected scenario stayed green in both states,
correctly). `git stash pop` restored the fix, reran green. `LocationSlugUniqueScopeTest` flaked red
once during this same run on an unrelated field (`data.phone`, not `data.slug`) — reran twice,
passed both times — this matches the team lead's own warning about a known-flaky, never-identified
test in the suite; not caused by this change (Location's own resource file was never touched).

Full suite after restoring the fix: 1560 passed / 5 skipped (develop baseline 1539/5 + 21 new tests
= exact match, 0 regressions), Pint clean (894 files). Rule doc updated:
`.claude/rules/filament-resources.md` → "`->unique(ignoreRecord: true)` bez `organization_id`"
section, flipped from "found, not fixed" to "found, fixed" with the helper + test pattern.
