# Lokalizacje (oddziały) — fazy 0-2

**Data:** 2026-08-30
**Zakres:** PR [#227](https://github.com/patrykgielo/registro/pull/227),
[#228](https://github.com/patrykgielo/registro/pull/228),
[#229](https://github.com/patrykgielo/registro/pull/229),
[#230](https://github.com/patrykgielo/registro/pull/230),
[#231](https://github.com/patrykgielo/registro/pull/231),
[#234](https://github.com/patrykgielo/registro/pull/234)

## Gdzie ten kod jest

| Środowisko | Stan |
|---|---|
| `develop` | **tak** |
| `staging` | **nie** |
| UAT (`registrolabs.com`) | **nie** — chodzi z `v0.13.0-rc25`, sprzed tej pracy |
| Produkcja | maszyna PreProd niekupiona |

Klient tego jeszcze nie widzi. Wejdzie na UAT dopiero przy najbliższym tagu `rc*`
ciętym ze `staging`.

---

## Co to zmienia dla właściciela wypożyczalni

**Oddział przestał być wierszem w ustawieniach, a stał się osobnym bytem.** W panelu jest
zakładka **Ustawienia → Lokalizacje**, gdzie opisuje się każdy punkt firmy: nazwę, symbol,
adres, godziny otwarcia, telefon, e-mail, opis, zdjęcie siedziby, galerię i pinezkę na mapie.

Te dane pokazują się klientowi jako **karty na stronie** — przez istniejący blok „Siatka
treści", więc nie trzeba nowego szablonu ani programisty.

**Pierwszy dodany oddział automatycznie staje się głównym**, a ostatniego nie da się usunąć —
firma zawsze ma co najmniej jedną siedzibę i pilnuje tego baza, nie konwencja.

Właściciel z **jedną** siedzibą nie widzi żadnej zmiany w codziennej pracy. Pole „Ilość
w magazynie" zostaje tam, gdzie było, i działa tak jak działało.

---

## Naprawiony przy okazji błąd, który kosztował pieniądze

Faza 0 naprawiła **realny oversell w koszyku**, znaleziony podczas przeglądu, nie zgłoszony
przez klienta.

Klient mógł zamówić więcej sztuk sprzętu, niż firma posiada — wystarczyło dodać ten sam
sprzęt do koszyka w kilku pozycjach na nachodzące się terminy. Walidacja sprawdzała każdą
pozycję **osobno**, nigdy nie sumując zapotrzebowania z pozostałych pozycji tego samego
koszyka. Przy jednej dostępnej sztuce dwie pozycje po jednej sztuce przechodziły obie.

To nie był problem współbieżności ani wyścigu — działo się przy jednym kliencie klikającym
spokojnie. Zgłoszenie [`86cb93tfw`](https://app.clickup.com/t/86cb93tfw) opisywało dokładnie
ten objaw.

---

## Co jest pod spodem, a czego jeszcze nie widać

Faza 2 dołożyła **stan magazynowy per oddział** (`service_location_stocks`) i przepięła
`quantity_total` na wartość wyliczaną z sumy stanów.

**Dostępność celowo pozostała nietknięta.** Kalendarz, katalog i checkout liczą ją tak samo
jak przed tą zmianą — dokładnie tak samo, co jest pinowane testami. Rozbicie dostępności na
oddziały to Faza 4, a wybór oddziału przez klienta Faza 5.

Powód takiego cięcia: dostępność ma dziś jedno wejście i dziewięć wywołań, w tym jedno łatwe
do przeoczenia (przedłużenie wypożyczenia). Przepięcie jej razem z wprowadzeniem magazynu
oznaczałoby jedną zmianę, której nie da się wycofać po kawałku.

---

## Czego to jeszcze NIE robi

- Klient **nie wybiera** oddziału — nie ma przełącznika, katalog jest wspólny.
- Dostępność **nie jest** rozbita na oddziały.
- Sprzęt **nie jest** przypisany do punktu; nie ma numerów seryjnych ani przesunięć.
- Pracownik **nie jest** przypisywany do oddziału.
- Statystyki **nie znają** wymiaru oddziału.

Fazy 3-9 są **wstrzymane** decyzją właściciela produktu do czasu zweryfikowania testów
pół-automatycznych panelu i frontu. To świadoma bramka, nie brak czasu.

---

## Co może zaskoczyć

**Dodanie oddziału nie umieszcza go na stronie.** Blok „Siatka treści" trzyma ręcznie wybraną
listę i nie ma trybu „wszystkie". Po dodaniu oddziału trzeba wejść w stronę CMS i dopisać go
do bloku. Nic o tym nie przypomina.
Zgłoszone: [`123k99ct3xt`](https://app.clickup.com/t/123k99ct3xt).

**Wyłączenie oddziału nie zdejmuje go ze strony.** Odznaczenie „Aktywna" usuwa go z listy
wyboru w panelu, ale jeśli był już w bloku — nadal się renderuje.

**Oddział nie ma własnego adresu URL.** Istnieje wyłącznie jako karta w siatce.

**Adres firmy jest w dwóch miejscach.** Adres na protokołach i w e-mailach pochodzi
z Ustawień, nie z encji oddziału. Zmiana w Lokalizacjach go nie zmieni.
Zgłoszone: [`123k99ct3j0`](https://app.clickup.com/t/123k99ct3j0).

---

## Migracje

Cztery, wszystkie z działającym `down()` zweryfikowanym **wykonanym** rollbackiem, nie
statycznym sprawdzeniem.

> **Zastrzeżenie dopisane 2026-08-30, po tym jak bramka MySQL to ujawniła:** ta weryfikacja
> biegła na SQLite, który **nie egzekwuje kluczy obcych** przy `DROP TABLE`. Na MySQL
> `locations` jest referencjonowana przez `service_location_stocks`, więc kolejność ma
> znaczenie. Realny `migrate:rollback` jest bezpieczny — Laravel cofa migracje w kolejności
> `batch desc, migration desc` (`DatabaseMigrationRepository.php:65-67`), a
> `2026_08_28_090000` (dziecko) sortuje się przed `2026_08_27_120000` (rodzic), więc FK znika
> pierwszy. **Ale rollback celowany w pojedynczą migrację** (`migrate:rollback --path=...`
> na samych `locations`) **na MySQL padnie** z błędem 3730. Nie rób tego; używaj `--step`.

| Migracja | Odwracalność |
|---|---|
| `create_locations_table` | pełna |
| `backfill_primary_location_for_organizations` | `down()` to **świadomy no-op** — usunięcie oddziałów utworzonych z danych tenanta skasowałoby dane, których migracja nie stworzyła |
| `create_service_location_stocks_table` | pełna |
| `backfill_service_location_stocks` | pełna |

Oddział główny każdego tenanta powstaje **z danych, które tenant już ma**
(`SettingsManager::contactDetailsFor()`), więc dzień po wdrożeniu adres jest poprawny bez
wpisywania czegokolwiek.

**Wymagany krok operatora:** `php artisan migrate`. Migracje nie uruchamiają się same.

---

## Weryfikacja

- Suite: 1474 → 1593 testów w trakcie tych faz, bez regresji na żadnym kroku.
- Każda faza sprawdzona **ręcznie w przeglądarce** — panel i front osobno — nie tylko testami.
- Oversell z Fazy 0 pinowany testem odtwarzającym scenariusz ze zgłoszenia.

## Dokumentacja

- Techniczna: [`app/docs/features/lokalizacje/`](../../app/docs/features/lokalizacje/README.md)
- Biznesowa: [`docs/business/customer-journey-locations.md`](../business/customer-journey-locations.md),
  [`staff-journey-locations.md`](../business/staff-journey-locations.md)
