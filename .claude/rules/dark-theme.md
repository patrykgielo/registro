---
paths:
  - "resources/css/**"
  - "resources/views/**"
  - "resources/js/**"
---

# Dark Theme Rules - CRITICAL

## KLUCZOWE ROZRÓŻNIENIE

**"Dark mode" w tym projekcie = ciemne tło sekcji, NIE systemowy dark mode!**

| Termin | Znaczenie |
|--------|-----------|
| **Dark theme/mode** | Sekcje z ciemnym tłem (#00323B, #000000) |
| **NIE** `prefers-color-scheme: dark` | Projekt nie ma przełącznika dark/light |
| **NIE** systemowy dark mode | Nie reagujemy na ustawienia systemu |

---

## Kolory klienta (BEZWZGLĘDNE)

Przekazane przez klienta 2025-12:

| Element | Kolor | Zmienna CSS |
|---------|-------|-------------|
| Tło sekcji | `#00323B` | `--color-dark-section` |
| Kafelki | `#000000` | `--color-dark-tile` |
| Przycisk CTA | `#0AB1EA` | `--color-dark-cta` |
| Tekst | `#FFFFFF` | `--color-dark-text` |
| Tekst przyciszony | `rgba(255,255,255,0.7)` | `--color-dark-text-muted` |

**NIGDY nie używaj innych kolorów dla ciemnych sekcji!**

---

## Kiedy włącza się "dark mode"

### 1. Service Card z `variant="dark"`

```blade
<x-ios.service-card :service="$service" variant="dark" />
```

Klasy CSS: `.service-card-dark`, `.btn-cta-dark`, `.shadow-dark-glow`

### 2. Block Tekstowy z ciemnym tłem

W Filament CMS:
- `background_type: solid`
- `background_color: #00323B` (lub inny ciemny kolor)

Automatyczna detekcja w `text-block.blade.php`:
```php
// Kalkulacja luminance (jasności) koloru
$isColorDark = function (string $hex): bool {
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $luminance < 0.5;
};

$isDark = ($data['background_type'] ?? 'none') === 'solid'
    && isset($data['background_color'])
    && $isColorDark($data['background_color']);

// Jeśli ciemne tło → dodaj prose-invert
$proseClasses = $isDark ? 'prose-invert' : '';
```

### 3. Sekcja z klasą `.bg-section-dark`

```blade
<section class="bg-section-dark">
    {{-- Treść na ciemnym tle --}}
</section>
```

---

## Istniejące klasy CSS

Zdefiniowane w `resources/css/app.css`:

```css
/* Tło sekcji */
.bg-section-dark { background-color: #00323B; }

/* Kafelek usługi */
.service-card-dark { background-color: #000000; }

/* Przycisk CTA */
.btn-cta-dark { background-color: #0AB1EA; color: white; }

/* Tekst */
.text-dark-primary { color: #FFFFFF; }
.text-dark-muted { color: rgba(255,255,255,0.7); }

/* Cienie z poświatą */
.shadow-dark-glow { box-shadow: 0 0 20px rgba(10,177,234,0.15), ...; }
.shadow-dark-glow-hover { box-shadow: 0 0 35px rgba(10,177,234,0.25), ...; }
```

---

## Typografia na ciemnym tle

Klasa `.prose-registro.prose-invert` używa kolorów klienta:

```css
.prose-registro.prose-invert {
  --tw-prose-body: #FFFFFF;           /* Klient */
  --tw-prose-headings: #FFFFFF;       /* Klient */
  --tw-prose-links: #0AB1EA;          /* Klient */
  --tw-prose-quote-borders: #0AB1EA;  /* Klient */
}
```

Zdefiniowane w `resources/css/prose-typography.css`.

---

## ZAKAZANE

1. **NIGDY nie używaj `@media (prefers-color-scheme: dark)`** dla głównych stylów
2. **NIGDY nie zmieniaj kolorów klienta** bez jego zgody
3. **NIGDY nie używaj odcieni szarości** (#D1D5DB, #F3F4F6) zamiast #FFFFFF na ciemnym tle
4. **NIGDY nie używaj innego koloru linków** niż #0AB1EA na ciemnym tle

---

## Historia incydentów

### 2026-01-24: Bug detekcji ciemnego tła

**Problem:** `text-block.blade.php` używał string matching:
```php
// BŁĘDNE - nie wykrywa kolorów klienta!
str_contains($data['background_color'], '1f') || str_contains($data['background_color'], '111')
```

Kolory klienta (#00323B, #000000) nie zawierają `1f` ani `111` → nie były wykrywane jako ciemne.

**Rozwiązanie:** Kalkulacja luminance (jasności) według WCAG.

---

## Pliki kluczowe

| Plik | Zawartość |
|------|-----------|
| `resources/css/app.css` | Klasy .service-card-dark, .btn-cta-dark, etc. |
| `resources/css/prose-typography.css` | .prose-registro.prose-invert |
| `resources/css/design-tokens.css` | Zmienne --color-dark-* |
| `design-system.json` | Sekcja "dark" z kolorami klienta |
| `components/ios/service-card.blade.php` | Logika variant="dark" |
| `components/content-blocks/text-block.blade.php` | Detekcja ciemnego tła |
