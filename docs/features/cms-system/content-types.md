# CMS Content Types Reference

> Szczegółowa dokumentacja wszystkich typów treści w systemie CMS.

## Spis Treści

- [Przegląd Typów Treści](#przegląd-typów-treści)
- [1. Strony (Pages)](#1-strony-pages)
- [2. Aktualności (Posts)](#2-aktualności-posts)
- [3. Promocje (Promotions)](#3-promocje-promotions)
- [4. Portfolio (Portfolio Items)](#4-portfolio-portfolio-items)
- [5. Kategorie (Categories)](#5-kategorie-categories)
- [Kiedy Używać Którego Typu](#kiedy-używać-którego-typu)
- [Wspólne Wzorce](#wspólne-wzorce)

---

## Przegląd Typów Treści

| Typ | Model | Tabela | URL Pattern | Kategorie | Publikacja |
|-----|-------|--------|-------------|-----------|------------|
| Strony | `Page` | `pages` | `/strona/{slug}` | Nie | `published_at` |
| Aktualności | `Post` | `posts` | `/aktualnosci/{slug}` | Tak | `published_at` |
| Promocje | `Promotion` | `promotions` | `/promocje/{slug}` | Nie | `active` + daty |
| Portfolio | `PortfolioItem` | `portfolio_items` | `/portfolio/{slug}` | Tak | `published_at` |

---

## 1. Strony (Pages)

### Przeznaczenie

Strony statyczne z niestandardowymi układami. Idealne dla:
- Strona główna
- O nas / O firmie
- Kontakt
- Regulamin / Polityka prywatności
- Landing pages

### Model

**Lokalizacja:** `app/Models/Page.php`

### Pola

| Pole | Typ | Wymagane | Opis |
|------|-----|----------|------|
| `title` | `varchar(255)` | Tak | Tytuł strony wyświetlany w nagłówku |
| `slug` | `varchar(255)` | Auto | URL-friendly identyfikator (auto-generowany z tytułu) |
| `body` | `text` | Nie | Główna treść (RichEditor/TipTap) |
| `content` | `json` | Nie | Zaawansowane bloki treści (Builder) |
| `layout` | `enum` | Nie | Układ strony: `default`, `full-width`, `minimal` |
| `published_at` | `datetime` | Nie | Data publikacji (null = szkic) |
| `meta_title` | `varchar(255)` | Nie | Tytuł SEO (fallback: title) |
| `meta_description` | `text` | Nie | Opis SEO |
| `featured_image` | `varchar(255)` | Nie | Główne zdjęcie strony |

### Layouty

```php
'layout' => enum('default', 'full-width', 'minimal')
```

| Layout | Opis | Użycie |
|--------|------|--------|
| `default` | Standardowy układ z sidebar | Większość stron |
| `full-width` | Pełna szerokość bez sidebar | Landing pages, galerie |
| `minimal` | Minimalistyczny bez nagłówka/stopki | Regulaminy, dokumenty |

### Scopes

```php
// Opublikowane strony
Page::published()->get();

// Szkice
Page::draft()->get();
```

### Metody Pomocnicze

```php
$page->isPublished();  // bool - czy opublikowana
$page->isDraft();      // bool - czy szkic
$page->url;            // string - pełny URL strony
```

### Przykład Użycia

```php
// Pobranie opublikowanej strony po slug
$page = Page::where('slug', 'o-nas')
    ->published()
    ->firstOrFail();

// Tworzenie nowej strony
$page = Page::create([
    'title' => 'O nas',
    'body' => '<p>Treść strony...</p>',
    'layout' => 'default',
    'published_at' => now(),
]);
```

---

## 2. Aktualności (Posts)

### Przeznaczenie

Artykuły blogowe i aktualności. Idealne dla:
- Blog firmowy
- Aktualności i nowości
- Artykuły eksperckie
- Poradniki i tutoriale

### Model

**Lokalizacja:** `app/Models/Post.php`

### Pola

| Pole | Typ | Wymagane | Opis |
|------|-----|----------|------|
| `title` | `varchar(255)` | Tak | Tytuł artykułu |
| `slug` | `varchar(255)` | Auto | URL-friendly identyfikator |
| `excerpt` | `text` | Nie | Krótki opis/zajawka (wyświetlany na listach) |
| `body` | `text` | Nie | Główna treść artykułu (RichEditor) |
| `content` | `json` | Nie | Zaawansowane bloki treści (Builder) |
| `category_id` | `bigint` | Nie | Powiązanie z kategorią |
| `published_at` | `datetime` | Nie | Data publikacji |
| `meta_title` | `varchar(255)` | Nie | Tytuł SEO |
| `meta_description` | `text` | Nie | Opis SEO |
| `featured_image` | `varchar(255)` | Nie | Zdjęcie główne artykułu |

### Relacje

```php
// Kategoria artykułu
$post->category;  // Category|null
```

### Scopes

```php
// Opublikowane posty
Post::published()->get();

// Szkice
Post::draft()->get();

// Posty w kategorii
Post::inCategory($categoryId)->get();

// Kombinacja
Post::published()->inCategory(5)->latest('published_at')->get();
```

### Metody Pomocnicze

```php
$post->isPublished();  // bool
$post->isDraft();      // bool
$post->url;            // string
$post->category;       // Category|null
```

### Przykład Użycia

```php
// Ostatnie 5 opublikowanych artykułów
$posts = Post::published()
    ->with('category')
    ->latest('published_at')
    ->take(5)
    ->get();

// Artykuły w kategorii "Poradniki"
$guides = Post::published()
    ->whereHas('category', fn($q) => $q->where('slug', 'poradniki'))
    ->get();
```

---

## 3. Promocje (Promotions)

### Przeznaczenie

Oferty specjalne i kampanie marketingowe. Idealne dla:
- Promocje sezonowe
- Rabaty i zniżki
- Oferty specjalne
- Kampanie czasowe

### Model

**Lokalizacja:** `app/Models/Promotion.php`

### Pola

| Pole | Typ | Wymagane | Opis |
|------|-----|----------|------|
| `title` | `varchar(255)` | Tak | Nazwa promocji |
| `slug` | `varchar(255)` | Auto | URL-friendly identyfikator |
| `body` | `text` | Nie | Opis promocji (RichEditor) |
| `content` | `json` | Nie | Zaawansowane bloki (Builder) |
| `valid_from` | `datetime` | Nie | Początek ważności (null = od zaraz) |
| `valid_until` | `datetime` | Nie | Koniec ważności (null = bezterminowo) |
| `active` | `boolean` | Tak | Czy promocja aktywna |
| `meta_title` | `varchar(255)` | Nie | Tytuł SEO |
| `meta_description` | `text` | Nie | Opis SEO |
| `featured_image` | `varchar(255)` | Nie | Grafika promocji |

### System Ważności

Promocje używają **podwójnego systemu** kontroli widoczności:

```
Widoczna = active:true + (valid_from <= now || null) + (valid_until >= now || null)
```

| `active` | `valid_from` | `valid_until` | Stan |
|----------|--------------|---------------|------|
| `true` | `null` | `null` | Widoczna zawsze |
| `true` | `2025-01-01` | `null` | Widoczna od 1 stycznia |
| `true` | `null` | `2025-12-31` | Widoczna do końca roku |
| `true` | `2025-01-01` | `2025-01-31` | Widoczna tylko w styczniu |
| `false` | `*` | `*` | Ukryta (niezależnie od dat) |

### Scopes

```php
// Aktywne promocje
Promotion::active()->get();

// Promocje w zakresie dat
Promotion::valid()->get();

// Aktywne I w zakresie dat (najczęściej używane)
Promotion::activeAndValid()->get();
```

### Metody Pomocnicze

```php
$promo->isActive();        // bool - czy active=true
$promo->isValid();         // bool - czy w zakresie dat
$promo->isActiveAndValid(); // bool - kombinacja obu
$promo->url;               // string
```

### Przykład Użycia

```php
// Wszystkie aktualnie dostępne promocje
$promos = Promotion::activeAndValid()
    ->orderBy('valid_until')
    ->get();

// Promocje kończące się w tym tygodniu
$expiring = Promotion::activeAndValid()
    ->whereBetween('valid_until', [now(), now()->addWeek()])
    ->get();
```

---

## 4. Portfolio (Portfolio Items)

### Przeznaczenie

Prezentacja projektów i realizacji. Idealne dla:
- Galeria realizacji
- Case studies
- Projekty przed/po
- Referencje z wizualizacją

### Model

**Lokalizacja:** `app/Models/PortfolioItem.php`

### Pola

| Pole | Typ | Wymagane | Opis |
|------|-----|----------|------|
| `title` | `varchar(255)` | Tak | Nazwa projektu |
| `slug` | `varchar(255)` | Auto | URL-friendly identyfikator |
| `body` | `text` | Nie | Opis projektu (RichEditor) |
| `content` | `json` | Nie | Zaawansowane bloki (Builder) |
| `category_id` | `bigint` | Nie | Kategoria portfolio |
| `before_image` | `varchar(255)` | Nie | Zdjęcie "przed" |
| `after_image` | `varchar(255)` | Nie | Zdjęcie "po" |
| `gallery` | `json` | Nie | Tablica zdjęć galerii |
| `published_at` | `datetime` | Nie | Data publikacji |
| `meta_title` | `varchar(255)` | Nie | Tytuł SEO |
| `meta_description` | `text` | Nie | Opis SEO |

### Specjalne Funkcje

#### Zdjęcia Przed/Po

```php
// W widoku Blade
@if($item->before_image && $item->after_image)
<div class="before-after-slider">
    <img src="{{ Storage::url($item->before_image) }}" alt="Przed">
    <img src="{{ Storage::url($item->after_image) }}" alt="Po">
</div>
@endif
```

#### Galeria

```php
// gallery to tablica ścieżek do zdjęć
$item->gallery = [
    'portfolio/projekt-1/foto-1.jpg',
    'portfolio/projekt-1/foto-2.jpg',
    'portfolio/projekt-1/foto-3.jpg',
];
```

### Relacje

```php
$item->category;  // Category|null
```

### Scopes

```php
// Opublikowane projekty
PortfolioItem::published()->get();

// Szkice
PortfolioItem::draft()->get();

// W kategorii
PortfolioItem::inCategory($categoryId)->get();
```

### Przykład Użycia

```php
// Portfolio z kategorią "Detailing"
$detailing = PortfolioItem::published()
    ->whereHas('category', fn($q) => $q->where('slug', 'detailing'))
    ->with('category')
    ->latest('published_at')
    ->get();

// Ostatnie 6 realizacji dla strony głównej
$featured = PortfolioItem::published()
    ->whereNotNull('featured_image')
    ->latest('published_at')
    ->take(6)
    ->get();
```

---

## 5. Kategorie (Categories)

### Przeznaczenie

Hierarchiczna organizacja treści dla Posts i Portfolio Items.

### Model

**Lokalizacja:** `app/Models/Category.php`

### Pola

| Pole | Typ | Wymagane | Opis |
|------|-----|----------|------|
| `name` | `varchar(255)` | Tak | Nazwa kategorii |
| `slug` | `varchar(255)` | Auto | URL-friendly identyfikator |
| `description` | `text` | Nie | Opis kategorii |
| `parent_id` | `bigint` | Nie | ID kategorii nadrzędnej |
| `type` | `varchar(50)` | Tak | Typ: `post` lub `portfolio` |

### System Typów

Kategorie są **rozdzielone według typu** treści:

```php
// Kategorie dla artykułów
Category::postCategories()->get();

// Kategorie dla portfolio
Category::portfolioCategories()->get();
```

### Struktura Hierarchiczna

```
Kategorie (type: post)
├── Aktualności
│   ├── Nowości firmowe
│   └── Branżowe
├── Poradniki
│   ├── Dla początkujących
│   └── Zaawansowane
└── Recenzje

Kategorie (type: portfolio)
├── Detailing
│   ├── Korekta lakieru
│   └── Powłoki ceramiczne
├── Renowacja
└── Tuning
```

### Relacje

```php
// Kategoria nadrzędna
$category->parent;  // Category|null

// Podkategorie
$category->children;  // Collection<Category>

// Powiązana treść
$category->posts;           // Collection<Post>
$category->portfolioItems;  // Collection<PortfolioItem>
```

### Przykład Użycia

```php
// Główne kategorie artykułów (bez parent)
$mainCategories = Category::postCategories()
    ->whereNull('parent_id')
    ->with('children')
    ->get();

// Kategoria z podkategoriami i treścią
$category = Category::where('slug', 'detailing')
    ->with(['children', 'portfolioItems' => fn($q) => $q->published()])
    ->first();
```

---

## Kiedy Używać Którego Typu

### Decision Tree

```
Czy treść ma datę ważności/promocję?
├── TAK → Promocje
└── NIE
    ├── Czy to artykuł/news z datą publikacji?
    │   ├── TAK → Aktualności (Post)
    │   └── NIE
    │       ├── Czy to realizacja/projekt do portfolio?
    │       │   ├── TAK → Portfolio
    │       │   └── NIE → Strony (Page)
```

### Porównanie Zastosowań

| Scenariusz | Typ | Uzasadnienie |
|------------|-----|--------------|
| "O nas", "Kontakt" | Page | Statyczna treść, bez kategorii |
| "Nowy produkt w ofercie" | Post | Aktualność z datą, może mieć kategorię |
| "Promocja -20% do końca miesiąca" | Promotion | Oferta z datami ważności |
| "Realizacja: BMW M3 detailing" | Portfolio | Projekt z galerią przed/po |
| "Regulamin serwisu" | Page | Dokument statyczny, layout: minimal |
| "Poradnik: Jak dbać o lakier" | Post | Artykuł edukacyjny, kategoria: Poradniki |
| "Black Friday -30%" | Promotion | Promocja czasowa |
| "Case study: Firma XYZ" | Portfolio | Realizacja z opisem i zdjęciami |

---

## Wspólne Wzorce

### Auto-generowanie Slug

Wszystkie modele automatycznie generują `slug` z `title`/`name`:

```php
// Model automatycznie utworzy slug
$page = Page::create(['title' => 'O Naszej Firmie']);
echo $page->slug;  // "o-naszej-firmie"
```

### Hybrid Content System

Wszystkie typy treści (oprócz Categories) wspierają:

```php
// Główna treść (RichEditor)
$record->body;  // HTML string

// Zaawansowane bloki (Builder)
$record->content;  // array of blocks
```

### SEO Fields

Wszystkie typy treści mają pola SEO:

```php
// W widoku Blade
<title>{{ $page->meta_title ?? $page->title }}</title>
<meta name="description" content="{{ $page->meta_description }}">
<meta property="og:image" content="{{ Storage::url($page->featured_image) }}">
```

### Publishing Workflow

```php
// Szkic (draft)
$record->published_at = null;

// Zaplanowane (scheduled)
$record->published_at = now()->addDays(7);

// Opublikowane (published)
$record->published_at = now();
```

---

## Powiązana Dokumentacja

- [CMS System Overview](./README.md)
- [Admin Panel Guide](./admin-panel.md)
- [Content Blocks Reference](./content-blocks.md)
- [Frontend Rendering](./frontend.md)
- [Database Schema](../../architecture/database-schema.md)
