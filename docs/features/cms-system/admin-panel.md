# CMS Admin Panel Guide

> Przewodnik po panelu administracyjnym CMS w Filament v4.

## Spis Treści

- [Dostęp do Panelu](#dostęp-do-panelu)
- [Nawigacja CMS](#nawigacja-cms)
- [Formularze Treści](#formularze-treści)
- [Listy Treści](#listy-treści)
- [Workflow Publikacji](#workflow-publikacji)
- [Bulk Actions](#bulk-actions)
- [Podgląd Treści](#podgląd-treści)
- [Best Practices](#best-practices)

---

## Dostęp do Panelu

### URL

```
https://registro.local:8444/admin
```

### Uprawnienia

Dostęp do panelu CMS mają użytkownicy spełniający warunek w `User::canAccessPanel()`.

```php
// app/Models/User.php
public function canAccessPanel(Panel $panel): bool
{
    // Logika dostępu
}
```

---

## Nawigacja CMS

### Menu Structure

Wszystkie zasoby CMS znajdują się w grupie **"Content"** w menu bocznym:

| Pozycja | Resource | URL Admin | Ikona |
|---------|----------|-----------|-------|
| 1 | Strony | `/admin/pages` | `heroicon-o-document-text` |
| 2 | Aktualności | `/admin/posts` | `heroicon-o-newspaper` |
| 3 | Promocje | `/admin/promotions` | `heroicon-o-megaphone` |
| 4 | Portfolio | `/admin/portfolio-items` | `heroicon-o-photo` |
| 5 | Kategorie | `/admin/categories` | `heroicon-o-folder` |

### Konfiguracja Resource

```php
// Przykład z PageResource.php
class PageResource extends Resource
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedDocumentText;
    protected static string | UnitEnum | null $navigationGroup = 'Content';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = 'Strona';
    protected static ?string $pluralModelLabel = 'Strony';
}
```

---

## Formularze Treści

### Struktura Formularza

Każdy typ treści używa **1-kolumnowego layoutu** z sekcjami:

```
┌──────────────────────────────────────────┐
│  Podstawowe informacje                    │
│  ├── Tytuł (auto-generuje slug)          │
│  ├── Slug (URL)                          │
│  ├── Layout / Kategoria                  │
│  └── Data publikacji                     │
├──────────────────────────────────────────┤
│  Główna treść                            │
│  └── RichEditor (body)                   │
├──────────────────────────────────────────┤
│  Zaawansowane bloki (opcjonalnie)        │  ← collapsed
│  └── Builder (content)                   │
├──────────────────────────────────────────┤
│  SEO                                     │  ← collapsed
│  ├── Featured image                      │
│  ├── Meta title                          │
│  └── Meta description                    │
└──────────────────────────────────────────┘
```

### Sekcja: Podstawowe Informacje

#### Tytuł z Auto-slug

```php
Forms\Components\TextInput::make('title')
    ->label('Tytuł')
    ->required()
    ->maxLength(255)
    ->live(onBlur: true)
    ->afterStateUpdated(function (string $state, callable $set) {
        $set('slug', Str::slug($state));
    })
    ->columnSpanFull(),

Forms\Components\TextInput::make('slug')
    ->label('Slug (URL)')
    ->required()
    ->maxLength(255)
    ->unique(ignoreRecord: true)
    ->helperText('Automatycznie generowany z tytułu'),
```

**Działanie:**
1. Wpisz tytuł → slug generuje się automatycznie
2. Możesz ręcznie edytować slug
3. Slug musi być unikalny w tabeli

#### Layout (tylko Pages)

```php
Forms\Components\Select::make('layout')
    ->label('Layout')
    ->options([
        'default' => 'Domyślny (z sidebarami)',
        'full-width' => 'Pełna szerokość',
        'minimal' => 'Minimalny (wąski)',
    ])
    ->default('default')
    ->required(),
```

#### Kategoria (Posts, Portfolio)

```php
Forms\Components\Select::make('category_id')
    ->label('Kategoria')
    ->relationship('category', 'name')
    ->searchable()
    ->preload()
    ->createOptionForm([...])  // Możliwość tworzenia w locie
```

#### Data Publikacji

```php
Forms\Components\DateTimePicker::make('published_at')
    ->label('Data publikacji')
    ->helperText('Pozostaw puste dla wersji roboczej'),
```

**Stany:**
- `null` → Szkic (draft)
- Data przyszła → Zaplanowane (scheduled)
- Data przeszła/teraz → Opublikowane (published)

### Sekcja: Główna Treść

#### RichEditor (TipTap)

```php
Forms\Components\RichEditor::make('body')
    ->label('Treść strony')
    ->required()
    ->toolbarButtons([
        'bold', 'italic', 'underline', 'strike',
        'link', 'bulletList', 'orderedList',
        'h2', 'h3', 'blockquote',
        'attachFiles', 'table',
        'undo', 'redo',
    ])
    ->fileAttachmentsDisk('public')
    ->fileAttachmentsDirectory('pages/attachments')
    ->columnSpanFull(),
```

**Dostępne przyciski:**
| Przycisk | Funkcja | Skrót |
|----------|---------|-------|
| bold | Pogrubienie | Ctrl+B |
| italic | Kursywa | Ctrl+I |
| underline | Podkreślenie | Ctrl+U |
| strike | Przekreślenie | - |
| link | Hiperlink | Ctrl+K |
| bulletList | Lista punktowana | - |
| orderedList | Lista numerowana | - |
| h2 | Nagłówek 2 | - |
| h3 | Nagłówek 3 | - |
| blockquote | Cytat blokowy | - |
| attachFiles | Załącz plik | - |
| table | Wstaw tabelę | - |
| undo/redo | Cofnij/Ponów | Ctrl+Z/Y |

### Sekcja: Zaawansowane Bloki

Builder umożliwia dodawanie zaawansowanych elementów:

```php
Forms\Components\Builder::make('content')
    ->label('Dodatkowe bloki')
    ->blocks([
        // image, gallery, video, cta, two_columns, three_columns, quote
    ])
    ->collapsible()
    ->collapsed()
    ->reorderable()
    ->addActionLabel('Dodaj blok'),
```

**Dostępne bloki:**

| Blok | Ikona | Opis |
|------|-------|------|
| `image` | photo | Pojedyncze zdjęcie z alt, caption, size |
| `gallery` | photo | Wiele zdjęć w siatce (2-4 kolumny) |
| `video` | film | YouTube/Vimeo embed |
| `cta` | cursor-arrow-ripple | Call-to-action box |
| `two_columns` | view-columns | Dwie kolumny RichEditor |
| `three_columns` | squares-2x2 | Trzy kolumny RichEditor |
| `quote` | chat-bubble-left-right | Cytat z autorem |

**Szczegóły bloków:** [Content Blocks Reference](./content-blocks.md)

### Sekcja: SEO

```php
Section::make('SEO')
    ->schema([
        Forms\Components\FileUpload::make('featured_image')
            ->label('Zdjęcie wyróżniające')
            ->image()
            ->directory('pages/featured')
            ->maxSize(5120),

        Forms\Components\TextInput::make('meta_title')
            ->label('Meta tytuł')
            ->maxLength(60)
            ->helperText('Zalecane: do 60 znaków'),

        Forms\Components\Textarea::make('meta_description')
            ->label('Meta opis')
            ->rows(3)
            ->maxLength(160)
            ->helperText('Zalecane: do 160 znaków'),
    ])
    ->collapsed(),
```

---

## Listy Treści

### Kolumny Tabeli

```php
Tables\Columns\TextColumn::make('title')
    ->label('Tytuł')
    ->searchable()
    ->sortable()
    ->weight('bold'),

Tables\Columns\TextColumn::make('slug')
    ->label('Slug')
    ->searchable()
    ->badge()
    ->color('gray'),

Tables\Columns\TextColumn::make('published_at')
    ->label('Status')
    ->badge()
    ->color(fn ($state) => $state && $state->isPast() ? 'success' : 'warning')
    ->formatStateUsing(fn ($state) =>
        $state
            ? ($state->isPast() ? 'Opublikowano' : 'Zaplanowano')
            : 'Wersja robocza'
    ),
```

### Status Badge Colors

| Stan | Kolor | Tekst |
|------|-------|-------|
| Opublikowano | success (zielony) | "Opublikowano" |
| Zaplanowano | warning (żółty) | "Zaplanowano" |
| Wersja robocza | warning (żółty) | "Wersja robocza" |

### Filtry

```php
Tables\Filters\Filter::make('published')
    ->label('Opublikowane')
    ->query(fn ($query) => $query->published()),

Tables\Filters\Filter::make('draft')
    ->label('Wersje robocze')
    ->query(fn ($query) => $query->draft()),

Tables\Filters\SelectFilter::make('layout')
    ->label('Layout')
    ->options([...]),

Tables\Filters\SelectFilter::make('category_id')
    ->label('Kategoria')
    ->relationship('category', 'name'),
```

### Wyszukiwanie

Wyszukiwanie działa po kolumnach z `->searchable()`:
- `title` - Tytuł
- `slug` - Slug URL

---

## Workflow Publikacji

### Stany Publikacji

```
┌─────────────┐     Ustaw datę      ┌─────────────┐
│   SZKIC     │ ─────────────────▶ │ ZAPLANOWANE │
│ (draft)     │    przyszłą        │ (scheduled) │
└─────────────┘                    └─────────────┘
       │                                  │
       │ Ustaw datę                       │ Data
       │ teraz/przeszłą                   │ mija
       ▼                                  ▼
┌─────────────────────────────────────────────────┐
│              OPUBLIKOWANE                        │
│              (published)                         │
└─────────────────────────────────────────────────┘
       │
       │ Wyczyść datę
       ▼
┌─────────────┐
│   SZKIC     │
│ (unpublish) │
└─────────────┘
```

### Jak Publikować

1. **Natychmiastowa publikacja:**
   - Ustaw `published_at` na bieżącą datę/czas
   - Zapisz formularz
   - Treść jest natychmiast widoczna

2. **Zaplanowana publikacja:**
   - Ustaw `published_at` na przyszłą datę
   - Treść pojawi się automatycznie w wybranym terminie

3. **Wersja robocza:**
   - Pozostaw `published_at` puste
   - Treść nie jest widoczna na frontend

4. **Cofnięcie publikacji:**
   - Wyczyść pole `published_at`
   - Treść wraca do statusu szkicu

### Promotions: Podwójny System

Promocje używają dodatkowych kontroli:

```php
// Widoczna gdy:
active = true
AND (valid_from <= now() OR valid_from IS NULL)
AND (valid_until >= now() OR valid_until IS NULL)
```

| Pole | Opis |
|------|------|
| `active` | Główny wyłącznik promocji |
| `valid_from` | Początek ważności (opcjonalne) |
| `valid_until` | Koniec ważności (opcjonalne) |

---

## Bulk Actions

### Dostępne Akcje Masowe

```php
->bulkActions([
    DeleteBulkAction::make(),
])
```

**Domyślne:**
- **Delete** - Usuwa zaznaczone rekordy

### Dodawanie Własnych Akcji

```php
->bulkActions([
    DeleteBulkAction::make(),

    BulkAction::make('publish')
        ->label('Opublikuj')
        ->icon('heroicon-o-check-circle')
        ->action(fn ($records) => $records->each->update(['published_at' => now()]))
        ->deselectRecordsAfterCompletion(),

    BulkAction::make('unpublish')
        ->label('Cofnij publikację')
        ->icon('heroicon-o-x-circle')
        ->action(fn ($records) => $records->each->update(['published_at' => null]))
        ->deselectRecordsAfterCompletion(),
])
```

---

## Podgląd Treści

### Preview Button

Każdy opublikowany rekord ma przycisk podglądu:

```php
Action::make('preview')
    ->label('Podgląd')
    ->icon('heroicon-o-eye')
    ->url(fn (Page $record) => route('page.show', $record->slug))
    ->openUrlInNewTab()
    ->visible(fn (Page $record) => $record->published_at && $record->published_at->isPast()),
```

**Działanie:**
- Ikona oka obok każdego rekordu w tabeli
- Otwiera frontend w nowej karcie
- Widoczny tylko dla opublikowanych treści

### URL Patterns

| Typ | URL Pattern | Przykład |
|-----|-------------|----------|
| Page | `/strona/{slug}` | `/strona/o-nas` |
| Post | `/aktualnosci/{slug}` | `/aktualnosci/nowy-artykul` |
| Promotion | `/promocje/{slug}` | `/promocje/rabat-20` |
| Portfolio | `/portfolio/{slug}` | `/portfolio/projekt-bmw` |

---

## Best Practices

### 1. Tworzenie Treści

```
✓ Zacznij od tytułu - slug wygeneruje się automatycznie
✓ Pisz główną treść w RichEditor (body)
✓ Dodawaj bloki Builder tylko gdy potrzebujesz zaawansowanych elementów
✓ Wypełnij SEO przed publikacją
✓ Używaj podglądu do weryfikacji
```

### 2. Organizacja Treści

```
✓ Używaj kategorii dla Posts i Portfolio
✓ Twórz hierarchię kategorii (parent_id)
✓ Stosuj spójne nazewnictwo slugów
✓ Archiwizuj zamiast usuwać (cofnij publikację)
```

### 3. SEO

```
✓ Meta title: max 60 znaków
✓ Meta description: max 160 znaków
✓ Featured image: min 1200x630px dla social sharing
✓ Unikalne meta title dla każdej strony
```

### 4. Obrazy

```
✓ Max rozmiar: 5MB
✓ Formaty: JPG, PNG, WebP
✓ Kompresuj przed uploadem
✓ Używaj opisowych nazw plików
✓ Zawsze dodawaj alt text
```

### 5. Performance

```
✓ Nie dodawaj zbyt wielu bloków na jedną stronę
✓ Ogranicz galerię do max 20 zdjęć
✓ Optymalizuj zdjęcia przed uploadem
✓ Używaj lazy loading dla galerii (frontend)
```

---

## Rozwiązywanie Problemów

### Zmiany nie są widoczne

```bash
# Restart kontenerów (OPcache)
docker compose restart app horizon queue scheduler

# Clear Laravel caches
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan filament:optimize-clear
```

### Slug już istnieje

- Slug musi być unikalny w tabeli
- Zmień slug ręcznie lub tytuł

### Upload nie działa

- Sprawdź uprawnienia storage: `chmod -R 775 storage`
- Sprawdź konfigurację dysku w `config/filesystems.php`
- Max size w PHP: `upload_max_filesize`, `post_max_size`

### Preview nie działa

- Treść musi być opublikowana (published_at w przeszłości)
- Sprawdź czy route istnieje: `php artisan route:list | grep strona`

---

## Powiązana Dokumentacja

- [CMS System Overview](./README.md)
- [Content Types Reference](./content-types.md)
- [Content Blocks Reference](./content-blocks.md)
- [Frontend Rendering](./frontend.md)
- [Filament v4 Migration](../../decisions/ADR-XXX-filament-v4.md)
