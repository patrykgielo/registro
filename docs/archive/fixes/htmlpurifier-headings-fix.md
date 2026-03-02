# HTMLPurifier - Naprawa Nagłówków h2/h3

**Data:** 2026-01-25
**Problem:** Nagłówki h2/h3 w RichEditor były konwertowane na `<p>` na froncie
**Status:** ✅ Naprawiony

---

## Problem

W sekcji "Główna treść" (pole `body`) w ServiceResource:
- Użytkownik zaznaczał tekst i wybierał h2/h3 w edytorze RichEditor
- Na froncie (services/show.blade.php) tekst renderował się jako `<p>` zamiast `<h2>` lub `<h3>`
- Problem dotyczył również `<blockquote>` (dostępny w toolbar, ale filtrowany)

## Przyczyna

**HTMLPurifier** (`config/purifier.php` linia 29) miał zbyt restrykcyjną konfigurację `HTML.Allowed`:

```php
'HTML.Allowed' => 'div,b,strong,i,em,u,a[href|title],ul,ol,li,p[style],br,span[style],img[width|height|alt|src]',
```

**Brakujące tagi:**
- `h2`, `h3`, `h4`, `h5`, `h6` - dostępne w toolbar RichEditor
- `blockquote` - dostępny w toolbar RichEditor

## Rozwiązanie

Zaktualizowano `config/purifier.php`:

```php
'HTML.Allowed' => 'div,b,strong,i,em,u,a[href|title],ul,ol,li,p[style],br,span[style],img[width|height|alt|src],h2,h3,h4,h5,h6,blockquote',
```

**Dodane tagi:**
- `h2`, `h3`, `h4`, `h5`, `h6` - pełny zakres nagłówków
- `blockquote` - cytaty

## Wpływ

**Dotknięte Resources:**
- ServiceResource (body field)
- PageResource (używa RichEditor w Builder blocks)
- PostResource (używa RichEditor w Builder blocks)
- PromotionResource (używa RichEditor w Builder blocks)
- PortfolioItemResource (używa RichEditor w Builder blocks)

**Dotknięte widoki:**
- `resources/views/services/show.blade.php` (linie 45, 116-117, 122-124)
- Wszystkie bloki Builder używające `clean()` w text_block, two_columns, three_columns

## Weryfikacja

Po wdrożeniu sprawdź:

1. **W Filament Admin:**
   - Edytuj usługę
   - Zaznacz tekst w polu "Opis usługi" (body)
   - Wybierz h2 lub h3 z toolbar
   - Zapisz

2. **Na froncie:**
   - Przejdź do `/uslugi/slug-uslugi`
   - Sprawdź czy nagłówki renderują się poprawnie jako `<h2>` i `<h3>` (nie `<p>`)
   - Sprawdź DevTools (Inspect Element) - powinny być tagi `<h2>`, `<h3>`

3. **Blockquote:**
   - W edytorze dodaj blockquote
   - Na froncie powinien renderować się jako `<blockquote>` (z odpowiednim stylowaniem Tailwind prose)

## Cache

Po wdrożeniu wykonaj:
```bash
php artisan config:clear
php artisan cache:clear
```

(W środowisku Docker: dodaj prefix `docker compose exec -T app`)

## Bezpieczeństwo

HTMLPurifier nadal zapewnia ochronę XSS:
- Dozwolone tylko semantyczne tagi HTML
- Brak obsługi `<script>`, `<style>`, `<iframe>` (poza youtube w osobnym profilu)
- Ograniczone atrybuty CSS w `CSS.AllowedProperties`

## Related

- Issue: [link do GitHub Issue jeśli utworzony]
- Filament RichEditor docs: https://filamentphp.com/docs/3.x/forms/fields/rich-editor
- HTMLPurifier docs: http://htmlpurifier.org/live/configdoc/plain.html
