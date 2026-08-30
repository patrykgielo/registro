# Przegląd biznesowy panelu administracyjnego

**Dla właścicieli firm/personelu:** ta strona to praktyczny przewodnik
operacyjny na co dzień — nie dokument architektury Filament. Opisuje
czynności, które faktycznie klika administrator lub członek personelu:
potwierdzenie zamówienia, oznaczenie odbioru przedmiotu, zatwierdzenie/
anulowanie wizyty, obsługę kaucji zabezpieczającej oraz zarządzanie zwrotami
dla klientów. Szczegóły techniczne maszyny stanów każdego modelu znajdują się
w [Development → Status Machines](../architecture/status-machines.md).

## Panele

Istnieją dwa panele Filament. `/platform` jest dostępny wyłącznie dla
super-admina i zarządza sprawami przekrojowymi (organizacje, rozliczenia
SaaS, statystyki całej platformy). `/admin` to panel per-tenant, w którym
odbywają się codzienne operacje biznesowe — dostęp ograniczony do
uwierzytelnionej organizacji.

To, które zasoby widzi administrator/personel w `/admin`, zależy od
**modułów** danego tenanta (`Organization::hasModule($key)` — niewidoczne w
nawigacji + 403 na trasach, jeśli moduł jest wyłączony dla danego tenanta):

| Moduł | Zasoby objęte kontrolą | Domyślnie włączony dla |
|--------|-----------------|-----------------|
| `rentals` | Orders, Rentals | Branża wypożyczalni sprzętu; `booking_type: item_rental` lub `both` |
| `bookings` | Appointments | Auto-detailing, usługi ogólne; `booking_type: time_slot` lub `both` |
| `customers` | Customers | Jawne nadpisanie lub domyślne ustawienie branży |
| `services` | Services | Wszystkie branże |
| *(brak — rdzeń)* | Dashboard, System Settings, Maintenance Settings | Zawsze widoczne |

`Statistics` i `AnalyticsOverview` całkowicie omijają kontrolę modułową i
opierają się wyłącznie na sprawdzaniu ról (admin + super-admin).

## Przetwarzanie zamówień (moduł `rentals`)

To główny przepływ "zatwierdź i zrealizuj wypożyczenie". `OrderResource` w
`/admin`: zamówień **nie można** tworzyć ręcznie (`canCreate: false` — powstają
wyłącznie z publicznego checkoutu) ani usuwać, i nie można ich edytować po
osiągnięciu stanu końcowego (`completed`, `cancelled`, `refunded`).

| Akcja biznesowa | Dostępna, gdy status zamówienia to | Rezultat | Klient powiadomiony? |
|---|---|---|---|
| Potwierdź zamówienie | `paid` | → `confirmed` | Tak — `OrderConfirmedNotification` |
| Oznacz jako w toku | `confirmed` | → `in_progress` | Nie |
| Oznacz jako zakończone (przedmiot zwrócony) | `in_progress` | → `completed` | Nie |
| Anuluj zamówienie | `pending_payment`, `paid` lub `confirmed` | → `cancelled` (wymaga podania powodu) | Tak — `OrderCancelledNotification` |
| Przetwórz zwrot | `completed` | → `refunded` (stan końcowy) | Brak automatycznego powiadomienia; ręczny proces administracyjny, brak wywołania API zwrotu P24 |

Przycisk anulowania w Filament celowo **nie pojawia się** dla `in_progress`
— ścieżka anulowania tego stanu jest zarezerwowana dla wyjątkowych scenariuszy
wymuszonego offboardingu (np. zamykania konta tenanta) i jest wywoływana
programowo przez `OrderService::cancel()`, a nie przez standardową akcję
wiersza. Zobacz [Cancellation](customer-journey-cancellation.md) po pełny
opis tego, kto może anulować i skąd.

### Poprawiony diagram stanów zamówienia (widok biznesowy)

```mermaid
flowchart TD
    START([Public checkout]) --> PP[pending_payment]

    PP -->|P24 webhook| PAID[paid]
    PP -->|Admin: Anuluj| CANCELLED[cancelled]

    PAID -->|Admin: Potwierdź| CONF[confirmed]
    PAID -->|Admin: Anuluj| CANCELLED

    CONF -->|Admin: Oznacz w toku| IP[in_progress]
    CONF -->|Admin: Anuluj| CANCELLED

    IP -->|Admin: Zakończ| COMP[completed]
    IP -.->|Wymuszony offboarding — wyjątkowy,\nniedostępny w standardowym UI| CANCELLED
    COMP -.->|ręcznie| REF[refunded]

    CANCELLED -.->|TYLKO rekoncyliacja — wymaga istniejącego\nwiersza Payment(status=success)| PAID

    subgraph DEPOSIT ["Ścieżka kaucji — aktywna od stanu 'paid'"]
        direction LR
        DP[pending] -->|collect_deposit| DC[collected]
        DC -->|return_deposit| DR[returned]
        DC -->|"forfeit_deposit (wymaga powodu)"| DF[forfeited]
    end

    PAID -.->|start cyklu życia kaucji| DP

    PAID -.->|emituje event OrderPaid| NP["OrderPaidNotification\nKlient + Właściciel organizacji"]
    CONF -.->|emituje event OrderConfirmed| NC["OrderConfirmedNotification\nKlient"]
    CANCELLED -.->|emituje event OrderCancelled| NK["OrderCancelledNotification\nKlient"]

    style PP fill:#64748b,color:#fff
    style PAID fill:#2563eb,color:#fff
    style CONF fill:#7c3aed,color:#fff
    style IP fill:#d97706,color:#fff
    style COMP fill:#16a34a,color:#fff
    style CANCELLED fill:#dc2626,color:#fff
    style REF fill:#ea580c,color:#fff
```

Poprawia to dwa przejścia, których brakowało w oryginalnym diagramie panelu
administracyjnego (zweryfikowane względem
`app/StateMachines/OrderStatusStateMachine.php`): przerywaną ścieżkę
wymuszonego offboardingu `in_progress → cancelled` oraz przerywaną ścieżkę
rekoncyliacji `cancelled → paid` (zabezpieczoną — dozwoloną tylko wtedy, gdy
zweryfikowany rekord `Payment` już istnieje).

## Zarządzanie kaucją (deposit)

Celowo pominięta w sumie faktury — to zwrotna kaucja zabezpieczająca, nigdy
nie pobierana przez Przelewy24, odbierana fizycznie przy odbiorze.

| Akcja biznesowa | Wymaga | Rezultat |
|---|---|---|
| "Pobrano kaucję" | `deposit_status = pending` | → `collected`, zapisywany znacznik czasu |
| "Zwrócono kaucję" | `deposit_status = collected` | → `returned`, zapisywany znacznik czasu |
| "Kaucja przepadła" | `deposit_status = collected` | → `forfeited` (nieodwracalne) |
| Częściowy zwrot | `deposit_status = collected` | → `partial_return` |

## Przetwarzanie wizyt (moduł `bookings`)

`AppointmentResource`: administrator/personel może przeglądać; tylko admin
może edytować status. Formularz wizyty wspiera bezpośrednio tworzenie
klienta w locie (inline).

| Akcja biznesowa | Efekt | Klient powiadomiony? |
|---|---|---|
| Potwierdź wizytę | `pending → confirmed` | Tylko SMS (`APPOINTMENT_CONFIRMED`) — **dla tego przejścia nie istnieje żaden e-mail** |
| Anuluj wizytę | dowolny status aktywny → `cancelled`, brak ograniczeń polityki dla admina | E-mail + SMS (`AppointmentCancelledNotification`) |
| Oznacz jako zakończoną | dowolny status aktywny → `completed`, ustawia `completed_at` | Nie |
| Zmiana terminu (zmiana pola daty/godziny) | emituje `AppointmentRescheduled` | E-mail + SMS — **podlega potwierdzonemu błędowi w środowisku uruchomieniowym, patrz niżej** |

`mutateFormDataBeforeSave` waliduje przy każdym zapisie: jeśli członek
personelu nie posiada roli `staff`, lub jeśli nowa data/godzina/personel
koliduje z istniejącą rezerwacją, zapis zostaje zablokowany trwałym
powiadomieniem typu danger (`$this->halt()`) — formularz pozostaje otwarty z
widocznymi nieprawidłowymi danymi, tak aby administrator mógł je poprawić.

**Znany błąd (potwierdzony, nie naprawiony w tym miejscu):**
`Appointment::booted()` emituje `event(new AppointmentRescheduled($appointment))`
przy zmianie terminu, ale konstruktor eventu wymaga
`(Appointment, Carbon $oldDate, Carbon $newDate)` — brakuje dwóch argumentów
typu `Carbon`. Powoduje to `TypeError` w czasie działania za każdym razem,
gdy administrator zmienia termin wizyty przez Filament. Zobacz
[Customer Journey — Booking](customer-journey-booking.md) (sekcja "Known bug
— reschedule TypeError") po pełny opis.

## Przetwarzanie wypożyczeń (moduł `rentals`, cykl życia przedmiotu fizycznego)

`RentalResource`: tylko admin/super-admin.

| Akcja biznesowa | Dostępna, gdy | Rezultat |
|---|---|---|
| Potwierdź | `Held` lub `Pending` | → `Confirmed` |
| Oznacz jako odebrane | `Confirmed` | → `Active` |
| Oznacz jako zwrócone | `Active` | → `Returned` |
| Anuluj | jeszcze nie `Returned`/`Cancelled`/`Expired` | Wymaga podania powodu → `Cancelled` |

Wszystkie zmiany stanu wypożyczenia to zwykłe wywołania `$record->update()`
— **żadne eventy nie są emitowane i żadne powiadomienia dla klienta nie są
wysyłane przy żadnej administracyjnej akcji na wypożyczeniu.** Obsługa
kaucji dla tych wierszy odbywa się przez powiązane `Order` (patrz wyżej), a
nie przez sam rekord `Rental`.

## Oddziały (Lokalizacje)

Zakładka **Ustawienia → Lokalizacje**. Służy do opisania fizycznych punktów firmy —
adresu, godzin, kontaktu i zdjęć — i pokazania ich klientowi na stronie.

**Uwaga:** to jeszcze **nie jest** magazyn per oddział. Sprzęt nie jest przypisany do punktu,
klient nie wybiera oddziału, a dostępność liczy się wspólnie dla całej firmy. To fazy 4-6,
wstrzymane. Dziś Lokalizacje są warstwą prezentacyjną i adresową.

### Co wypełniasz i co z tego widzi klient

| Pole w panelu | Gdzie trafia |
|---|---|
| Nazwa | nagłówek karty na stronie |
| Symbol (np. `MMZ`) | plakietka przy nazwie |
| Ulica, budynek, kod, miasto | linia adresu na karcie |
| Opis | akapit na karcie, skracany do 120 znaków |
| Godziny otwarcia | lista na karcie |
| Telefon, e-mail | klikalne odnośniki na karcie |
| Zdjęcie siedziby | zdjęcie nagłówkowe karty |
| Galeria | pasek do 4 miniatur z licznikiem „+N" |
| Lokalizacja na mapie | odnośnik „Zobacz na mapie" |

Oddział **nie ma własnej podstrony** — istnieje wyłącznie jako karta w siatce na stronie CMS.

### Trzy rzeczy, które zaskakują

**1. Dodanie oddziału nie umieszcza go na stronie.** Musisz wejść w stronę CMS, znaleźć blok
„Siatka treści" z typem „Lokalizacje" i **dopisać nowy oddział do listy**. Blok pokazuje
ręcznie wybrane elementy i nie ma opcji „wszystkie". Nic o tym nie przypomina — strona po
prostu wygląda jak wcześniej. ([`123k99ct3xt`](https://app.clickup.com/t/123k99ct3xt))

**2. Odznaczenie „Aktywna" nie ukrywa oddziału.** Usuwa go z listy do wyboru w panelu, ale
jeśli był już w bloku — nadal się renderuje. Żeby zniknął ze strony, usuń go z bloku.

**3. Pierwszy oddział zostaje główny automatycznie** i nie da się usunąć ostatniego — system
pilnuje, żeby firma zawsze miała co najmniej jedną siedzibę.

### Adres firmy jest dziś w dwóch miejscach

Adres pokazywany w checkoucie, na protokołach i w e-mailach pochodzi z **Ustawień**
(dane kontaktowe firmy), a nie z encji oddziału — mimo że oddział ma własny adres.
Zmiana adresu w Lokalizacjach **nie zmieni** adresu na dokumentach.
Zgłoszone: [`123k99ct3j0`](https://app.clickup.com/t/123k99ct3j0).

## Zarządzanie klientami (moduł `customers`)

`CustomerResource`: użytkownik pojawia się na tej liście tylko wtedy, gdy ma
przynajmniej jedną wizytę, wypożyczenie lub zamówienie w ramach bieżącego
tenanta. Administrator może edytować imię/nazwisko/dane kontaktowe/limity
(`max_vehicles`, `max_addresses`, 1–10 każdy), ale sama rola `customer` jest
zablokowana — z tego ekranu nie ma ścieżki UI do awansowania klienta do
personelu/administratora.

## Podsumowanie powiadomień (kto otrzymuje e-mail przy jakiej akcji administratora)

| Akcja administratora | Odbiorca | Powiadomienie |
|---|---|---|
| Zamówienie → confirmed | Klient | `OrderConfirmedNotification` |
| Zamówienie → cancelled | Klient | `OrderCancelledNotification` |
| Zamówienie → in_progress / completed / dowolna akcja na kaucji | Nikt | — |
| Wizyta → confirmed | Klient | Tylko SMS |
| Wizyta → cancelled | Klient | E-mail + SMS |
| Zmiana terminu wizyty | Klient | E-mail + SMS (błąd opisany wyżej) |
| Dowolna zmiana statusu wypożyczenia | Nikt | — |

## Statystyki i analityka (tylko do odczytu, widok administratora)

`Statistics` (`/admin/statystyki`) — zestawienie przychodów/liczby zamówień,
wizyt i wypożyczeń, top usług według przychodu, eksport CSV/PDF.
`AnalyticsOverview` (`/admin/analityka`) — konwersja lejka, porzucone
koszyki, źródła ruchu, jakość sesji. Oba omijają kontrolę modułową i są
tylko do odczytu, poza dwiema akcjami eksportu w `Statistics`.

## Kluczowe pliki

`app/Filament/Resources/OrderResource.php`, `app/Filament/Resources/AppointmentResource.php`,
`app/Filament/Resources/RentalResource.php`, `app/Filament/Resources/CustomerResource.php`,
`app/Services/Order/OrderService.php`, `app/Support/BaseResource.php` (kontrola modułowa).
