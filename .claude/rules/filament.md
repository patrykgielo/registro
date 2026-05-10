---
paths:
  - "app/Filament/**"
  - "**/Filament/**"
---

# Filament v4 Rules

When working with Filament files, ALWAYS follow these rules:

## CRITICAL: Version Check Before New Features

**ALWAYS verify method availability before implementing!**

```bash
# Check current Filament version
docker compose exec app composer show filament/filament | grep versions

# Current project version: v4.5.2
```

**Before using any Filament method:**
1. Search official docs: https://filamentphp.com/docs/4.x/
2. Check release notes for when method was introduced
3. Use web-research-specialist agent with Firecrawl to verify

**Example of version-specific methods:**
- `automaticallyOpenImageEditorForAspectRatio()` - requires v4.5+
- `imageAspectRatio()` - requires v4.5+

## Critical Namespace Changes (v4 Breaking)

### Resource/Page Method Signatures (BREAKING!)

**Filament v4 zmienił sygnatury metod — `Form` → `Schema`!**

```php
// ❌ WRONG — v3 signature, NIE DZIAŁA w v4!
use Filament\Forms\Form;
public static function form(Form $form): Form { ... }

// ✅ CORRECT — v4 signature
use Filament\Schemas\Schema;
public static function form(Schema $schema): Schema { ... }
```

**Dotyczy też property types:**
```php
// ❌ WRONG
protected static ?string $navigationIcon = 'heroicon-o-...';

// ✅ CORRECT — v4 wymaga union type
protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-...';

// ❌ WRONG
protected static ?string $navigationGroup = 'content';

// ✅ CORRECT
protected static string|\UnitEnum|null $navigationGroup = 'content';
```

**Table/Resource Actions — przeniesione do `Filament\Actions`!**
```php
// ❌ WRONG — v3 namespace, NIE ISTNIEJE w v4!
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\DeleteBulkAction;
Tables\Actions\EditAction::make()

// ✅ CORRECT — v4: wszystkie Actions w jednym namespace
use Filament\Actions;
Actions\EditAction::make()
Actions\DeleteBulkAction::make()
Actions\BulkActionGroup::make([...])
```

**Zawsze weryfikuj wzorce z istniejących Resources w projekcie** (np. `ServiceResource.php`).

### DECISION RULE: Container vs Field

**Ask yourself:** Is it a CONTAINER (holds other components) or a DATA ENTRY FIELD (user types/selects)?

- **CONTAINER** (layout structure) → `Filament\Schemas\Components`
- **DATA ENTRY FIELD** (input) → `Filament\Forms\Components`

### Layout/Container Components → `Filament\Schemas\Components\*`

```php
// These HOLD other components - use Schemas namespace!
use Filament\Schemas\Components\Section;     // Collapsible section
use Filament\Schemas\Components\Grid;        // Multi-column layout
use Filament\Schemas\Components\Tabs;        // Tab container
use Filament\Schemas\Components\Fieldset;    // Grouped fields
use Filament\Schemas\Components\Flex;        // Flexbox layout
use Filament\Schemas\Components\Group;       // Simple grouping
use Filament\Schemas\Components\Wizard;      // Multi-step wizard
use Filament\Schemas\Schema;                 // Form schema return type
```

### Form Input Fields → `Filament\Forms\Components\*`

```php
// These accept USER INPUT - use Forms namespace!
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Builder;       // Page builder
use Filament\Forms\Components\Builder\Block; // Builder block
```

### ⚠️ COMMON MISTAKE - Grid/Section/Fieldset

```php
// ❌ WRONG - these don't exist!
Forms\Components\Grid::make(2)
Forms\Components\Section::make()
Forms\Components\Fieldset::make()

// ✅ CORRECT - use Schemas namespace!
Filament\Schemas\Components\Grid::make(2)
Filament\Schemas\Components\Section::make()
Filament\Schemas\Components\Fieldset::make()
```

### Infolist Display Components → `Filament\Infolists\Components\*`

```php
// For DISPLAYING data (read-only)
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
```

## Dependent Fields Reset Pattern (Bug z 2026-01-21)

**Problem:** Gdy używasz `->live()` na polu Select, które kontroluje opcje innego pola, zmiana wartości odświeża OPCJE ale NIE resetuje WARTOŚCI zależnego pola.

```php
// ❌ BUG: content_items zachowa stare ID po zmianie content_type
Forms\Components\Select::make('content_type')
    ->options([...])
    ->live(),  // Tylko odświeża opcje, nie resetuje wartości!

Forms\Components\Select::make('content_items')
    ->options(fn ($get) => match($get('content_type')) {...})
```

**Rozwiązanie:** Dodaj `->afterStateUpdated()` aby explicite zresetować zależne pole:

```php
// ✅ POPRAWNIE: resetuje content_items przy zmianie typu
Forms\Components\Select::make('content_type')
    ->options([...])
    ->live()
    ->afterStateUpdated(fn ($set) => $set('content_items', [])),
```

**Wzorce użycia:**
```php
// Reset do null (pojedyncza wartość)
->afterStateUpdated(fn ($set) => $set('field_name', null))

// Reset do pustej tablicy (multiple select)
->afterStateUpdated(fn ($set) => $set('field_name', []))

// Reset wielu pól na raz
->afterStateUpdated(function ($state, callable $set) {
    if ($state !== 'expected_value') {
        $set('field1', null);
        $set('field2', null);
    }
})
```

---

## Livewire Assets - Reactivity Issues

**Symptom:** Warning w konsoli: "Livewire: The published Livewire assets are out of date"

**Problem:** `->live()` i `->afterStateUpdated()` mogą nie działać poprawnie.

**Rozwiązanie:**
```bash
php artisan livewire:publish --assets
php artisan filament:assets
php artisan optimize:clear
```

**Kiedy publikować assety:**
- Po upgrade Livewire/Filament
- Gdy reactivity przestaje działać
- Gdy widzisz warning w konsoli

---

## Widget Rules (Avoid Recent Bug)

- Widgets are top-level components with built-in layout
- **NEVER** nest `<x-filament::section>` as root element in widgets
- Use `<x-filament-widgets::widget>` wrapper in Blade templates
- Heading/description go to widget slots, NOT section component

## Array Column + formatStateUsing TypeError (Bug z 2026-02-16)

**Problem:** `formatStateUsing()` na kolumnie z `array`-cast modelem rzuca TypeError z `declare(strict_types=1)`.

Filament wewnętrznie wykonuje `array_map` na `formatStateUsing` — przekazuje KAŻDY element tablicy osobno. Gdy kolumna ma array cast, Filament próbuje sformatować tablicę element po elemencie.

```php
// ❌ BŁĄD: TypeError z strict_types — Str::limit(6, 40) zwraca int 6
Tables\Columns\TextColumn::make('old_values')
    ->formatStateUsing(fn ($state) => Str::limit((string) $state, 40))
    // $state = array! Filament array_map-uje to → TypeError

// ✅ POPRAWNIE: użyj getStateUsing() do konwersji PRZED Filament
Tables\Columns\TextColumn::make('old_values')
    ->getStateUsing(fn ($record) => json_encode($record->old_values))
    ->limit(100)
```

**Zasada:** Dla kolumn z `array` cast — ZAWSZE używaj `getStateUsing()` zamiast `formatStateUsing()`.

---

## SVG w Filament Placeholder — Sizing (Bug z 2026-02-16)

**Problem:** Ikony SVG wewnątrz `Forms\Components\Placeholder` renderują się OGROMNE.

**Root cause:** Filament CSS `.fi-in-entry-content { @apply block w-full }` wymusza `display: block; width: 100%`. SVG bez HTML atrybutów `width`/`height` rozszerzają się do rozmiaru kontenera. Klasy Tailwind (`w-4 h-4`) mają niższy priorytet niż layout flow SVG.

```php
// ❌ BŁĄD: SVG będzie ogromne (100% width kontenera)
Forms\Components\Placeholder::make('icon')
    ->content(new HtmlString('
        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">...</svg>
    '))

// ✅ POPRAWNIE: dodaj HTML width/height + flex-shrink-0
Forms\Components\Placeholder::make('icon')
    ->content(new HtmlString('
        <svg class="w-4 h-4 flex-shrink-0" width="16" height="16" fill="currentColor" viewBox="0 0 20 20">...</svg>
    '))
```

**Zasady:**
1. SVG w Placeholder MUSI mieć atrybuty HTML `width` i `height`
2. Dodaj `flex-shrink-0` aby zapobiec kurczeniu w flex kontenerze
3. Klasy Tailwind (`w-4 h-4`) same w sobie NIE WYSTARCZĄ

---

## DatePicker — Warunkowe minDate (Bug z 2026-02-16)

**Problem:** `->minDate(now())` na DatePicker blokuje KAŻDĄ edycję rekordu z datą w przeszłości — nawet zmianę statusu.

Filament waliduje WSZYSTKIE pola formularza przy zapisie, nie tylko te zmienione. Jeśli appointment_date = 2026-01-30 a minDate = now() (2026-02-16), walidacja odrzuci formularz.

```php
// ❌ BŁĄD: blokuje edycję starych rekordów
Forms\Components\DatePicker::make('appointment_date')
    ->minDate(now())

// ✅ POPRAWNIE: minDate tylko przy tworzeniu (create), nie przy edycji
Forms\Components\DatePicker::make('appointment_date')
    ->minDate(fn (?Appointment $record): ?string => $record ? null : now()->toDateString())
```

**Zasada:** Gdy pole ma walidację zależną od kontekstu (create vs edit), ZAWSZE sprawdzaj `$record`:
- `$record === null` → tworzenie nowego rekordu
- `$record !== null` → edycja istniejącego rekordu

---

## Action Modal z Infolist Components (Pattern z 2026-02-16)

**Problem:** W Filament v4 `Filament\Infolists\Infolist` NIE ISTNIEJE. Metoda `->infolist()` jest deprecated alias dla `->schema()`.

```php
// ❌ BŁĄD: klasa nie istnieje w v4
use Filament\Infolists\Infolist;
Actions\Action::make('details')
    ->infolist(Infolist::make([...]));

// ❌ BŁĄD: Section z Infolists namespace nie istnieje
use Filament\Infolists\Components\Section;

// ✅ POPRAWNIE: ->schema() + Section z Schemas namespace
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;

Actions\Action::make('details')
    ->modalHeading('Szczegóły')
    ->modalSubmitAction(false)
    ->schema(function (Model $record): array {
        return [
            Section::make('Info')
                ->schema([
                    TextEntry::make('field')->label('Label'),
                ])
                ->columns(2),
        ];
    });
```

**Zasady:**
- `Section` → ZAWSZE z `Filament\Schemas\Components\Section`
- `TextEntry` → z `Filament\Infolists\Components\TextEntry` (OK)
- `->schema()` zamiast `->infolist()` na Action

---

## Widget Property Static vs Instance — CRITICAL (Bug 2026-05-10)

Filament v4 widget properties are NOT uniformly static. Declaring them wrong causes FatalError.

```php
// ❌ WRONG — ChartWidget/StatsOverviewWidget: these are NON-STATIC
protected static ?string $heading = '...';      // FatalError!
protected static ?string $description = '...';  // FatalError!
protected static ?string $pollingInterval = ...; // FatalError!

// ✅ CORRECT — instance properties (no static)
protected ?string $heading = '...';
protected ?string $description = '...';
protected ?string $pollingInterval = null;

// ✅ CORRECT — Widget base: $sort IS static
protected static ?int $sort = 2;
```

**Root cause:** `Filament\Widgets\ChartWidget` and CanPoll trait declare these as `protected ?string`,
not `protected static`. PHP throws FatalError when a child tries to override non-static with static.

---

## Module System Integration (Phase 6)

Resources dziedziczą automatyczny `shouldRegisterNavigation()` z `BaseResource` który sprawdza `Organization.hasModule()`.

**Pattern:** `protected static ?string $module = 'services';` na Resource class.

Szczegóły: → `filament-resources.md` sekcja "Module Visibility Gating"

## Documentation References

- [Component Architecture](docs/guides/filament-v4-component-architecture.md)
- [Migration Guide](docs/guides/filament-v4-migration-guide.md)
- [Best Practices](docs/guides/filament-v4-best-practices.md)
- [Widgets Guide](docs/guides/filament-v4-widgets-guide.md)
