# Query Optimization & ServiceQueryParams

## Problem

Dwa krytyczne problemy wydajnościowe zidentyfikowane podczas analizy architektury:

1. `RentalAvailabilityService::getMonthlyAvailability()` — 60 queries per widok kalendarza
2. `ServiceController::index()` — brak paginacji, `->get()` na nieograniczonej kolekcji

Brak mechanizmu parametryzowania zapytań o usługi (odpowiednik WP_Query z WordPress).

## Rozwiązanie

### 1. Bulk calendar availability (60 → 2 queries)

**Plik:** `app/Services/RentalAvailabilityService.php`

**Stare podejście:** Pętla `for $day = 1..30` → `getAvailableQuantity()` per dzień → 2 queries/dzień = 60 queries/miesiąc.

**Nowe podejście:** 2 bulk queries na cały miesiąc (rentals + order_items), następnie agregacja per-dzień w PHP:

```php
$monthRentals = Rental::where('service_id', $service->id)
    ->whereIn('status', $blockedStatuses)
    ->where('start_date', '<=', $monthEnd->toDateString())
    ->where('end_date', '>=', $monthStart->toDateString())
    ->select('start_date', 'end_date', 'quantity')
    ->get();

// Per-day z kolekcji (PHP, no DB):
$reservedViaRentals = (int) $monthRentals
    ->filter(fn ($r) => $r->start_date->lte($date) && $r->end_date->gte($date))
    ->sum('quantity');
```

**Uwaga:** `start_date`/`end_date` są rzutowane jako `'date'` (Carbon) w obu modelach — `->lte()` i `->gte()` działają poprawnie. NIE używaj `Collection->where('date', string)` — patrz `rules/services.md` bug Carbon 2026-05-10.

**Fix 2026-07-07:** bulk `$monthOrderItems` query pierwotnie hand-rollowała własną logikę "które
zamówienia blokują dostępność" przez `whereHas('order', ...)`, sprawdzając tylko
`status IN ('paid','confirmed','in_progress')` OR `(pending_payment AND expires_at > now())`. To
pomijało P24 grace-period (`Order::ttlGraceMinutes()`) dla zamówień z ustawionym `p24_token` —
kalendarz mógł pokazać "dostępne" dla dnia wciąż blokowanego przez zamówienie z płatnością w locie.
Naprawione przez podmianę na kanoniczny `OrderItem::scopeBlockingAvailability()` (ten sam scope,
którego już używa `getAvailableQuantity()` powyżej) — `select()` qualifikowany jako
`order_items.start_date/end_date/quantity`, bo scope robi realny `JOIN` na `orders`.

### 2. ServiceQueryParams — WP_Query equivalent

**Nowy plik:** `app/Support/Services/ServiceQueryParams.php`

```php
Service::filterBy(new ServiceQueryParams(
    type: 'item_rental',
    category: 'elektronarzedzia',
    orderBy: 'price_asc',
    limit: 6,
))->get();
```

**Scope:** `Service::scopeFilterBy(Builder $query, ServiceQueryParams $params)` — kompiluje query z params, chainable.

**Parametry:**
- `type` — `'item_rental'` | `'time_slot'` | null
- `category` — slug kategorii (przez `whereHas('category', ...)`)
- `featured` — `true` = `is_popular = true`
- `exclude` — array ID do wykluczenia
- `orderBy` — `'sort_order'` | `'price_asc'` | `'price_desc'` | `'newest'`
- `limit` — `0` = bez limitu

**Uwaga:** `withPagination`/`perPage` zostały usunięte (2026-07-07) — były martwym kodem, nigdy nie
skonsumowanym przez `scopeFilterBy()`. Paginacja `ServiceController::index()` (patrz niżej) jest
osobnym `->paginate(24)` na oddzielnym query, w ogóle nie przechodzącym przez `filterBy()`/DTO. Jeśli
w przyszłości `filterBy()` zyska realnego callera potrzebującego paginacji, dodaj ją z powrotem wtedy
— razem z tym callerem, nie jako "może się przyda".

### 3. Paginacja ServiceController

`->get()` → `->paginate(24)`. Widok: `$services->links()` gdy `$services->hasPages()`.

## Użycie w CMS content-blocks

`content-grid.blade.php` może teraz korzystać z `filterBy()`:

```php
$services = Service::filterBy(new ServiceQueryParams(
    type: $data['content_type'] ?? null,
    category: $data['category_filter'] ?? null,
    limit: $data['items_count'] ?? 6,
    orderBy: $data['order_by'] ?? 'sort_order',
))->get();
```

(Nie zaimplementowane w content-grid domyślnie — każda integracja to osobna zmiana.)
