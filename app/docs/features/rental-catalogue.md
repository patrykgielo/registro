# Publiczny Katalog Wypożyczalni (Phase 7)

## Cel

Umożliwia klientom przeglądanie oferty wypożyczalni per tenant przez kategorię,
bez potrzeby znajomości bezpośredniego URL usługi.

## Trasy

| Route name | URL | Opis |
|------------|-----|------|
| `rental.index` | `/wypozyczalnia` | Lista kategorii + wyróżnione usługi |
| `rental.category` | `/wypozyczalnia/{category:slug}` | Usługi w danej kategorii |

Kategorie są zarządzane z panelu admina (`/admin` → RentalCategory). Binding po `slug`.

## Kontroler

`app/Http/Controllers/RentalController.php` — cienki kontroler, bez logiki biznesowej.

- `index()`: pobiera `RentalCategory::active()->ordered()->withCount(services)` + 6 wyróżnionych usług
- `showCategory(RentalCategory)`: `abort_unless(is_active)` + usługi z kategorii + wszystkie kategorie do sidebara

## Widoki

| Plik | Opis |
|------|------|
| `resources/views/rentals/index.blade.php` | Grid kategorii + sekcja "Najnowsze" |
| `resources/views/rentals/category.blade.php` | 2-kolumnowy: sticky sidebar kategorii + grid usług |

Reużywa: `x-ios.service-card`, `x-ui.card`, `x-layout.section`, `x-layout.grid`.

## Co NIE zostało zmienione

- Strona produktu `/uslugi/{slug}` (kalendarze, koszyk, pricing) — bez zmian
- API dostępności `/api/rental/*` — bez zmian
- Cart/Checkout/Przelewy24 — bez zmian
- `/uslugi` index — nadal miesza time_slot i item_rental (celowo)

## Deprecacja

Stare trasy `wypozyczalnia/{service}/*` (Sprint 4, zwracały 410) zostały usunięte
z `routes/web.php`. `RentalBookingController` legacy metody (createHold, confirmHold)
pozostają w kodzie jako fallback — będą usunięte w oddzielnej inicjatywie cleanup.

## Nawigacja

Link "Wypożyczalnia" w menu jest zarządzany przez CMS (tabela `pages`, pole
`show_in_menu`). Aby dodać link, stwórz stronę w panelu admina z URL `/wypozyczalnia`
i włącz `Pokaż w menu`.
