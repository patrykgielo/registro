# Content Management System (CMS)

**Status:** ✅ Production Ready
**Version:** 1.0
**Last Updated:** November 26, 2025

## Overview

Complete content management system with 4 content types, Filament v4 admin panel, and public frontend with Blade views.

### Content Types

| Type | Purpose | URL Pattern | Admin URL |
|------|---------|-------------|-----------|
| **Pages** | Static content (About, Services, Contact) | `/strona/{slug}` | `/admin/pages` |
| **Posts** | Blog articles & news | `/aktualnosci/{slug}` | `/admin/posts` |
| **Promotions** | Special offers & campaigns | `/promocje/{slug}` | `/admin/promotions` |
| **Portfolio** | Project showcases with before/after | `/portfolio/{slug}` | `/admin/portfolio-items` |
| **Categories** | Hierarchical organization | - | `/admin/categories` |

### Key Features

- ✅ **Hybrid Content System:** RichEditor (main) + Builder blocks (advanced)
- ✅ **Content Blocks:** image, gallery, video, CTA, columns, quotes
- ✅ **SEO Optimization:** meta_title, meta_description, featured_image
- ✅ **Publishing Workflow:** draft → scheduled → published states
- ✅ **Categories:** Hierarchical for Posts/Portfolio (parent/children)
- ✅ **Before/After Images:** Portfolio transformation showcase
- ✅ **Preview Buttons:** Open frontend in new tab (admin)
- ✅ **Auto-Slug Generation:** From title on blur

---

## Quick Start

### 1. Access Admin Panel

```bash
# Visit admin panel
https://registro.local:8444/admin

# Navigate to content sections
/admin/pages         # Static pages
/admin/posts         # Blog/News
/admin/promotions    # Special offers
/admin/portfolio-items  # Project showcase
/admin/categories    # Organize content
```

### 2. Create Your First Page

1. Go to **Admin → Pages**
2. Click **"Nowa strona"** (New Page)
3. Fill in basic fields:
   - **Title:** "O nas" (auto-generates slug: `o-nas`)
   - **Layout:** "Domyślny" (default with sidebars)
   - **Published At:** Leave empty for draft, or set date
4. Add main content in **RichEditor** (body field)
5. *(Optional)* Add advanced blocks (image, gallery, CTA, etc.)
6. Set **SEO fields** (meta_title, meta_description, featured_image)
7. Click **"Zapisz"** (Save)
8. Preview with **eye icon** button → opens `/strona/o-nas` in new tab

### 3. Content Workflow

```
Draft (no published_at)
   ↓ Set published_at to future date
Scheduled (published_at > now)
   ↓ Wait for date to pass
Published (published_at <= now) → Visible on frontend
```

---

## Architecture

### Database Schema

```
pages
├── id, title, slug (unique)
├── body (TEXT) - RichEditor main content
├── content (JSON) - Builder blocks (optional)
├── layout (enum) - default, full-width, minimal
├── published_at (datetime nullable) - Publishing date
└── SEO: meta_title, meta_description, featured_image

posts
├── Similar to pages +
├── excerpt (TEXT) - Short description for listings
└── category_id (FK → categories)

promotions
├── Similar to pages +
├── active (boolean) - Enable/disable toggle
└── valid_from, valid_until (datetime nullable) - Date range

portfolio_items
├── Similar to pages +
├── before_image, after_image - Transformation showcase
├── gallery (JSON) - Additional images array
└── category_id (FK → categories)

categories
├── id, name, slug, description
├── parent_id (FK → self) - Hierarchical structure
└── type (enum: 'post', 'portfolio') - Content type
```

### Models

- **`Page`** - `app/Models/Page.php`
- **`Post`** - `app/Models/Post.php`
- **`Promotion`** - `app/Models/Promotion.php`
- **`PortfolioItem`** - `app/Models/PortfolioItem.php`
- **`Category`** - `app/Models/Category.php`

**Scopes:**
- `published()` - published_at <= now()
- `draft()` - published_at IS NULL
- `active()` - active = true (Promotions)
- `valid()` - Within valid_from/valid_until (Promotions)

### Controllers

All controllers filter by published status and return 404 if not found:

- **`PageController`** - `/strona/{slug}`
- **`PostController`** - `/aktualnosci/{slug}` (eager loads category)
- **`PromotionController`** - `/promocje/{slug}` (checks active + date range)
- **`PortfolioController`** - `/portfolio/{slug}` (eager loads category)

### Filament Resources

Full Resources (separate Create/Edit/List pages):

- **`PageResource`** - `/admin/pages/*`
- **`PostResource`** - `/admin/posts/*`
- **`PromotionResource`** - `/admin/promotions/*`
- **`PortfolioItemResource`** - `/admin/portfolio-items/*`
- **`CategoryResource`** - `/admin/categories/*`

---

## Hybrid Content System

### Why Hybrid?

**Problem:** Need both simple text editing AND advanced layouts.

**Solutions Considered:**
1. ❌ Pure Builder - Too complex for simple text
2. ❌ Pure RichEditor - Too limited for advanced layouts
3. ✅ Hybrid - Best of both worlds

### How It Works

```php
// Database Structure
body (TEXT)     - Primary content (RichEditor)
content (JSON)  - Advanced blocks (Builder) [optional]

// Rendering Priority
1. Render `body` content (always)
2. Render `content` blocks (if exists)
```

**Example:**

```php
// Admin Form
RichEditor::make('body')
    ->label('Treść strony')
    ->required()
    ->toolbarButtons(['bold', 'italic', 'link', 'bulletList', ...])

Builder::make('content')
    ->label('Dodatkowe bloki')
    ->blocks([
        Block::make('image')->schema([...]),
        Block::make('gallery')->schema([...]),
        Block::make('cta')->schema([...]),
        // ... more blocks
    ])
    ->collapsed()  // Collapsed by default (90% of content doesn't need it)
```

**Result:**
- 90% of content uses only RichEditor (fast editing)
- 10% of content adds advanced blocks when needed
- No migration needed - hybrid works from day one

---

## Content Blocks Reference

### Available Blocks

| Block | Purpose | Fields |
|-------|---------|--------|
| **Image** | Single image with caption | image, alt, caption, size (small/medium/large/full) |
| **Gallery** | Multiple images grid | images (array), columns (2/3/4) |
| **Video** | YouTube/Vimeo embed | url, caption |
| **CTA** | Call-to-action box | heading, description, button_text, button_url, style |
| **Two Columns** | Side-by-side content | left_column, right_column (RichEditor) |
| **Three Columns** | Triple content layout | column_1, column_2, column_3 (RichEditor) |
| **Quote** | Testimonial/quote box | quote, author, author_title |

**See:** [Content Blocks Reference](./content-blocks.md)

---

## SEO Features

### Meta Fields

All content types have:

```php
meta_title (varchar 255)         // Custom title for <title> tag
meta_description (text)           // Custom description for <meta name="description">
featured_image (varchar 255)     // OG:image, Twitter Card image
```

### Frontend Implementation

```blade
{{-- Blade Template (resources/views/pages/show.blade.php) --}}
<head>
    <title>{{ $page->meta_title ?? $page->title }} - {{ config('app.name') }}</title>
    <meta name="description" content="{{ $page->meta_description ?? Str::limit(strip_tags($page->body), 160) }}">

    @if($page->featured_image)
        <meta property="og:image" content="{{ Storage::url($page->featured_image) }}">
        <meta name="twitter:image" content="{{ Storage::url($page->featured_image) }}">
    @endif
</head>
```

---

## Publishing Workflow

### States

```
DRAFT → SCHEDULED → PUBLISHED
```

| State | Condition | Frontend Visible | Admin Preview |
|-------|-----------|------------------|---------------|
| **Draft** | `published_at` IS NULL | ❌ No | ✅ Yes |
| **Scheduled** | `published_at` > now() | ❌ No | ✅ Yes |
| **Published** | `published_at` <= now() | ✅ Yes | ✅ Yes |

### Examples

```php
// Draft - no published_at
$page = Page::create([
    'title' => 'About Us',
    'slug' => 'about-us',
    'body' => '...',
    'published_at' => null,  // Draft
]);

// Scheduled - future date
$page->published_at = now()->addDays(7);  // Publish in 7 days
$page->save();

// Published - past/present date
$page->published_at = now();  // Publish now
$page->save();
```

---

## Categories

### Hierarchical Structure

```
Categories (type = 'post')
├── Aktualności (parent_id = null)
│   ├── Nowe usługi (parent_id = 1)
│   └── Promocje (parent_id = 1)
└── Porady (parent_id = null)

Categories (type = 'portfolio')
├── Detailing zewnętrzny (parent_id = null)
├── Detailing wewnętrzny (parent_id = null)
└── Korekta lakieru (parent_id = null)
```

### Admin Management

```
/admin/categories
- Create category → Select type (post/portfolio)
- Optional parent category (for nested structure)
- Auto-slug from name
- Description field (optional)
```

---

## Preview Functionality

### Admin Preview Button

```php
// In Filament Resource table()
->actions([
    Action::make('preview')
        ->label('Podgląd')
        ->icon('heroicon-o-eye')
        ->url(fn (Page $record) => route('page.show', $record->slug))
        ->openUrlInNewTab()
        ->visible(fn (Page $record) => $record->published_at && $record->published_at->isPast()),
    // ... EditAction, DeleteAction
])
```

**Behavior:**
- Eye icon button in table row
- Opens frontend URL in new tab
- Only visible for published content
- Works even for scheduled content (admin can preview before publish)

---

## Migration from Builder-Only

### What Changed?

**Before (Pure Builder):**
```php
content (JSON) - All blocks including "text" blocks
```

**After (Hybrid):**
```php
body (TEXT)    - Primary content (extracted from "text" blocks)
content (JSON) - Advanced blocks only (no "text" blocks)
```

### Migration Command

```bash
php artisan migrate
# Migration: 2025_11_26_010000_add_body_column_to_cms_tables.php
```

**What It Does:**
1. Adds `body` TEXT column to 4 tables (pages, posts, promotions, portfolio_items)
2. Migrates existing Builder "text" blocks → body field
3. Removes "text" blocks from content JSON
4. Preserves all other blocks (image, gallery, video, etc.)

---

## Troubleshooting

### Content Not Showing on Frontend

**Check:**
1. Is `published_at` set and <= now()?
2. Is slug correct in URL?
3. Clear Laravel cache: `php artisan optimize:clear`
4. Check route: `php artisan route:list | grep strona`

### Preview Button Not Working

**Check:**
1. Import added: `use Filament\Tables\Actions\Action;`
2. Using correct namespace: `Action::make()` (not `Tables\Actions\Action::make()`)
3. OPcache cleared: Restart containers

### Images Not Loading

**Check:**
1. Storage symlink: `php artisan storage:link`
2. File exists in `storage/app/public/`
3. Using `Storage::url()` in Blade: `{{ Storage::url($page->featured_image) }}`

---

## Related Documentation

- [Content Types Reference](./content-types.md) - Detailed field guide for each type
- [Admin Panel Guide](./admin-panel.md) - Filament Resources walkthrough
- [Content Blocks](./content-blocks.md) - Builder blocks reference
- [Frontend Rendering](./frontend.md) - Controllers, routes, Blade views
- [Database Schema](../../architecture/database-schema.md) - CMS tables structure
- [Project Map](../../project_map.md) - Models & controllers documentation

---

## See Also

- **Filament Documentation:** https://filamentphp.com/docs/4.x
- **TipTap (RichEditor):** https://tiptap.dev/
- **Laravel Storage:** https://laravel.com/docs/12.x/filesystem
