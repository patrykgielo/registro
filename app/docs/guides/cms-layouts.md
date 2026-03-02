# CMS Layouts System

Kompletny system layoutów Blade dla CMS w projekcie Registro.

## Spis treści

- [Przegląd](#przegląd)
- [Dostępne Layouty](#dostępne-layouty)
- [Konfiguracja w Filament](#konfiguracja-w-filament)
- [Użycie w Blade](#użycie-w-blade)
- [Responsywność](#responsywność)
- [Dostosowywanie](#dostosowywanie)

---

## Przegląd

System składa się z **4 layoutów CMS** zaprojektowanych dla różnych typów treści:

| Layout | Użycie | Max Width | Sidebar |
|--------|--------|-----------|---------|
| **Default** | Blogi, artykuły z powiązanymi treściami | `max-w-7xl` (1280px) | ✅ Tak (8+4 grid) |
| **Full-Width** | Landing pages, hero sections, galerie | Brak (edge-to-edge) | ❌ Nie |
| **Minimal** | Long-form, dokumentacja, polityka prywatności | `max-w-prose` (~65ch, 700px) | ❌ Nie |
| **Home** | Homepage, custom marketing pages | Brak (pełna kontrola) | ❌ Nie |

---

## Dostępne Layouty

### 1. Default Layout (z sidebarami)

**Lokalizacja:** `resources/views/components/layouts/cms/default.blade.php`

**Cechy:**
- Grid 12 kolumn: Main (8) + Sidebar (4)
- Sticky sidebar na desktop (`lg:sticky lg:top-24`)
- Stack na mobile (jedna kolumna)
- Breadcrumbs opcjonalnie
- Meta info (data publikacji, autor, czas czytania)
- Social share buttons
- Related pages widget
- CTA widget w sidebarze
- Contact info widget

**Użycie:**
```blade
<x-layouts.cms.default :page="$page">
    {{-- Content blocks --}}
</x-layouts.cms.default>
```

**Opcjonalne atrybuty `$page`:**
```php
$page->show_breadcrumbs = true;        // Pokaż breadcrumbs
$page->show_toc = true;                // Auto-generate spis treści z nagłówków
$page->show_social_share = true;       // Social share buttons
$page->show_sidebar_cta = true;        // CTA widget w sidebarze
```

---

### 2. Full-Width Layout

**Lokalizacja:** `resources/views/components/layouts/cms/full-width.blade.php`

**Cechy:**
- Edge-to-edge hero section z gradienty/obrazy
- Wewnętrzny container `max-w-7xl` dla treści
- Noise texture overlay (iOS style)
- Meta info w hero section
- Optional: Full-width CTA section
- Social share buttons
- Responsywne breakpointy

**Użycie:**
```blade
<x-layouts.cms.full-width :page="$page">
    {{-- Content blocks (full container width) --}}
</x-layouts.cms.full-width>
```

**Opcjonalne atrybuty `$page`:**
```php
$page->show_breadcrumbs = true;        // Breadcrumbs pod hero
$page->show_cta_section = true;        // Full-width CTA section na końcu
$page->show_social_share = true;       // Social share buttons
```

---

### 3. Minimal Layout (Article/Reading)

**Lokalizacja:** `resources/views/components/layouts/cms/minimal.blade.php`

**Cechy:**
- Optimized for reading: `max-w-prose` (65ch, ~700px)
- Clean, minimalistyczny design
- Gradient background (`from-gray-50 to-white`)
- Enhanced prose typography (większe nagłówki, lepszy spacing)
- Rounded featured image
- Author bio card (opcjonalnie)
- Related articles cards (2 kolumny)
- Minimal social share buttons

**Użycie:**
```blade
<x-layouts.cms.minimal :page="$page">
    {{-- Content blocks (wąski kontener) --}}
</x-layouts.cms.minimal>
```

**Opcjonalne atrybuty `$page`:**
```php
$page->show_breadcrumbs = true;        // Minimal breadcrumbs
$page->show_social_share = true;       // Minimal social buttons
$page->show_author_bio = true;         // Author bio card na końcu
$page->featured_image_caption = 'Caption text'; // Caption pod obrazem
```

---

### 4. Home Layout (Homepage Special)

**Lokalizacja:** `resources/views/components/layouts/cms/home.blade.php`

**Cechy:**
- Zero wrapper - content blocks mają pełną kontrolę
- Full-screen hero (80-90vh) z video/image background
- Animated background orbs (iOS style)
- Noise texture overlay
- Trust indicators (gwiazdki, gwarancja, lata doświadczenia)
- Scroll indicator (animated bounce)
- Optional: Newsletter signup section
- Optional: Stats section (4 metryki)
- Final CTA section (gradient)
- Scroll reveal animations

**Użycie:**
```blade
<x-layouts.cms.home :page="$page">
    {{-- Content blocks (pełna kontrola layoutu) --}}
</x-layouts.cms.home>
```

**Opcjonalne atrybuty `$page`:**
```php
$page->hero_video = 'videos/hero.mp4'; // Video background (jeśli dostępne)
$page->show_trust_indicators = true;   // Trust badges w hero
$page->show_newsletter_signup = true;  // Newsletter section
$page->show_stats_section = true;      // Stats (clients, projects, etc.)
$page->show_final_cta = true;          // Final CTA section
```

---

## Konfiguracja w Filament

### PageResource (Admin Panel)

Layout wybierany jest w Filamencie poprzez enum `PageLayout`:

```php
use App\Enums\PageLayout;

Forms\Components\Select::make('layout')
    ->label('Layout')
    ->options(PageLayout::options())
    ->default(PageLayout::DEFAULT->value)
    ->required()
    ->helperText('Wybierz układ strony')
```

**Opcje w admin UI:**
- **Domyślny (z sidebarami)** → `PageLayout::DEFAULT`
- **Pełna szerokość** → `PageLayout::FULL_WIDTH`
- **Minimalny (wąski)** → `PageLayout::MINIMAL`
- **Strona główna (specjalny)** → `PageLayout::HOME`

---

## Użycie w Blade

### W `pages.show.blade.php`

System automatycznie wykrywa layout z modelu `$page->layout`:

```blade
@php
    use App\Enums\PageLayout;
    $layoutType = $page->layout ?? PageLayout::DEFAULT;
@endphp

@switch($layoutType)
    @case(PageLayout::HOME)
        <x-layouts.cms.home :page="$page">
            {{-- Content --}}
        </x-layouts.cms.home>
        @break

    @case(PageLayout::FULL_WIDTH)
        <x-layouts.cms.full-width :page="$page">
            {{-- Content --}}
        </x-layouts.cms.full-width>
        @break

    @case(PageLayout::MINIMAL)
        <x-layouts.cms.minimal :page="$page">
            {{-- Content --}}
        </x-layouts.cms.minimal>
        @break

    @default
        <x-layouts.cms.default :page="$page">
            {{-- Content --}}
        </x-layouts.cms.default>
@endswitch
```

### Renderowanie bloków treści

Partial `partials/content-block.blade.php` renderuje różne typy bloków:

```blade
@foreach($page->content as $block)
    @include('partials.content-block', ['block' => $block])
@endforeach
```

**Wspierane typy bloków:**
- `hero` → Hero section z background image/gradient
- `content_grid` → Grid kart (2-4 kolumny)
- `feature_list` → Lista feature z ikonami
- `cta_banner` → Call-to-action banner
- `text_block` → Rich text block
- `custom_html` → Custom HTML
- `image` → Single image (różne rozmiary)
- `gallery` → Grid zdjęć
- `video` → Embedded video (YouTube, Vimeo)
- `cta` → Inline CTA box
- `two_columns` → 2-kolumnowy layout
- `three_columns` → 3-kolumnowy layout
- `quote` → Blockquote z autorem

---

## Responsywność

### Breakpointy (Tailwind CSS 4.0)

| Breakpoint | Min Width | Użycie |
|------------|-----------|--------|
| `sm:` | 640px | Small tablets |
| `md:` | 768px | Tablets |
| `lg:` | 1024px | Laptops (grid switcher) |
| `xl:` | 1280px | Desktops |

### Mobile-First Approach

Wszystkie layouty używają mobile-first podejścia:

```blade
{{-- Default: Stack na mobile, grid na desktop --}}
<div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
    <article class="lg:col-span-8">...</article>
    <aside class="lg:col-span-4">...</aside>
</div>

{{-- Sticky sidebar tylko na desktop --}}
<aside class="lg:sticky lg:top-24">...</aside>
```

### Container Queries (Tailwind CSS 4.0)

Dla zaawansowanych layoutów używamy container queries:

```css
@container (min-width: 768px) {
    /* Styles for larger containers */
}
```

---

## Dostosowywanie

### 1. Dodanie custom sidebar widget (Default Layout)

W `default.blade.php` dodaj widget w sekcji `<aside>`:

```blade
<aside class="lg:col-span-4">
    <div class="lg:sticky lg:top-24 space-y-6">
        {{-- Custom widget --}}
        <div class="bg-white rounded-xl shadow-ios-sm p-6">
            <h2 class="text-lg font-bold text-gray-900 mb-4">
                Custom Widget
            </h2>
            {{-- Widget content --}}
        </div>
    </div>
</aside>
```

### 2. Modyfikacja hero section (Home Layout)

W `home.blade.php` dostosuj hero section:

```blade
<section class="relative w-full min-h-[80vh] md:min-h-[90vh]">
    {{-- Custom background --}}
    <div class="absolute inset-0 bg-gradient-to-br from-cyan-500 to-blue-600">
        {{-- Your custom overlay/effects --}}
    </div>

    {{-- Hero content --}}
    <div class="container mx-auto px-4 relative z-10">
        {{-- Custom content --}}
    </div>
</section>
```

### 3. Dodanie nowego layout

1. **Dodaj enum value** w `app/Enums/PageLayout.php`:

```php
enum PageLayout: string
{
    case CUSTOM = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::CUSTOM => 'Custom Layout',
            // ...
        };
    }
}
```

2. **Utwórz komponent** w `resources/views/components/layouts/cms/custom.blade.php`:

```blade
@props(['page'])

{{-- Custom Layout --}}
<div class="custom-wrapper">
    {{ $slot }}
</div>
```

3. **Dodaj case w `pages.show.blade.php`**:

```blade
@case(PageLayout::CUSTOM)
    <x-layouts.cms.custom :page="$page">
        {{-- Content --}}
    </x-layouts.cms.custom>
    @break
```

---

## Przykłady Użycia

### Przykład 1: Blog Post (Default Layout)

```php
// W kontrolerze lub seederze
$page = Page::create([
    'title' => 'Jak dbać o lakier samochodu',
    'slug' => 'jak-dbac-o-lakier',
    'layout' => PageLayout::DEFAULT,
    'body' => '<p>Long-form article content...</p>',
    'featured_image' => 'images/car-paint.jpg',
    'published_at' => now(),
]);
```

**Wynik:** Artykuł w 8-kolumnowym layoutcie z sidebarami (TOC, related articles, CTA).

---

### Przykład 2: Landing Page (Full-Width Layout)

```php
$page = Page::create([
    'title' => 'Detailing Premium',
    'excerpt' => 'Najlepsza ochrona dla Twojego samochodu',
    'layout' => PageLayout::FULL_WIDTH,
    'featured_image' => 'images/hero-detailing.jpg',
    'content' => [
        ['type' => 'hero', 'data' => [...]],
        ['type' => 'content_grid', 'data' => [...]],
        ['type' => 'cta_banner', 'data' => [...]],
    ],
    'published_at' => now(),
]);
```

**Wynik:** Edge-to-edge hero z pełną szerokością bloków.

---

### Przykład 3: Privacy Policy (Minimal Layout)

```php
$page = Page::create([
    'title' => 'Polityka Prywatności',
    'layout' => PageLayout::MINIMAL,
    'body' => '<h2>1. Wprowadzenie</h2><p>...</p>...',
    'show_author_bio' => false,
    'show_social_share' => false,
    'published_at' => now(),
]);
```

**Wynik:** Wąski kontener (~700px) dla optymalnej czytelności długich tekstów.

---

### Przykład 4: Homepage (Home Layout)

```php
$page = Page::create([
    'title' => 'Profesjonalny Detailing w Poznaniu',
    'excerpt' => 'Przywróć blask Twojemu samochodowi',
    'layout' => PageLayout::HOME,
    'featured_image' => 'images/homepage-hero.jpg',
    'content' => [
        ['type' => 'feature_list', 'data' => [...]],
        ['type' => 'content_grid', 'data' => [...]],
        ['type' => 'cta_banner', 'data' => [...]],
    ],
    'show_trust_indicators' => true,
    'show_stats_section' => true,
    'show_newsletter_signup' => true,
    'show_final_cta' => true,
    'published_at' => now(),
]);
```

**Wynik:** Full-screen hero z animated orbs + content blocks + stats + newsletter + final CTA.

---

## Best Practices

### 1. Wybór odpowiedniego layoutu

| Typ treści | Rekomendowany layout | Dlaczego? |
|------------|----------------------|-----------|
| Blog post, artykuł | **Default** | Sidebar z related content zwiększa engagement |
| Landing page, promocja | **Full-Width** | Edge-to-edge hero maksymalizuje visual impact |
| Dokumentacja, polityka | **Minimal** | Wąski kontener (~65ch) = optymalna czytelność |
| Homepage | **Home** | Pełna kontrola layoutu dla custom marketing sections |

### 2. Accessibility

Wszystkie layouty są zgodne z **WCAG 2.2 AA**:
- ✅ Semantic HTML (`<article>`, `<aside>`, `<nav>`)
- ✅ ARIA labels (`aria-label`, `aria-hidden`)
- ✅ Keyboard navigation
- ✅ Focus indicators
- ✅ Color contrast ratio ≥4.5:1
- ✅ Responsive images z `loading="lazy"`

### 3. Performance

- **Lazy loading:** `loading="lazy"` dla wszystkich obrazów
- **Optimized shadows:** iOS-style shadows bez performance hit
- **Reduced motion:** `@media (prefers-reduced-motion)` dla animacji
- **Container queries:** Tailwind CSS 4.0 container queries dla responsywności

### 4. SEO

- **Semantic HTML:** Proper heading hierarchy (`<h1>` → `<h2>` → `<h3>`)
- **Meta tags:** `$page->meta_title`, `$page->meta_description`
- **Breadcrumbs:** Schema.org markup dla Google
- **Open Graph:** Social share tags

---

## Troubleshooting

### Problem: Sidebar nie jest sticky

**Przyczyna:** Brak wysokości na parent container.

**Rozwiązanie:**
```blade
{{-- Upewnij się, że parent ma min-height --}}
<div class="grid grid-cols-1 lg:grid-cols-12 gap-8 min-h-screen">
    ...
</div>
```

---

### Problem: Hero section za niski na mobile

**Przyczyna:** `min-h-[80vh]` może być za duży na małych ekranach.

**Rozwiązanie:**
```blade
{{-- Użyj mniejszego min-height na mobile --}}
<section class="min-h-[60vh] md:min-h-[80vh] lg:min-h-[90vh]">
    ...
</section>
```

---

### Problem: Content blocks nie renderują się

**Przyczyna:** Brak `@include('partials.content-block')` w loopie.

**Rozwiązanie:**
```blade
@foreach($page->content as $block)
    @include('partials.content-block', ['block' => $block])
@endforeach
```

---

## Changelog

| Wersja | Data | Zmiany |
|--------|------|--------|
| **1.0.0** | 2026-01-14 | Initial release: 4 layouts (Default, Full-Width, Minimal, Home) |

---

## Zobacz też

- [Filament v4 Component Architecture](filament-v4-component-architecture.md)
- [Filament v4 Best Practices](filament-v4-best-practices.md)
- [CMS System Documentation](../features/cms-system/README.md)
- [Database Schema](../architecture/database-schema.md)
