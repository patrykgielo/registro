# Content Blocks Reference

> Kompletna dokumentacja bloków Builder w systemie CMS.

## Spis Treści

- [Przegląd Systemu Bloków](#przegląd-systemu-bloków)
- [Blok: Image](#blok-image)
- [Blok: Gallery](#blok-gallery)
- [Blok: Video](#blok-video)
- [Blok: CTA (Call to Action)](#blok-cta-call-to-action)
- [Blok: Two Columns](#blok-two-columns)
- [Blok: Three Columns](#blok-three-columns)
- [Blok: Quote](#blok-quote)
- [Struktura JSON](#struktura-json)
- [Renderowanie Frontend](#renderowanie-frontend)
- [Tworzenie Nowych Bloków](#tworzenie-nowych-bloków)

---

## Przegląd Systemu Bloków

### Architektura

System bloków używa **Filament Builder** do tworzenia zaawansowanych układów treści:

```
content (JSON column)
├── Block 1 (type: "image")
│   └── data: { image, alt, caption, size }
├── Block 2 (type: "cta")
│   └── data: { heading, description, button_text, button_url, style }
└── Block 3 (type: "gallery")
    └── data: { images[], columns }
```

### Dostępne Bloki

| Typ | Ikona | Opis | Pola |
|-----|-------|------|------|
| `image` | heroicon-o-photo | Pojedyncze zdjęcie | image, alt, caption, size |
| `gallery` | heroicon-o-photo | Siatka zdjęć | images[], columns |
| `video` | heroicon-o-film | YouTube/Vimeo | url, caption |
| `cta` | heroicon-o-cursor-arrow-ripple | Call to Action | heading, description, button_*, style |
| `two_columns` | heroicon-o-view-columns | Dwie kolumny | left_column, right_column |
| `three_columns` | heroicon-o-squares-2x2 | Trzy kolumny | column_1, column_2, column_3 |
| `quote` | heroicon-o-chat-bubble-left-right | Cytat | quote, author, author_title |

### Kiedy Używać Bloków vs Body

| Użyj Body (RichEditor) | Użyj Bloków (Builder) |
|------------------------|----------------------|
| Zwykły tekst | Galerie zdjęć |
| Proste listy | Sekcje CTA |
| Podstawowe formatowanie | Układy kolumnowe |
| Tabele | Wyróżnione cytaty |
| Pojedyncze zdjęcia inline | Wideo embed |

---

## Blok: Image

### Przeznaczenie

Wyświetlanie pojedynczego zdjęcia z opcjonalnym podpisem i kontrolą rozmiaru.

### Pola

| Pole | Typ | Wymagane | Opis |
|------|-----|----------|------|
| `image` | FileUpload | Tak | Ścieżka do zdjęcia |
| `alt` | TextInput | Nie | Tekst alternatywny (accessibility) |
| `caption` | TextInput | Nie | Podpis pod zdjęciem |
| `size` | Select | Tak | Rozmiar: small, medium, large, full |

### Opcje Rozmiaru

| Size | CSS Class | Szerokość |
|------|-----------|-----------|
| `small` | `max-w-xl mx-auto` | ~576px |
| `medium` | `max-w-2xl mx-auto` | ~672px |
| `large` | `max-w-3xl mx-auto` | ~768px |
| `full` | `w-full` | 100% |

### Definicja Filament

```php
Forms\Components\Builder\Block::make('image')
    ->label('Zdjęcie')
    ->icon('heroicon-o-photo')
    ->schema([
        Forms\Components\FileUpload::make('image')
            ->label('Zdjęcie')
            ->image()
            ->required()
            ->directory('pages/images')
            ->maxSize(5120),

        Forms\Components\TextInput::make('alt')
            ->label('Tekst alternatywny (ALT)')
            ->maxLength(255),

        Forms\Components\TextInput::make('caption')
            ->label('Podpis')
            ->maxLength(255),

        Forms\Components\Select::make('size')
            ->label('Rozmiar')
            ->options([
                'small' => 'Mały',
                'medium' => 'Średni',
                'large' => 'Duży',
                'full' => 'Pełna szerokość',
            ])
            ->default('large'),
    ]),
```

### JSON Structure

```json
{
    "type": "image",
    "data": {
        "image": "pages/images/hero.jpg",
        "alt": "Główne zdjęcie artykułu",
        "caption": "Widok naszego studia",
        "size": "large"
    }
}
```

### Renderowanie Blade

```blade
@if($block['type'] === 'image')
    <div class="mb-8 @if($block['data']['size'] === 'full') w-full @elseif($block['data']['size'] === 'large') max-w-3xl mx-auto @elseif($block['data']['size'] === 'medium') max-w-2xl mx-auto @else max-w-xl mx-auto @endif">
        <img src="{{ Storage::url($block['data']['image']) }}"
             alt="{{ $block['data']['alt'] ?? '' }}"
             class="w-full rounded-lg">
        @if(!empty($block['data']['caption']))
            <p class="text-sm text-gray-600 text-center mt-2">{{ $block['data']['caption'] }}</p>
        @endif
    </div>
@endif
```

---

## Blok: Gallery

### Przeznaczenie

Wyświetlanie wielu zdjęć w układzie siatki z możliwością sortowania.

### Pola

| Pole | Typ | Wymagane | Opis |
|------|-----|----------|------|
| `images` | FileUpload (multiple) | Tak | Tablica ścieżek do zdjęć |
| `columns` | Select | Tak | Liczba kolumn: 2, 3, 4 |

### Ograniczenia

- Max plików: 20
- Max rozmiar: 5MB per plik
- Obsługiwane formaty: JPG, PNG, WebP, GIF
- Możliwość sortowania (drag & drop)

### Definicja Filament

```php
Forms\Components\Builder\Block::make('gallery')
    ->label('Galeria')
    ->icon('heroicon-o-photo')
    ->schema([
        Forms\Components\FileUpload::make('images')
            ->label('Zdjęcia')
            ->image()
            ->multiple()
            ->required()
            ->directory('pages/galleries')
            ->maxSize(5120)
            ->maxFiles(20)
            ->reorderable(),

        Forms\Components\Select::make('columns')
            ->label('Liczba kolumn')
            ->options([
                '2' => '2 kolumny',
                '3' => '3 kolumny',
                '4' => '4 kolumny',
            ])
            ->default('3'),
    ]),
```

### JSON Structure

```json
{
    "type": "gallery",
    "data": {
        "images": [
            "pages/galleries/foto-1.jpg",
            "pages/galleries/foto-2.jpg",
            "pages/galleries/foto-3.jpg",
            "pages/galleries/foto-4.jpg"
        ],
        "columns": "3"
    }
}
```

### Renderowanie Blade

```blade
@elseif($block['type'] === 'gallery')
    <div class="mb-8">
        <div class="grid grid-cols-{{ $block['data']['columns'] ?? 3 }} gap-4">
            @foreach($block['data']['images'] as $image)
                <img src="{{ Storage::url($image) }}"
                     alt=""
                     class="w-full h-64 object-cover rounded-lg">
            @endforeach
        </div>
    </div>
@endif
```

---

## Blok: Video

### Przeznaczenie

Osadzanie filmów z YouTube lub Vimeo.

### Pola

| Pole | Typ | Wymagane | Opis |
|------|-----|----------|------|
| `url` | TextInput (URL) | Tak | Link do YouTube/Vimeo |
| `caption` | TextInput | Nie | Podpis pod wideo |

### Obsługiwane Platformy

| Platforma | Format URL |
|-----------|-----------|
| YouTube | `https://www.youtube.com/watch?v=...` |
| YouTube | `https://youtu.be/...` |
| Vimeo | `https://vimeo.com/...` |

### Definicja Filament

```php
Forms\Components\Builder\Block::make('video')
    ->label('Wideo')
    ->icon('heroicon-o-film')
    ->schema([
        Forms\Components\TextInput::make('url')
            ->label('URL YouTube lub Vimeo')
            ->url()
            ->required()
            ->helperText('np. https://www.youtube.com/watch?v=...'),

        Forms\Components\TextInput::make('caption')
            ->label('Podpis')
            ->maxLength(255),
    ]),
```

### JSON Structure

```json
{
    "type": "video",
    "data": {
        "url": "https://www.youtube.com/embed/dQw4w9WgXcQ",
        "caption": "Prezentacja naszych usług"
    }
}
```

### Renderowanie Blade

```blade
@elseif($block['type'] === 'video')
    <div class="mb-8">
        <div class="aspect-w-16 aspect-h-9">
            <iframe src="{{ $block['data']['url'] }}"
                    frameborder="0"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen
                    class="w-full h-96 rounded-lg"></iframe>
        </div>
        @if(!empty($block['data']['caption']))
            <p class="text-sm text-gray-600 text-center mt-2">{{ $block['data']['caption'] }}</p>
        @endif
    </div>
@endif
```

> **Uwaga:** URL musi być w formacie embed (np. `/embed/` dla YouTube). Rozważ dodanie transformacji URL w kontrolerze.

---

## Blok: CTA (Call to Action)

### Przeznaczenie

Wyróżniona sekcja z nagłówkiem, opisem i przyciskiem akcji.

### Pola

| Pole | Typ | Wymagane | Opis |
|------|-----|----------|------|
| `heading` | TextInput | Tak | Nagłówek sekcji |
| `description` | Textarea | Nie | Opis/treść sekcji |
| `button_text` | TextInput | Nie | Tekst przycisku |
| `button_url` | TextInput (URL) | Nie | Link przycisku |
| `style` | Select | Tak | Styl: primary, secondary, accent |

### Style

| Style | Tło | Przycisk |
|-------|-----|----------|
| `primary` | `bg-blue-50` | `bg-blue-600 text-white` |
| `secondary` | `bg-gray-50` | `bg-gray-600 text-white` |
| `accent` | `bg-green-50` | `bg-green-600 text-white` |

### Definicja Filament

```php
Forms\Components\Builder\Block::make('cta')
    ->label('Call to Action')
    ->icon('heroicon-o-cursor-arrow-ripple')
    ->schema([
        Forms\Components\TextInput::make('heading')
            ->label('Nagłówek')
            ->required()
            ->maxLength(255),

        Forms\Components\Textarea::make('description')
            ->label('Opis')
            ->rows(3)
            ->maxLength(500),

        Forms\Components\TextInput::make('button_text')
            ->label('Tekst przycisku')
            ->default('Dowiedz się więcej')
            ->maxLength(100),

        Forms\Components\TextInput::make('button_url')
            ->label('Link przycisku')
            ->url(),

        Forms\Components\Select::make('style')
            ->label('Styl')
            ->options([
                'primary' => 'Podstawowy (niebieski)',
                'secondary' => 'Drugorzędny (szary)',
                'accent' => 'Akcentowy (zielony)',
            ])
            ->default('primary'),
    ]),
```

### JSON Structure

```json
{
    "type": "cta",
    "data": {
        "heading": "Umów się na wizytę",
        "description": "Skontaktuj się z nami i umów profesjonalny detailing.",
        "button_text": "Rezerwuj termin",
        "button_url": "/rezerwacja",
        "style": "primary"
    }
}
```

### Renderowanie Blade

```blade
@elseif($block['type'] === 'cta')
    <div class="mb-8 p-8 rounded-lg @if($block['data']['style'] === 'primary') bg-blue-50 @elseif($block['data']['style'] === 'accent') bg-green-50 @else bg-gray-50 @endif">
        <h3 class="text-2xl font-bold mb-4">{{ $block['data']['heading'] }}</h3>
        @if(!empty($block['data']['description']))
            <p class="text-gray-700 mb-6">{{ $block['data']['description'] }}</p>
        @endif
        @if(!empty($block['data']['button_url']))
            <a href="{{ $block['data']['button_url'] }}"
               class="inline-block px-6 py-3 rounded-lg font-semibold @if($block['data']['style'] === 'primary') bg-blue-600 text-white hover:bg-blue-700 @elseif($block['data']['style'] === 'accent') bg-green-600 text-white hover:bg-green-700 @else bg-gray-600 text-white hover:bg-gray-700 @endif">
                {{ $block['data']['button_text'] ?? 'Dowiedz się więcej' }}
            </a>
        @endif
    </div>
@endif
```

---

## Blok: Two Columns

### Przeznaczenie

Układ dwukolumnowy z niezależną treścią w każdej kolumnie.

### Pola

| Pole | Typ | Wymagane | Opis |
|------|-----|----------|------|
| `left_column` | RichEditor | Tak | Treść lewej kolumny |
| `right_column` | RichEditor | Tak | Treść prawej kolumny |

### Responsive

- **Desktop:** 2 kolumny obok siebie (50/50)
- **Mobile:** 1 kolumna (full width, lewa nad prawą)

### Definicja Filament

```php
Forms\Components\Builder\Block::make('two_columns')
    ->label('Dwie kolumny')
    ->icon('heroicon-o-view-columns')
    ->schema([
        Forms\Components\RichEditor::make('left_column')
            ->label('Lewa kolumna')
            ->required()
            ->toolbarButtons([
                'bold', 'italic', 'link', 'bulletList',
                'orderedList', 'h3', 'blockquote'
            ]),

        Forms\Components\RichEditor::make('right_column')
            ->label('Prawa kolumna')
            ->required()
            ->toolbarButtons([
                'bold', 'italic', 'link', 'bulletList',
                'orderedList', 'h3', 'blockquote'
            ]),
    ]),
```

### JSON Structure

```json
{
    "type": "two_columns",
    "data": {
        "left_column": "<p>Treść lewej kolumny...</p>",
        "right_column": "<p>Treść prawej kolumny...</p>"
    }
}
```

### Renderowanie Blade

```blade
@elseif($block['type'] === 'two_columns')
    <div class="mb-8 grid grid-cols-1 md:grid-cols-2 gap-8">
        <div class="prose max-w-none">{!! $block['data']['left_column'] !!}</div>
        <div class="prose max-w-none">{!! $block['data']['right_column'] !!}</div>
    </div>
@endif
```

---

## Blok: Three Columns

### Przeznaczenie

Układ trzykolumnowy dla porównań lub list funkcji.

### Pola

| Pole | Typ | Wymagane | Opis |
|------|-----|----------|------|
| `column_1` | RichEditor | Tak | Treść kolumny 1 |
| `column_2` | RichEditor | Tak | Treść kolumny 2 |
| `column_3` | RichEditor | Tak | Treść kolumny 3 |

### Responsive

- **Desktop:** 3 kolumny (33/33/33)
- **Mobile:** 1 kolumna (wszystkie pod sobą)

### Definicja Filament

```php
Forms\Components\Builder\Block::make('three_columns')
    ->label('Trzy kolumny')
    ->icon('heroicon-o-squares-2x2')
    ->schema([
        Forms\Components\RichEditor::make('column_1')
            ->label('Kolumna 1')
            ->required()
            ->toolbarButtons([
                'bold', 'italic', 'link', 'bulletList'
            ]),

        Forms\Components\RichEditor::make('column_2')
            ->label('Kolumna 2')
            ->required()
            ->toolbarButtons([
                'bold', 'italic', 'link', 'bulletList'
            ]),

        Forms\Components\RichEditor::make('column_3')
            ->label('Kolumna 3')
            ->required()
            ->toolbarButtons([
                'bold', 'italic', 'link', 'bulletList'
            ]),
    ]),
```

### JSON Structure

```json
{
    "type": "three_columns",
    "data": {
        "column_1": "<h3>Basic</h3><p>Podstawowy pakiet</p>",
        "column_2": "<h3>Pro</h3><p>Profesjonalny pakiet</p>",
        "column_3": "<h3>Premium</h3><p>Pełny pakiet</p>"
    }
}
```

### Renderowanie Blade

```blade
@elseif($block['type'] === 'three_columns')
    <div class="mb-8 grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="prose max-w-none">{!! $block['data']['column_1'] !!}</div>
        <div class="prose max-w-none">{!! $block['data']['column_2'] !!}</div>
        <div class="prose max-w-none">{!! $block['data']['column_3'] !!}</div>
    </div>
@endif
```

---

## Blok: Quote

### Przeznaczenie

Wyróżniony cytat z opcjonalnym autorem i tytułem.

### Pola

| Pole | Typ | Wymagane | Opis |
|------|-----|----------|------|
| `quote` | Textarea | Tak | Treść cytatu |
| `author` | TextInput | Nie | Imię i nazwisko autora |
| `author_title` | TextInput | Nie | Tytuł/stanowisko autora |

### Definicja Filament

```php
Forms\Components\Builder\Block::make('quote')
    ->label('Cytat')
    ->icon('heroicon-o-chat-bubble-left-right')
    ->schema([
        Forms\Components\Textarea::make('quote')
            ->label('Cytat')
            ->required()
            ->rows(3)
            ->maxLength(500),

        Forms\Components\TextInput::make('author')
            ->label('Autor')
            ->maxLength(255),

        Forms\Components\TextInput::make('author_title')
            ->label('Tytuł autora')
            ->maxLength(255)
            ->helperText('np. CEO, Dyrektor'),
    ]),
```

### JSON Structure

```json
{
    "type": "quote",
    "data": {
        "quote": "Profesjonalna obsługa i świetne efekty. Polecam!",
        "author": "Jan Kowalski",
        "author_title": "CEO, Example Corp"
    }
}
```

### Renderowanie Blade

```blade
@elseif($block['type'] === 'quote')
    <blockquote class="mb-8 border-l-4 border-blue-600 pl-6 py-4 bg-gray-50 rounded-r-lg">
        <p class="text-xl text-gray-700 italic mb-4">{{ $block['data']['quote'] }}</p>
        @if(!empty($block['data']['author']))
            <footer class="text-gray-600">
                <strong>{{ $block['data']['author'] }}</strong>
                @if(!empty($block['data']['author_title']))
                    <span class="text-gray-500"> - {{ $block['data']['author_title'] }}</span>
                @endif
            </footer>
        @endif
    </blockquote>
@endif
```

---

## Struktura JSON

### Pełna Struktura Content

```json
[
    {
        "type": "image",
        "data": {
            "image": "pages/images/hero.jpg",
            "alt": "Hero image",
            "caption": "Nasze studio",
            "size": "full"
        }
    },
    {
        "type": "cta",
        "data": {
            "heading": "Umów wizytę",
            "description": "Zarezerwuj termin online",
            "button_text": "Rezerwuj",
            "button_url": "/rezerwacja",
            "style": "primary"
        }
    },
    {
        "type": "gallery",
        "data": {
            "images": ["img1.jpg", "img2.jpg", "img3.jpg"],
            "columns": "3"
        }
    }
]
```

### Akces w PHP

```php
// Model zwraca array (cast: 'array')
$blocks = $page->content;

foreach ($blocks as $block) {
    $type = $block['type'];   // "image", "cta", etc.
    $data = $block['data'];   // Block-specific data
}
```

---

## Renderowanie Frontend

### Wspólny Partial

Utwórz partial dla DRY:

```blade
{{-- resources/views/partials/content-blocks.blade.php --}}
@foreach($blocks as $block)
    @include('partials.blocks.' . $block['type'], ['data' => $block['data']])
@endforeach
```

### Osobne Pliki Bloków

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

### Przykład: image.blade.php

```blade
{{-- resources/views/partials/blocks/image.blade.php --}}
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

## Tworzenie Nowych Bloków

### 1. Dodaj Block do Resource

```php
// W PageResource.php (lub innym Resource)
Forms\Components\Builder\Block::make('testimonial')
    ->label('Opinia klienta')
    ->icon('heroicon-o-star')
    ->schema([
        Forms\Components\Textarea::make('content')
            ->label('Treść opinii')
            ->required()
            ->rows(3),

        Forms\Components\TextInput::make('author')
            ->label('Autor')
            ->required(),

        Forms\Components\FileUpload::make('avatar')
            ->label('Zdjęcie')
            ->avatar()
            ->directory('testimonials'),

        Forms\Components\Select::make('rating')
            ->label('Ocena')
            ->options([
                '5' => '⭐⭐⭐⭐⭐',
                '4' => '⭐⭐⭐⭐',
                '3' => '⭐⭐⭐',
                '2' => '⭐⭐',
                '1' => '⭐',
            ])
            ->default('5'),
    ]),
```

### 2. Dodaj Renderowanie Blade

```blade
{{-- W widoku show.blade.php --}}
@elseif($block['type'] === 'testimonial')
    <div class="mb-8 bg-white rounded-lg shadow p-6">
        <div class="flex items-center gap-4 mb-4">
            @if(!empty($block['data']['avatar']))
                <img src="{{ Storage::url($block['data']['avatar']) }}"
                     alt="{{ $block['data']['author'] }}"
                     class="w-12 h-12 rounded-full object-cover">
            @endif
            <div>
                <strong>{{ $block['data']['author'] }}</strong>
                <div class="text-yellow-500">
                    @for($i = 0; $i < $block['data']['rating']; $i++)
                        ⭐
                    @endfor
                </div>
            </div>
        </div>
        <p class="text-gray-700 italic">"{{ $block['data']['content'] }}"</p>
    </div>
@endif
```

### 3. Skopiuj do Wszystkich Resources

Jeśli blok ma być dostępny we wszystkich typach treści, dodaj go do:
- `PageResource.php`
- `PostResource.php`
- `PromotionResource.php`
- `PortfolioItemResource.php`

> **Tip:** Rozważ utworzenie trait lub klasy pomocniczej dla współdzielonych bloków.

---

## Powiązana Dokumentacja

- [CMS System Overview](./README.md)
- [Content Types Reference](./content-types.md)
- [Admin Panel Guide](./admin-panel.md)
- [Frontend Rendering](./frontend.md)
- [Filament Builder Documentation](https://filamentphp.com/docs/3.x/forms/fields/builder)
