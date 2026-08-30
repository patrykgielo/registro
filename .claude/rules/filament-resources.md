---
paths:
  - "app/Filament/Resources/**"
---

# Filament Resources - Save Behavior

## ZASADA: Pozostań na stronie po zapisie (Content Resources)

Resources z kategorii Content Management (Pages, Posts, Promotions, Portfolio) powinny używać traitów:

### EditRecord - StaysOnPageAfterSave

```php
use App\Filament\Traits\StaysOnPageAfterSave;

class EditPage extends EditRecord
{
    use StaysOnPageAfterSave;

    // Trait zapewnia:
    // - getRedirectUrl() -> null (pozostań na stronie)
    // - getSavedNotification() -> "Zapisano pomyślnie"
    // - getFormActions() -> "Zapisz" + "Zapisz i zamknij"
}
```

### CreateRecord - CreatesAndRedirectsToEdit

```php
use App\Filament\Traits\CreatesAndRedirectsToEdit;

class CreatePage extends CreateRecord
{
    use CreatesAndRedirectsToEdit;

    // Trait zapewnia:
    // - getRedirectUrl() -> edit page nowego rekordu
    // - getCreatedNotification() -> "Utworzono pomyślnie"
}
```

## NIE UŻYWAJ getRedirectUrl() do listy

```php
// ❌ ŹLE: Przekierowanie do listy (frustrujące UX)
protected function getRedirectUrl(): string
{
    return $this->getResource()::getUrl('index');
}

// ✅ DOBRZE: Użyj trait
use StaysOnPageAfterSave;  // dla EditRecord
use CreatesAndRedirectsToEdit;  // dla CreateRecord
```

## Resources z traitami (Content Management)

| Resource | EditRecord | CreateRecord |
|----------|------------|--------------|
| Pages | StaysOnPageAfterSave | CreatesAndRedirectsToEdit |
| Posts | StaysOnPageAfterSave | CreatesAndRedirectsToEdit |
| Promotions | StaysOnPageAfterSave | CreatesAndRedirectsToEdit |
| PortfolioItems | StaysOnPageAfterSave | CreatesAndRedirectsToEdit |

## Traity - lokalizacja

- `app/Filament/Traits/StaysOnPageAfterSave.php` - dla EditRecord
- `app/Filament/Traits/CreatesAndRedirectsToEdit.php` - dla CreateRecord

## Kiedy NIE używać traitów

Traity są przeznaczone dla Content Management. Inne Resources (np. Customer, Employee, Appointment) mogą mieć inne wymagania biznesowe i mogą potrzebować własnej logiki przekierowań.

## Zachowanie przycisków

### EditRecord z StaysOnPageAfterSave:
- **Zapisz** - zapisuje i pozostaje na stronie
- **Zapisz i zamknij** - zapisuje i wraca do listy
- **Anuluj** - wraca do listy bez zapisu

### CreateRecord z CreatesAndRedirectsToEdit:
- **Utwórz** - tworzy i przekierowuje do edycji nowego rekordu

---

## Module Visibility Gating (Phase 6)

**BaseResource auto-gatuje widoczność Resources na podstawie modułów organizacji.**

### Pattern: `$module` property

```php
// Resource z modułem — widoczny tylko gdy moduł aktywny
protected static ?string $module = 'services';

// Core resource — zawsze widoczny
protected static ?string $module = null;
```

### `shouldRegisterNavigation()` w BaseResource

```php
public static function shouldRegisterNavigation(): bool
{
    if (static::$module === null) return true;        // core = always
    $tenant = TenantFeature::currentTenant();
    if ($tenant === null) return true;                // platform/CLI = show all
    return $tenant->hasModule(static::$module);       // tenant = check module
}
```

### NIGDY nie override'uj `shouldRegisterNavigation()` na Resource

```php
// ❌ ŹLE — stary pattern (Phase 2-5), USUNIĘTY w Phase 6
public static function shouldRegisterNavigation(): bool
{
    return TenantFeature::active('vehicles');
}

// ✅ DOBRZE — Phase 6: użyj $module property
protected static ?string $module = 'vehicles';
```

### Incident 2026-07-01: `$shouldRegisterNavigation = false` ignorowane przez BaseResource

**Problem:** `BaseResource::shouldRegisterNavigation()` całkowicie nadpisywał metodę z Filament core (`HasNavigation` trait, która zwraca `static::$shouldRegisterNavigation`) bez w ogóle sprawdzania tej właściwości. Skutek: `StaffDateExceptionResource` (`$shouldRegisterNavigation = false` — celowo dostępny WYŁĄCZNIE przez header actions w `StaffScheduleResource`, nie przez sidebar) i tak pojawiał się w nawigacji grupy "Personel".

**Przyczyna:** Moduł-gating override (Phase 6) zastąpił metodę bazową zamiast ją rozszerzyć — zgubił krótkie spięcie na `static::$shouldRegisterNavigation`.

**Rozwiązanie:** `shouldRegisterNavigation()` w `BaseResource` najpierw sprawdza `! static::$shouldRegisterNavigation → return false`, dopiero potem stosuje `$module` gating. `shouldRegisterNavigation()` wpływa TYLKO na widoczność w sidebarze — trasy (`route:list`) rejestrują się niezależnie.

**Zapobieganie:** Gdy nadpisujesz metodę z Filament core (`shouldRegisterNavigation`, `shouldRegisterNavigationItem` itp.) w bazowej klasie — ZAWSZE zachowaj oryginalny short-circuit z core PRZED dodaniem własnej logiki, nie zastępuj go całkowicie.

### Moduły vs Features (WAŻNE ROZRÓŻNIENIE)

| System | Gating | Użycie |
|--------|--------|--------|
| `$module` | Widoczność CAŁEGO Resource w nawigacji | `protected static ?string $module = 'staff';` |
| `TenantFeature::active()` | Widoczność POLA w formularzu | `->visible(fn () => TenantFeature::active('vehicles'))` |

Moduły nie zastępują feature flags — oba systemy współistnieją.

---

## Warunkowa Walidacja: Create vs Edit (Bug z 2026-02-16)

**Filament waliduje WSZYSTKIE pola formularza przy zapisie, nie tylko zmienione!**

Jeśli DatePicker ma `->minDate(now())`, to edycja rekordu z przeszłą datą ZAWSZE fail — nawet gdy zmienisz tylko status.

```php
// ❌ BŁĄD: blokuje edycję starych rekordów
Forms\Components\DatePicker::make('appointment_date')
    ->minDate(now())  // 2026-02-16

// Appointment #6: appointment_date = 2026-01-30
// Zmiana status → "completed" → WALIDACJA ODRZUCA!
// "The data wizyty field must be a date after or equal to 2026-02-16"

// ✅ POPRAWNIE: walidacja warunkowa
Forms\Components\DatePicker::make('appointment_date')
    ->minDate(fn (?Model $record): ?string => $record ? null : now()->toDateString())
```

**Pattern ogólny:**
```php
->rule(fn (?Model $record) => $record ? null : 'required_validation')
->minDate(fn (?Model $record) => $record ? null : now())
->maxDate(fn (?Model $record) => $record ? null : now()->addYear())
```

`$record === null` → create mode, `$record !== null` → edit mode.

---

## ZASADA: Admin → Frontend Impact Check (CRITICAL)

**Każda zmiana w Filament Resource MUSI być zweryfikowana na frontendzie!**

### Reguła

Gdy zmieniasz formularz w panelu admina (nowe pola, zmiana formatu danych, Repeater, KeyValue):

1. **ZNAJDŹ WSZYSTKIE widoki** które renderują te dane na frontendzie
2. **SPRAWDŹ edge cases**: puste wartości, null, zmiana formatu (object→array)
3. **PRZETESTUJ** stronę publiczną z danymi które admin może wpisać

### Incident 2026-03-22: htmlspecialchars() crash na stronie produktowej

**Problem:** Zmiana specs z KeyValue `{key: value}` na Repeater `[{label, value, unit}]` spowodowała crash `htmlspecialchars(): Argument #1 must be string, array given` gdy admin dodał puste wpisy repeatera (label=null, value=null).

**Przyczyna:** Frontend (`show.blade.php`) nie filtrował pustych wpisów ani nie castował null na string. Admin dodał puste wiersze repeatera → frontend crash.

**Zapobieganie:**
- **ZAWSZE** filtruj puste wpisy z Repeater/KeyValue przed renderowaniem
- **ZAWSZE** castuj do `(string)` wartości z JSON które mogą być null
- **ZAWSZE** sprawdź `empty()` przed wyświetleniem sekcji
- Przy zmianie formatu danych → BACKWARD COMPATIBLE rendering (obsługa starego i nowego formatu)

### Checklist przy zmianach admin form:

```
[ ] Zidentyfikowano WSZYSTKIE Blade views renderujące zmienione dane
[ ] Przetestowano z pustymi/null danymi
[ ] Przetestowano z danymi w starym formacie (backward compat)
[ ] Przetestowano stronę publiczną po zapisie z admin panelu
```

---

## Role Escalation Guard (feature/user-role-escalation-guard, 2026-08-07)

**`->options()` na relationship Select is UI-only — for `multiple()` fields it's the only thing
enforcing the list, because the field itself is `dehydrated(false)` and never reaches
`mutateFormDataBeforeSave()`.** Real enforcement must be a `->rule()` on the field, since that's
the only hook that sees the submitted value regardless of client input. Pattern + full "why"
(including the `canViewAny()`-gates-every-page Livewire testing gotcha) documented in
`app/docs/security/patterns/role-escalation-guard.md`. Reusable guard: `App\Support\RoleAssignmentGuard`
+ `App\Rules\AssignableRole` (UserResource) / `App\Rules\ProtectedRoleName` (RoleResource).

---

## Autoryzacja: `can*()` NIE jest punktem egzekwowania (incydent 2026-08-07)

**`app/Policies/` w tym projekcie NIE ISTNIEJE. Zero polityk.** Filament bez polityki i bez strict
mode zwraca `Response::allow()` — czyli **domyślnie zezwala**. Autoryzacja to 33 ręcznie skopiowane
`hasRole()` w poszczególnych zasobach, bez jednego punktu.

### Które metody Filament faktycznie pyta

| Piszesz | Filament pyta | Skutek |
|---|---|---|
| `canViewAny()` | `canViewAny()` | działa — gatuje też **mount każdej strony** zasobu, nie tylko listę |
| `canDelete()` | **`getDeleteAuthorizationResponse()`** | `canDelete()` **nie jest wołane** przez `DeleteAction` |
| `canDeleteAny()` | **`getDeleteAnyAuthorizationResponse()`** | to samo dla `DeleteBulkAction` |

**Incydent:** agent napisał `canDelete()` zwracające `false` dla admina tenanta i uznał sprawę za
zamkniętą. Recenzent **faktycznie usunął współadmina** przez `callAction('delete')`. Guard czytał
się poprawnie i nie robił nic.

### Jak to zrobić poprawnie

```php
// ❌ ZA MAŁO — czyta się jak zabezpieczenie, nie egzekwuje niczego
public static function canDelete($record): bool { return $user->hasRole('super-admin'); }

// ✅ Egzekwowanie + ukrycie przycisku
public static function getDeleteAuthorizationResponse(Model $record): Response
{
    return static::canDelete($record) ? Response::allow() : Response::deny();
}
Actions\DeleteAction::make()->visible(fn ($record) => static::canDelete($record))
```

Samo `->visible()` nie wystarcza — akcję da się wywołać bez wyrenderowania.

### Masowe akcje NIE sprawdzają rekordów pojedynczo (incydent 2026-08-08)

`DeleteBulkAction` pyta `canDeleteAny()` **raz** i kasuje całe zaznaczenie.
`CanBeAuthorized::shouldAuthorizeIndividualRecords()` zwraca `false`, dopóki nie dopniesz
`->authorizeIndividualRecords()`, a `getIndividuallyAuthorizedSelectedRecords()` oddaje wtedy
zaznaczenie **nieprzefiltrowane**.

```php
// ❌ reguła per-rekord z canDelete() nigdy nie zostanie sprawdzona
Actions\DeleteBulkAction::make()

// ✅ dopiero to woła getDeleteAuthorizationResponse() dla każdego rekordu
Actions\DeleteBulkAction::make()->authorizeIndividualRecords()
```

**Zasada: jeśli `canDelete($record)` jest ostrzejsze niż `canDeleteAny()`, akcja masowa MUSI mieć
`->authorizeIndividualRecords()`.** Inaczej masz zabezpieczenie na akcji wiersza i otwartą furtkę
obok.

Incydent: agent napisał w docblocku i dokumentacji, że Filament „zawsze" sprawdza rekordy
pojedynczo. Nieprawda. Pracownik mógł masowo usunąć **własny zatwierdzony urlop**, którego akcja
wiersza słusznie broniła. Wyszło dopiero, gdy recenzent faktycznie skasował rekord.

**Test akcji masowej to osobny test.** `callTableAction()` nie pokrywa `callTableBulkAction()` —
to dwie różne ścieżki autoryzacji.

### Zasada

**Otwierasz `canViewAny()` dla szerszej roli → przejrzyj WSZYSTKIE akcje tego zasobu.** To, co było
nieszkodliwe przy zamkniętym zasobie, staje się osiągalne w tej samej minucie. Dotyczy też
`getNavigationBadge()` — `getModel()::count()` policzy wszystkich tenantów (patrz `models.md`).

**Test musi być zweryfikowany mutacją.** Odwróć guard, sprawdź że test pada. Test na autoryzację,
który przechodzi z konstrukcji, jest gorszy niż jego brak — daje fałszywą pewność.

### Naprawione: `BaseResource` teraz egzekwuje `can*()` (2026-08-08)

Incydent wyżej opisuje dziurę per-zasób. **Zamknięta w jednym miejscu** —
`App\Filament\Resources\BaseResource` nadpisuje wszystkie `get*AuthorizationResponse()` tak, by
wołały odpowiadające `can*()` (`getDeleteAuthorizationResponse()` → `canDelete()`, itd.), zamiast
schodzić do Gate/policy. **`canDelete()` w zasobie znowu robi to, na co wygląda.** Pełny writeup
(uzasadnienie domyślnej postawy, rekurencja, decyzje per-zasób): `app/docs/security/patterns/resource-authorization.md`.

**Domyślna postawa BaseResource:** `canViewAny()` = deny-by-default (żaden z 34 zasobów tego nie
potrzebował — wszystkie już nadpisywały; `ServiceAreaResource` był jedynym wyjątkiem, dostał własne
nadpisanie). Mutujące `can*()` (create/edit/delete/deleteAny/...) = `hasAnyRole(['admin', 'super-admin'])`
— to samo, czego już używa niemal każde istniejące `canViewAny()`.

**Rekurencja:** domyślne `can*()` w `BaseResource` NIGDY nie wołają `get*AuthorizationResponse()` —
to gwarantowana pętla (domyślne `getDeleteAuthorizationResponse()` woła `canDelete()`, więc
`canDelete()` nie może wołać z powrotem).

**Zasób z tylko `canViewAny()` nadpisanym** (większość z 34) dziedziczy domyślne mutujące `can*()` z
`BaseResource` — jeśli Twój `canViewAny()` używa innego zestawu ról niż `admin`/`super-admin` (np.
dopuszcza `staff`, jak `AppointmentResource`), **musisz jawnie nadpisać też `canCreate()`/`canEdit()`/
`canDelete()`/`canDeleteAny()`/`canView()`** — inaczej domyślna, węższa postawa cicho zabierze
uprawnienia roli, która ich realnie używa. `StaffVacationPeriodResource` (już miał pełny per-rekordowy
`can*()`) potrzebował tylko brakującego `canDeleteAny()` — indywidualny rekord i tak jest sprawdzany
przez Filamentowy `DeleteBulkAction` resolver niezależnie od tego, co zwraca `canDeleteAny()`.

**RelationManager to ta sama klasa dziury, osobna hierarchia klas** (`Filament\Resources\RelationManagers\RelationManager`,
nie dziedziczy po `BaseResource`) — metody instancyjne, nie statyczne. `OrderItemsRelationManager`
naprawiony lokalnie (jedyny przypadek w repo z realną logiką `can*()`; nie ma jeszcze wspólnej bazy).

**Panel `platform` — celowo NIE objęty.** `canAccessPanel()` (`app/Models/User.php`) gatuje CAŁY panel
`/platform` do `super-admin` na wejściu, przed dotarciem do jakiegokolwiek zasobu — to jedyna rola,
jaka tam w ogóle występuje. Per-akcja `can*()` na `Platform\RoleResource` autoryzowałoby super-admina
przeciwko super-adminowi, więc nie dodaje żadnej realnej granicy. Rewizja: jeśli kiedyś powstanie
druga rola z dostępem do `/platform` (np. read-only auditor), ta decyzja przestaje być aktualna.

**Strict mode (`$panel->strictAuthorization()`) — nadal NIE do włączenia.** `Platform\RoleResource` nie
ma żadnego `can*()` i dziedziczy wprost po `Filament\Resources\Resource` — bez polityki w
`app/Policies/` (nadal nie istnieje) strict rzuciłby `LogicException` na każdej akcji tego zasobu przy
pierwszym mouncie. Warunek do rewizji: dopiero po tym, jak platform dostanie tę samą warstwę co

---

## `->unique(ignoreRecord: true)` bez `organization_id` — walidacja ostrzejsza niż schemat (2026-08-27)

**`Rule::unique()` (to, co `->unique()` buduje pod spodem) czyta surowe zapytanie DB, NIE respektuje
globalnego scope'a `BelongsToOrganization`.** Jeśli tabela ma `UNIQUE(organization_id, slug)`, a pole
formularza ma gołe `->unique(ignoreRecord: true)`, walidacja Filamenta jest de facto GLOBALNA — dwóch
tenantów nie może mieć tego samego sluga, mimo że baza by na to pozwoliła. Incydent: backfill dał
każdemu tenantowi ten sam slug `siedziba-glowna` dla głównej lokalizacji — żaden z nich (poza
pierwszym) nie mógł potem zapisać ŻADNEJ edycji tego rekordu, bo walidacja odrzucała slug, którego
nawet nie zmieniali. Fix (wzorzec do powielania): `App\Filament\Resources\Locations\Schemas\
LocationForm.php`:

```php
->unique(
    ignoreRecord: true,
    modifyRuleUsing: fn (Illuminate\Validation\Rules\Unique $rule) => $rule->where(
        'organization_id',
        TenantFeature::currentTenant()?->id ?? -1, // brak tenanta → reguła nigdy nie trafia, nie wywala wyjątku, DB constraint zostaje backstopem
    ),
)
```

**Ten sam wzorzec (composite `unique(organization_id, X)` w migracji + gołe `->unique(ignoreRecord:
true)` w Resource) potwierdzony i NAPRAWIONY 2026-08-28** (branch `fix/tenant-scoped-slug-validation`,
zadanie ClickUp `86cbb2ft5`) w: `ServiceResource`, `Categories\CategoryResource`,
`Pages\PageResource`, `Posts\PostResource`, `PortfolioItems\PortfolioItemResource`,
`Promotions\PromotionResource`, `RentalCategoryResource` — 13 kolizji slugów w `services` i 7 w
`rental_categories` na dev-bazie w dniu naprawy, obie systematyczne (seeder daje każdej wypożyczalni
ten sam katalog), nie przypadkowe. Wspólny helper zamiast siedmiu kopii closure:
`App\Filament\Support\TenantScopedUniqueRule::forCurrentTenant()` — identyczny `where('organization_id',
TenantFeature::currentTenant()?->id ?? -1)` co `LocationForm.php` (`LocationForm.php` samo NIE zostało
zrefaktoryzowane na ten helper — poza zakresem zadania, wielooddziałowość celowo nietknięta):

```php
->unique(
    ignoreRecord: true,
    modifyRuleUsing: TenantScopedUniqueRule::forCurrentTenant(),
)
```

Każdy dostał test `tests/Feature/Filament/{Resource}SlugUniqueScopeTest.php` (czerwony przed poprawką,
zweryfikowany przez `git stash` na siedmiu plikach Resource — potwierdzone 2 z 3 scenariuszy na test
padają bez fixa: edycja bez zmiany sluga i tworzenie sluga zajętego tylko przez innego tenanta;
scenariusz "duplikat w obrębie tego samego tenanta nadal odrzucony" zostaje zielony w obu wariantach,
bo to nie jest to, co fix zmienia). `CategoryResource` nie ma dedykowanych stron Create/Edit (jedna
`ManageCategories`, akcje w modalu) — test steruje przez `callTableAction`/`callAction`, nie
`Livewire::test(EditRecord::class)`.

`CarBrandResource`/`VehicleTypeResource` (tabela globalna, bez `organization_id`),
`CustomerResource`/`EmployeeResource`/`UserResource` (`users.email` jest CELOWO globalnie unikalny —
jedno konto na e-mail w całej apce), `RoleResource` (Spatie `teams => false` w `config/permission.php`
→ `roles` ma globalny `unique(name, guard_name)`), `EmailSuppressionResource` (`email_suppressions`
nie ma w ogóle kolumny `organization_id` — globalna lista wykluczeń), `OrganizationResource`
(`organizations.slug` to tożsamość organizacji, nie ma `organization_id` samo w sobie) — **NIE mają
tego błędu**, sprawdzone przez migrację/config, nie przez zgadywanie.

**Zasada:** przed dodaniem `->unique()` do pola formularza sprawdź migrację tabeli — jeśli unique jest
`['organization_id', kolumna]`, reguła Filamenta MUSI dostać `modifyRuleUsing` z tym samym `where`.
`/admin`, albo własną politykę.

---

## Podgląd pliku wiszący na „Pobieranie rozmiaru" (2026-08-29)

`FileUpload`/FilePond, który w nieskończoność pokazuje „Wczytywanie / Pobieranie rozmiaru"
na subdomenie tenanta, to **nie** bug Filamenta, nie limit uploadu i nie za duży plik.

FilePond robi `fetch()` po URL zwrócony przez `Storage::url()`. Jeśli ten URL ma inny host
niż panel — a na stacku współdzielonym ma, bo adres dysku `public` jest budowany z `APP_URL`
— przeglądarka blokuje żądanie przez CORS i komponent czeka w nieskończoność.

Mylące: zwykły `<img src>` na storefroncie z tym samym złym adresem **działa**, bo obrazki
nie podlegają CORS. Objaw jest więc panel-only i wygląda na problem Filamenta.

Mechanizm i trzy niezależne pułapki (w tym `Storage::forgetDisk()`): `architecture-models.md`,
sekcja „Adres dysku `public` to TRZECI, osobny adres".

---

## Kształt komponentu MUSI odpowiadać kształtowi w bazie (2026-08-30)

`Repeater` czyta i pisze **listę wierszy**. Wskazany na kolumnę JSON trzymającą **słownik**
nie zgłasza błędu — po cichu przepisuje ją na listę pustych wierszy i **dane przepadają**.

Zdarzone: `ServiceResource.php:321` to `Repeater::make('metadata.specs')` o schemacie
`label`/`value`/`unit`, a seeder onboardingu zapisywał `['power_w' => 800]`. Otwarcie usługi
i zapis **bez żadnej zmiany** dawał `[{"label":null,"value":null,"unit":null}, ...]`.
Dotknięte 24 z 26 usług; każdy nowy tenant dostawał wadliwy kształt, bo produkował go seeder.

**Dlaczego nikt tego nie zauważył przez miesiące:** błąd był maskowany przez walidację sluga.
`save()` przerywał się na walidacji, zanim dotarł do zapisu `metadata`. Naprawa slugów
(PR #232) zamieniłaby „nie da się zapisać" na „zapis po cichu niszczy dane" — to jest wzorzec
wart zapamiętania: **poprawka jednej walidacji potrafi odsłonić utratę danych, którą tamta
walidacja przypadkiem blokowała.** Przed naprawą walidacji sprawdź, co się stanie, gdy zapis
faktycznie dojdzie do końca.

**Czego nie wykryje test sprawdzający tylko brak błędów walidacji.** Wykrywa to wyłącznie
porównanie atrybutów **przed i po zapisie bez zmian** (`fresh()` diff) — patrz
`PanelWalkthroughTest`.

**Zasada:** dokładając komponent formularza nad polem JSON, sprawdź kształt, który realnie
leży w bazie (`JSON_TYPE(JSON_EXTRACT(kolumna,'$.klucz'))`), a nie ten, który zakładasz.
Jeśli mogą występować oba, znormalizuj na modelu (`NormalizesSpecsShape` na `Service`),
nie w komponencie — model chroni też API, konsolę i seedery.
