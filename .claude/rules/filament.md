---
paths:
  - "app/Filament/**"
  - "**/Filament/**"
---

# Filament v4 Rules

## Critical: Form Method Signature (BREAKING!)

```php
// ❌ v3 — NIE DZIAŁA w v4!
use Filament\Forms\Form;
public static function form(Form $form): Form { ... }

// ✅ v4 — wymagane
use Filament\Schemas\Schema;
public static function form(Schema $schema): Schema { ... }
```

Property types (BREAKING):
```php
// ❌ WRONG                                      // ✅ CORRECT
?string $navigationIcon                          string|\BackedEnum|null $navigationIcon
?string $navigationGroup                         string|\UnitEnum|null $navigationGroup
```

## Critical: Actions Namespace (BREAKING!)

```php
// ❌ WRONG — v3 namespace nie istnieje w v4!
use Filament\Tables\Actions\EditAction;

// ✅ CORRECT — wszystkie Actions w jednym namespace
use Filament\Actions;
Actions\EditAction::make()
Actions\DeleteBulkAction::make()
Actions\BulkActionGroup::make([...])
```

## Critical: Container vs Field Namespace

- **CONTAINER** (Section, Grid, Tabs, Fieldset, Flex, Wizard) → `Filament\Schemas\Components\*`
- **DATA ENTRY** (TextInput, Select, Textarea, Toggle, DatePicker, FileUpload) → `Filament\Forms\Components\*`
- **DISPLAY** (TextEntry, IconEntry, ImageEntry) → `Filament\Infolists\Components\*`

```php
// ❌ WRONG — nie istnieje!
Forms\Components\Section::make()
Forms\Components\Grid::make(2)

// ✅ CORRECT
Filament\Schemas\Components\Section::make()
Filament\Schemas\Components\Grid::make(2)
```

## Critical: Widget Properties — Static vs Instance (Bug 2026-05-10)

```php
// ❌ WRONG — FatalError (ChartWidget/StatsOverviewWidget)
protected static ?string $heading = '...';
protected static ?string $pollingInterval = null;

// ✅ CORRECT — instance properties (no static)
protected ?string $heading = '...';
protected ?string $pollingInterval = null;

// ✅ CORRECT — $sort IS static
protected static ?int $sort = 2;
```

## Dependent Fields Reset (Bug 2026-01-21)

`->live()` odświeża opcje ale NIE resetuje wartości zależnego pola:

```php
// ❌ BUG: content_items zachowa stare ID
Select::make('content_type')->live()

// ✅ FIX: explicite reset
Select::make('content_type')
    ->live()
    ->afterStateUpdated(fn ($set) => $set('content_items', []))
```

## DatePicker — minDate w Edit Context (Bug 2026-02-16)

```php
// ❌ Blokuje edycję rekordów z datą w przeszłości
->minDate(now())

// ✅ minDate tylko przy tworzeniu
->minDate(fn (?Appointment $record) => $record ? null : now()->toDateString())
```

## Array Column + formatStateUsing TypeError (Bug 2026-02-16)

```php
// ❌ TypeError — Filament array_map-uje na array cast
->formatStateUsing(fn ($state) => Str::limit((string) $state, 40))

// ✅ Użyj getStateUsing() dla array-cast kolumn
->getStateUsing(fn ($record) => json_encode($record->old_values))->limit(100)
```

## Action Modal z Infolist (v4 Pattern)

```php
// ❌ BŁĄD: Infolist::make() nie istnieje w v4
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Section;

// ✅ POPRAWNIE
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
Actions\Action::make('details')
    ->schema([
        Section::make()->schema([TextEntry::make('field')])->columns(2),
    ]);
```

## SVG w Placeholder — Sizing (Bug 2026-02-16)

Filament CSS wymusza `width: 100%` na SVG. Dodaj HTML atrybuty:
```php
// ❌ SVG będzie ogromne
new HtmlString('<svg class="w-4 h-4">...</svg>')

// ✅ HTML atrybuty + flex-shrink-0
new HtmlString('<svg class="w-4 h-4 flex-shrink-0" width="16" height="16">...</svg>')
```

## Custom CSS/JS via renderHook (Pattern z Phase 11b)

```php
// ❌ WRONG — zastępuje CSS Filament (łamie layout)
->viteTheme('resources/css/filament/admin.css')

// ✅ CORRECT — additive (dodaje obok Filament)
->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString(
    '<link rel="stylesheet" href="' . Vite::asset('resources/css/filament/admin.css') . '">'
    . '<script type="module" src="' . Vite::asset('resources/js/filament-admin.js') . '"></script>'
))
```

Tailwind v4 w Filament: używaj `@theme { }` (nie `:root { }`) — tylko `@theme` generuje utility classes.

Alpine.js w filament-admin.js: rejestruj przez `alpine:init`, BEZ `Alpine.start()`.

## Livewire Assets (gdy `->live()` nie działa)

```bash
php artisan livewire:publish --assets && php artisan filament:assets && php artisan optimize:clear
```

## Module System

`protected static ?string $module = 'services';` na Resource → auto-gating przez `BaseResource::shouldRegisterNavigation()`.

## Version Check

Projekt: **v4.5.2**. Przed nowymi metodami: https://filamentphp.com/docs/4.x/
