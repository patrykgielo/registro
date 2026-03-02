# CMS Frontend Rendering

> Dokumentacja kontrolerów, routingu i renderowania widoków CMS.

## Spis Treści

- [Architektura Frontend](#architektura-frontend)
- [Routing](#routing)
- [Kontrolery](#kontrolery)
- [Widoki Blade](#widoki-blade)
- [Renderowanie Bloków](#renderowanie-bloków)
- [SEO i Meta Tags](#seo-i-meta-tags)
- [Caching](#caching)
- [Rozszerzanie Frontend](#rozszerzanie-frontend)

---

## Architektura Frontend

### Przepływ Żądania

```
Request: GET /strona/o-nas
          ↓
routes/web.php → Route::get('/strona/{slug}', ...)
          ↓
PageController@show($slug)
          ↓
Page::where('slug', $slug)->published()->firstOrFail()
          ↓
return view('pages.show', compact('page'))
          ↓
resources/views/pages/show.blade.php
          ↓
Response: Rendered HTML
```

### Struktura Plików

```
app/
├── Http/Controllers/
│   ├── PageController.php       # Strony statyczne
│   ├── PostController.php       # Aktualności
│   ├── PromotionController.php  # Promocje
│   └── PortfolioController.php  # Portfolio
│
resources/views/
├── layouts/
│   └── app.blade.php           # Layout główny
├── pages/
│   └── show.blade.php          # Widok strony
├── posts/
│   └── show.blade.php          # Widok posta
├── promotions/
│   └── show.blade.php          # Widok promocji
└── portfolio/
    └── show.blade.php          # Widok portfolio
```

---

## Routing

### Definicje Routes

**Lokalizacja:** `routes/web.php`

```php
// CMS Content routes
Route::get('/strona/{slug}', [PageController::class, 'show'])->name('page.show');
Route::get('/aktualnosci/{slug}', [PostController::class, 'show'])->name('post.show');
Route::get('/promocje/{slug}', [PromotionController::class, 'show'])->name('promotion.show');
Route::get('/portfolio/{slug}', [PortfolioController::class, 'show'])->name('portfolio.show');
```

### URL Patterns

| Typ | Route Name | URL Pattern | Przykład |
|-----|------------|-------------|----------|
| Page | `page.show` | `/strona/{slug}` | `/strona/o-nas` |
| Post | `post.show` | `/aktualnosci/{slug}` | `/aktualnosci/nowy-artykul` |
| Promotion | `promotion.show` | `/promocje/{slug}` | `/promocje/rabat-20` |
| Portfolio | `portfolio.show` | `/portfolio/{slug}` | `/portfolio/projekt-bmw` |

### Generowanie URL w Blade

```blade
{{-- Z named route --}}
<a href="{{ route('page.show', $page->slug) }}">{{ $page->title }}</a>

{{-- Z modelu (via accessor) --}}
<a href="{{ $page->url }}">{{ $page->title }}</a>
```

### Listy Routeów (opcjonalne)

Jeśli potrzebujesz stron listingowych:

```php
// Przykład: Lista postów
Route::get('/aktualnosci', [PostController::class, 'index'])->name('posts.index');

// Kontroler
public function index()
{
    $posts = Post::published()
        ->with('category')
        ->latest('published_at')
        ->paginate(12);

    return view('posts.index', compact('posts'));
}
```

---

## Kontrolery

### PageController

**Lokalizacja:** `app/Http/Controllers/PageController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Page;

class PageController extends Controller
{
    public function show(string $slug)
    {
        $page = Page::where('slug', $slug)
            ->where('published_at', '<=', now())
            ->firstOrFail();

        return view('pages.show', compact('page'));
    }
}
```

**Logika:**
1. Szuka strony po slug
2. Filtruje tylko opublikowane (`published_at <= now()`)
3. Zwraca 404 jeśli nie znaleziono
4. Renderuje widok z danymi strony

### PostController

**Lokalizacja:** `app/Http/Controllers/PostController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Post;

class PostController extends Controller
{
    public function show(string $slug)
    {
        $post = Post::where('slug', $slug)
            ->where('published_at', '<=', now())
            ->with('category')
            ->firstOrFail();

        return view('posts.show', compact('post'));
    }
}
```

**Różnice:**
- Eager loading kategorii (`with('category')`)
- Możliwość dodania related posts

### PromotionController

**Lokalizacja:** `app/Http/Controllers/PromotionController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Promotion;

class PromotionController extends Controller
{
    public function show(string $slug)
    {
        $promotion = Promotion::where('slug', $slug)
            ->where('active', true)
            ->where(function ($query) {
                $query->where('valid_from', '<=', now())
                    ->orWhereNull('valid_from');
            })
            ->where(function ($query) {
                $query->where('valid_until', '>=', now())
                    ->orWhereNull('valid_until');
            })
            ->firstOrFail();

        return view('promotions.show', compact('promotion'));
    }
}
```

**Logika filtrowania:**
1. `active = true` - promocja włączona
2. `valid_from <= now() OR NULL` - rozpoczęta lub bez daty początku
3. `valid_until >= now() OR NULL` - nie wygasła lub bezterminowa

### PortfolioController

**Lokalizacja:** `app/Http/Controllers/PortfolioController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\PortfolioItem;

class PortfolioController extends Controller
{
    public function show(string $slug)
    {
        $portfolioItem = PortfolioItem::where('slug', $slug)
            ->where('published_at', '<=', now())
            ->with('category')
            ->firstOrFail();

        return view('portfolio.show', compact('portfolioItem'));
    }
}
```

---

## Widoki Blade

### Struktura Widoku

Każdy widok dziedziczy z layoutu głównego:

```blade
@extends('layouts.app')

@section('content')
    {{-- Treść strony --}}
@endsection
```

### Widok: pages/show.blade.php

```blade
@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto">
    <article class="bg-white rounded-lg shadow-lg p-8">
        {{-- Header --}}
        <header class="mb-8">
            <h1 class="text-4xl font-bold text-gray-900 mb-4">{{ $page->title }}</h1>

            @if($page->featured_image)
                <img src="{{ Storage::url($page->featured_image) }}"
                     alt="{{ $page->title }}"
                     class="w-full h-96 object-cover rounded-lg mb-6">
            @endif
        </header>

        {{-- Main content (body) --}}
        @if($page->body)
            <div class="prose max-w-none mb-8">
                {!! $page->body !!}
            </div>
        @endif

        {{-- Content blocks --}}
        @if($page->content)
            @foreach($page->content as $block)
                @include('partials.blocks.' . $block['type'], ['data' => $block['data']])
            @endforeach
        @endif

        {{-- Footer --}}
        <footer class="mt-8 pt-6 border-t border-gray-200">
            <p class="text-sm text-gray-600">
                Opublikowano: {{ $page->published_at?->format('d.m.Y H:i') }}
            </p>
        </footer>
    </article>
</div>
@endsection
```

### Widok: posts/show.blade.php

```blade
@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto">
    <article class="bg-white rounded-lg shadow-lg p-8">
        {{-- Back navigation --}}
        <a href="{{ route('posts.index') }}" class="text-blue-600 hover:underline mb-4 inline-block">
            ← Powrót do aktualności
        </a>

        {{-- Header --}}
        <header class="mb-8">
            @if($post->category)
                <span class="inline-block px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm mb-4">
                    {{ $post->category->name }}
                </span>
            @endif

            <h1 class="text-4xl font-bold text-gray-900 mb-4">{{ $post->title }}</h1>

            <p class="text-gray-600">
                {{ $post->published_at?->format('d.m.Y') }}
            </p>

            @if($post->featured_image)
                <img src="{{ Storage::url($post->featured_image) }}"
                     alt="{{ $post->title }}"
                     class="w-full h-96 object-cover rounded-lg mt-6">
            @endif
        </header>

        {{-- Excerpt --}}
        @if($post->excerpt)
            <div class="text-xl text-gray-700 mb-8 font-medium">
                {{ $post->excerpt }}
            </div>
        @endif

        {{-- Main content --}}
        @if($post->body)
            <div class="prose max-w-none mb-8">
                {!! $post->body !!}
            </div>
        @endif

        {{-- Content blocks --}}
        @if($post->content)
            @foreach($post->content as $block)
                @include('partials.blocks.' . $block['type'], ['data' => $block['data']])
            @endforeach
        @endif
    </article>
</div>
@endsection
```

### Widok: promotions/show.blade.php

```blade
@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto">
    <article class="bg-white rounded-lg shadow-lg p-8">
        <header class="mb-8">
            <h1 class="text-4xl font-bold text-gray-900 mb-4">{{ $promotion->title }}</h1>

            {{-- Validity dates --}}
            @if($promotion->valid_from || $promotion->valid_until)
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                    <p class="text-yellow-800">
                        @if($promotion->valid_from && $promotion->valid_until)
                            Promocja ważna od {{ $promotion->valid_from->format('d.m.Y') }}
                            do {{ $promotion->valid_until->format('d.m.Y') }}
                        @elseif($promotion->valid_until)
                            Promocja ważna do {{ $promotion->valid_until->format('d.m.Y') }}
                        @endif
                    </p>
                </div>
            @endif

            @if($promotion->featured_image)
                <img src="{{ Storage::url($promotion->featured_image) }}"
                     alt="{{ $promotion->title }}"
                     class="w-full h-96 object-cover rounded-lg">
            @endif
        </header>

        {{-- Main content --}}
        @if($promotion->body)
            <div class="prose max-w-none mb-8">
                {!! $promotion->body !!}
            </div>
        @endif

        {{-- Content blocks --}}
        @if($promotion->content)
            @foreach($promotion->content as $block)
                @include('partials.blocks.' . $block['type'], ['data' => $block['data']])
            @endforeach
        @endif

        {{-- CTA --}}
        <div class="mt-8 p-6 bg-blue-50 rounded-lg text-center">
            <p class="text-lg mb-4">Zainteresowany? Umów się już dziś!</p>
            <a href="{{ route('booking.create') }}"
               class="inline-block px-8 py-3 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700">
                Zarezerwuj termin
            </a>
        </div>
    </article>
</div>
@endsection
```

### Widok: portfolio/show.blade.php

```blade
@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto">
    <article class="bg-white rounded-lg shadow-lg p-8">
        <header class="mb-8">
            @if($portfolioItem->category)
                <span class="inline-block px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm mb-4">
                    {{ $portfolioItem->category->name }}
                </span>
            @endif

            <h1 class="text-4xl font-bold text-gray-900 mb-4">{{ $portfolioItem->title }}</h1>
        </header>

        {{-- Before/After comparison --}}
        @if($portfolioItem->before_image && $portfolioItem->after_image)
            <div class="grid grid-cols-2 gap-4 mb-8">
                <div>
                    <p class="text-sm text-gray-600 mb-2 text-center">Przed</p>
                    <img src="{{ Storage::url($portfolioItem->before_image) }}"
                         alt="Przed"
                         class="w-full h-64 object-cover rounded-lg">
                </div>
                <div>
                    <p class="text-sm text-gray-600 mb-2 text-center">Po</p>
                    <img src="{{ Storage::url($portfolioItem->after_image) }}"
                         alt="Po"
                         class="w-full h-64 object-cover rounded-lg">
                </div>
            </div>
        @endif

        {{-- Main content --}}
        @if($portfolioItem->body)
            <div class="prose max-w-none mb-8">
                {!! $portfolioItem->body !!}
            </div>
        @endif

        {{-- Gallery --}}
        @if($portfolioItem->gallery && count($portfolioItem->gallery) > 0)
            <div class="mb-8">
                <h2 class="text-2xl font-bold mb-4">Galeria</h2>
                <div class="grid grid-cols-3 gap-4">
                    @foreach($portfolioItem->gallery as $image)
                        <img src="{{ Storage::url($image) }}"
                             alt=""
                             class="w-full h-48 object-cover rounded-lg cursor-pointer hover:opacity-75"
                             loading="lazy">
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Content blocks --}}
        @if($portfolioItem->content)
            @foreach($portfolioItem->content as $block)
                @include('partials.blocks.' . $block['type'], ['data' => $block['data']])
            @endforeach
        @endif
    </article>
</div>
@endsection
```

---

## Renderowanie Bloków

### Inline Rendering

Obecna implementacja renderuje bloki inline:

```blade
@if($page->content)
    @foreach($page->content as $block)
        @if($block['type'] === 'image')
            {{-- Render image --}}
        @elseif($block['type'] === 'gallery')
            {{-- Render gallery --}}
        @endif
    @endforeach
@endif
```

### Partial-Based Rendering (zalecane)

Dla lepszej organizacji, utwórz osobne partials:

```
resources/views/partials/blocks/
├── image.blade.php
├── gallery.blade.php
├── video.blade.php
├── cta.blade.php
├── two_columns.blade.php
├── three_columns.blade.php
└── quote.blade.php
```

**Użycie:**

```blade
@foreach($page->content as $block)
    @include('partials.blocks.' . $block['type'], ['data' => $block['data']])
@endforeach
```

**Przykład partials/blocks/image.blade.php:**

```blade
<div class="mb-8 @if($data['size'] === 'full') w-full @elseif($data['size'] === 'large') max-w-3xl mx-auto @elseif($data['size'] === 'medium') max-w-2xl mx-auto @else max-w-xl mx-auto @endif">
    <img src="{{ Storage::url($data['image']) }}"
         alt="{{ $data['alt'] ?? '' }}"
         class="w-full rounded-lg"
         loading="lazy">
    @if(!empty($data['caption']))
        <p class="text-sm text-gray-600 text-center mt-2">{{ $data['caption'] }}</p>
    @endif
</div>
```

---

## SEO i Meta Tags

### Layout z Meta Tags

```blade
{{-- layouts/app.blade.php --}}
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- SEO --}}
    <title>@yield('meta_title', config('app.name'))</title>
    <meta name="description" content="@yield('meta_description', '')">

    {{-- Open Graph --}}
    <meta property="og:title" content="@yield('meta_title', config('app.name'))">
    <meta property="og:description" content="@yield('meta_description', '')">
    <meta property="og:image" content="@yield('og_image', asset('images/default-og.jpg'))">
    <meta property="og:type" content="website">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @yield('content')
</body>
</html>
```

### Widok z SEO

```blade
{{-- pages/show.blade.php --}}
@extends('layouts.app')

@section('meta_title', $page->meta_title ?? $page->title)
@section('meta_description', $page->meta_description ?? '')
@section('og_image', $page->featured_image ? Storage::url($page->featured_image) : asset('images/default-og.jpg'))

@section('content')
    {{-- ... --}}
@endsection
```

### Structured Data (JSON-LD)

```blade
@push('head')
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": "{{ $post->title }}",
    "datePublished": "{{ $post->published_at->toIso8601String() }}",
    "dateModified": "{{ $post->updated_at->toIso8601String() }}",
    @if($post->featured_image)
    "image": "{{ Storage::url($post->featured_image) }}",
    @endif
    "author": {
        "@type": "Organization",
        "name": "{{ config('app.name') }}"
    }
}
</script>
@endpush
```

---

## Caching

### View Caching

```bash
# Cache wszystkich widoków
php artisan view:cache

# Clear cache
php artisan view:clear
```

### Query Caching

```php
// W kontrolerze
public function show(string $slug)
{
    $page = Cache::remember("page:{$slug}", 3600, function () use ($slug) {
        return Page::where('slug', $slug)
            ->published()
            ->firstOrFail();
    });

    return view('pages.show', compact('page'));
}
```

### Cache Invalidation

```php
// W modelu (boot method)
protected static function booted(): void
{
    static::saved(function ($page) {
        Cache::forget("page:{$page->slug}");
    });

    static::deleted(function ($page) {
        Cache::forget("page:{$page->slug}");
    });
}
```

### Fragment Caching w Blade

```blade
@cache('sidebar-' . $page->id, 3600)
    <aside>
        {{-- Expensive sidebar content --}}
    </aside>
@endcache
```

---

## Rozszerzanie Frontend

### Dodawanie Nowych Widoków

1. **Utwórz kontroler:**

```php
// app/Http/Controllers/NewsletterController.php
class NewsletterController extends Controller
{
    public function index()
    {
        $posts = Post::published()
            ->whereHas('category', fn($q) => $q->where('slug', 'newsletter'))
            ->latest('published_at')
            ->paginate(12);

        return view('newsletter.index', compact('posts'));
    }
}
```

2. **Dodaj route:**

```php
Route::get('/newsletter', [NewsletterController::class, 'index'])->name('newsletter.index');
```

3. **Utwórz widok:**

```blade
{{-- resources/views/newsletter/index.blade.php --}}
@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto">
    <h1 class="text-3xl font-bold mb-8">Newsletter</h1>

    <div class="grid grid-cols-3 gap-6">
        @foreach($posts as $post)
            <article class="bg-white rounded-lg shadow">
                @if($post->featured_image)
                    <img src="{{ Storage::url($post->featured_image) }}"
                         class="w-full h-48 object-cover rounded-t-lg">
                @endif
                <div class="p-4">
                    <h2 class="font-bold mb-2">{{ $post->title }}</h2>
                    <p class="text-gray-600 text-sm">{{ $post->excerpt }}</p>
                    <a href="{{ route('post.show', $post->slug) }}"
                       class="text-blue-600 hover:underline mt-2 inline-block">
                        Czytaj więcej →
                    </a>
                </div>
            </article>
        @endforeach
    </div>

    {{ $posts->links() }}
</div>
@endsection
```

### Komponenty Blade

```blade
{{-- components/post-card.blade.php --}}
@props(['post'])

<article {{ $attributes->merge(['class' => 'bg-white rounded-lg shadow']) }}>
    @if($post->featured_image)
        <img src="{{ Storage::url($post->featured_image) }}"
             class="w-full h-48 object-cover rounded-t-lg"
             loading="lazy">
    @endif
    <div class="p-4">
        <h2 class="font-bold mb-2">{{ $post->title }}</h2>
        <p class="text-gray-600 text-sm">{{ $post->excerpt }}</p>
        <a href="{{ route('post.show', $post->slug) }}"
           class="text-blue-600 hover:underline mt-2 inline-block">
            Czytaj więcej →
        </a>
    </div>
</article>

{{-- Użycie --}}
<x-post-card :post="$post" class="hover:shadow-lg" />
```

---

## Powiązana Dokumentacja

- [CMS System Overview](./README.md)
- [Content Types Reference](./content-types.md)
- [Content Blocks Reference](./content-blocks.md)
- [Admin Panel Guide](./admin-panel.md)
- [Blade Templates](https://laravel.com/docs/blade)
