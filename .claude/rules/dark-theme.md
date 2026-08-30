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
| **Dark theme/mode** | Sekcje z ciemnym tłem (tokeny `--color-dark-bg`, `--color-dark-bg-raised`) |
| **NIE** `prefers-color-scheme: dark` | Projekt nie ma przełącznika dark/light |
| **NIE** systemowy dark mode | Nie reagujemy na ustawienia systemu |

---

## Kolory ciemnych sekcji — STAN FAKTYCZNY

Wszystko poniżej odczytane z `resources/css/design-tokens.css:67-71`. **Nie wpisuj tu
wartości, której nie da się znaleźć `grep`em w `resources/`.**

| Element | Token | Wartość | sRGB |
|---------|-------|---------|------|
| Tło sekcji | `--color-dark-bg` | `oklch(15% 0.01 250)` | ~rgb(10, 13, 16) |
| Tło kafelka/karty | `--color-dark-bg-raised` | `oklch(20% 0.01 250)` | **rgb(19, 22, 26)** |
| Tekst | `--color-dark-text` | `oklch(100% 0 0)` | `#FFFFFF` |
| Tekst przyciszony | `--color-dark-text-muted` | `oklch(70% 0.01 250)` | ~rgb(163, 167, 172) |
| Akcent / CTA / linki | `--color-dark-accent` | `oklch(65% 0.2 250)` | ~`#0AB1EA` |

### PUŁAPKA: tło ciemnej karty NIE jest czernią

Licząc kontrast warstw półprzezroczystych (`text-white/80`, `bg-white/10`, `bg-black/60`)
**spłaszcz je nad realnym tłem z tabeli wyżej**, nie nad `#000000`. Przyjęcie czerni daje
wynik zawyżony i fałszywie zielony.

Zmierzone 2026-08-29 na badge'u `code` w `x-ios.location-card`: licząc na `#000000` wychodzi
11,39:1, licząc na `rgb(19,22,26)` — **9,40:1**. Obie przechodzą AA, ale różnica rośnie
przy ciemniejszym tekście i potrafi przenieść wynik przez próg.

Metoda, która daje poprawny wynik: oklch → linear sRGB → spłaszczenie alfy → WCAG.
Nie wyprowadzaj sRGB tokena oklch z pamięci — policz.

### Zmienne i kolory, których NIE MA

`--color-dark-section`, `--color-dark-tile`, `.text-dark-primary`, `.text-dark-muted`
oraz literały `#00323B` / `#000000` jako wartości tła **nie występują w `resources/`**.
`#00323B` żyje wyłącznie w komentarzach (`prose-typography.css:12,77`,
`text-block.blade.php:24`) jako historyczna notatka o życzeniu klienta z 2025-12.

`text-dark-primary` i `text-dark-muted` są mimo to **użyte 8× w
`components/ios/footer.blade.php`** — to martwe klasy, kolor tekstu jest tam dziedziczony,
a nie ustawiany. Nie powielaj ich; przy okazji dotykania stopki zgłoś to (ClickUp
`123k99ct3zb`).

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

Zdefiniowane w `resources/css/app.css` — **wszystkie przez tokeny, żadna przez literał**:

```css
.bg-section-dark  { background-color: var(--color-dark-bg); color: var(--color-dark-text); }
.bg-section-dark .text-muted { color: var(--color-dark-text-muted); }
.service-card-dark { background-color: var(--color-dark-bg-raised); border: none; }
.btn-cta-dark      { background-color: var(--color-dark-accent); color: white; }
.shadow-dark-glow  { box-shadow: 0 0 20px oklch(65% 0.2 250 / 15%), 0 4px 20px oklch(0% 0 0 / 40%); }
.badge-duration-dark { background-color: oklch(100% 0 0 / 10%); }
```

`.service-card-dark` jest oznaczona w `app.css:148-150` jako **legacy**, do usunięcia po
przepisaniu komponentów w Fazie 3 — nie buduj na niej nowych rzeczy.

Warstwa tokenów jest po to, żeby tenant mógł nadpisać kolory. Wpisanie literału
zamiast `var(--color-dark-*)` cicho wyłącza tę możliwość.

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
3. **NIGDY nie używaj odcieni szarości** (#D1D5DB, #F3F4F6) zamiast `--color-dark-text` na ciemnym tle
4. **NIGDY nie używaj innego koloru linków** niż `--color-dark-accent` na ciemnym tle
5. **NIGDY nie wpisuj literału koloru** tam, gdzie istnieje token — literał wyłącza
   nadpisanie per tenant
6. **NIGDY nie deklaruj kontrastu bez policzenia go** — patrz pułapka wyżej

---

## Historia incydentów

### 2026-08-29: Reguła podawała kolory, których nie ma w kodzie

**Problem:** ten plik wymieniał `--color-dark-section`, `--color-dark-tile` oraz tła
`#00323B`/`#000000` jako obowiązujące. Żadna z tych wartości nie istniała w `resources/`
— realne tokeny to `oklch(...)`. Ponieważ reguła jest oznaczona jako BEZWZGLĘDNA i ładuje
się przy każdej edycji `resources/**`, była stosowana w dobrej wierze.

**Koszt:** kontrast badge'a policzony na czerni z tej tabeli → 11,39:1 zamiast 9,40:1.
Błąd prostowany dwa razy, zanim ktokolwiek policzył konwersję oklch w kodzie.

**Zapobieganie:** każda wartość w tym pliku musi dać się znaleźć `grep`em w `resources/`.
ClickUp `123k99ct3zb`.

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
