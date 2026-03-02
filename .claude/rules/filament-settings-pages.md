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
