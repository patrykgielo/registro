# Tenant Website Seeder (`onboarding:seed-website`)

## Problem

A freshly-provisioned tenant (`registro:tenant-provision`) has no CMS pages. The public root
(`/`) renders `home-fallback.blade.php` — a neutral "coming soon" placeholder, not a sales-ready
homepage — and the header/footer navigation is empty (menu items come exclusively from
published, `show_in_menu = true` `Page` rows — see `cms-page-menu.md`). For demos and real
onboarding this is a blocker: no company name, no product presentation, no working menu.

`onboarding:seed-website` is an **onboarding command, run once per new tenant by an operator**,
not part of `registro:tenant-provision`'s automatic flow (same reasoning as
`onboarding:seed-vertical` — see `onboarding.md`). It is intentionally **not** built on the
`demo:seed` framework: that framework hard-blocks production (`guardProduction()`) and requires
`config('demo.enabled')`, but new tenants are provisioned on production machines
(`APP_ENV=production`) — using it would make the command unusable exactly where it's needed.

## Usage

```bash
php artisan onboarding:seed-website {organization} [--force] [--dry-run]
```

- `{organization}` — numeric ID or slug.
- `--force` — required when the organization already has any CMS pages. Deletes **all** of the
  organization's pages (not just ones this command previously created — mirrors
  `onboarding:seed-vertical`'s purge semantics) and recreates them.
- `--dry-run` — preview only, no writes. Mirrors the real exit code (`FAILURE` if a real run
  would fail for lack of `--force`).

Follows the mandatory 5-part destructive-command pattern from `console-commands.md`
(dry-run / confirm gate / audit log / transaction+rollback / guard-before-purge).

## What it creates

Everything is read from the `Organization` (and its `Service` catalogue) **at run time** — no
hardcoded company name, industry, or product list. A second tenant never sees the first tenant's
name or products.

| Page | Slug | In menu? | Notes |
|---|---|---|---|
| Homepage | `strona-glowna` | No | `hero` + `text_block` (+ `content_grid` if the tenant has active services) + `feature_list` + `cta_banner`. Layout: `full-width` (see "Layout choice" below). |
| About | `o-nas` | Yes (header) | Always created — the one universal, industry-agnostic menu entry, so the menu is never empty even for tenants with no rental catalogue. |
| Rental link | `wypozyczalnia` | Yes (header), only if `$org->supportsRentals()` | Menu-only stand-in — see "Rental menu link" below. |

The homepage is registered as the tenant's homepage via the `cms.homepage_page_id` setting (see
"Setting write" below) — the same mechanism `PageResource`'s "Set as homepage" action uses.

## Pitfalls this command works around

### 1. `SettingsManager::set()` would leak the homepage globally

`SettingsManager::set()` targets `TenantFeature::currentTenant()?->id`, which is **always `null`
in a console command with no ambient tenant**. Calling it here would write
`organization_id = NULL` — a GLOBAL setting every tenant without their own override inherits —
silently making tenant A's homepage tenant B's homepage too. The seeder bypasses
`SettingsManager` entirely and writes the `Setting` row directly, scoped to the tenant, exactly
like `SeedOrganizationDefaults::seedSettings()` does:

```php
Setting::withoutGlobalScope('organization')->updateOrCreate(
    ['organization_id' => $org->id, 'group' => 'cms', 'key' => 'homepage_page_id'],
    ['value' => [$page->id]]   // scalars are always wrapped in an array for JSON storage
);
```

`SettingsManager::clearCache()` is private, so the seeder replicates its two cache keys manually
after every write/clear: `settings:tenant:{orgId}:cms:homepage_page_id` and
`settings:tenant:{orgId}:cms`.

**Test coverage:** `SeedWebsiteCommandTest::test_homepage_setting_is_organization_scoped_not_global`
asserts a tenant-scoped row exists AND that no `organization_id IS NULL` row for the same
group/key was created.

### 2. Layout choice: `full-width`, not `home`

`PageLayout::HOME` (`cms/layouts/home.blade.php`) looks like the obvious choice for a homepage,
but it only renders **6 of the 13** builder block types (`hero`, `content_grid`, `feature_list`,
`cta_banner`, `text_block`, `custom_html`) — any other block silently disappears, no error. The
seeder uses `PageLayout::FULL_WIDTH` instead, which renders through
`x-cms.partials.builder-blocks` (all 13 types) — the same path the one real configured page in
this codebase uses. The seeder only emits the 5 "advanced" block types anyway, so this is
forward-compatible if the seeder is ever extended with a basic block (`quote`, `two_columns`,
etc.) that `home` doesn't support at all.

### 3. `content_grid` with `content_type: 'rental_items'` is dead

`BuilderBlocks.php` references `content_type === 'rental_items'` for the "styl kart" field's
visibility, but `ContentGridResolver::CONTENT_TYPES` has no `rental_items` entry — using it
produces an empty grid with a "Brak elementów" warning. Product presentation on the homepage uses
`content_type: 'services'` with real `Service` IDs resolved at seed time
(`is_active = true`, ordered by `sort_order`) — `RentalCategory` cannot be displayed by any block
in the resolver's registry at all; there is no way to present it except linking to
`/wypozyczalnia`.

### 4. Empty catalogue: the block is omitted, not seeded empty

If the tenant has zero active services, the `content_grid` block is **not added** to `content`
at all (rather than added with `content_items: []`). An empty `content_grid` renders a visible
yellow "Brak elementów" warning box to every public visitor — exactly the kind of broken-looking
placeholder this command exists to eliminate.

### 5. `--force` purge order: setting before pages

`PageObserver::deleting()` throws if the page being deleted is the organization's current
homepage. `purge()` therefore clears `cms.homepage_page_id` **first**, then deletes the pages —
the reverse order throws on the very first `--force` re-run.

## Rental menu link (`wypozyczalnia`)

Menu items are exclusively `Page` rows (`NavigationService`/`cms-page-menu.md`) — there is no
"menu item" model. The public rental catalogue is served by `RentalController` at `/wypozyczalnia`
(`routes/web.php`), registered **ahead of** the CMS catch-all `page.show` route. A `Page` with
`slug = 'wypozyczalnia'` and `show_in_menu = true` therefore produces a working, correctly-styled
menu entry whose link (`NavigationService` builds it from `route('page.show', $page->slug)`)
always resolves to `RentalController`, never to the CMS page itself — the page's `content`/`body`
are irrelevant and left empty. `wypozyczalnia` is **not** in `Page::RESERVED_SLUGS` (that list is
enforced only in the Filament form, not the model), and this link is only created when
`$org->supportsRentals()` is true — item-rental is the first vertical, not the only one
(`auto_detailing`/`general_services` tenants get the universal `o-nas` menu entry instead).

## Homepage placeholder (`home-fallback.blade.php`)

Fixed alongside the seeder: the placeholder previously showed the developer-facing English string
"Homepage Not Configured" / "Please configure homepage in admin panel." to every public visitor,
regardless of auth state. It now shows a neutral Polish "Strona w przygotowaniu" / "Wróć do nas
wkrótce." message; the existing `@can('viewAny', Page::class)`-gated "Skonfiguruj stronę główną"
CTA is unchanged (route logic in `routes/web.php` was not touched).

**Note found while fixing this (not fixed here — no `PagePolicy` exists):** `@can('viewAny',
\App\Models\Page::class)` has no registered policy for `Page` at all, so `Gate::check()` denies
unconditionally for every user, including real admins — the CTA is currently dead code for
everyone, not just anonymous visitors. Flagged for separate investigation.

## Known issue found, not fixed here

`NavigationService::getMenuItems()` caches under `navigation.pages.{$location}` —
**no tenant ID in the cache key.** The first tenant to hit `/` on a fresh cache populates
`navigation.pages.header`/`navigation.pages.footer` for **every** tenant for up to 30 minutes
(`Page::booted()`'s `saved`/`deleted` hooks only clear these two global keys, which happens to
paper over the bug on the *same* tenant's own edits — it does nothing for a second tenant reading
a first tenant's cached menu). This is a real cross-tenant content leak, pre-existing and
unrelated to this change; out of scope for this PR.
