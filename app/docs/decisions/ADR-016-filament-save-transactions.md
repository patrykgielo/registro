# ADR-016: Transakcje zapisu w Filamencie — per strona, nie na cały panel

**Data:** 2026-11-25
**Status:** ✅ Przyjęte
**Kontekst:** przegląd etapu 5a formularza produktu (zapis per zakładka) — panel nigdy nie włączał
transakcji, więc awaria w połowie zapisu zostawiała połowę danych w bazie.

---

## Problem

Filament 4.17 owija `CreateRecord::create()`, `EditRecord::save()` i `EditRecord::saveFormComponentOnly()`
w `beginDatabaseTransaction()` / `commit` / `rollBack`, ale te metody robią cokolwiek **tylko**, gdy
`hasDatabaseTransactions()` zwraca `true`: `$this->hasDatabaseTransactions ?? panel->hasDatabaseTransactions()`
(`vendor/filament/filament/src/Pages/Concerns/CanUseDatabaseTransactions.php`). Domyślnie `false`, a ani
`AdminPanelProvider`, ani `PlatformPanelProvider` nie wołają `->databaseTransactions()`.

Skutek: wyjątek po `UPDATE`/`INSERT` — w `afterSave`/`afterCreate` (routing stanu magazynowego w
`EditService`/`CreateService`, `assignRole` w `CreateEmployee`) albo w hooku `saved` modelu — zostawiał
zapisane wiersze, a użytkownik widział błąd. Np. pracownik bez roli i bez pivota `organization_user`:
konto istnieje, ale nie da się na nie zalogować.

## Mechanika (przeczytana w vendorze, Filament 4.17)

- **Flaga panelu** (`Panel.php`, `register()`): poza stronami włącza `Action::configureUsing(fn ($a) =>
  $a->databaseTransaction())` — **każdą** akcję w aplikacji (tabela, wiersz, bulk, nagłówek, relation
  manager, własne `Action::make()->action(fn …)`).
- **Flaga strony** (`protected ?bool $hasDatabaseTransactions = true;`): tylko `CreateRecord`/`EditRecord`
  (i strony profilu/rejestracji/tenancy, których nie używamy). Akcji NIE dotyka; `ListRecords`/`ManageRecords`
  jej nie mają.
- Transakcja obejmuje: `beforeValidate`, `getState()` (zapis relacji i plików FileUpload), `mutateFormData*`,
  `handleRecord*`, `afterSave`/`afterCreate`, zdarzenia `RecordSaved` (tylko `save()`/`create()` —
  `saveFormComponentOnly()` żadnych nie wysyła). Po `commit`: `rememberData()`,
  powiadomienie, przekierowanie.
- `Halt` domyślnie **commituje** (rollback tylko przy `halt(shouldRollbackDatabaseTransaction: true)`);
  każdy inny `Throwable` (także `ValidationException`) — rollback i rethrow.
- Laravel: zagnieżdżony `DB::transaction` = savepoint; `afterCommit` odpala się dopiero po commicie
  zewnętrznej transakcji. Jedno połączenie DB w całej aplikacji (brak `DB::connection('…')`), więc transakcja
  obejmuje każdy dotykany model.

## Decyzja

1. **Flaga panelu zostaje wyłączona w obu panelach.** Pilnuje tego test
   `FilamentSaveTransactionsTest::test_neither_panel_enables_transactions_panel_wide`.
2. **Włączamy per strona**, po audycie efektów ubocznych danej strony:

   | Strona | Co staje się atomowe | Efekty uboczne (sprawdzone) |
   |---|---|---|
   | `EditService` (pełny `save()` i `saveTab()`) | `UPDATE services` + routing stanu (`RouteQuantityFieldToPrimaryLocationStock`) | tylko DB; blokady — patrz „Blokady i snapshot” niżej |
   | `CreateService` | `INSERT` + `SyncServiceLocationStock::forService` + routing | tylko DB; `insertOrIgnore` bez `Service::lockForUpdate()` |
   | `CreateEmployee` | `INSERT users` + rola `staff` + pivot tenanta | tylko DB; `assignRole` na userze nie czyści cache Spatie (tylko na `Permission`), `events_enabled=false` |
   | `CreateCustomer` | `INSERT users` + rola `customer` | jw. |

   Lista jest przypięta testem `test_exactly_the_audited_pages_opt_in_and_no_action_does`: nowa strona albo
   akcja z transakcją = najpierw audyt, potem wpis w teście i w tym ADR.
3. **Blokady i snapshot (`EditService`, gałąź routingu stanu).** Wiersz `services` z `UPDATE` jest teraz trzymany
   do commitu, więc zapis bierze blokady w kolejności `services` → `service_location_stocks` → `services`
   (`recalculateQuantityTotal`). Zgodne z koszykiem (Zasada 4 `kontrakt-dostepnosci.md`), ale ODWROTNE niż
   `ServiceUnitObserver` (wiersz stanu → `services`). Zapis „Ilość w magazynie” równoległy z utworzeniem pierwszego
   egzemplarza w głównym oddziale może dać deadlock: InnoDB przerywa jedną stronę z `1213`, transakcja strony cofa
   CAŁY jej zapis, użytkownik widzi błąd i ponawia — dane spójne (bez transakcji ta sama kolizja zostawiłaby
   połowę zapisu). Filament nie ponawia po `1213`. Do tego snapshot REPEATABLE READ zaczyna się przy pierwszym
   odczycie w transakcji (walidacja w `getState()`), więc `eligibleForDirectRouting` i suma w
   `recalculateQuantityTotal` nie widzą wiersza zatwierdzonego przez inne żądanie w tych milisekundach — ta sama
   pułapka co w `rental-availability.md`; dotyczy tylko najemców z jednym aktywnym oddziałem. Testy na SQLite tego
   nie pokrywają (savepoint, nie InnoDB); sonda w `tests/Concurrency` byłaby osobnym zadaniem.
4. **Pliki nie są cofane.** FileUpload zapisuje plik w `getState()`, przed `UPDATE`; po rollbacku plik zostaje
   jako sierota (dziś po awarii zostawał plik + wiersz). Brak utraty danych, najwyżej śmieć na dysku.

## Dlaczego nie cały panel — blokery

Każdy z nich robi się **błędny** dopiero wewnątrz transakcji (dziś działa, bo każde zapytanie autocommituje):

| # | Gdzie | Problem w transakcji |
|---|---|---|
| B1 | Platforma: `OrganizationResource` akcja `initiateClosing` → `App\Actions\Offboarding\StartOrganizationOffboarding` | `CancelInFlightObligationsJob`, `ExportOrganizationDataJob` (zwykłe `ShouldQueue`) i `OrganizationOffboardingStartedNotification` idą do kolejki PRZED commitem; przy rollbacku organizacja wraca do Active, a job i tak anuluje zamówienia klientów. |
| B2 | Akcje zamówień: `OrderResource`, `EditOrder` (`OrderService::recordOfflinePayment`, `OrderStatusStateMachine`) | `OrderPaid` miało iść po własnym commicie serwisu; zdarzenia maszyny stanów → powiadomienia `ShouldQueue` bez `ShouldQueueAfterCommit`, listener `RecordAnalyticsOnOrderPaid` w kolejce — worker może czytać zamówienie sprzed commitu albo działać mimo rollbacku. |
| B3 | `CreateUser::afterCreate`, akcja „wyślij ponownie” w `UserResource` | `AdminCreatedUserNotification` (`ShouldQueue`, `ShouldBeUnique`) niesie token zapisany w transakcji; worker przed commitem → brak wiersza / pusty token. `catch (\Exception)` połyka błąd, transakcja i tak commituje. |
| B4 | `RoleResource` (admin i platforma), CheckboxList uprawnień `->relationship()` | `forgetCachedPermissions()` na `saved` roli odpala się przed commitem; równoległe żądanie zapisze w Redisie stare uprawnienia na 24 h (`config/permission.php`). |

Ryzyka mniejszej wagi (nie blokują, ale trzeba je znać przy rozszerzaniu listy): `Cache::forget` przed
commitem (`OrganizationObserver`, `SitemapCacheObserver`, `ServiceAreaValidator`, `SettingsManager`);
dłużej trzymane blokady w akcjach `UnitsRelationManager` (scenariusz `ServiceUnitFirstUnitRaceTest`, ryzyko
deadlocka 1213); SMTP/SMS wysyłane synchronicznie w akcjach szablonów (transakcja otwarta na czas wywołania);
deadlock InnoDB w połkniętym `catch` → końcowy `commit()` rzuca.

Strony ustawień (`SystemSettings`, `MaintenanceSettings`, `DesignHub`, `PlatformSettings`) to zwykłe
`Filament\Pages\Page` z `->action('metoda')` (string) — żadna z flag ich nie obejmuje.

## Warunek przejścia na flagę panelu

1. `'after_commit' => true` dla połączenia `redis` w `config/queue.php` (dziś `false`) — ogólna naprawa B1–B3;
   dodatkowo `ShouldQueueAfterCommit` na powiadomieniach offboardingu i `AdminCreatedUserNotification` oraz
   na listenerze analityki.
2. Czyszczenie cache Spatie i `OrganizationObserver` przez `DB::afterCommit()`.
3. Test rollbacku dla każdej akcji, która zapisuje więcej niż jeden wiersz, i przebieg `tests/Concurrency`.

Bez tego włączenie flagi panelu = `->databaseTransaction(false)` na każdej akcji z listy blokerów i
`$hasDatabaseTransactions = false` na stronach ról — łatwo o pominięcie, dlatego nie wybraliśmy tej drogi.

## Testy

`tests/Feature/Filament/FilamentSaveTransactionsTest.php`: awaria po `UPDATE` cofa pełny zapis i zapis
zakładki produktu (routing stanu i hook `saved`), awaria w `afterCreate` nie zostawia produktu, pracownika ani
klienta; dwa testy kontrolne z wyłączoną flagą (pełny zapis i zakładka) pokazują, że ten sam scenariusz bez
transakcji zostawia zapis (inaczej testy rollbacku niczego by nie dowodziły). Mutacja (flaga → `false` na
czterech stronach) wywraca wszystkie 7 testów rollbacku tego pliku, test pamięci po awarii w
`ServiceFormPerTabSaveTest` i test-strażnik — sprawdzone 2026-11-25. `RefreshDatabase` owija test w transakcję, więc transakcja strony jest w
teście savepointem — to dokładnie ten sam mechanizm cofnięcia.
