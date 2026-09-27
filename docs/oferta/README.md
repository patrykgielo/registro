# Oferta Registro

Opis produktu dla klienta — do ofert, prezentacji, strony i materiałów marketingowych.
Językiem korzyści, bez techniki. Każde zdanie tutaj musi być prawdą **o kodzie na `develop`**.

Mechanika (trasy, statusy, zdarzenia) jest osobno: [`app/docs/flows/`](../../app/docs/flows/README.md).

## Strony

| Strona | O czym |
|---|---|
| [O Registro](o-registro.md) | Czym jest, dla kogo, dokąd zmierza |
| [Jak wygląda współpraca](model-wspolpracy.md) | Wdrożenie, dostęp, wsparcie, demo, start |
| [Wypożyczalnia online](wypozyczalnia.md) | Katalog, kalendarz, koszyk, zamówienie, płatności, kaucja, protokoły |
| [Rezerwacja terminów](rezerwacje-terminow.md) | Kreator rezerwacji, grafiki, dojazd, przypomnienia, SMS |
| [Wiele oddziałów](wiele-oddzialow.md) | Wybór oddziału, dostępność per punkt, egzemplarze |
| [Panel, strona www i statystyki](panel-i-strona.md) | Strona, panel, analityka, RODO |

## Katalog funkcji — jedyne źródło statusu

Stan na 2026-09-27. **Dostępne** = działa na `develop`. **Przy wdrożeniu** = działa, włączamy
i konfigurujemy dla klienta (moduł, konto zewnętrzne, treści). **W przygotowaniu** = zatwierdzony
plan, jeszcze bez kodu — w ofercie tylko jako kierunek, nigdy jako obietnica terminu.

| Obszar | Funkcja | Status |
|---|---|---|
| Wypożyczalnia | Katalog z kategoriami, strona sprzętu z kalendarzem dostępności | Dostępne |
| | Ceny: za dzień, próg dni, tydzień; „cena do ustalenia" z zapytaniem e-mail | Dostępne |
| | Koszyk z ilością, ochrona przed sprzedaniem ponad stan | Dostępne |
| | Zamówienie B2C i B2B, zgody z datą, PESEL opcjonalny | Dostępne |
| | Płatność online Przelewy24 | Przy wdrożeniu (dane P24 klienta) |
| | Płatność przy odbiorze z automatycznym zwolnieniem nieopłaconej rezerwacji | Dostępne |
| | Kaucja: wyliczenie i ewidencja (pobrana / zwrócona / zatrzymana) | Dostępne |
| | Wydanie i zwrot, e-maile do klienta na każdym etapie | Dostępne |
| | Protokoły wydania i zwrotu PDF z numerami egzemplarzy | Dostępne |
| | Przypomnienie o zwrocie (dzień przed, po terminie) | Dostępne |
| | Wnioski o przedłużenie najmu | Dostępne (włączane w ustawieniach) |
| Rezerwacje | Kreator rezerwacji terminu (detailing, usługi) | Dostępne |
| | Godziny, odstęp terminów, wyprzedzenie, zasady odwołania | Dostępne |
| | Grafiki pracowników, wyjątki, urlopy | Przy wdrożeniu (moduł) |
| | Dojazd do klienta i obszar obsługi | Przy wdrożeniu (moduł) |
| | Przypomnienia e-mail/SMS, powiadomienia SMS | Przy wdrożeniu (moduł, bramka SMS, treści) |
| | Dodanie wizyty do kalendarza klienta | Dostępne |
| | Codzienne podsumowanie rezerwacji dla właściciela | Dostępne |
| Oddziały | Oddział z adresem, godzinami, galerią, mapą; dane dla Google | Dostępne |
| | Wybór oddziału przez klienta, dostępność per oddział, „dostępne też w…" | Dostępne (od 2 oddziałów) |
| | Jeden oddział odbioru na zamówienie, adres na protokołach i w e-mailach | Dostępne |
| | Stan per oddział, egzemplarze z numerem, serwis pojedynczej sztuki | Dostępne |
| | Przesunięcia sprzętu między oddziałami | W przygotowaniu |
| | Pracownik przypisany do oddziału | W przygotowaniu |
| | Statystyki per oddział | W przygotowaniu |
| Strona i panel | Strona z blokami, menu, aktualności, promocje, portfolio | Dostępne |
| | SEO: tytuły, opisy, mapa strony, podgląd w social media | Dostępne |
| | Google Tag Manager | Dostępne |
| | Startowa strona i przykładowa oferta dla branży | Przy wdrożeniu |
| | Pulpit, statystyki sprzedaży z eksportem CSV/PDF | Dostępne |
| | Analityka ruchu: źródła, UTM, lejek, porzucone koszyki | Dostępne |
| | Użytkownicy panelu, historia zmian | Dostępne |
| | Baza klientów, edycja treści e-mail/SMS, historia wysyłek | Przy wdrożeniu (moduł) |
| | Własna domena | Przy wdrożeniu (ustalane indywidualnie) |
| Konto klienta | Konto, historia zamówień i wizyt, pobranie protokołów | Dostępne |
| | Eksport i usunięcie własnych danych (RODO) | Dostępne |

## Czego nie obiecywać

Rzeczy, które łatwo „dopowiedzieć" w ofercie, a których **nie ma w kodzie**. Sprawdzone
2026-09-27. Zanim coś stąd trafi do oferty, musi najpierw powstać.

- **Faktury VAT** — system zbiera dane do faktury, ale jej nie wystawia. Brak integracji
  z KSeF, Fakturownią czy innym systemem księgowym.
- **Zwroty pieniędzy w systemie** — zwrot robi się poza Registro.
- **Kaucja pobierana lub blokowana online** — kaucję pobiera się na miejscu.
- **Pobieranie danych firmy z GUS po NIP** — dane wpisuje klient.
- **Zakup lub rezerwacja bez konta** — wymagane konto klienta.
- **Rozliczanie najmu godzinowego** — stawka godzinowa jest tylko wyświetlana.
- **Protokoły wysyłane e-mailem** — są do pobrania z konta i z panelu.
- **Automatyczne potwierdzanie zamówień i wizyt** — potwierdza obsługa.
- **E-mail do właściciela o każdym nowym zamówieniu lub rezerwacji** — jest e-mail
  o zamówieniu opłaconym i codzienne podsumowanie rezerwacji.
- **Wybór pracownika przez klienta** przy rezerwacji — system przydziela sam.
- **Ceny zależne od rozmiaru auta** — cena usługi jest jedna.
- **Samodzielna zmiana koloru marki i czcionek przez właściciela**, własny menedżer menu
  z linkami zewnętrznymi, edycja ról i uprawnień.
- **Samodzielne założenie konta firmy przez internet, pakiety cenowe, okres próbny.**
- **Ceny, referencje klientów, statystyki skuteczności** — nie wymyślamy. Tylko prawdziwe,
  zatwierdzone przez właściciela produktu.

## Jak utrzymywać

1. **Ten katalog to jedyne miejsce ze statusem.** Strony funkcji nie mają faz, numerów PR
   ani dat — opisują, co klient dostaje.
2. **Zmiana widoczna dla klienta = aktualizacja tutaj** w tym samym PR: status w katalogu
   i opis na stronie funkcji. Hook `stop-gate.sh` pyta o to przy każdej zmianie widoków,
   tras, powiadomień i panelu.
3. **Nowa funkcja z planu** trafia do katalogu jako „W przygotowaniu" dopiero, gdy plan
   jest zatwierdzony; na „Dostępne" — gdy jest na `develop`.
4. **Język:** „Ty" do właściciela firmy, „Twój klient" dla klienta końcowego. Bez nazw klas,
   tras, pól bazy, statusów w kodzie. Ograniczenie opisuj jako krok do wykonania
   („dopisz oddział do strony"), nie jako defekt.
5. **Nie rejestr usterek.** Błędy i braki → ClickUp. Tutaj trafia dopiero to, co działa.
6. **Tylko po polsku.** Wersję angielską generujemy z gotowych stron, gdy będzie potrzebna.
7. **Nowa strona** = wpis w tabeli „Strony" powyżej i w `nav` w `docs-site/mkdocs.yml`.
8. **Przegląd przed każdym release'em** — porównaj zmergowane PR-y od ostatniej aktualizacji
   katalogu z jego treścią (`git log --since=<data stanu> --first-parent develop`).
