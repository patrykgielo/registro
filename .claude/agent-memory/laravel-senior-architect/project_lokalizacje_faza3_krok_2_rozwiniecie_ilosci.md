---
name: project-lokalizacje-faza3-krok-2-rozwiniecie-ilosci
description: Wielooddziałowość Faza 3 krok 2 — CartService::convertToOrder() rozwija cart-quantity N na N OrderItem po 1 szt.; item_count w analytics_events zmieniony z row-count na sum(quantity)
metadata:
  type: project
---

Branch `feature/lokalizacje-faza3-egzemplarze` (2026-09-08), kontynuacja
[[project_lokalizacje_faza3_krok_1_egzemplarze]]. Implementacja decyzji już spisanej w
`app/docs/features/lokalizacje/plan-wdrozenia.md` → "Ilość > 1 — rozstrzygnięcie" (właściciel
produktu, ta sama data) — nie nowa decyzja, tylko jej wykonanie.

**Zmiana:** `CartService::convertToOrder()` (`app/Services/Cart/CartService.php:347-374`) — pętla
tworząca `OrderItem` rozwija każdą pozycję koszyka o ilości N na N wierszy `OrderItem` po 1 szt.
Pętla walidacji dostępności (`:213-260`, Fazie 0, `.claude/rules/rental-availability.md`) **zweryfikowana
osobiście, nietknięta** — kończy się przed blokiem tworzącym, więc rozwinięcie nie ma wpływu na
matematykę oversellu (nadal odejmuje N sztuk, tylko zapisanych w N wierszach zamiast jednym).

**Podział `total_price` jest zawsze dokładny co do grosza, nie problem "reszty do rozdzielenia":**
`total_price` = `calculatePricing()`'s już-zaokrąglona-do-grosza stawka × quantity — to jest
MNOŻENIE, nie niezależnie zaokrąglona suma dzielona wstecz. Dzielenie przez to samo `quantity`
zawsze odtwarza stawkę bez reszty (dowód w komentarzu przy kodzie). Zweryfikowane testem
(`test_convert_to_order_expansion_does_not_change_the_order_total`) — suma `order_items.total_price`
po rozwinięciu = `order.subtotal` = to, co dałby pojedynczy wiersz sprzed zmiany. `order.subtotal`
sam w sobie liczony jest z kolekcji CART items PRZED pętlą tworzącą (`:264`), więc jest całkowicie
niewrażliwy na rozwinięcie — to nie jest coś, co ta zmiana mogła zepsuć, tylko coś do zweryfikowania.

**Skutek uboczny, znaleziony, NIE naprawiany osobno:** `OrderItem.deposit_amount` był pisany jako
`service->deposit_amount` bez `* quantity` (rozjazd z `models.md`'s dokumentacją, która mówi że
POWINNO być pomnożone) — utajony bug widoczny tylko przy quantity>1, pole nigdzie nieodczytywane
poza zapisem (zgrepowane). Po rozwinięciu każdy wiersz ma faktycznie quantity=1, więc kod
przypadkiem staje się zgodny z dokumentacją — nic do zrobienia, tylko odnotowane.

**`price_snapshot`'s `total` klucz jest przeliczany per split-wiersz** (nie kopiowany 1:1 z
N-quantity snapshotu) — inaczej wiersz z `quantity=1` niósłby snapshot opisujący N sztuk, co jest
mylące dla ewentualnego przyszłego czytelnika (dziś: nikt nie czyta tego pola poza zapisem,
zgrepowane).

**`item_count` w `analytics_events` zmieniony z row-count na `sum(quantity)`** w 3 miejscach:
`CheckoutController::show()` (`checkout.started`), `RecordAnalyticsOnOrderPaid` (`order.completed`),
`MarkCartsAbandonedJob` (`cart.abandoned`). Powód: `app/docs/analytics/technical-reference.md`
dokumentuje `item_count` jako TO SAMO pole przez cały lejek — po rozwinięciu `->count()` na cart
items vs order items przestałoby się zgadzać nawet bez żadnej realnej zmiany w koszyku (1 cart-item
qty=3 → 3 order-items). Pole jest dziś **write-only** (zgrepowane — żaden widget/dashboard go nie
czyta, Faza 4 analytics-expansion TODO dopiero ma je skonsumować), więc zmiana semantyki jest
bezpieczna teraz i błędna gdyby poczekać aż ktoś zacznie z niego czytać. Udokumentowane w obu
plikach docs (`analytics-event-tracking.md`, `technical-reference.md`).

**Zweryfikowane, że NIE trzeba dotykać:** PDF-y protokołów (`OrderProtocolPdfService`,
`orders/protocols/{handover,return}.blade.php`) i maile (`BuildsOrderRentalEmailVariables`) już
iterują `$order->items` bez żadnego założenia "1 pozycja = 1 usługa" — po rozwinięciu po prostu
pokażą N wierszy zamiast jednego z "× N", co plan-wdrozenia.md nazywa wprost zyskiem (protokół
podpisywany przez klienta wymienia każdą sztukę osobno). `RentalExtensionService`/
`RentalExtensionController` też nietknięte — działają per-`OrderItem`, każde `requestExtension()`/
`approve()` osobno lockuje `Service` i re-checkuje dostępność pod `forUpdate: true`, więc N osobnych
wniosków przedłużenia na tym samym sprzęcie w tym samym oknie poprawnie serializują się przez
istniejący mechanizm (`.claude/rules/rental-availability.md` "dziewiąte wywołanie") — nie trzeba
żadnej nowej agregacji popytu rodzeństwa jak w `CartService` (tam sibling demand trzeba było
agregować bo wiele `CartItem` żyje w JEDNEJ nierozstrzygniętej transakcji; tu każde `approve()` to
osobny, zatwierdzony commit, więc kolejny wniosek widzi poprzedni jako już-zapisany `order_items`
wiersz blokujący). Brak `UNIQUE(order_id, service_id)` w migracji `order_items` — sprawdzone, nie
istnieje, więc N wierszy tej samej usługi w jednym zamówieniu jest strukturalnie dozwolone.

**Testy dodane:** `tests/Unit/Services/CartServiceTest.php` — 5 nowych (rozwinięcie N→N, quantity=1
regresja, kwota niezmieniona + suma wierszy = subtotal, dostępność spada dokładnie o N, dwie usługi
niezależnie rozwinięte) + naprawiony 1 pre-existing (`test_convert_to_order_creates_order_and_order_items`
zakładał 1 wiersz z `quantity=2`, teraz 2 wiersze z `quantity=1`). `tests/Feature/Analytics/
FunnelTrackingTest.php` — 2 nowe, obie celowo konstruują rozjazd row-count vs sum(quantity) (3 wiersze
o różnych quantity, suma≠count), żeby dowód był falsyfikowalny, nie przypadkowo zgodny.

Baseline przed zmianą: 1720 passed / 5 skipped, pint 940 plików czysto. Po zmianie: **1727 passed /
5 skipped, 0 failed**, pint clean — dokładnie +7 (5+2 nowych testów), zero regresji.

**Pytania otwarte / nieustalone (do przekazania właścicielowi produktu albo kolejnego kroku, NIE
zgadywane):** żadne nie znalezione w trakcie tego kroku — zakres był węższy i lepiej ograniczony
FK/unique-wise niż typowe zadanie tej wielkości.
