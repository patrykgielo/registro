# Podróż pracownika — Praca w oddziale

> **Co działa, a co jest planem** (sprawdzone w kodzie 2026-09-27):
> - **Działa:** egzemplarze (`ServiceUnit`) z numerem i oddziałem, wydanie i przyjęcie konkretnej
>   sztuki z numerem na protokole, ostrzeżenie przy zwrocie innej sztuki, status serwisowy
>   pojedynczej sztuki, rozbicie pozycji o ilości N na N wierszy zamówienia (fazy 3–6).
> - **Plan:** przypisanie pracownika do oddziału i zawężony widok (Faza 8 — brak `location_user`),
>   przesunięcia z księgą ruchów i kontrolą pokrycia (Faza 7 — brak `stock_movements`).
>   Sekcje „Przypisanie do oddziału", „Codzienna praca" (zawężenie widoku) i „Przeniesienie
>   sprzętu" opisują ten plan.
> - Plan faz: [`app/docs/features/lokalizacje/`](../features/lokalizacje/README.md).
> Opis dla klienta: [`docs/oferta/wiele-oddzialow.md`](../../../docs/oferta/wiele-oddzialow.md).

**Dla właścicieli:** pracownikowi przypisujesz oddział, w którym pracuje. Od tego momentu widzi
w panelu tylko zamówienia swojego punktu, a gdy klika zwrot — system wie, do którego oddziału
sprzęt wrócił, bez pytania go o to.

## Przypisanie do oddziału

Pracownik to `User` z rolą `staff`, zarządzany przez `EmployeeResource`. Oddziały przypisuje się
przez pivot `location_user` — pracownik może obsługiwać **więcej niż jeden** punkt, jeden z nich
oznaczony jako podstawowy.

**Dlaczego nie kolumna na `users`:** ta sama tabela trzyma klientów i super-adminów, a użytkownik
może należeć do wielu organizacji przez pivot `organization_user`. Kolumna `branch_id` złamałaby
oba te fakty.

**Dlaczego nie rola „kierownik oddziału":** role Spatie są w tym projekcie globalne
(`config/permission.php` → `'teams' => false`). Przynależność do oddziału to **przypisanie**,
nie rola.

## Codzienna praca

```mermaid
flowchart TD
    LOGIN(["Pracownik loguje się do panelu"])
    LOGIN --> SCOPE["Widzi zamówienia SWOICH oddziałów"]

    SCOPE --> LIST["Lista zamówień do obsługi"]
    LIST --> HANDOVER["Wydanie sprzętu klientowi\nstatus: confirmed → in_progress"]
    HANDOVER --> RENTED["Sprzęt u klienta.\nEgzemplarz NADAL przypisany\ndo oddziału wydania"]

    RENTED --> RETURN["Zwrot\nstatus: in_progress → completed"]
    RETURN --> AUTO["Stan oddziału wraca SAM —\ndostępność jest liczona, nie przechowywana"]

    AUTO --> WHO["Kto przyjął zwrot:\nzapisane w state_histories"]
```

## Trzy rzeczy, które warto rozumieć

### 1. Zwrot nie wymaga żadnej akcji magazynowej

Dostępność jest **liczona, a nie przechowywana**. Sprzęt przestaje blokować magazyn w chwili, gdy
status zamówienia wypada ze zbioru blokującego — nikt niczego nie dekrementuje ani nie
inkrementuje. Pracownik klika „Sprzęt zwrócony" i to wszystko.

### 2. Egzemplarz wypożyczony nie zmienia oddziału ani statusu

Przez cały czas wypożyczenia sztuka pozostaje „sprawna, przypisana do oddziału X". To nie jest
niedopatrzenie — gdyby zmieniała status, zostałaby odjęta od stanu **dwa razy**: raz jako
niedostępny egzemplarz, raz jako rezerwacja.

### 3. Kto co zrobił, zapisuje się samo

`state_histories.responsible_*` zapisuje wykonawcę każdej zmiany statusu automatycznie, z
`auth()->user()`. Nie trzeba tego nigdzie wpisywać.

## Przeniesienie sprzętu między oddziałami

Osobna, **świadoma** operacja admina — bo w realnej wypożyczalni maszyna czasem jedzie z Gdańska
do Warszawy na stałe.

| Krok | Co się dzieje |
|---|---|
| 1 | Admin wybiera egzemplarz (albo liczbę sztuk) i oddział docelowy |
| 2 | **Sprawdzenie pokrycia** — czy oddział źródłowy po zabraniu sztuki nadal obsłuży swoje przyjęte przyszłe rezerwacje |
| 3 | Jeśli nie: **odmowa** z listą kolidujących zamówień. Jeśli tak: wpis w księdze ruchu |
| 4 | Status `in_transit` — sprzęt w drodze nie jest dostępny w żadnym oddziale |
| 5 | Potwierdzenie przyjęcia w oddziale docelowym |

Krok 2 broni przed najcichszym błędem, jaki ten model dopuszcza: jedyna sztuka w Gdańsku,
opłacone zamówienie na przyszły tydzień, admin przenosi ją do Warszawy — i opłacona rezerwacja
traci pokrycie **bez jednego komunikatu**.

## Serwis pojedynczej sztuki

Ustawienie egzemplarzowi statusu `maintenance` zdejmuje **jedną** sztukę z dostępności oddziału.

Działa (`ServiceUnitStatus`, `ServiceUnitObserver`). Wcześniej jedynym wyłącznikiem było
`is_active` na **całej** usłudze.

Dziś ręczna zmiana oddziału sztuki w zakładce „Egzemplarze" jest możliwa, ale **nie sprawdza
przyszłych rezerwacji** — dokładnie ten cichy błąd, przed którym chroni planowany krok 2
przeniesienia. Lista sztuk do wydania nie jest też zawężona do oddziału odbioru zamówienia.
