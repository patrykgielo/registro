---
paths:
  - "app/Filament/Pages/*Settings.php"
  - "app/Filament/Traits/HasGroupedSettings.php"
---

# Filament Settings Pages - Per-Tab Validation

## CRITICAL: Problem z $this->form->getState()

**NIE UŻYWAJ** `$this->form->getState()` w metodach save - waliduje WSZYSTKIE pola formularza!

```php
// ❌ BŁĄD: Waliduje WSZYSTKIE pola (42+ required fields!)
$data = $this->form->getState();

// ❌ BŁĄD: Globalny submit
Action::make('save')->submit('submit');
```

## Rozwiązanie: HasGroupedSettings Trait

### Użycie

```php
use App\Filament\Traits\HasGroupedSettings;

class MySettings extends Page implements HasForms
{
    use InteractsWithForms;
    use HasGroupedSettings;

    protected function getSettingsGroups(): array
    {
        return [
            'group_name' => [
                'label' => 'Human Label',
                'rules' => [
                    'field1' => ['required', 'string'],
                    'field2' => ['nullable', 'integer'],
                ],
            ],
        ];
    }

    public function saveGroupSettings(): void
    {
        $this->saveSettingsGroup('group_name');
    }
}
```

### Jak to działa

1. **Dane:** `$this->data[$group]` - tylko dane tej grupy (bez getState)
2. **Walidacja:** `Validator::make()` - tylko rules tej grupy
3. **Zapis:** `persistSettingsGroup()` - iteruje i zapisuje
4. **Cache:** `Cache::forget("settings:{$group}")` - czyści cache grupy
5. **Notyfikacja:** Success/error z labela grupy

### CRITICAL: `->default()` na polu tej strony NIE DZIAŁA — nie polegaj na nim

**Problem, zweryfikowany empirycznie (nie hipoteza — pierwsza teoria w code review 2026-08-22
była błędna, zobacz `payment-settlement-modes.md` → "Faza 1a"):** `Schema::fill($state)`
konsultuje `->default()` na polu WYŁĄCZNIE gdy CAŁY formularz wypełniany jest dosłownym `null`
(świeża strona "Create", bez rekordu) — patrz `vendor/filament/schemas/src/Concerns/HasState.php`,
`fill()`/`hydrateDefaultState()`. `SystemSettings::mount()` zawsze woła
`fill($settingsManager->all())` z PRAWDZIWĄ (nie-null) tablicą, gdy tenant ma choć jedno
ustawienie w JAKIEJKOLWIEK grupie — więc `->default()` na polach tej strony **nigdy nie jest
konsultowany**. Klucz nieobecny w `all()` hydratuje się do `null`. Dla `Toggle` ten `null` jest
następnie BEZWARUNKOWO koerentowany na `false` przez wbudowany `BooleanStateCast(isNullable:
false)` (`Toggle::getDefaultStateCasts()`) — PRZED jakimkolwiek hookiem, w tym
`afterStateHydrated`. Dla `TextInput` numeryczny brak wiersza zostaje `null` i po zapisie ląduje
w bazie jako `null` (np. `checkout.offline_reservation_hold_hours` → `(int) null` = `0` → po
`max(1, ...)` = **`1`** zamiast zamierzonych 48h). `persistSettingsGroup()` zapisuje WSZYSTKIE
klucze grupy, nie tylko zmieniony — więc zapis JEDNEGO niezwiązanego pola cicho utrwala złą
wartość dla całej reszty, dla KAŻDEGO tenanta bez własnego wiersza.

**Prawdziwa poprawka:** `->afterStateHydrated()` na polu, sprawdzający SUROWE ustawienie wprost
przez `app(SettingsManager::class)->get($key)` **bez defaultu** (zwraca `null` tylko gdy naprawdę
nie ma żadnego wiersza — tenant ani global) i dopiero wtedy wymuszający właściwą wartość przez
`$component->state(...)`. Nie sprawdzaj `$state === null` wewnątrz hooka — dla `Toggle` state już
dotarł jako coerced `false`, nie `null` (patrz wyżej); trzeba pytać `SettingsManager` wprost, PRZED
tym, jak cast zdąży zatrzeć różnicę między "brak wiersza" a "tenant świadomie wybrał false/0".
Wzór: `checkout.settlement_offline_enabled` w `SystemSettings.php` (naprawione
`feature/offline-settlement-default`, 2026-08-22). **Nienaprawione tam samo (poza mandatem tej
gałęzi, sprawdź przy najbliższej okazji):** `settlement_online_enabled` (ten sam mechanizm,
potwierdzone empirycznie: świeży tenant z realnie skonfigurowanym P24 dostaje
`isOnlineSettlementEnabled() === false` po dowolnym zapisie tej zakładki) i
`offline_reservation_hold_hours` (ląduje jako `1h` zamiast `48h`).

**Test-strażnik musi przejść przez prawdziwy round-trip** (`Livewire::test(SystemSettings::class)`,
mount+fill realne, `->call('saveXxxSettings')`), nie tylko sprawdzić `SettingsManager`
bezpośrednio — sama asercja na `SettingsManager` nie widzi tej klasy błędu. Od naprawy 2026-08-22
(patrz sekcja niżej) real `->call()` jest wykonalne nawet z polami `RichEditor` w grupie — nie ma
już powodu wywoływać `persistSettingsGroup()` refleksją. Wzór:
`tests/Feature/Filament/SystemSettingsCheckoutTabSaveTest.php`,
`tests/Feature/Filament/SystemSettingsCheckoutOfflineDefaultTest.php`.

**Tenant w teście, który używa `Livewire::test()` na stronie:** `Filament::setTenant($org)`,
NIE `$this->app['request']->attributes->set('tenant', $org)`. Zweryfikowane empirycznie
2026-08-22: `Livewire::test(SomePage::class)` (pełnostronicowy komponent) dispatchuje realny
sub-request przez kernel HTTP, co podmienia singleton `request` w kontenerze na NOWĄ instancję
`Request` — każdy atrybut ustawiony na request PRZED tym wywołaniem znika, zanim
`TenantFeature::currentTenant()`'s fallback (`app('request')->attributes->get('tenant')`) w ogóle
zdąży zapytać. Ten panel (`AdminPanelProvider`) nie ma skonfigurowanego `->tenant()`, więc gałąź 1
(`filament()->getTenant()`) też domyślnie zwraca `null` — bez `Filament::setTenant()` KAŻDY zapis
przez realny `->call()` ląduje po cichu w wierszu globalnym (`organization_id = NULL`), nie
wiersz tenanta, a test i tak "przechodzi" (asercje czytają ten sam globalny wiersz). Format
request-attribute nadal działa dla testów, które NIE wołają `Livewire::test()` na pełnostronicowym
komponencie (np. bezpośrednie wywołania statyczne na klasie Resource) — `RentalAvailabilityGuard
Test`, `ServiceResourceTenantLabelTest`. `Filament::setTenant()` zapisuje do `FilamentManager`,
zarejestrowanego przez `$this->app->scoped('filament', ...)` — ten scope przeżywa podmianę
instancji requestu (czyści go dopiero `forgetScopedInstances()`/koniec requestu), więc
`filament()->getTenant()` widzi go zarówno przed, jak i po `Livewire::test()`.

### RichEditor w grupie z `HasGroupedSettings` — NAPRAWIONE 2026-08-22

**Był problem:** `HasGroupedSettings::saveSettingsGroup()` walidowało `$this->data[$group]`
BEZPOŚREDNIO, z pominięciem castów Filamenta (deliberatnie — patrz sekcja "Problem z
`$this->form->getState()`" wyżej). Dla pól `RichEditor` internal `$this->data[...]` jest ZAWSZE
surowym dokumentem JSON Tiptapa (`RichEditorStateCast::set()` zawsze zwraca `getDocument()`),
nigdy stringiem HTML — więc reguła `['nullable', 'string', ...]` waliła walidację przy KAŻDYM
zapisie tej grupy, dla KAŻDEGO tenanta, nawet nietkniętym polu. Dotyczyło `checkout` (4 pola
RichEditor) — `saveCheckoutSettings()` nigdy się nie udawał, dla żadnego tenanta, w żadnym stanie.

**Naprawa:** `HasGroupedSettings::getGroupStateFromComponents($group)` zastępuje
`$this->data[$group] ?? []` jako źródło danych do walidacji. Idzie po
`$this->form->getFlatComponents(withHidden: true)`, filtruje komponenty których absolutny
`getStatePath()` zaczyna się od `data.{$group}.` i nie ma dalszej kropki w reszcie ścieżki (czyli
pomija pola zagnieżdżone wewnątrz Repeatera/FileUploada — te są zwinięte we własny `getState()`
rodzica), po czym woła `$component->getState()` na KAŻDYM z osobna.

**Dlaczego to bezpieczne — sprawdzone w vendorze:** `Component::getState()`
(`vendor/filament/schemas/src/Components/Concerns/HasState.php:934`, trait `Filament\Schemas\
Components\Concerns\HasState` — UWAGA, inna klasa niż walidujący `Filament\Schemas\Concerns\
HasState::getState()` na poziomie Schema/kontenera, patrz "Problem z `$this->form->getState()`")
robi tylko: `getRawState()` (czyta surowy stan Livewire tego JEDNEGO pola) + iteruje
`getStateCasts()`. Nie waliduje, nie dotyka żadnego innego pola. Dla `RichEditor`:
`RichEditorStateCast::get()` (`vendor/filament/forms/src/Components/RichEditor/StateCasts/
RichEditorStateCast.php`) renderuje dokument Tiptap do HTML przez `$editor->getHtml()`
(`isJson()` zwraca `false` tu — SystemSettings nie jest Eloquent-backed, `getContentAttribute()`
zwraca `null`). Dla `FileUpload`: `FileUploadStateCast::get()` (`vendor/filament/schemas/src/
Components/StateCasts/FileUploadStateCast.php`) wyciąga string ścieżki z tablicy indeksowanej
UUID (`Arr::first($state)`) — dla pól niewielokrotnych to ta sama transformacja, którą
`normalizeFileUploadValue()` w `persistSettingsGroup()` robiła ręcznie; ten kod zostaje
nietknięty (nadal potrzebny dla Repeatera, który nie ma własnego `StateCast`, więc trafia tam
nadal jako surowa tablica UUID-kluczowana).

**Zweryfikowany dowód mutacyjny:** cofnięcie `getGroupStateFromComponents()` z powrotem na
`$this->data[$group] ?? []` daje dosłownie: `Component has errors: "data.checkout.terms_label",
"data.checkout.rodo_label", "data.checkout.withdrawal_label", "data.checkout.deposit_policy_note"`
w `SystemSettingsCheckoutTabSaveTest`.

**DesignHub (druga strona na tym traicie) — bez regresji, sprawdzone testem.** Nie ma pól
RichEditor, więc nigdy nie było zablokowane tym bugiem, ale zmiana wpływa na WSZYSTKIE grupy na
obu stronach — `saveBrandIdentitySettings()` łączy DWIE grupy (`appearance` + `design`) z pól w
JEDNEJ Section, więc filtr po prefiksie `data.{group}.` (nie po granicy Section) musiał zostać
zweryfikowany osobno. Zobacz `tests/Feature/Filament/DesignHubSaveRegressionTest.php`.

### Dodawanie Nowego Taba (3 kroki)

1. **Dodaj metodę `xxxTab()`** zwracającą `Tabs\Tab` z przyciskiem save:
   ```php
   private function xxxTab(): Tabs\Tab
   {
       return Tabs\Tab::make('XXX')
           ->schema([
               // ... fields ...
               \Filament\Schemas\Components\Actions::make([
                   \Filament\Actions\Action::make('saveXxx')
                       ->label('Zapisz ustawienia')
                       ->action('saveXxxSettings')
                       ->color('primary')
                       ->icon('heroicon-o-check'),
               ])->columnSpanFull(),
           ]);
   }
   ```

2. **Dodaj grupę do `getSettingsGroups()`:**
   ```php
   'xxx' => [
       'label' => 'Ustawienia XXX zapisane',
       'rules' => [
           'field1' => ['required', 'string'],
       ],
   ],
   ```

3. **Dodaj metodę save:**
   ```php
   public function saveXxxSettings(): void
   {
       $this->saveSettingsGroup('xxx');
   }
   ```

## Validation Rules Format

```php
// Proste pola
'field_name' => ['required', 'string', 'max:100'],

// Tablice (Repeater)
'items' => ['nullable', 'array'],
'items.*.name' => ['required_with:items', 'string'],

// Toggle (boolean)
'is_enabled' => ['nullable', 'boolean'],

// Select z opcjami
'type' => ['required', 'in:option1,option2,option3'],

// Opcjonalne z formatem
'gtm_container_id' => ['nullable', 'string', 'max:20', 'regex:/^GTM-[A-Z0-9]+$/'],
```

## Architektura

```
PRZED (Problem):
┌─────────────────────────────┐
│ saveBookingSettings()       │
│   $this->form->getState()   │  ← Waliduje 42+ pól!
│   foreach($data['booking']) │
│   Cache::forget()           │
│   Notification::make()      │
└─────────────────────────────┘
× 10 tabów = 150+ linii duplikacji

PO (Rozwiązanie):
┌─────────────────────────────┐
│ saveBookingSettings()       │
│   $this->saveSettingsGroup( │  ← 1 linia!
│     'booking'               │
│   );                        │
└─────────────────────────────┘
× 10 tabów = 30 linii total

┌─────────────────────────────┐
│ HasGroupedSettings Trait    │
│   saveSettingsGroup($group) │  ← Reusable logic
│   - $this->data[$group]     │  ← Tylko dane grupy
│   - Validator::make()       │  ← Tylko rules grupy
│   - persistSettingsGroup()  │
│   - Cache::forget()         │
│   - Notification::make()    │
└─────────────────────────────┘
```

## Kontekstowe Akcje (Test Buttons)

Przyciski testowe (np. "Test Email", "Test SMS") powinny być:
- **W odpowiednim tabie** - NIE globalnie w `getFormActions()`
- **Obok przycisku Save** - w tej samej grupie Actions
- **Z polskim tłumaczeniem** - "Testuj połączenie" zamiast "Test Connection"

```php
// ✅ DOBRZE: W tabie obok save
\Filament\Schemas\Components\Actions::make([
    \Filament\Actions\Action::make('saveEmail')
        ->label('Zapisz ustawienia')
        ->action('saveEmailSettings')
        ->color('primary')
        ->icon('heroicon-o-check'),

    \Filament\Actions\Action::make('testEmail')
        ->label('Testuj połączenie')
        ->color('gray')
        ->icon('heroicon-o-paper-airplane')
        ->action('testEmailConnection')
        ->requiresConfirmation()
        ->modalHeading('Test połączenia email')
        ->modalDescription('Wyślemy testowy email na Twój adres. Kontynuować?')
        ->modalSubmitActionLabel('Wyślij testowy email'),
])->columnSpanFull(),

// ❌ ŹLE: W getFormActions() - widoczne na WSZYSTKICH tabach!
protected function getFormActions(): array
{
    return [
        \Filament\Actions\Action::make('testEmail'),  // Widoczny wszędzie!
        \Filament\Actions\Action::make('testSms'),    // Widoczny wszędzie!
    ];
}
```

### Dlaczego getFormActions() jest złe dla kontekstowych akcji?

`getFormActions()` zwraca akcje **na poziomie strony** (page-level), nie taba.
Filament wyświetla te przyciski globalnie - na każdym tabie.

**Rozwiązanie:** Umieszczaj kontekstowe akcje WEWNĄTRZ odpowiednich tabów.

## Powiązane pliki

- `app/Filament/Traits/HasGroupedSettings.php` - Trait z logiką
- `app/Filament/Pages/SystemSettings.php` - Implementacja
- `docs/decisions/ADR-016-per-tab-validation.md` - ADR

## FileUpload w Settings Pages (CRITICAL)

### Problem: Non-Eloquent Forms

Settings Pages NIE używają Eloquent modeli, więc Filament FileUpload:
- **NIE finalizuje** uploadów automatycznie (pliki zostają w `livewire-tmp`)
- Zwraca **asocjacyjne tablice** z UUID jako kluczem: `{"uuid-xxx": "path"}`
- **NIE można użyć** `$path[0]` - musi być `reset($array)`

### Rozwiązanie: saveUploadedFileUsing()

```php
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use enshrined\svgSanitize\Sanitizer;  // composer require enshrined/svg-sanitize

FileUpload::make('appearance.header_logo')
    ->disk('public')
    ->directory('settings/logos')
    ->visibility('public')
    ->image()
    ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/webp', 'image/jpeg'])
    ->maxSize(1024)
    ->saveUploadedFileUsing(function (TemporaryUploadedFile $file) {
        $mimeType = $file->getMimeType();

        // Magic bytes validation for raster images
        if ($mimeType !== 'image/svg+xml') {
            $magicBytes = file_get_contents($file->getRealPath(), false, null, 0, 8);
            $validSignatures = [
                "\x89PNG\x0D\x0A\x1A\x0A",  // PNG
                "RIFF",                      // WebP
                "\xFF\xD8\xFF",              // JPEG
            ];
            $isValid = false;
            foreach ($validSignatures as $signature) {
                if (str_starts_with($magicBytes, $signature)) {
                    $isValid = true;
                    break;
                }
            }
            if (!$isValid) {
                throw new \Exception('Invalid image format');
            }
        }

        // Store file
        $path = $file->storePublicly('settings/logos', 'public');

        // SVG Sanitization (XSS prevention)
        if ($mimeType === 'image/svg+xml') {
            $storage = Storage::disk('public');
            $content = $storage->get($path);
            $sanitizer = new Sanitizer();
            $sanitizer->removeRemoteReferences(true);
            $cleanSvg = $sanitizer->sanitize($content);
            if ($cleanSvg === false) {
                $storage->delete($path);
                throw new \Exception('SVG contains dangerous content');
            }
            $storage->put($path, $cleanSvg);
        }

        return $path;
    }),
```

### Bezpieczne pobieranie ścieżki (SettingsManager)

```php
private function extractFilePath(mixed $value): ?string
{
    if (empty($value)) {
        return null;
    }

    $path = null;

    if (is_string($value)) {
        $path = $value;
    } elseif (is_array($value)) {
        // reset() zwraca pierwszy element niezależnie od klucza
        $firstValue = reset($value);
        $path = is_string($firstValue) ? $firstValue : null;
    }

    if ($path === null) {
        return null;
    }

    return $this->validateFilePath($path);
}

private function validateFilePath(string $path): ?string
{
    // Reject empty paths
    if (empty(trim($path))) {
        return null;
    }
    // Reject absolute paths
    if (str_starts_with($path, '/') || preg_match('/^[a-z]:/i', $path)) {
        return null;
    }
    // Reject path traversal
    if (str_contains($path, '../') || str_contains($path, '..\\')) {
        return null;
    }
    // Reject livewire-tmp paths
    if (str_contains($path, 'livewire-tmp')) {
        return null;
    }
    // Normalize and verify
    $normalized = str_replace('\\', '/', $path);
    if (!Storage::disk('public')->exists($normalized)) {
        return null;
    }
    return $normalized;
}
```

### Incident 2026-01-24: Production 500 Error

**Problem:** `header_logo` zapisane jako `{"uuid": []}` zamiast string path.
**Root cause:** FileUpload bez `saveUploadedFileUsing()` nie finalizuje plików.
**Fix:** v4.20.2 - `saveUploadedFileUsing()` + `extractFilePath()` + SVG sanitization.

---

## Repeater Data Format (CRITICAL)

### Problem: [object Object] w Simple Repeater

**Symptom:** Po zapisaniu Repeatera, pola tekstowe pokazują `[object Object]` zamiast wartości.

### Filament Repeater Internal Format (WAŻNE!)

Filament używa UUID jako kluczy wewnętrznych. Format zależy od typu Repeatera:

```php
// Simple Repeater: simple(TextInput::make('item'))
// WEWNĘTRZNY STAN FILAMENT: ['uuid1' => ['item' => 'text1'], 'uuid2' => ['item' => 'text2']]
// ✅ Powinno być zapisane: ['text1', 'text2']
// ❌ NIE: [['item' => 'text1'], ['item' => 'text2']] - powoduje [object Object]!

// Complex Repeater: schema([TextInput::make('name'), IconPicker::make('icon')])
// WEWNĘTRZNY STAN FILAMENT: ['uuid1' => ['name' => 'X', 'icon' => 'Y'], ...]
// ✅ Powinno być zapisane: [['name' => 'X', 'icon' => 'Y'], ...]
```

### Rozróżnianie Simple vs Complex Repeater (AKTUALNA LOGIKA)

**Kluczowa różnica:** Simple Repeater ma DOKŁADNIE 1 klucz per item, Complex ma więcej.

```php
// W normalizeFileUploadValue() - HasGroupedSettings.php:

// Detect Simple Repeater: ALL items have exactly ONE key
$isSingleKeyRepeater = true;
foreach ($value as $item) {
    if (!is_array($item) || count($item) !== 1) {
        $isSingleKeyRepeater = false;
        break;
    }
}

if ($isSingleKeyRepeater) {
    // Simple Repeater: extract the single value from each item
    // ['uuid' => ['item' => 'text1'], ...] → ['text1', 'text2', ...]
    return array_map(
        fn($item) => reset($item),
        array_values($value)
    );
}

// Complex Repeater: keep structure, re-index to numeric
// ['uuid' => ['name' => 'X', 'icon' => 'Y'], ...] → [['name' => 'X', 'icon' => 'Y'], ...]
return array_values($value);
```

### Seeder Format (UWAGA!)

```php
// ✅ POPRAWNIE: Flat array dla Simple Repeater
'before_visit_items' => [
    'Upewnij się, że samochód...',
    'Usuń wartościowe przedmioty...',
],

// ✅ POPRAWNIE: Array of objects dla Complex Repeater
'service_location_types' => [
    ['name' => 'Parking', 'icon' => 'sun', 'description' => '...'],
    ['name' => 'Garaż', 'icon' => 'home', 'description' => '...'],
],

// ❌ BŁĘDNIE: Podwójne zagnieżdżenie
'before_visit_items' => [
    [  // Ten dodatkowy array powoduje [object Object]!
        'Upewnij się, że samochód...',
    ],
],
```

### Incident 2026-02-05: [object Object] na staging (ROZWIĄZANY)

**Problem:** Pola w Repeater "Przed wizytą" pokazywały `[object Object]`.

**Root cause (PRAWDZIWY):**
1. Filament Simple Repeater wysyła: `['uuid' => ['item' => 'text']]`
2. Stary kod zwracał: `[['item' => 'text1'], ['item' => 'text2']]`
3. Filament oczekuje: `['text1', 'text2']`
4. Filament próbował wyświetlić `['item' => 'text']` jako string → `[object Object]`

**Błędna pierwsza próba naprawy:**
- Zakładałem że Simple Repeater wysyła `['uuid' => 'string']`
- W rzeczywistości wysyła `['uuid' => ['fieldName' => 'string']]`
- Mój fix sprawdzał tylko `looksLikeFilePath()` co nie wystarczyło

**Poprawny fix (2026-02-05 v2):**
1. Wykrywanie Single-key Repeater przez sprawdzenie `count($item) === 1` dla WSZYSTKICH items
2. Flatten: `array_map(fn($item) => reset($item), array_values($value))`
3. Migracja naprawiająca format `[['item' => 'x']]` → `['x']`

**Lekcja:** ZAWSZE testuj ręcznie na staging PRZED zgłoszeniem "gotowe"!

---

## Odnośnik

- **ADR-016:** Per-Tab Validation for Settings Pages
- **Problem:** Filament v4 `getState()` → `validate()` → ALL fields
- **Root cause:** `getComponents(withHidden: true)` includes hidden tabs
