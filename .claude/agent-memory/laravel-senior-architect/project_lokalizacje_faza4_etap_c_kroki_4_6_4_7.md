---
name: project-lokalizacje-faza4-etap-c-kroki-4-6-4-7
description: Faza 4 etap C (kroki 4.6/4.7, 2026-09-09) — kalendarz i zbiorcza dostępność per lokalizacja, Faza 4 zamknięta w całości
metadata:
  type: project
---

Branch `feature/lokalizacje-faza4-kalendarz` (2026-09-09), ostatni etap Fazy 4 z
[[project_lokalizacje_faza4_etap_b_kroki_4_4_4_5]]. Dwa kroki, oba w `RentalAvailabilityService.php`:

**4.6** — `getMonthlyAvailability(..., ?int $locationId = null)`, sygnatura mirroruje
`getAvailableQuantity()`'s null-branch/lock-hierarchię (nigdy nie blokuje, `locationCapacity()`
wołana z `forUpdate: false`).

**4.7** — `availabilityForServices(Collection $services, Carbon $start, Carbon $end): array` —
**3 zapytania zbiorcze zawsze**, niezależnie od liczby usług (zmierzone: 3 dla 3, 3 dla 50).
Zwraca `[serviceId => ['total' => int, 'locations' => [locationId => int]]]`. Nie wpięte do
żadnego widoku (`RentalController::showCategory()` nietknięty — Faza 5).

**Pytanie od team leada, nie do zgadnięcia:** skąd `RentalBookingController` (publiczne,
nieuwierzytelnione API) ma wziąć `$locationId`, skoro `LocationContext` to dopiero Faza 5.1?
Rozstrzygnięcie: **opcjonalny query param `location_id`**, fail-closed przez
`Rule::exists('locations','id')->where('organization_id', $service->organization_id)` — NIE
przez ponowne rozwiązywanie tenanta z requestu, bo `{service:slug}` jest już związane fail-closed
przez `Service::BelongsToOrganization` (VULN-003 Layer 2) zanim ten kod się wykona. Brak
parametru = `null` = dzisiejsze zachowanie, zero regresji. Oba endpointy (`:31` `checkAvailability`,
`:48` `monthlyAvailability`) przepięte **w jednym kroku** — kryterium z planu: gdyby tylko jeden
z nich akceptował lokalizację, dwa zapytania o ten sam dzień/oddział mogłyby się nie zgodzić
("kalendarz kłamie"). Dowód zgodności:
`RentalBookingControllerTest::test_point_check_and_calendar_agree_for_the_same_location_and_day`.

**Mechanizm 4.7, warty zapamiętania przy podobnym zadaniu:** reguła „`location_id = NULL` blokuje
KAŻDY oddział" nie da się wyrazić jako pojedynczy `GROUP BY service_id, location_id` — grupa NULL
jest rozłączna z każdą realną lokalizacją. Rozwiązanie: dwa zapytania zagregowane po
`(service_id, location_id)` (Rental, OrderItem), scalone w PHP — grupa `NULL` per usługa liczona
osobno i DOKŁADANA do każdej realnej lokalizacji tej usługi przed odjęciem od pojemności kotwicy.
`total` (branch bez lokalizacji) to suma WSZYSTKICH kubełków usługi włącznie z `NULL` — algebraicznie
identyczne z zapytaniem bez filtra lokalizacji, więc nie trzeba 3. wariantu zapytania po to.

**Pułapka fabryki, nie logiki:** `RentalAvailabilityServiceBulkTest`'s test 50-usługowy najpierw
wywalił się `OverflowException` w `RentalCategoryFactory` — `fake()->unique()->randomElement()`
z puli **8** nazw, wyczerpanej przy tworzeniu 53 `RentalCategory` (jednej per `Service::factory()
->itemRental()`, bo ta state tworzy nową kategorię za każdym razem). Naprawa: jedna współdzielona
`RentalCategory`, przekazywana jawnie jako `rental_category_id` w każdym `create()`. Druga pułapka
w tym samym pliku: `ServiceLocationStock::where(...)->update(...)` cicho no-opuje (0 wierszy, bez
błędu), jeśli lokalizacje powstały PRZED usługą — `ServiceLocationStockObserver` materializuje
kotwicę tylko na `Location::created()` dla usług, które JUŻ istnieją w tym momencie, nigdy
odwrotnie. Naprawa: `ServiceLocationStock::updateOrCreate()` zamiast `where()->update()` —
działa niezależnie od kolejności tworzenia.

Weryfikacja: SQLite 1875 passed / 5 skipped (baseline 1854 + 21 nowych, dokładna zgodność). MySQL
8.0: `tests/Feature/Database` 183/183, nowy/zmieniony zestaw 59/59 (`GROUP BY service_id,
location_id` pod `ONLY_FULL_GROUP_BY` — sprawdzone, nie założone). `bash scripts/test-concurrency.sh`
4/4 bez zmian (4.6/4.7 nie dotykają ścieżki zapisu). Dowód zgodności zbiorczego z pojedynczym:
`RentalAvailabilityServiceBulkTest`'s testy parity porównują `availabilityForServices()` z N
wywołaniami `getAvailableQuantity()` na tych samych danych, nie z ręcznie wyliczonymi liczbami.

**Faza 4 zamknięta w całości** (kroki 4.1-4.7; 4.8 to migracje `location_id nullable` — już
istniały wcześniej, patrz [[project_lokalizacje_faza2_stan_magazynowy]]). Następny krok: Faza 5
(`LocationContext`, przełącznik w headerze) — patrz `app/docs/features/lokalizacje/plan-wdrozenia.md`.
