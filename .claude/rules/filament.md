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

## Critical: Set/Get Type-Hints (Bug 2026-07-02)

`Set`/`Get` moved namespaces in v4 too. `Forms\Set`/`Forms\Get` shorthand compiles fine but resolves
to the removed v3 class — TypeError only when the closure fires (`afterStateUpdated`, `->visible()`).

```php
// ❌ WRONG — v3, TypeError only when closure actually runs
use Filament\Forms;
fn (Forms\Set $set) => ...
use Filament\Forms\Get;
fn (Get $get) => ...

// ✅ CORRECT
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
fn (Set $set) => ...
fn (Get $get) => ...
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

## Transakcje zapisu — per strona, NIGDY `->databaseTransactions()` na panelu (2026-11-25, ADR-016)

Bez transakcji `begin/commit/rollBack` w `create()`/`save()`/`saveFormComponentOnly()` to no-opy: wyjątek w
`afterSave`/`afterCreate` albo w hooku `saved` zostawia już zapisane wiersze. Flaga PANELU włącza też transakcję
w **każdej akcji** (`Panel::register` → `Action::configureUsing`), a akcje offboardingu, zamówień, `CreateUser` i ról
wysyłają joby/powiadomienia do kolejki PRZED commitem (`after_commit=false`) albo czyszczą cache Spatie — lista
blokerów i warunek przejścia na flagę panelu: `app/docs/decisions/ADR-016-filament-save-transactions.md`.

```php
// ✅ na stronie Create/Edit, PO audycie jej efektów ubocznych (joby, maile, cache, HTTP, pliki)
protected ?bool $hasDatabaseTransactions = true;
```

Dziś: `EditService` (pełny zapis + `saveTab()`), `CreateService`, `CreateEmployee`, `CreateCustomer`. Lista jest
przypięta testem `FilamentSaveTransactionsTest` — nowa strona albo akcja z `->databaseTransaction()` = audyt + wpis
w teście i w ADR. `Halt` domyślnie COMMITUJE (rollback: `halt(true)`). Pliki z FileUpload nie są cofane (sieroty).
Test rollbacku: wyjątek wstrzyknięty po `UPDATE` (np. `ServiceLocationStock::saving` rzuca) + test kontrolny z flagą
`false` na instancji — bez niego test rollbacku przechodzi też wtedy, gdy scenariusz w ogóle nie dochodzi do zapisu.

## Livewire Assets (gdy `->live()` nie działa)

```bash
php artisan livewire:publish --assets && php artisan filament:assets && php artisan optimize:clear
```

## Module System

`protected static ?string $module = 'services';` na Resource → auto-gating przez `BaseResource::shouldRegisterNavigation()`.

## maxContentWidth() — Enum to `Width`, NIE `MaxWidth` (Platform Redesign)

```php
// ❌ Nie istnieje
use Filament\Support\Enums\MaxWidth;

// ✅ v4.5
use Filament\Support\Enums\Width;
$panel->maxContentWidth(Width::ScreenTwoExtraLarge); // 'screen-2xl'
```

## Section/Tabs/Grid/Fieldset → `->headerActions()` działa też w Form Schema

`HasHeaderActions` jest w bazowym `Schemas\Components\Component` — nie tylko w Infolist Section.
`$record` jest auto-resolvowany z bound modelu schematu (jak w table actions). Guard `->visible()`
na `?Model $record` (Create page nie ma jeszcze rekordu). Pełny przykład: `app/docs/guides/filament-v4-component-architecture.md`.

## Version Check

Wersję czytaj z `composer.lock` (`"name": "filament/filament"` → `version`), nie z tego pliku —
wpisana tu liczba (4.5.2) rozjechała się z instalacją (4.11.5 w 2026-11) i dwa razy wprowadziła
w błąd. Kod vendora jest tylko w drzewie WSL (`/var/www/projects/registro/app/vendor`).
Przed nowymi metodami: https://filamentphp.com/docs/4.x/

## Aktualizacja 4.11.5 → 4.17.0 (2026-11-22) — co z 4.12–4.17 dotyka tego repo

Ta sama wersja główna (Livewire zostaje na 3.x: 3.8.0 → 3.8.12, wymuszone przez `filament/support ^4.17`
→ `livewire ^3.8.12`; `composer update filament/filament livewire/livewire --with-dependencies`,
samo `filament/filament` zatrzymuje się na 4.13.4, bo Livewire to root requirement i bez wskazania nie rusza).
Zmiany, które realnie dotykają kodu/testów:

- **Repeater, Builder, Text, Fieldset, Flex… renderują się z PHP (`toEmbeddedHtml()`), nie z Blade.**
  `Repeater::getDefaultView()` zwraca `null` — nazwa widoku to teraz tylko `getPublishedViewOverrideCheckPath()`.
  Markup liczników/tabów się zmienił: `x-on:expand="revealTab('<klucz>::data::tab')"` (było `tab = …`), item Buildera ma
  `x-data="item(JSON.parse('{"isCollapsed":true,…}'))"` (było `isCollapsed: true,`). Testy `->html()` pinują
  markup — trzy z nich (`ServiceFormContentAndSpecLayoutTest` ×2, `ServiceFormTabsTest` ×1) zaktualizowano za zgodą
  właściciela do nowego markupu, logika asercji bez zmian. Przy kolejnym bumpie zmiany markupu będą się objawiać
  właśnie tam: czytaj diff markupu, nie „naprawiaj" logiki.
- **Pusty/„disabled" root schema nic nie zwraca (4.17, #21076/#21070).** `getState()` na schemacie rootowym z
  `->disabled()` (np. `EditAction::disabledSchema()`) waliduje i zwraca `[]`; relacje, `FileUpload` i `RichEditor`
  w wyłączonym schemacie/komponencie nie zapisują się same. Pole z `->disabled()->dehydrated()` w AKTYWNYM schemacie
  działa jak dotąd (grep po `app/Filament/**`: ani jeden wyłączony root, ani wyłączony kontener Section/Group/Fieldset —
  wszystkie `->disabled(` stoją na polach). Wzorzec „wyłącz pole, ale zapisz jego wartość" zostaje
  `->disabled()->dehydrated()`; NIE „wyłączaj cały formularz i licz na zapis". Uwaga: `->disabled()` ustawia
  `saved(false)` (`CanBeDisabled`, 4.11 i 4.17), więc wyłączone pole BEZ `->dehydrated()` NIE jest zapisywane.
- **Deploy: otwarte strony przestają działać do przeładowania.** Livewire 3.8.12 zapisuje w snapshocie `memo.origin` i
  `memo.route` i zwraca 404 dla snapshotu bez nich lub z inną domeną/trasą — każda strona `/admin` i `/platform`
  otwarta w trakcie wdrożenia zawiedzie przy następnej akcji, aż do odświeżenia (Livewire: „Refresh already-open
  pages"). To zewnętrzna warstwa PRZED naszym `ResolveTenant`/`RequireTenant`, nie ich zamiennik — patrz
  `app/docs/security/patterns/livewire-tenant-isolation.md`, test `LivewireTenantReplaySameOriginTest`.
- **Powiadomienia Filamenta nie leżą już w sesji (4.17, `NotificationMailbox`).** `Notification::send()` odkłada je w
  pamięci; middleware `PublishNotifications` zapisuje je do cache (klucz zależny od sesji), a komponent
  `Notifications` odbiera je przy `GET` z nagłówkiem `Sec-Fetch-Dest: document` (przeglądarka go wysyła, klient testowy
  NIE) albo przez Livewire. `session('filament.notifications')` to teraz tylko znacznik skrzynki, nie lista. Test, który
  woła metodę strony poza kernelem, czyta `app(NotificationMailbox::class)` → `flush()` + `pull()`; test, który
  sprawdza treść strony po redirecie, musi wysłać `Sec-Fetch-Dest: document`. Dotyczy:
  `ClosureRequestRecipientTest` (helper `flashed()`), `SuperAdminPasswordResetTest`,
  `SuperAdminPasswordResetPinnedStackTest` — poprawki zweryfikowane na kopiach (47 zielonych), do zatwierdzenia przez
  właściciela.
- **`composer audit` po bumpie: 14 ostrzeżeń, wszystkie istniały wcześniej (żadne nie pochodzi z tego bumpu); bump
  naprawił 15 innych** (m.in. omijanie MFA w Filamencie, XSS w Livewire, DoS w tiptap). Pozostałe, w tym krytyczny
  `laravel/framework <12.68` (TokenGuard), wymagają OSOBNEGO bumpu frameworka.
- **`Repeater`/`Builder::partiallyRenderAfterActionsCalled()` ma teraz domyślnie `! isLive()`** (było `true`). Dotyka
  tylko repeaterów `->live()` (pełny rerender po dodaniu/usunięciu wiersza — tańsze dla nas niż błąd, wolniejsze o
  rerender). Nasz Builder ma `false` jawnie.
- **Livewire (#20812, 4.16):** metody zwracające komponent Filamenta są niedostępne z frontu, a „server-managed"
  właściwości `#[Locked]`. Własne `wire:click`/`$wire.set()` w `resources/views/filament/**` (metody `void`, `data.*`)
  nie są dotknięte; nowa własna integracja wołająca metodę zwracającą `Component` wymaga `#[Exposed]`.
- Inline kolumny (`TextInputColumn`/`ToggleColumn`): 4.17 dodaje opt-in `authorize()` — domyślnie nadal NIE sprawdzają
  polityki (komentarz w `LocationStocksRelationManager` pozostaje prawdziwy).
- `->reactive()` (`MaintenanceSettings`) jest oznaczone `@deprecated` na rzecz `->live()` — działa, tylko metadane.
- Dla porządku: wbudowanego licznika znaków `Textarea`/`TextInput` w 4.17 nadal NIE ma (`grep` po `packages/forms`).
- Assety: `public/{css,js,fonts}/filament/**` i `public/vendor/livewire/**` są w gicie — po każdym bumpie
  `php artisan filament:assets && php artisan livewire:publish --assets`; `filament:assets` NIE kasuje starych czcionek
  `inter-*.woff2` (hash w nazwie), więc osierocone pliki trzeba usunąć ręcznie (zostają tylko te z `index.css`).
