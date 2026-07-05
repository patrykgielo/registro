# Twoja Analityka — Przewodnik dla Administratorów

> **Dla kogo:** Właściciele firm i administratorzy panelu Registro
> **Poziom techniczny:** Brak wymagań — ten przewodnik jest napisany prostym językiem
> **Ostatnia aktualizacja:** 2026-06-16

---

## Co daje Ci analityka?

Panel analityczny Registro odpowiada na dwa podstawowe pytania:

1. **Ile zarabiasz?** → zakładka **Statystyki**
2. **Skąd przychodzą Twoi klienci i co robią na stronie?** → zakładka **Analityka**

Razem dają Ci wiedzę potrzebną do podejmowania lepszych decyzji — bez zgadywania.

---

## Gdzie znaleźć dane?

### W panelu administracyjnym (`/admin`) masz dostęp do czterech miejsc:

| Gdzie | Co znajdziesz |
|-------|--------------|
| **Główny pulpit** (strona startowa po zalogowaniu) | Szybki przegląd przychodów za bieżący miesiąc |
| **Raporty → Statystyki** | Szczegółowe dane finansowe z możliwością eksportu |
| **Raporty → Analityka** | Ruch na stronie, lejek konwersji, porzucenia koszyka, jakość sesji |

---

## Pulpit główny — szybki przegląd

Po zalogowaniu do panelu admin widzisz dwa bloki:

### Blok "Podsumowanie miesiąca"

Pokazuje dane za **bieżący miesiąc kalendarzowy**:

- **Łączny przychód** — suma pieniędzy z wszystkich zamówień, wizyt i wypożyczeń
- **Zamówienia** — liczba i wartość złożonych zamówień
- **Wizyty** *(jeśli masz aktywowany moduł bookingów)* — liczba i wartość umówionych wizyt
- **Wypożyczenia** *(jeśli masz aktywowany moduł wynajmu)* — liczba i wartość wypożyczeń

> **Wskazówka:** Dane za bieżący dzień mogą być opóźnione nawet o godzinę — system przelicza je co godzinę. Dane z poprzednich dni są zawsze dokładne.

### Wykres "Przychód — ostatnie 30 dni"

Linia na wykresie pokazuje **łączny dzienny przychód** przez ostatnie 30 dni. Jeśli linia idzie w górę — zarabiasz coraz więcej. Jeśli masz charakterystyczne szczyty w weekendy lub w konkretne dni tygodnia — to ważna wskazówka o zachowaniach Twoich klientów.

---

## Raporty → Statystyki

To jest Twój główny raport finansowy. Znajdziesz go w menu bocznym panelu: **Raporty → Statystyki**.

### Jak wybrać okres?

Na górze strony możesz wybrać przedział czasu:

| Opcja | Oznacza |
|-------|---------|
| Dziś | Tylko dzisiejsze transakcje |
| Ten tydzień | Od poniedziałku do dziś |
| Ten miesiąc | Od 1. do dzisiaj |
| Ten rok | Cały rok kalendarzowy |
| Poprzedni miesiąc | Zamknięty poprzedni miesiąc |
| Poprzedni rok | Zamknięty poprzedni rok |

### Co pokazują karty KPI?

Cztery karty u góry to Twoje najważniejsze liczby:

**Łączny przychód** — suma wszystkich przychodów w wybranym okresie. To jest kwota, która faktycznie trafiła lub trafi do Ciebie (zamówienia opłacone + potwierdzone wizyty + aktywne wypożyczenia).

> **Ważne:** Kaucja za wypożyczenia **nie jest wliczona** w przychód — to zwrotny depozyt od klienta, nie Twój zarobek.

**Zamówienia** — każde opłacone zamówienie z Twojego sklepu internetowego. Liczba to ilość zamówień, kwota to ich łączna wartość.

**Wizyty** *(jeśli moduł aktywny)* — umówione i zrealizowane wizyty.

**Wypożyczenia** *(jeśli moduł aktywny)* — potwierdzone, trwające i zakończone wypożyczenia.

### Wykres 30-dniowy

Nawet jeśli wybrałeś okres "Ten rok", wykres pokazuje zawsze **ostatnie 30 dni** — to celowe, bo dłuższe okresy byłyby nieczytelne jako wykres dzienny. Trzy serie:

- **Zamówienia** — dzienna wartość zamówień
- **Wizyty** — dzienna wartość wizyt
- **Wypożyczenia** — dzienna wartość wypożyczeń

Gdy jedna seria gwałtownie rośnie lub spada — warto sprawdzić, co się działo w firmie w tamtym czasie.

### Tabela "Najpopularniejsze usługi"

Lista 10 usług, które przyniosły Ci największy przychód w wybranym okresie. Pomaga odpowiedzieć na pytanie: **co tak naprawdę sprzedaje się najlepiej?**

### Eksport danych

Dwa przyciski w prawym górnym rogu:

- **Pobierz CSV** — plik z danymi do Excela (separator: średnik, kodowanie: UTF-8). Zawiera podsumowanie i dzienny rozkład.
- **Pobierz PDF** — raport do wydrukowania lub wysłania mailem. Zawiera tabele (bez wykresów).

---

## Raporty → Analityka

To jest sekcja **ruchu i zachowań odwiedzających** — pokazuje, co dzieje się *przed* tym, zanim klient złoży zamówienie. Znajdziesz ją w: **Raporty → Analityka**.

### Co to jest "sesja"?

Sesja to jedna wizyta na Twojej stronie. Gdy ktoś otwiera stronę i przegląda ją przez kilkanaście minut — to jest jedna sesja. Gdy zamknie przeglądarkę i wróci następnego dnia — to już nowa sesja.

---

## Filtry — jak zawęzić widok danych

W górnej części strony Analityki znajduje się **pasek filtrów**, który pozwala Ci skupić się na dokładnie tych danych, które Cię interesują. Wszystkie wybrane filtry zostają zapamiętane w adresie URL — możesz wysłać link innemu użytkownikowi i zobaczy dokładnie ten sam widok.

### Filtr okresu

Pięć przycisków w pierwszym rzędzie:

| Opcja | Co oznacza |
|-------|-----------|
| **Dziś** | Tylko dane z bieżącego dnia |
| **Ten tydzień** | Od poniedziałku do dziś |
| **Ten miesiąc** | Od 1. do dziś |
| **Poprzedni miesiąc** | Kompletny zamknięty miesiąc — idealny do porównań |
| **Własny zakres** | Dowolny przedział dat — wybierz "Od" i "Do" z kalendarza |

> **Wskazówka:** "Poprzedni miesiąc" daje najdokładniejsze dane — miesiąc jest zamknięty i już się nie zmieni.

### Filtr urządzenia

Trzy chipsiki pod przyciskami okresu:

| Chip | Oznacza |
|------|---------|
| **Komputer** | Desktopy i laptopy |
| **Telefon** | Smartfony |
| **Tablet** | Tablety |

Możesz wybrać **kilka urządzeń jednocześnie** — kliknij kolejne, aby je dodać. Kliknij ponownie, żeby usunąć. Gdy żaden nie jest wybrany, widzisz dane ze wszystkich urządzeń.

**Przykładowe zastosowanie:** Kliknij "Telefon" i obserwuj, jak zmieniają się wskaźniki — jeśli bounce rate na telefonie jest znacznie wyższy niż na komputerze, strona może być nieprzyjazna dla mobilnych klientów.

### Filtr źródła UTM

Jeśli prowadzisz kampanie reklamowe oznaczone parametrami UTM (np. z Google Ads, Facebook Ads, mailingów), w pasku filtrów pojawią się chipsiki z nazwami tych źródeł. Kliknij, żeby zobaczyć dane tylko dla odwiedzających z danej kampanii.

**Co to jest UTM?** To specjalny kod, który dodajesz do linku w reklamie, np. `?utm_source=facebook`. Dzięki temu system wie, że dany odwiedzający przyszedł właśnie z Facebooka.

### Aktywne filtry

Gdy masz aktywne filtry, zobaczysz je jako kolorowe chipsiki poniżej paska:
- **Żółty chipsiik** — aktywny filtr dat (pokazuje wybrany zakres)
- **Niebieski chipsiik** — aktywny filtr urządzenia
- **Zielony chipsiik** — aktywny filtr UTM

Każdy chipsik ma przycisk "×" — kliknij, żeby usunąć tylko ten filtr. Przycisk **Resetuj filtry** (w prawym górnym rogu paska) usuwa wszystkie filtry naraz.

---

## Karty KPI — liczby na pierwszy rzut oka

Cztery karty u góry Analityki:

| Karta | Co oznacza | Jak interpretować |
|-------|-----------|------------------|
| **Odsłony stron** | Ile razy łącznie wyświetlono jakąkolwiek stronę | Ogólny poziom aktywności na stronie |
| **Unikalne sesje** | Ile osobnych wizyt było | Liczba "wejść" na stronę |
| **Zalogowani użytkownicy** | Ile sesji należało do klientów z kontem | Stosunek do unikalnych sesji pokazuje, ile osób wraca po zalogowaniu |
| **Śr. zdarzeń/sesja** | Ile akcji wykonuje przeciętny odwiedzający | Wyższe = bardziej zaangażowani odwiedzający |

---

## Wykres dziennych odsłon

Linia pokazuje dzienny ruch (odsłony stron). Pozwala zobaczyć:
- W które dni tygodnia masz największy ruch?
- Czy kampanie reklamowe przyniosły wzrost odwiedzin?
- Czy sezonowość wpływa na ruch?

---

## Urządzenia

Podział na: **Komputer**, **Telefon**, **Tablet** — z procentami i liczbami bezwzględnymi.

> **Ważne:** Ten widget zawsze pokazuje pełny rozkład urządzeń, nawet gdy używasz filtra urządzeń. Dzięki temu nie tracisz kontekstu — filtr w pasku powyżej zawęża pozostałe dane, ale tutaj widzisz "cały tort".

W Polsce ponad 60% ruchu pochodzi z urządzeń mobilnych. Jeśli Twoja strona wygląda słabo na telefonie — tracisz klientów.

---

## Typy stron

Pokazuje, które typy stron odwiedzają użytkownicy najczęściej:

| Typ strony | Co to jest |
|-----------|-----------|
| homepage | Strona główna |
| service | Strona konkretnej usługi lub sprzętu |
| booking | Formularz rezerwacji |
| cart | Koszyk |
| checkout | Strona płatności |
| confirmation | Potwierdzenie zamówienia |

**Jak to czytać?** Jeśli dużo osób trafia na stronę usługi, ale mało z nich przechodzi do koszyka — strona usługi może być niewystarczająco przekonująca.

---

## Głębokość przewijania

Pokazuje, jak daleko użytkownicy scrollują Twoje strony:

| Milestone | Oznacza |
|----------|---------|
| 25% | Zobaczyli tylko górną część strony |
| 50% | Przewinęli do połowy |
| 75% | Przeczytali większość |
| 90% | Prawie do końca |
| 100% | Dotarli do samego dołu |

Jeśli tylko 20% użytkowników dociera do 50% strony — Twoje kluczowe informacje mogą być zbyt nisko.

---

## Najpopularniejsze strony

Lista 10 stron z największą liczbą odwiedzin i unikalnych sesji. Pomaga odpowiedzieć na pytanie: **Co przyciąga ludzi na Twoją stronę?**

---

## Lejek konwersji

To jeden z najważniejszych raportów — pokazuje, ile procent odwiedzających przechodzi przez kolejne etapy ścieżki zakupowej.

### Pięć etapów lejka

```
Wyświetlenia → Produkty → Do koszyka → Koszyk → Checkout
     100%         ?%          ?%           ?%         ?%
```

| Etap | Co oznacza |
|------|-----------|
| **Wyświetlenia** | Odwiedzający, którzy zobaczyli jakąkolwiek stronę — 100% (punkt odniesienia) |
| **Produkty** | Odwiedzający, którzy weszli na stronę konkretnej usługi/produktu |
| **Do koszyka** | Odwiedzający, którzy kliknęli "Dodaj do koszyka" |
| **Koszyk** | Odwiedzający, którzy zobaczyli stronę koszyka `/koszyk` |
| **Checkout** | Odwiedzający, którzy zaczęli wypełniać formularz zamówienia |

### Jak interpretować lejek?

**Scenariusz dobry:** 100% → 40% → 25% → 20% → 15%
Każdy etap traci rozsądny procent — to naturalne.

**Scenariusz niepokojący:** 100% → 35% → 5% → 5% → 4%
Ogromny spadek między "Produkty" a "Do koszyka" — coś zniechęca do dodania do koszyka. Może za wysoka cena? Brak informacji o dostępności?

**Scenariusz do zbadania:** 100% → 40% → 30% → 5% → 4%
Dużo osób ogląda produkty i dodaje do koszyka, ale mało otwiera koszyk. Może koszyk jest trudno znaleźć?

> **Wskazówka:** Użyj filtru urządzeń, żeby porównać lejek na telefonach vs komputerach. Duże różnice mogą wskazywać na problemy z UX mobilnym.

---

## Porzucenia koszyka

Ten panel odpowiada na pytanie: **ilu klientów zaczęło zakup, ale go nie ukończyło?**

### Trzy liczby

| Liczba | Oznacza |
|--------|---------|
| **Do koszyka** | Ile razy klienci kliknęli "Dodaj do koszyka" |
| **Koszyk** | Ile razy klienci otwarli stronę koszyka |
| **Porzucone** | Ile razy klienci zaczęli wypełniać formularz, ale go nie ukończyli |

### Porzucone pola (jeśli dostępne)

Poniżej liczb zobaczysz listę pól formularza, które klienci najczęściej porzucają — czyli na którym polu formularz jest najczęściej opuszczany.

**Przykład:** Jeśli "NIP" pojawia się często na liście porzuceń — formularz B2B może być zbyt skomplikowany lub klienci nie mają pod ręką numeru NIP podczas zakupu.

---

## Źródła ruchu

Ten widget pokazuje, **skąd przychodzą Twoi odwiedzający** według oznaczenia UTM:

| Źródło | Przykład skąd |
|--------|--------------|
| `google` | Reklamy Google Ads lub organiczne wyniki wyszukiwania (z UTM) |
| `facebook` | Reklamy Facebook/Instagram |
| `newsletter` | E-mail marketing |
| `direct` | Bezpośrednie wejście (wpisanie adresu, zakładki, brak UTM) |

Każde źródło pokazuje liczbę sesji i procent całego ruchu.

**Jak to czytać?** Jeśli 80% ruchu to "direct", a reklamy dają tylko 5% — kampanie reklamowe mogą być nieskuteczne lub nie mają prawidłowo ustawionych UTM.

> **Ważne:** Jeśli nie używasz parametrów UTM w linkach reklamowych, cały ruch z reklam pojawi się jako "direct" — nie zobaczysz, które kampanie działają. Skontaktuj się z opiekunem Registro, aby pomóc w prawidłowej konfiguracji.

---

## Jakość sesji

Cztery metryki, które razem opisują, jak bardzo zaangażowani są Twoi odwiedzający:

| Metryka | Co oznacza | Dobry wynik |
|---------|-----------|------------|
| **Bounce rate** | Procent osób, które weszły i od razu wyszły (jedna odsłona) | Poniżej 60% |
| **Zdarzeń/sesja** | Średnia liczba akcji na wizytę | Im wyższe, tym lepiej |
| **Rage clicks** | Liczba "kliknięć z frustracji" — kilka kliknięć w to samo miejsce w krótkim czasie | Jak najniższe |
| **Śr. czas** | Średni czas aktywnego przeglądania strony (bez czasu gdy zakładka jest nieaktywna) | Zależy od treści |

### Jak interpretować rage clicks?

Wysoka liczba rage clicks oznacza, że odwiedzający klikają w coś, co wygląda jak przycisk, ale nie reaguje — lub czekają na ładowanie i klikają wielokrotnie ze zniecierpliwienia. To konkretny sygnał, że coś na stronie nie działa tak, jak użytkownicy się spodziewają.

---

## Jak używać filtrów razem z widgetami

Filtry w pasku (urządzenie, UTM, data) wpływają na **wszystkie widgety jednocześnie** — poza panelem Urządzeń, który zawsze pokazuje pełny rozkład.

### Przykładowe analizy z filtrami

**Analiza kampanii Facebook:**
1. Wybierz filtr UTM: `facebook`
2. Sprawdź lejek konwersji — czy osoby z Facebooka kupują?
3. Sprawdź bounce rate w Jakości sesji — czy strona docelowa jest odpowiednia?
4. Porównaj z `google` — które źródło daje lepszą jakość ruchu?

**Analiza mobilna:**
1. Wybierz filtr urządzenia: Telefon
2. Sprawdź bounce rate — jeśli wyższy niż na komputerze, strona jest nieprzyjazna mobilnie
3. Sprawdź lejek — na którym etapie mobilni użytkownicy odpuszczają?
4. Sprawdź porzucone pola — które pola formularza sprawiają problem na telefonie?

**Porównanie miesięcy:**
1. Wybierz "Poprzedni miesiąc", zanotuj kluczowe liczby
2. Przełącz na "Ten miesiąc"
3. Porównaj odsłony, sesje i lejek — czy rośniesz?

---

## Jak czytać dane razem?

### Przykład 1: Sprawdzasz skuteczność akcji reklamowej

1. Otwórz **Raporty → Analityka**, wybierz "Ten tydzień"
2. Sprawdź wykres dzienny — czy w dniu startu kampanii wzrósł ruch?
3. Jeśli używasz UTM: włącz filtr UTM dla swojej kampanii i sprawdź Lejek konwersji
4. Sprawdź Źródła ruchu — jaki procent odwiedzin pochodzi z kampanii?
5. Przejdź do **Raporty → Statystyki** — czy wzrost ruchu przełożył się na więcej zamówień?

### Przykład 2: Diagnozujesz porzucone koszyki

1. W **Raporty → Analityka** sprawdź widget **Lejek konwersji**
2. Na którym etapie jest największy spadek? "Do koszyka" → "Koszyk"? Albo "Koszyk" → "Checkout"?
3. Sprawdź **Porzucenia koszyka** — na jakim polu formularza klienci najczęściej rezygnują?
4. Włącz filtr Telefon — czy porzucenia są częstsze na mobile?

### Przykład 3: Który miesiąc był najlepszy?

1. Idź do **Raporty → Statystyki**
2. Przełącz między "Poprzedni miesiąc" a "Ten miesiąc"
3. Porównaj łączny przychód i liczbę zamówień
4. Pobierz oba jako CSV, aby zestawić w Excelu

### Przykład 4: Dlaczego ruch jest wysoki, ale sprzedaż niska?

1. W Analityce sprawdź Lejek konwersji — na którym etapie uciekają odwiedzający?
2. Jeśli dużo jest "Wyświetleń" i "Produktów", ale mało "Do koszyka" — strony usług nie przekonują
3. Sprawdź Głębokość scrollowania — czy odwiedzający w ogóle docierają do przycisku "Dodaj do koszyka"?
4. Sprawdź Jakość sesji — wysoki bounce rate może oznaczać, że reklamy przyciągają złą grupę docelową

---

## Jak zbieramy dane — co jest, a czego nie ma

### Czego NIE robimy

- **Nie instalujemy plików cookie** — Twoi klienci nie zobaczą żadnego baneru "Akceptuj pliki cookie" związanego z naszą analityką
- **Nie śledzimy między stronami** — dane dotyczą tylko Twojej strony, bez połączenia z innymi serwisami
- **Nie przechowujemy adresów IP** klientów — zbieramy jedynie zaszyfrowaną, anonimową sumę kontrolną
- **Nie sprzedajemy danych** żadnym podmiotom trzecim
- **Nie tworzymy profili osobowych** klientów

### Co zbieramy i dlaczego

| Co zbieramy | Po co | Jak długo |
|-------------|-------|----------|
| Jaką stronę odwiedzono | Aby wiedzieć, które strony działają najlepiej | 13 miesięcy |
| Jakiego urządzenia użyto | Aby optymalizować stronę pod telefony/komputery | 13 miesięcy |
| Skąd przyszedł odwiedzający (UTM) | Aby wiedzieć, które kanały marketingowe działają | 13 miesięcy |
| Czy ktoś zaczął, ale nie ukończył zakupu | Aby poprawić ścieżkę zakupową | 13 miesięcy |
| Czy zamówienie zostało złożone i opłacone | Aby połączyć ruch z przychodem | Bezterminowo (jako zamówienie) |
| Anonimowy identyfikator przeglądarki | Aby rozróżnić powracające wizyty z tej samej przeglądarki | 13 miesięcy |

### Kiedy dane mogą być niekompletne?

Poniższe sytuacje powodują, że liczba odwiedzin może być **niższa niż rzeczywista**:

- Użytkownik ma zainstalowaną wtyczkę blokującą reklamy (np. uBlock Origin) — szacunkowo dotyczy 15% użytkowników
- Użytkownik włączył opcję "Nie śledź" w przeglądarce — nasz tracker to respektuje i nie zbiera żadnych danych
- Wolne łącze internetowe może uniemożliwić wysłanie danych analitycznych

> **To jest zamierzone** — wolimy zbierać nieco mniej danych, ale dbać o prywatność Twoich klientów.

---

## Najczęściej zadawane pytania

**Dlaczego liczba zamówień w "Statystykach" nie pasuje do zakładki "Zamówienia"?**

Statystyki liczą tylko zamówienia o statusie "opłacone". W zakładce Zamówienia widzisz wszystkie zamówienia, łącznie z oczekującymi i anulowanymi.

**Dlaczego dane z dzisiaj wyglądają inaczej wieczorem niż rano?**

Dane dzienne są przeliczane co godzinę. Zamówienie złożone o 9:00 może pojawić się w statystykach między 9:00 a 10:00.

**Jak daleko wstecz sięgają dane analityczne?**

Śledzenie zachowań odwiedzających (Analytics) zostało uruchomione w maju 2026. Dane finansowe (Statystyki) dostępne są od uruchomienia Twojego panelu.

**Czy moi pracownicy wliczają się w statystyki ruchu?**

Tak — jeśli Ty lub Twoi pracownicy odwiedzacie stronę sklepu, te odwiedziny będą widoczne w analityce. Nie ma obecnie filtra wykluczającego ruch wewnętrzny. Jeśli logujesz się do panelu admin, Twoje sesje na stronie frontowej nadal są liczone.

**Czy klient, który kupował wielokrotnie, liczy się jako jedna czy wiele sesji?**

Jako wiele sesji — każda wizyta to oddzielna sesja. Jeśli klient jest zalogowany, możemy go rozpoznać (kolumna "Zalogowani użytkownicy"), ale i tak każda wizyta to osobna sesja.

**Czy mogę zobaczyć, kto konkretnie odwiedził stronę?**

Nie — i to jest zamierzone. Anonimizujemy dane, aby chronić prywatność Twoich klientów (wymagania RODO). Możesz zobaczyć, ile osób odwiedziło stronę, ale nie kto konkretnie.

**Co to jest "bounce rate"?**

Procent osób, które weszły na stronę i od razu wyszły bez wykonania żadnej akcji (scrollowania, kliknięcia, przejścia do innej podstrony). Wysoki bounce rate na stronie głównej może oznaczać, że strona nie przykuwa uwagi. Wysoki na stronie produktu — że coś odstraszało od zakupu.

**Dlaczego lejek zaczyna się od 100%?**

Każda liczba w lejku jest pokazywana jako procent sesji, które zobaczyły *jakąkolwiek* stronę. To pozwala szybko zobaczyć, ile tracisz na każdym etapie.

**Co oznaczają "rage clicks"?**

Kilka kliknięć w to samo miejsce w krótkim czasie — znak frustracji odwiedzającego. Zazwyczaj oznacza, że coś wygląda jak przycisk (lub link), ale nie reaguje na kliknięcie, lub strona ładuje się zbyt wolno.

**Dlaczego "Analityka" ma mniej opcji czasowych niż "Statystyki"?**

Dane o ruchu (Analytics) zbieramy od maja 2026. Dane finansowe (Statystyki) możemy generować za dowolny okres, bo odczytujemy je bezpośrednio z historii zamówień. Analityka ma teraz opcję "Własny zakres" — możesz wybrać dowolne daty od maja 2026.

---

## Słowniczek

| Termin | Znaczenie w języku prostym |
|--------|---------------------------|
| **Sesja** | Jedna wizyta na stronie (jak jedna wizyta w sklepie stacjonarnym) |
| **Odsłona** | Wyświetlenie jednej strony (wejście na podstronę usługi to odsłona) |
| **Bounce rate** | Procent osób, które weszły i od razu wyszły bez kliknięcia |
| **Lejek konwersji** | Ścieżka zakupowa: od wejścia na stronę do złożenia zamówienia |
| **Konwersja** | Zamiana odwiedzającego w klienta (np. z przeglądającego w kupującego) |
| **UTM / Oznaczenie kampanii** | Kod w linku, który mówi systemowi "ten klient przyszedł z reklamy na Facebooku" |
| **Rage click** | Wielokrotne kliknięcie w to samo miejsce z frustracji (znak problemu UX) |
| **Kaucja** | Zwrotny depozyt klienta — nie wlicza się do przychodów |
| **Snapshot** | Zapis danych wykonany przez system co godzinę (zamiast przeliczania na żywo) |

---

*Ten przewodnik dotyczy wersji panelu Registro z modułem Analytics Phase D (czerwiec 2026). W przypadku pytań skontaktuj się ze swoim opiekunem Registro.*
