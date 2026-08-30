---
paths:
  - "resources/views/**"
  - "resources/css/**"
  - "resources/js/**"
---

# Frontend Code Quality Rules

## CRITICAL: Rebuild After EVERY Frontend Change

```bash
docker compose exec -T app npm run build
```

- ZAWSZE po modyfikacji Blade/CSS/JS — bez wyjątków
- `npm run dev` = tylko aktywne pisanie, NIGDY jako "gotowe"
- Jeśli stare style → `rm public/hot` (blokuje @vite directive)

**Incidents:** 2026-03-26: commit bez build → stare style. 2026-03-26b: `public/hot` → brak stylów na subdomenie.

---

## CRITICAL: Animation — GPU Only

→ Patrz `animations.md`. Krótko: **tylko `transform` i `opacity`**. NIGDY `width/height/left/margin`.

---

## CRITICAL: Tailwind max-width vs breakpoints

`max-w-*` ≠ screen breakpoints!

| Class | Actual | Screen bp |
|-------|--------|-----------|
| `max-w-lg` | 512px | `lg:` = 1024px |
| `max-w-xl` | 576px | `xl:` = 1280px |
| `max-w-2xl` | 672px | `2xl:` = 1536px |

Use `max-w-screen-xl` (1280px) or `max-w-[1920px]` for screen-width matching.

**Incident 2026-01-21:** `max-w-xl` renderowało 576px zamiast 1280px.

---

## CRITICAL: Dynamic Tailwind Classes → Safelist

Klasy generowane dynamicznie w PHP `match()` / concatenation nie są wykrywane przez Tailwind JIT.

```js
// tailwind.config.js
safelist: ['p-8', 'p-10', 'md:p-12', 'rounded-xl', ...]
```

**Incident 2026-01-21:** `p-10 md:p-16` nie działało bo dynamiczne.

---

## CRITICAL: Tailwind w inline style="" → CSS values

```php
// ❌ WRONG
style="background: linear-gradient(to-r, ...)"  // "to-r" nie jest CSS!

// ✅ RIGHT  
$css = match($dir) { 'to-r' => 'to right', ... };
style="background: linear-gradient({$css}, ...)"
```

---

## Accessibility (WCAG 2.2 AA) — Mandatory

- Semantic HTML (`<button>` not `<div onclick>`)
- ARIA labels na każdym interactive elemencie
- Keyboard: Tab, Enter/Space, Escape
- Touch targets ≥ 44×44px
- Focus: `:focus-visible` z visible outline
- `aria-busy="true"` + `aria-live="polite"` na loading states
- Color contrast ≥ 4.5:1 — **policzony, nie oceniony wzrokiem**

  Warstwy półprzezroczyste (`text-white/80`, `bg-white/10`, `bg-black/60`) trzeba najpierw
  **spłaszczyć nad realnym tłem** odczytanym z `resources/css/design-tokens.css`, nie nad
  zgadniętym `#000000`/`#ffffff`. Kolor z klasą alfa wygląda w kodzie jak biel i po
  spłaszczeniu nad ciemnym tłem nią nie jest.

  Tła ciemnych sekcji są w `oklch()` — przelicz oklch → linear sRGB → spłaszczenie alfy →
  WCAG. Nie wyprowadzaj sRGB z pamięci: 2026-08-29 ten sam badge dostał trzy różne wyniki
  (11,39 / 8,92 / **9,40**), zanim ktoś policzył to kodem. Wartości tokenów i pułapka:
  `dark-theme.md`.

  Wynik zapisz w komentarzu obok klas — liczba bez metody starzeje się po cichu.
- `prefers-reduced-motion` (patrz animations.md)

```html
<button class="min-h-11 min-w-11 focus-visible:outline-2 focus-visible:outline-offset-2
               focus-visible:outline-brand transition-transform active:scale-95"
        aria-label="Close dialog">
```

---

## Alpine.js Form Validation Pattern

Form MUSI mieć `novalidate` — inaczej HTML5 validation wyskakuje przed Alpine.

```html
<form novalidate x-data="myForm()">
```

Validation: `validateField(name)` na `@blur`, `triggerFullValidation()` przed submit. Errors w obiekcie `errors: {}`. Input klasy: `border-green-400` (valid) / `border-red-400` (error).

Patrz `resources/views/booking-wizard/` dla pełnego przykładu.

---

---

## CRITICAL: Usuwasz właściwość komponentu Alpine → przegrepuj SZABLONY

Kasując getter/pole z `x-data`, wyszukaj jego nazwę w całym `resources/views/`. Blade nic nie zgłosi,
a `:href="cosCzegoNieMa"` rzuca `ReferenceError` przy **inicjalizacji** Alpine, nie przy kliknięciu.

**Incident 2026-08-08:** przy migracji ze starego wizarda na Cart/Checkout usunięto getter
`bookingUrl()` (wskazywał na skasowany route), ale zostało jedno z dwóch użyć w
`services/show.blade.php`. Efekt: **każda** strona sprzętu `item_rental` rzucała
`Uncaught ReferenceError` i pokazywała drugi, martwy przycisk „Zarezerwuj online" pod działającym
„Dodaj do koszyka". Przeżyło to wszystkie 1054 testy, bo **testy Feature nie wykonują JS** —
sprawdzają, co serwer wysłał, nie co przeglądarka z tym zrobiła. Znalazł to dopiero pierwszy test
przeglądarkowy sklepu.

**Zapobieganie:** `assertNoJavaScriptErrors()` w teście przeglądarkowym każdej ważnej strony
publicznej. Kosztuje jedną linię i łapie całą tę klasę błędów.

---

## CRITICAL: Logo/Brand Fallback → text, NIGDY bundled asset

`SettingsManager::headerLogo()`/`footerLogo()` zwracają `?string` — `null` gdy tenant nie ma
uploadowanego logo. Każdy caller MUSI `@if($logo) <img> @else <span>{{ text brand }}</span> @endif`.
**NIGDY** `?? asset('images/cokolwiek.svg')` jako fallback — to dokładnie ten bug.

**Incident 2026-08-13:** `public/images/logo.svg` to marka poprzedniego właściciela kodu (Paradocks,
migracja 2026-03-02). Każdy tenant bez własnego logo pokazywał cudzą markę na każdej stronie
publicznej — istniejące fallbacki tekstowe w szablonach były martwym kodem, bo `headerLogo()` nigdy
nie zwracało pustej wartości. Pełny opis + lista call-site'ów: `app/docs/features/tenant-branding.md`.
Nie dodawaj nowego bundled placeholder logo "na wszelki wypadek" — go nie ma, i nie powinno być.

Ta sama zasada dotyczy tekstu, nie tylko obrazków: `SettingSeeder.php` seedował globalnie
(`organization_id IS NULL`, więc u KAŻDEGO tenanta bez własnej wartości) fałszywy kontakt
(`contact@example.com`, `ul. Marszałkowska 1`) i copy opisujące mobilną myjnię/detailing — projekt
sprzedaje wyłącznie wynajem sprzętu. Domyślna wartość ustawienia widocznego dla klienta jest albo
branżowo-neutralna, albo jej po prostu nie ma — nigdy nie wymyślaj nowego copy "na zastępstwo".

---

## Verification Checklist (przed commit)

- [ ] `npm run build` wykonany
- [ ] Animacje używają TYLKO transform/opacity
- [ ] Touch targets ≥ 44px
- [ ] ARIA labels na interactive elementach
- [ ] Keyboard navigation działa
- [ ] `:focus-visible` style obecne
- [ ] `prefers-reduced-motion` obsługiwane
- [ ] Dynamiczne klasy Tailwind w safelist
