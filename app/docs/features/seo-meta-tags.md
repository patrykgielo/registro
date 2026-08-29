# SEO Meta Tags (Phase A)

## Problem

`meta_title`/`meta_description` DB columns existed on `Post`, `PortfolioItem`, `Page` (and `Service`) but nothing rendered them in `<head>` for Post/Portfolio/Page — the columns were silently dropped on the frontend. Separately, `Service` (the only working example) had a **duplicate `<title>` bug**: `resources/views/layouts/app.blade.php` rendered an unconditional `<title>` at the top of `<head>`, and `services/show.blade.php` pushed a *second* `<title>` via `@push('head')` (emitted later in the same file). Browsers/crawlers use the first `<title>` — the wrong, generic one.

## Fix

`layouts/app.blade.php` is now the **single source of truth** for `<title>` and `<meta name="description">`:

```blade
<title>{{ $metaTitle ?? $title ?? config('app.name') }}</title>
@if($metaDescription ?? null)
    <meta name="description" content="{{ $metaDescription }}">
@endif
```

`@stack('head')` (still emitted later in the layout) is now reserved for **Open Graph tags and JSON-LD only** — those don't have a duplication risk since there's only one `og:*`/script family per page, not a title-vs-title collision.

## `App\Support\Seo\MetaTagBuilder`

Stateless helper, no DB access — reads already-loaded model attributes only:

```php
MetaTagBuilder::forModel($model, array $overrides = []): array
// returns ['metaTitle' => ?string, 'metaDescription' => ?string]
```

Fallback chain per model (`meta_title`/`meta_description` win when present and non-empty):

| Model | Title fallback | Description fallback |
|-------|-----------------|------------------------|
| `Post` | `title` | `excerpt` |
| `PortfolioItem` | `title` | `Str::limit(strip_tags($body), 160)` (no `excerpt` field) |
| `Page` | `title` | `null` (no body-derived fallback) |
| `Service` | `name` | `excerpt` |

When the model has **no** explicit `meta_title`, the resolved fallback title gets `' — ' . config('app.name')` appended (branding suffix). Admin-authored `meta_title` values are used verbatim — no suffix.

`$overrides` lets a caller force either key (e.g. a future OG-image-specific title variant) — the class stays generic and does not hardcode image logic.

## Controller wiring

Each `show()` controller spreads the builder's result into the `view()` data array:

```php
return view('posts.show', [
    'post' => $post,
    ...
    ...MetaTagBuilder::forModel($post),
]);
```

Wired in: `ServiceController::show()`, `PostController::show()`, `PortfolioController::show()`, `PageController::show()`.

### Non-controller code path: the homepage closure (fixed in Phase B)

`routes/web.php`'s `Route::...->get('/', function () {...})->name('home')` also renders `pages.show` directly (when a tenant has `cms.homepage_page_id` configured) — bypassing `PageController::show()` entirely. Phase A's controller sweep missed this closure since it isn't a controller method, which left `pages/show.blade.php`'s `@push('head')` block (`{{ $metaTitle }}`, no `??` fallback) referencing an undefined variable — a hard `ErrorException`, not a silent fallback, crashing `GET /` for any tenant with a CMS homepage configured. Fixed by spreading `MetaTagBuilder::forModel($page)` into the closure's `view()` data array, same as the controller. The other branch of the closure (`view('home-fallback')`, used when no CMS homepage is set or the page isn't published) does **not** reference `$metaTitle`/`$metaDescription` at all, so it was never affected.

**Lesson:** when sweeping controllers for a cross-cutting concern, also grep `routes/*.php` for inline closures that render the same views — they don't show up in a `Controller` file search.

## View wiring — pattern for new content-detail pages

Detail views only need `@push('head')` for OG tags + structured data — **never** re-add a `<title>` or `<meta name="description">` tag there, the layout already owns those via `$metaTitle`/`$metaDescription`:

```blade
@push('head')
    <meta property="og:title" content="{{ $metaTitle }}">
    @if($metaDescription)
        <meta property="og:description" content="{{ $metaDescription }}">
    @endif
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('post.show', $post->slug) }}">
    @if($post->featured_image)
        <meta property="og:image" content="{{ Storage::url($post->featured_image) }}">
    @endif
@endpush
```

OG image source per model: `Post`/`Page` use `featured_image`; `PortfolioItem` has no `featured_image` field, uses `before_image` as the fallback.

`Storage::url()` resolves against the **tenant's own subdomain**, not `APP_URL`, because
`ResolveTenant` overwrote `filesystems.disks.public.url` with the request origin — the same
dependency the sitemap section documents for `route()`. Outside the request cycle (a queued
job or a console command rendering the same view) it falls back to `APP_URL`, i.e. the root
domain, which for an `og:image` means every social-media preview points at the wrong host.
Before this was forced, that was the live behaviour for every tenant.

**When adding a new content-detail controller/view:** call `MetaTagBuilder::forModel()` in the controller, pass `metaTitle`/`metaDescription` to the view, and only push OG/JSON-LD from the view — do not hand-roll a `<title>` override again (that's exactly the bug this phase fixed).

## Files

- `app/Support/Seo/MetaTagBuilder.php` (Phase A + B: Category support)
- `resources/views/layouts/app.blade.php` (owns `<title>`/description)
- `app/Http/Controllers/{Service,Post,Portfolio,Page}Controller.php`
- `resources/views/{services,posts,portfolio,pages}/show.blade.php`
- `database/migrations/2026_07_02_000001_add_meta_fields_to_categories_table.php` (Phase B, new)
- `app/Models/Category.php`, `app/Filament/Resources/Categories/CategoryResource.php` (Phase B)
- `resources/views/{posts,portfolio}/category.blade.php` (Phase B, new)
- `resources/views/components/cms/card.blade.php` (Phase B, new — extracted from `content-grid.blade.php`)

## Phase B — Category archive pages

`GET /aktualnosci/kategoria/{category:slug}` and `GET /portfolio/kategoria/{category:slug}` render a paginated (9/page) archive of published `Post`/`PortfolioItem` records for that category, using the previously-unused `scopeInCategory()` on both models.

### Category meta fields (migration)

`categories` gained `meta_title` (nullable string, after `description`) and `meta_description` (nullable text) — same shape as Post/Portfolio/Page/Service. Added to `Category::$fillable` and to `CategoryResource`'s form (`Meta tytuł` / `Meta opis`, same labels/limits as `PostResource`/`PortfolioItemResource`).

### `MetaTagBuilder` extended for `Category`

`Category` has `name` (not `title`) and `description` (not `excerpt`) — `MetaTagBuilder::forModel()`'s union type and both fallback-chain `match()` blocks were extended with explicit `Category` arms rather than relying on the `default` arm (which assumes `->title`/`->excerpt`):

| Model | Title fallback | Description fallback |
|-------|-----------------|------------------------|
| `Category` | `name` | `description` |

### Routing and type scoping

Routes are registered in the same `Route::middleware([ResolveTenant::class])` group as `post.show`/`portfolio.show` in `routes/web.php`. The 3-segment `/kategoria/{slug}` paths never collide with the 2-segment `/{slug}` detail routes regardless of registration order (Laravel matches by segment count).

Each controller method uses implicit route-model binding (`{category:slug}`) then guards the category's `type` so a post-type category slug 404s under the portfolio route and vice versa:

```php
public function category(Category $category): View
{
    abort_unless($category->type === 'post', 404); // 'portfolio' in PortfolioController

    $items = Post::published()->inCategory($category->id)->latest('published_at')->paginate(9);
    $allCategories = Category::postCategories()->get(); // portfolioCategories() in PortfolioController

    return view('posts.category', ['category' => $category, 'items' => $items, 'allCategories' => $allCategories, ...MetaTagBuilder::forModel($category)]);
}
```

### Views

`resources/views/posts/category.blade.php` and `resources/views/portfolio/category.blade.php` follow the structural pattern of `resources/views/rentals/category.blade.php`: breadcrumb, sticky sidebar (desktop) / horizontal pill nav (mobile) of sibling categories with active-state highlighting, 3-column card grid, empty state, and `{{ $items->links() }}` (Laravel's default Tailwind paginator — first public use of pagination in this app, no custom paginator view exists).

### `<x-cms.card>` component (dedup)

The CMS card markup previously duplicated inline in `content-grid.blade.php` (posts/promotions/portfolio grid items) was extracted to `resources/views/components/cms/card.blade.php`, props `:item`, `:url`, `:dark`. `content-grid.blade.php` now calls `<x-cms.card :item="$item" :url="$itemUrl" :dark="$isDark" />` instead of an inline `<article>` block. The two new category archive views use the same component, built with `route('post.show', $item->slug)` / `route('portfolio.show', $item->slug)`.

Image fallback inside the component: `$item->featured_image ?? $item->before_image ?? null` — `Post` has `featured_image`, `PortfolioItem` doesn't (uses `before_image` instead, same fallback OG tags already use in `portfolio/show.blade.php`).

### Deliberate MVP scope limits

- Archive shows only exact `category_id` matches — no descendant-category inclusion (`Category.parent_id` hierarchy is not walked).
- No sitemap changes (Phase D) and no clickable category badge on detail pages yet (Phase C) — those are separate phases per the plan.

## Phase C — Clickable category badges

The category badge on `posts/show.blade.php` (via `components/cms/partials/content-header.blade.php`) and on `portfolio/show.blade.php` (header badge + footer "Kategoria: X") is now an `<a href="{{ route('post.category', ...) }}">` / `route('portfolio.category', ...)` link to the Phase B archive, instead of a non-interactive `<span>`. No controller changes — `category` was already eager-loaded. Focus-visible state uses the working v5.0 token pattern (`focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand`), not the legacy `ring-primary-*`/`ring-purple-*` convention seen elsewhere in these CMS partials — `primary-*` is not a defined Tailwind color in this app (no `tailwind.config.js` extension, no `--color-primary-*` token in `design-tokens.css`; it only exists in `resources/css/filament/admin.css` for the admin panel), so `bg-primary-50`/`ring-primary-400` on the post badge are pre-existing no-op classes on the frontend bundle.

## Phase D — Sitemap per tenant

`GET /sitemap.xml` returns a `<urlset>` XML sitemap of the current tenant's published `Page`s, `Post`s (+ one `post.category` URL per distinct category in use), `PortfolioItem`s (+ one `portfolio.category` URL per distinct category in use), and active `Service`s.

### VULN-003 — route registered with `RequireTenant` from the start

This route queries tenant-owned models, so — same as every other content route in `routes/web.php` — it's registered as:

```php
Route::middleware([ResolveTenant::class, RequireTenant::class])
    ->get('/sitemap.xml', \App\Http\Controllers\SitemapController::class)
    ->name('sitemap');
```

Registered before the catch-all `page.show` route (same requirement every other content route already follows). Without `RequireTenant`, the bare root domain would 200 with an unscoped (or empty, since `TenantFeature::currentTenant()` would be null) sitemap instead of 404ing — exactly the class of bug VULN-003 fixed elsewhere. Regression-tested in `tests/Feature/Seo/SitemapTest.php::test_sitemap_returns_404_on_root_domain`.

### `App\Support\Seo\SitemapBuilder`

`build(Organization $tenant): string` — every query is **explicitly** filtered by `where('organization_id', $tenant->id)`, on top of (not instead of) each model's own `BelongsToOrganization` global scope. Defense in depth: this builder could plausibly run outside a per-request tenant context in the future (e.g. a console command looping over tenants), where the scope's `TenantFeature::currentTenant()` resolution can't be relied upon.

Content gathered, each with `<loc>` (absolute URL via `route()`, resolved against the current tenant subdomain since `ResolveTenant` already called `URL::forceRootUrl()` for this request) and `<lastmod>` (`updated_at->toAtomString()`):

- `Page::published()` (`whereNotNull('published_at')` + `<= now()`)
- `Post::published()`, plus a `post.category` URL per distinct `category_id` actually in use among those posts
- `PortfolioItem::published()`, plus a `portfolio.category` URL per distinct category in use
- Active `Service`s — mirrors `ServiceController::index()`'s condition exactly: `time_slot` must be `published()`, `item_rental` only needs `is_active` (no `published_at` workflow for rentals)

XML is built with `SimpleXMLElement`, not string concatenation — `addChild()` does **not** escape XML special characters (verified: an unescaped `&` in a URL throws `unterminated entity reference`), so every `<loc>` value is passed through `htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8')` before `addChild()`.

### `App\Http\Controllers\SitemapController`

```php
public function __invoke(SitemapBuilder $builder): Response
{
    $tenant = TenantFeature::currentTenant();
    abort_unless($tenant !== null, 404); // defense in depth — RequireTenant already guarantees this

    $xml = Cache::remember("sitemap:{$tenant->id}", now()->addHour(), fn () => $builder->build($tenant));

    return response($xml, 200)->header('Content-Type', 'application/xml');
}
```

Cache key mirrors the tenant-resolution cache pattern already used in `ResolveTenant.php` (`Cache::remember` per key, not a global sitemap cache).

### Cache invalidation — `App\Observers\SitemapCacheObserver`

A single observer (`saved`/`deleted` hooks calling `Cache::forget("sitemap:{$model->organization_id}")`) is registered on all three content models in `AppServiceProvider::boot()`:

```php
PageModel::observe(SitemapCacheObserver::class);
Post::observe(SitemapCacheObserver::class);
PortfolioItem::observe(SitemapCacheObserver::class);
```

One shared observer instead of three near-identical `booted()` hooks (the pattern `Page::booted()` already uses for its own, unrelated navigation-menu cache) — keeps the "sitemap" cache-invalidation concern out of the CMS models entirely and follows the project's existing Observer convention (`AppointmentObserver`, `OrganizationObserver`, `PageObserver`, `UserObserver` are all registered the same way). This means publishing/unpublishing/deleting content invalidates the cached sitemap within the same request, instead of waiting up to an hour for the `Cache::remember` TTL to expire.

### Deliberate scope limits (per plan)

- No RentalCategory/Wypożyczalnia URLs (out of original scope; same mechanism could extend to it later).
- No `<sitemap>` index file (single flat `<urlset>` is sufficient at current content volume).
- No `robots.txt` changes.
- Archive category URLs only include categories actually referenced by at least one published item — an empty category (no published posts/portfolio items) does not get a sitemap entry, since there'd be nothing to link to from it in the current MVP archive view either.

### Files

- `app/Support/Seo/SitemapBuilder.php` (new)
- `app/Http/Controllers/SitemapController.php` (new)
- `app/Observers/SitemapCacheObserver.php` (new)
- `app/Providers/AppServiceProvider.php` (registers the observer on `Page`/`Post`/`PortfolioItem`)
- `routes/web.php` (`GET /sitemap.xml` → `sitemap`, `ResolveTenant` + `RequireTenant`)
- `tests/Feature/Seo/SitemapTest.php` (new — valid XML + tenant content, root-domain 404, cross-tenant isolation)
