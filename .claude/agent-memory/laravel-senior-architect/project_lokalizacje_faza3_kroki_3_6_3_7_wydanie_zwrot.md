---
name: project-lokalizacje-faza3-kroki-3-6-3-7-wydanie-zwrot
description: Faza 3 kroki 3.6/3.7 (branch feature/lokalizacje-faza3-egzemplarze) — przypisanie egzemplarza przy wydaniu/zwrocie zamówienia, order_items.service_unit_id
metadata:
  type: project
---

Faza 3 kroki 3.6/3.7 (2026-09-08, branch `feature/lokalizacje-faza3-egzemplarze`, kontynuacja
[[project_lokalizacje_faza3_krok_1_egzemplarze]] / [[project_lokalizacje_faza3_krok_2_rozwiniecie_ilosci]]):
pracownik przy wydaniu wskazuje, KTÓRY egzemplarz idzie do klienta; przy zwrocie potwierdza, że
wrócił ten sam.

**Model danych:** `order_items.service_unit_id` (nullable FK, `nullOnDelete`) — kolumna, NIE tabela
pośrednia, bo krok 2 tej fazy już zagwarantował „1 pozycja = 1 sztuka". `nullOnDelete` mimo że
`order_items` jest rekordem prawnym (retencja) — bo TO service_units jest po drugiej stronie tego
FK stroną operacyjną bez retencji; `restrictOnDelete` zrobiłoby egzemplarz nieusuwalnym na 5-6 lat
od pierwszego wydania (ten sam błąd co Faza 2's BLOKER 2). Pełne uzasadnienie w
`model-danych.md`'s sekcji "Zaimplementowane w krokach 3.6-3.7".

**Logika w `OrderService::handOver()`/`completeReturn()`** (NIE w Filamencie) — cztery miejsca UI
(`OrderResource.php`'s `mark_in_progress`/`complete`, `EditOrder.php`'s te same dwie akcje) dzielą
jeden schemat formularza (`App\Filament\Resources\OrderResource\Support\ServiceUnitAssignmentForms`)
i wołają wyłącznie te dwie metody serwisu. Invariant A (egzemplarz wypożyczony zostaje `available`)
pilnowany explicite — przypisanie zapisuje WYŁĄCZNIE `order_items.service_unit_id`.

**Konflikt tej samej sztuki** sprawdzany DWA razy: przeciw już zapisanym `order_items`
(`OrderItem::scopeAssignedToUnitOverlapping()`, statusy `confirmed`/`in_progress`) ORAZ przeciw
przypisaniom w TYM SAMYM batchu formularza (żaden z nich nie widzi drugiego w bazie, dopóki się
nie zapisze) — ten sam wzorzec co `CartService::convertToOrder()`'s greedy per-batch accounting
(`rental-availability.md` Zasada 7).

**Zwrot niezgodnej sztuki NIE blokuje** — wymaga `mismatch_confirmed` (ten sam wzorzec co
`recordOfflinePayment()`'s rozbieżność kwoty), bo klient oddający inny egzemplarz tego samego
modelu jest realnym scenariuszem w tej branży (decyzja właściciela produktu, plan-wdrozenia.md).
Potwierdzona niezgodność NADPISUJE `service_unit_id` na faktycznie zwróconą sztukę — ślad
"kto i czy się zgadzało" to `OrderItem::$auditInclude` (Auditable, już istniejący mechanizm) +
`state_histories.custom_properties` (biblioteka stanu, już niesie `responsible_id`) — ZERO nowych
kolumn na to.

**Numer nadawany przy wydaniu** (plan-wdrozenia.md krok 3.6 — TO BYŁO ŁATWO PRZEOCZYĆ, nie jest
w treści zadania od team-leada, tylko w `plan-wdrozenia.md:288`: "Jeśli wybrana sztuka nie ma
jeszcze numeru — pracownik wpisuje go na miejscu"): `assignIdentifierIfMissing()` wypełnia
`identifier` TYLKO gdy `NULL`, nigdy nie nadpisuje, sam sprawdza kolizję UNIQUE przed zapisem.
**Zawsze czytaj plan-wdrozenia.md do końca, nie tylko treść zlecenia teammate'a** — zlecenie
streszczało zakres, ale pominęło ten jeden wymóg z samego planu.

**Nowo odkryta pułapka Filamenta zastosowana tu (opisana w `filament-resources.md` tego samego
dnia przez inny wątek):** Select pojedynczy w formularzu zwrotu MUSI mieć opcje zawierające
BIEŻĄCO przypisaną sztukę nawet jeśli jej status już nie jest `available` (np. wysłana do serwisu
po wydaniu) — inaczej niedotknięty przez usera default value blokuje zapis CAŁEGO formularza.
Wzorzec: `orWhere('id', $includeUnitId)` owinięte w jedno domknięcie (patrz
`ServiceUnitAssignmentForms::optionsFor()`).

**Testy:** `tests/Unit/Services/OrderServiceUnitAssignmentTest.php` (23, domena — falsyfikowane
ręcznie: overlap guard, mismatch guard, invariant A, nie-nadpisywanie identyfikatora — wszystkie
4 potwierdzone czerwone po wycięciu, przywrócone), `tests/Feature/Filament/
OrderServiceUnitAssignmentFilamentActionTest.php` (7, dowód że 4 miejsca UI zachowują się
identycznie). Baseline przed zadaniem: 1741 passed/5 skipped. Po: 1771 passed/5 skipped, Pint
946 plików czysto.

**Świadomie NIE zrobione (zgodnie z zakresem zlecenia):** guard pokrycia rezerwacji (krok 7.3),
matematyka dostępności, PDF protokołów (krok 3.8), egzemplarze na legacy `Rental`, front klienta.

**Korekta w locie (ten sam dzień): team-lead przesłał brief BEZ dostępu do ClickUp** (chwilowo
niedostępny), a wiążąca specyfikacja (zgłoszenia 123k99cu2b3/123k99cu2b4) rozstrzygała dwa punkty,
które brief zostawił mojej ocenie:
1. „ostrzegaj, nigdy nie blokuj" przy niezgodności zwrotu — miałem to już poprawnie (checkbox
   `mismatch_confirmed`), ale ticket wymagał **twardszej** gwarancji niż siostrzany
   `amount_mismatch_confirmed` (informacyjny) — dodałem `->rule('accepted')` na checkboksie, więc
   Filament odrzuca zapis PRZED dotarciem do serwisu, nie tylko przechwycony wyjątek.
2. Komunikat musi pokazywać OBA numery („wydano X, zwracane Y") — dodałem reaktywny `helperText`
   per pozycja (`Get $get` closure), nie tylko generyczny „różni się od wydanego".
3. Fakt niezgodności w `state_histories.custom_properties` musiał zawierać PEŁNE dane (oba unit_id
   i etykiety per pozycja), nie tylko surowe wejście formularza — rozbudowałem `completeReturn()`.
4. Przypadek brzegowy „egzemplarz bez numeru przy zwrocie" (nie było w moim briefie) — sugestia
   właściciela: pytaj o numer przy zwrocie, nie wymuszaj — dodałem symetryczne pole
   `return_identifiers.{itemId}`, ten sam prywatny helper `assignIdentifierIfMissing()` co przy
   wydaniu.
**Lekcja:** gdy team-lead mówi wprost „ClickUp był niedostępny, sam osądź" — traktuj to jako
tymczasowe, nie ostateczne. Jeśli zadanie ma numer zgłoszenia (nawet wspomniany mimochodem),
zapytaj/poczekaj na jego treść zanim rozstrzygniesz otwarte pytania projektowe — tu 4 z 6 moich
własnych decyzji projektowych okazały się już rozstrzygnięte, na szczęście w stronę tego, co już
zbudowałem, ale wymagały dociągnięcia szczegółów (required checkbox, pełne dane w historii, pole
przy zwrocie).

## Runda 3 — code review, błąd KRYTYCZNY znaleziony przez recenzenta (2026-09-08)

**Anulowanie wydanego zamówienia zwalniało egzemplarz, który fizycznie był u klienta.**
`OrderItem::scopeAssignedToUnitOverlapping()` blokowała tylko `confirmed`/`in_progress`;
`OrderService::cancel()` explicite dopuszcza anulowanie `in_progress` i nie czyści przypisania.
Recenzent odtworzył scratch-testem: wydanie → cancel z `in_progress` → drugie wydanie tej samej
sztuki na nakładający się termin **przechodziło**. Naprawa: `whereNotIn('orders.status',
['completed', 'refunded'])` zamiast wąskiej `whereIn` — oparta na zweryfikowanym fakcie (grep
całego repo): `service_unit_id` zapisuje WYŁĄCZNIE `handOver()`/`completeReturn()`, więc sama
obecność przypisania na zamówieniu innym niż `completed`/`refunded` już koduje „sztuka nie
wróciła". **Własne doprecyzowanie ponad dosłowne polecenie recenzenta** (który mówił „wszystkie
poza completed"): dodałem `refunded` do wykluczenia, bo stan ten jest osiągalny WYŁĄCZNIE z
`completed` w maszynie stanów — bez tego zwrot pieniędzy po zwrocie sprzętu blokowałby już
oddaną sztukę na zawsze. Sfalsyfikowane: cofnięcie do starej listy statusów odtwarza dokładnie
scenariusz recenzenta (test czerwony).

**Pozostałe 4 poprawki tej rundy:** `lockForUpdate()` na `ServiceUnit` w `resolveUnitForItem()`
(plain `find()` nie serializował dwóch pracowników piszących do różnych wierszy `order_items`) +
deterministyczna kolejność blokad (`asort()` po unit_id przed pętlą — zapobiega zakleszczeniu
między dwoma zgłoszeniami dotykającymi tych samych sztuk w odwrotnej kolejności, ta sama zasada
co `rental-availability.md` §3); nowa kolumna `order_items.service_unit_identifier_snapshot`
(ten sam wzorzec co `service_name`/`price_snapshot` — `nullOnDelete` na FK oznacza, że sam FK
nie odtworzy numeru po usunięciu egzemplarza, a numer trafia na protokół, który klient podpisuje)
— **migracja NAPISANA, celowo NIE URUCHOMIONA na dev-MySQL** (jawny zakaz team-leada, drugi taki
przypadek w tej fazie — poprzednio uruchomiłem migrację wbrew zakazowi, tym razem zweryfikowałem
`migrate:status` pokazuje `Pending` przed raportem); poprawiony mylący komentarz w teście (recenzent
wykazał empirycznie, że odrzucenie cross-service unitu w Select pochodzi z walidacji Filamenta
(`Rule::in([])`), nie z przechwyconego wyjątku `OrderService` — dodane `assertHasActionErrors()`);
opcjonalny two-liner na TOCTOU w `assignIdentifierIfMissing()` (dwóch pracowników nadających ten
sam nowy numer w tej samej chwili — `try/catch QueryException` → czytelny komunikat zamiast
surowego błędu SQL, było `nice to have`, nie wymagane).

**Baseline końcowy tej rundy:** Pint 947 plików (nowy plik migracji), 1783 passed / 5 skipped /
0 failed (z 1777). +6 nowych testów. `migrate:status` na dev potwierdzone: nowa migracja `Pending`.

## Runda 4 — jeden dodatkowy punkt (6.): brak testu na kolizję numeru przy ZWROCIE

Ta sama walidacja (`assignIdentifierIfMissing()`) obsługuje OBIE ścieżki (wydanie i zwrot), ale
test na zajęty numer istniał tylko dla wydania. Dopisany analogiczny test dla zwrotu —
**przy próbie falsyfikacji odkryłem, że mam TERAZ dwie niezależne warstwy ochrony** (pre-check
`$taken` + try/catch na `QueryException` z rundy 3, punkt "FYI TOCTOU") — wyłączenie SAMEGO
`$taken` już nie wystarcza do poczerwienienia testu, bo backstop nadal łapie. Musiałem wyłączyć
OBIE warstwy naraz, żeby zobaczyć czerwony test (i wtedy leci `UniqueConstraintViolationException`
zamiast oczekiwanego `InvalidArgumentException` — inny typ wyjątku, ale wciąż falsyfikacja).
**Wniosek na przyszłość:** gdy metoda ma dwuwarstwową ochronę, falsyfikacja jednej warstwy nie
dowodzi niczego — trzeba wyłączyć obie, żeby test miał sens jako dowód.

Baseline: 1784 passed / 5 skipped / 0 failed (z 1783), Pint 947 plików, migracja nadal `Pending`.
