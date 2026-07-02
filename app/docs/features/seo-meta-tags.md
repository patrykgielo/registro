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

**When adding a new content-detail controller/view:** call `MetaTagBuilder::forModel()` in the controller, pass `metaTitle`/`metaDescription` to the view, and only push OG/JSON-LD from the view — do not hand-roll a `<title>` override again (that's exactly the bug this phase fixed).

## Files

- `app/Support/Seo/MetaTagBuilder.php` (new)
- `resources/views/layouts/app.blade.php` (owns `<title>`/description)
- `app/Http/Controllers/{Service,Post,Portfolio,Page}Controller.php`
- `resources/views/{services,posts,portfolio,pages}/show.blade.php`

## Out of scope (Phase B/C/D — see plan)

Category archive pages, clickable category badges, and `sitemap.xml` are separate phases and not covered here.
