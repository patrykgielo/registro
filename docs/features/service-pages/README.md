# Service Pages Feature

Complete implementation of dedicated SEO-friendly service pages with CMS functionality.

## Overview

Service Pages extend the basic `services` table with full CMS capabilities, allowing each service to have:
- Dedicated public-facing page at `/uslugi/{slug}`
- Rich content editing (RichEditor + Builder blocks)
- SEO optimization (meta tags, Schema.org structured data)
- Publishing workflow (draft, scheduled, published)
- Integration with booking system

**Version**: v0.3.0+
**Status**: ✅ Production Ready
**Related Features**: CMS System, Booking System, Google Maps Integration

## Table of Contents

- [Architecture](#architecture)
- [Database Schema](#database-schema)
- [Implementation Details](#implementation-details)
- [Admin Panel Usage](#admin-panel-usage)
- [Frontend Display](#frontend-display)
- [SEO Implementation](#seo-implementation)
- [Integration with Booking](#integration-with-booking)
- [Migration Guide](#migration-guide)
- [Troubleshooting](#troubleshooting)

## Architecture

### Design Philosophy

Service Pages follow the **Hybrid Content System** pattern established by Pages/Posts/Promotions:

- **90% Content**: RichEditor (`body` field) for main content
- **10% Advanced**: Builder blocks (`content` field) for galleries, videos, CTAs
- **SEO-First**: Schema.org JSON-LD, breadcrumbs, OpenGraph tags
- **Publishing Workflow**: NULL = draft, future date = scheduled, past date = published

### Key Components

```
app/
├── Models/
│   └── Service.php                      # Extended model with CMS fields
├── Http/Controllers/
│   └── ServiceController.php            # Public show() + Schema.org generation
├── Filament/Resources/
│   └── ServiceResource.php              # Admin CRUD with 4 sections
resources/views/
├── services/
│   ├── index.blade.php                  # Service listing page
│   └── show.blade.php                   # Single service page
├── pages/
│   └── show.blade.php                   # CMS page renderer (includes homepage)
└── components/content-blocks/
    └── content-grid.blade.php           # Service grid for CMS pages
routes/
└── web.php                              # /uslugi/{slug} route
database/migrations/
└── 2025_12_06_add_cms_fields_to_services.php
```

## Database Schema

### Migration: Add CMS Fields to Services

**File**: `database/migrations/2025_12_06_add_cms_fields_to_services.php`

```php
Schema::table('services', function (Blueprint $table) {
    // Content fields
    $table->string('slug')->unique()->after('name');
    $table->text('excerpt')->nullable()->after('description');
    $table->text('body')->nullable()->after('excerpt');
    $table->json('content')->nullable()->after('body');

    // SEO fields
    $table->string('meta_title')->nullable()->after('content');
    $table->text('meta_description')->nullable()->after('meta_title');
    $table->string('featured_image')->nullable()->after('meta_description');

    // Publishing workflow
    $table->timestamp('published_at')->nullable()->index()->after('featured_image');

    // Schema.org structured data fields
    $table->decimal('price_from', 10, 2)->nullable()->after('price');
    $table->string('area_served')->nullable()->after('published_at');
});
```

### Field Descriptions

| Field | Type | Purpose | Nullable |
|-------|------|---------|----------|
| `slug` | string | SEO-friendly URL identifier | No |
| `excerpt` | text | Short summary for cards/SEO | Yes |
| `body` | text | Main content (RichEditor) | Yes |
| `content` | json | Builder blocks (gallery, video, CTA) | Yes |
| `meta_title` | string | SEO title (max 60 chars) | Yes |
| `meta_description` | text | SEO description (max 160 chars) | Yes |
| `featured_image` | string | Hero image for page/cards | Yes |
| `published_at` | timestamp | Publishing workflow control | Yes |
| `price_from` | decimal | "Od X PLN" pricing display | Yes |
| `area_served` | string | Local SEO (e.g., "Poznań") | Yes |

**Backwards Compatibility**: All existing fields (`id`, `name`, `description`, `duration_minutes`, `price`, `is_active`, `sort_order`) remain unchanged.

## Implementation Details

### 1. Model Extension

**File**: `app/Models/Service.php`

```php
protected $fillable = [
    // Existing fields
    'name', 'description', 'duration_minutes', 'price', 'is_active', 'sort_order',
    // CMS fields
    'slug', 'excerpt', 'body', 'content',
    'meta_title', 'meta_description', 'featured_image',
    'published_at', 'price_from', 'area_served',
];

protected $casts = [
    'price' => 'decimal:2',
    'price_from' => 'decimal:2',
    'duration_minutes' => 'integer',
    'is_active' => 'boolean',
    'content' => 'array',
    'published_at' => 'datetime',
];

// Scopes
public function scopePublished($query)
{
    return $query->whereNotNull('published_at')
                 ->where('published_at', '<=', now());
}

public function scopeDraft($query)
{
    return $query->whereNull('published_at');
}

// Auto-slug generation
protected static function booted()
{
    static::creating(function ($service) {
        if (empty($service->slug) && !empty($service->name)) {
            $service->slug = Str::slug($service->name);
        }
    });
}

// Route model binding by slug
public function getRouteKeyName(): string
{
    return 'slug';
}

// Check if published
public function isPublished(): bool
{
    return $this->published_at && $this->published_at->isPast();
}

// Duration display accessor
public function getDurationDisplayAttribute(): string
{
    return $this->formatted_duration;
}
```

### 2. Controller Implementation

**File**: `app/Http/Controllers/ServiceController.php`

**Key Features**:
- Public `show()` method for single service page
- Private methods for Schema.org JSON-LD generation
- 404 for unpublished services
- Related services logic (3 similar services)

**Schema.org JSON-LD Generation**:
```php
private function buildServiceSchema(Service $service): string
{
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'Service',
        'name' => $service->name,
        'description' => $service->excerpt ?? $service->name,
        'provider' => [
            '@type' => 'LocalBusiness',
            'name' => config('app.name'),
            'areaServed' => [
                '@type' => 'City',
                'name' => $service->area_served ?? 'Poznań',
            ],
        ],
        'serviceType' => 'Car Detailing',
        'url' => route('service.show', $service),
    ];

    // Add offers with price
    if ($service->price) {
        $schema['offers'] = [
            '@type' => 'Offer',
            'price' => $service->price,
            'priceCurrency' => 'PLN',
        ];

        if ($service->price_from) {
            $schema['offers']['priceSpecification'] = [
                '@type' => 'UnitPriceSpecification',
                'minPrice' => $service->price_from,
            ];
        }
    }

    // Add image
    if ($service->featured_image) {
        $schema['image'] = \Storage::url($service->featured_image);
    }

    return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
```

**Why move JSON-LD to controller?**
- **Problem**: Blade compiler couldn't handle nested `@if` directives inside `<script type="application/ld+json">` tags
- **Solution**: Generate clean JSON using PHP's `json_encode()` with proper flags
- **Benefit**: Cleaner Blade templates, better separation of concerns

### 3. Routing

**File**: `routes/web.php`

```php
use App\Http\Controllers\ServiceController;

// Service listing page
Route::get('/uslugi', [ServiceController::class, 'index'])->name('services.index');

// Single service page (route model binding by slug)
Route::get('/uslugi/{service:slug}', [ServiceController::class, 'show'])->name('service.show');
```

**URL Examples**:
- `/uslugi` - List all published services
- `/uslugi/mycie-podstawowe` - Single service page
- `/uslugi/detailing-kompletny` - Another service

**SEO Considerations**:
- Polish URL prefix `/uslugi/` for better local SEO
- Slug-based routing (not numeric IDs)
- Canonical URLs via Schema.org

## Admin Panel Usage

### Filament Resource: 4 Sections

**File**: `app/Filament/Resources/ServiceResource.php`

The admin form is divided into 4 collapsible sections following Pages/Posts pattern:

#### 1. Podstawowe Informacje (Basic Information)

- **Name**: Auto-generates slug on blur
- **Slug**: Editable, unique constraint
- **Excerpt**: Short summary (max 500 chars)
- **Duration**: Hours + Minutes inputs
- **Price**: Numeric with PLN prefix
- **Price From**: Optional "Od X PLN" display
- **Area Served**: Local SEO field (e.g., "Poznań")
- **Active**: Toggle visibility
- **Sort Order**: Display order

#### 2. Treść Strony (Page Content)

- **Body**: RichEditor with toolbar (bold, italic, headings, lists, links, blockquote)
- 90% of content goes here
- XSS protection via HTMLPurifier

#### 3. Zaawansowane Bloki Treści (Advanced Content Blocks)

**Collapsed by default** - Builder with 7 block types:

1. **Image**: Upload, alt text, caption, size (small/medium/large/full)
2. **Gallery**: Multiple images, grid columns (2/3/4)
3. **Video**: YouTube/Vimeo embed URL, caption
4. **CTA**: Heading, description, button text/URL, style (primary/secondary/accent)
5. **Two Columns**: Left/right RichEditor columns
6. **Three Columns**: Three RichEditor columns
7. **Quote**: Quote text, author, author title

#### 4. SEO i Publikacja (SEO & Publishing)

**Collapsed by default**:

- **Featured Image**: Upload with preview (200px height)
- **Meta Title**: Max 60 chars (fallback to `name`)
- **Meta Description**: Max 160 chars (fallback to `excerpt`)
- **Published At**: DateTime picker
  - `NULL` = Draft (not visible on frontend)
  - Future date = Scheduled (not visible until date)
  - Past date = Published (visible on frontend)

### Admin Features

**Preview Button**:
```php
Tables\Actions\Action::make('preview')
    ->label('Podgląd')
    ->icon('heroicon-o-eye')
    ->url(fn ($record) => route('service.show', $record->slug))
    ->openUrlInNewTab()
    ->visible(fn ($record) => $record->published_at?->isPast()),
```

**Published Status Badge**:
```php
Tables\Columns\TextColumn::make('published_at')
    ->label('Status')
    ->badge()
    ->formatStateUsing(function ($state) {
        if (!$state) return 'Szkic';
        if ($state->isFuture()) return 'Zaplanowana';
        return 'Opublikowana';
    })
    ->color(fn ($state) => match(true) {
        !$state => 'gray',
        $state->isFuture() => 'warning',
        default => 'success',
    }),
```

## Frontend Display

### Service Index Page

**URL**: `/uslugi`
**View**: `resources/views/services/index.blade.php`

**Features**:
- 3-column grid of service cards
- Displays: featured image, name, excerpt, price, duration
- "Zobacz Więcej" button linking to service page
- Only shows published + active services

### Single Service Page

**URL**: `/uslugi/{slug}`
**View**: `resources/views/services/show.blade.php`

**Layout Structure**:
```blade
@push('head')
    <!-- Open Graph Meta Tags -->
    <!-- SEO Meta Tags -->
    <!-- Schema.org Service JSON-LD -->
    <!-- Schema.org BreadcrumbList JSON-LD -->
@endpush

@section('content')
    <!-- Breadcrumb Navigation -->
    <nav aria-label="Breadcrumb">...</nav>

    <!-- Hero Section -->
    <div class="hero">
        <img src="{{ featured_image }}" />
        <h1>{{ name }}</h1>
        <p>{{ excerpt }}</p>
        <a href="{{ booking }}">Zarezerwuj Termin</a>
        <div class="service-meta">Duration + Price</div>
    </div>

    <!-- Main Content (body) -->
    {!! clean($service->body) !!}

    <!-- Advanced Builder Blocks (content) -->
    @foreach($service->content as $block)
        @include("components.blocks.{$block['type']}")
    @endforeach

    <!-- Related Services -->
    <div class="related-services">...</div>

    <!-- Footer CTA -->
    <a href="{{ booking }}">Zarezerwuj Termin</a>
@endsection
```

**XSS Protection**:
```blade
{!! clean($service->body) !!}
```
Uses `mews/purifier` package to sanitize HTML content.

### Homepage Integration

**Note**: Homepage uses CMS system via `pages.show` view with `content-grid` blocks.
Service cards are rendered via `components/content-blocks/content-grid.blade.php`.

**Service Card Component**: `components/ios/service-card.blade.php`

**Service Card Features** (v0.3.0+):

1. **Clickable Image**: Links to service page
2. **Clickable Heading**: Links to service page with hover effect
3. **Zobacz Szczegóły Button**: Secondary button with eye icon
4. **Zarezerwuj Termin**: Primary button (auth users only)
5. **Semantic HTML**: `<article>` instead of `<div>`
6. **Expand/Collapse**: `@click.prevent.stop` to prevent navigation

**Before/After**:
```blade
<!-- BEFORE (v0.2.x) -->
@else
    <a href="{{ route('login') }}" class="btn btn-secondary">
        Zaloguj się, aby zarezerwować
    </a>
@endelse

<!-- AFTER (v0.3.0+) -->
<a href="{{ route('service.show', $service) }}" class="btn btn-secondary">
    <svg><!-- eye icon --></svg>
    Zobacz Szczegóły
</a>
@auth
    <a href="{{ route('booking.create', $service) }}" class="btn btn-primary">
        Zarezerwuj Termin
    </a>
@endauth
```

## SEO Implementation

### Schema.org Structured Data

**Service Markup** (`@type: Service`):
```json
{
  "@context": "https://schema.org",
  "@type": "Service",
  "name": "Mycie Podstawowe",
  "description": "Profesjonalne mycie ręczne z suszeniem",
  "provider": {
    "@type": "LocalBusiness",
    "name": "Registro",
    "areaServed": {
      "@type": "City",
      "name": "Poznań"
    }
  },
  "serviceType": "Car Detailing",
  "url": "https://registro.local:8444/uslugi/mycie-podstawowe",
  "offers": {
    "@type": "Offer",
    "price": "150.00",
    "priceCurrency": "PLN"
  },
  "image": "https://registro.local:8444/storage/services/featured/mycie.jpg"
}
```

**BreadcrumbList Markup**:
```json
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {
      "@type": "ListItem",
      "position": 1,
      "name": "Strona główna",
      "item": "https://registro.local:8444"
    },
    {
      "@type": "ListItem",
      "position": 2,
      "name": "Usługi",
      "item": "https://registro.local:8444/uslugi"
    },
    {
      "@type": "ListItem",
      "position": 3,
      "name": "Mycie Podstawowe",
      "item": "https://registro.local:8444/uslugi/mycie-podstawowe"
    }
  ]
}
```

**Validation**: Test with [Google Rich Results Test](https://search.google.com/test/rich-results)

### OpenGraph Tags

```html
<meta property="og:title" content="{{ $service->meta_title ?? $service->name }}">
<meta property="og:description" content="{{ $service->meta_description ?? $service->excerpt }}">
<meta property="og:type" content="website">
<meta property="og:url" content="{{ route('service.show', $service) }}">
<meta property="og:image" content="{{ Storage::url($service->featured_image) }}">
```

### SEO Best Practices

✅ **Implemented**:
- Unique, descriptive URLs (`/uslugi/mycie-podstawowe` not `/services/1`)
- Semantic HTML (`<article>`, `<nav aria-label="Breadcrumb">`)
- Schema.org structured data (Service + BreadcrumbList)
- OpenGraph tags for social sharing
- Meta title/description with fallbacks
- Featured images for visual search
- Internal linking (homepage → service page → booking)
- Mobile-first responsive design

## Integration with Booking

### User Journey

**Guest Users**:
1. Homepage → Click "Zobacz Szczegóły" on service card
2. Service Page (`/uslugi/{slug}`) → Read full details
3. Click "Zarezerwuj Termin" → Redirected to login/register
4. After login → Booking wizard with pre-selected service

**Authenticated Users**:
1. Homepage → Click "Zarezerwuj Termin" (direct booking)
   OR Click "Zobacz Szczegóły" → Service page → "Zarezerwuj Termin"
2. Booking wizard (`/services/{service}/book`) with pre-selected service
3. Complete booking flow

### Booking Integration Code

**Service Page CTA**:
```blade
<a href="{{ route('booking.create', $service) }}"
   class="btn btn-primary">
    Zarezerwuj Termin - {{ $service->price }} zł
</a>
```

**Homepage Cards** (Authenticated):
```blade
@auth
    <a href="{{ route('booking.create', $service) }}"
       class="btn btn-primary">
        Zarezerwuj Termin
    </a>
@endauth
```

**Booking Controller** (Existing):
```php
public function create(Service $service)
{
    // Route model binding auto-loads service by ID
    // Pre-fills service in wizard via data-service attribute
    return view('booking.create', compact('service'));
}
```

## Migration Guide

### From Basic Services to Service Pages

**1. Run Migration**:
```bash
php artisan migrate
```

**2. Populate Slugs** (automatically via model event):
```php
// Existing services auto-generate slugs from name
Service::all()->each(fn($s) => $s->save());
```

**3. Set Published Dates**:
```bash
php artisan tinker
Service::query()->update(['published_at' => now()]);
```

**4. Add Content** (via Filament admin):
- Edit each service in `/admin/services`
- Add `excerpt` (short summary)
- Add `body` (main content via RichEditor)
- Upload `featured_image`
- Set `meta_title` and `meta_description`
- Optionally add `content` blocks (gallery, video, CTA)

**5. Verify Routes**:
```bash
php artisan route:list --name=service
```

Expected output:
```
GET|HEAD  uslugi .................... services.index › ServiceController@index
GET|HEAD  uslugi/{service:slug} ..... service.show › ServiceController@show
```

### Data Migration for Production

**File**: `database/migrations/2025_12_06_seed_services_cms_content.php`

```php
public function up()
{
    $services = [
        [
            'name' => 'Mycie Podstawowe',
            'slug' => 'mycie-podstawowe',
            'excerpt' => 'Profesjonalne mycie ręczne z suszeniem',
            'body' => '<p>Nasza usługa mycia podstawowego...</p>',
            'meta_title' => 'Mycie Podstawowe Auta - Registro',
            'meta_description' => 'Profesjonalne mycie ręczne. 60 minut, 150 zł.',
            'published_at' => now(),
        ],
        // ... other services
    ];

    foreach ($services as $data) {
        Service::where('name', $data['name'])->update($data);
    }
}
```

## Troubleshooting

### Issue: Blade ParseError in services/show.blade.php

**Symptom**: `syntax error, unexpected end of file, expecting "elseif" or "else" or "endif"`

**Cause**: Nested `@if` directives inside `<script type="application/ld+json">` tags confuse Blade compiler.

**Solution**: Move JSON-LD generation to controller using `json_encode()`.

**Example**:
```php
// Controller
private function buildServiceSchema(Service $service): string
{
    $schema = ['@context' => 'https://schema.org', ...];
    return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

// Blade
<script type="application/ld+json">{!! $schemaService !!}</script>
```

### Issue: Service page shows 404

**Possible Causes**:

1. **Service not published**:
```php
// Check published_at
$service->published_at; // Should be in the past
```

2. **Slug mismatch**:
```bash
php artisan tinker
Service::pluck('slug', 'name');
```

3. **Route cache**:
```bash
php artisan route:clear
php artisan route:cache
```

### Issue: Schema.org validation fails

**Validation Tool**: [Google Rich Results Test](https://search.google.com/test/rich-results)

**Common Issues**:
- Missing required fields (`name`, `provider`, `url`)
- Invalid `price` format (use decimal, not string)
- Missing `@context` or `@type`

**Debug**:
```bash
php artisan tinker
$service = Service::first();
app(ServiceController::class)->buildServiceSchema($service);
```

### Issue: Homepage cards not clickable

**Symptom**: Clicking image/heading doesn't navigate to service page.

**Solution**: Ensure Alpine.js `@click.prevent.stop` on expand button:
```blade
<button @click.prevent.stop="toggleDetails()">...</button>
```

This prevents the button from triggering parent link navigation.

## Testing Checklist

### Functional Tests

- [ ] Homepage displays service cards with images
- [ ] Clicking image navigates to service page
- [ ] Clicking heading navigates to service page
- [ ] "Zobacz Szczegóły" button works
- [ ] "Zarezerwuj Termin" button redirects to booking (auth users)
- [ ] Service page loads for published services
- [ ] Service page shows 404 for drafts/unpublished
- [ ] Breadcrumb navigation works
- [ ] Related services display correctly
- [ ] Booking CTA links to booking wizard

### Admin Tests

- [ ] Create new service with slug auto-generation
- [ ] Edit service content in RichEditor
- [ ] Add Builder blocks (image, gallery, video, CTA)
- [ ] Upload featured image
- [ ] Set meta title/description
- [ ] Publish service (set `published_at`)
- [ ] Preview button opens service page in new tab
- [ ] Status badge shows correct state (Draft/Scheduled/Published)

### SEO Tests

- [ ] Schema.org Service markup validates
- [ ] Schema.org BreadcrumbList markup validates
- [ ] OpenGraph tags present in `<head>`
- [ ] Meta description max 160 chars
- [ ] Meta title max 60 chars
- [ ] Canonical URL in Schema.org
- [ ] Featured image for social sharing

### Security Tests

- [ ] XSS protection via `clean()` function
- [ ] HTMLPurifier config allows safe HTML only
- [ ] No script injection via RichEditor
- [ ] File upload validates image types
- [ ] Slugs are URL-safe (no special chars)

## Performance Considerations

### Database Queries

**N+1 Prevention**:
```php
// ServiceController@index
$services = Service::published()
    ->active()
    ->with(['category']) // If using categories
    ->ordered()
    ->get();
```

### Caching Strategy

**Route Caching**:
```bash
php artisan route:cache
```

**View Caching**:
```bash
php artisan view:cache
```

**OPcache**:
- Production: `validate_timestamps=Off` (opcache.ini)
- Development: `validate_timestamps=On` (opcache-dev.ini)

### Image Optimization

**Featured Images**:
- Recommended size: 1200x630px (OpenGraph standard)
- Format: WebP or JPEG
- Compression: 80-90% quality

**Gallery Images**:
- Recommended size: 800x600px
- Lazy loading via `loading="lazy"` attribute

## Related Documentation

- [CMS System](../cms-system/README.md) - Content management architecture
- [Booking System](../booking-system/README.md) - Appointment booking flow
- [Database Schema](../../architecture/database-schema.md) - Complete DB structure
- [Security Patterns](../../security/patterns/file-upload-security.md) - Image upload security
- [SEO Best Practices](../../guides/seo-best-practices.md) - SEO optimization guide

## Changelog

### v0.3.0 (2025-12-08)

**Added**:
- Service Pages with dedicated URLs (`/uslugi/{slug}`)
- CMS fields (slug, excerpt, body, content, meta_title, meta_description, featured_image)
- Publishing workflow (published_at)
- Schema.org Service + BreadcrumbList structured data
- Filament admin with 4 sections (Basic, Content, Blocks, SEO)
- Homepage clickable service cards
- "Zobacz Szczegóły" button for guest users
- Related services display
- Breadcrumb navigation

**Fixed**:
- Blade ParseError with nested `@if` in JSON-LD (moved to controller)
- Missing `duration_display` accessor
- Guest user UX (no forced login, show service details first)

**SEO**:
- Polish URLs (`/uslugi/`) for local SEO
- OpenGraph tags for social sharing
- Schema.org markup for rich results
- Internal linking from homepage

---

**Maintained by**: Laravel Senior Architect Agent
**Last Updated**: 2025-12-08
**Review Cycle**: Quarterly
