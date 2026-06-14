# Twoja Analityka — Przewodnik dla Administratorów

> **Dla kogo:** Właściciele firm i administratorzy panelu Registro
> **Poziom techniczny:** Brak wymagań — ten przewodnik jest napisany prostym językiem
> **Ostatnia aktualizacja:** 2026-06-15

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
| **Raporty → Analityka** | Ruch na stronie, zachowania odwiedzających |

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

- 🔵 **Zamówienia** — dzienna wartość zamówień
- 🟢 **Wizyty** — dzienna wartość wizyt
- 🟡 **Wypożyczenia** — dzienna wartość wypożyczeń

Gdy jedna seria gwałtownie rośnie lub spada — warto sprawdzić, co się działo w firmie w tamtym czasie.

### Tabela "Najpopularniejsze usługi"

Lista 10 usług, które przyniosły Ci największy przychód w wybranym okresie. Pomaga odpowiedzieć na pytanie: **co tak naprawdę sprzedaje się najlepiej?**

### Eksport danych

Dwa przyciski w prawym górnym rogu:

- **Pobierz CSV** — plik z danymi do Excela (separator: średnik, kodowanie: UTF-8). Zawiera podsumowanie i dzienny rozkład.
- **Pobierz PDF** — raport do wydrukowania lub wysłania mailem. Zawiera tabele (bez wykresów).

---

## Raporty → Analityka

To jest sekcja **ruchu na stronie** — pokazuje, co dzieje się *przed* tym, zanim klient złoży zamówienie. Znajdziesz ją w: **Raporty → Analityka**.

### Co to jest "sesja"?

Sesja to jedna wizyta na Twojej stronie. Gdy ktoś otwiera stronę i przegląda ją przez kilkanaście minut — to jest jedna sesja. Gdy zamknie przeglądarkę i wróci następnego dnia — to już nowa sesja.

### Jak wybrać okres?

Dostępne opcje: **Dziś**, **Ten tydzień**, **Ten miesiąc**, **Poprzedni miesiąc**.

> **Dlaczego mniej opcji niż w Statystykach?** Dane analityczne zbieramy od niedawna, więc historyczne okresy nie mają jeszcze pełnych danych. Dostępne opcje będą rozszerzane.

### Karty KPI

| Karta | Co oznacza | Jak interpretować |
|-------|-----------|------------------|
| **Odsłony stron** | Ile razy łącznie wyświetlono jakąkolwiek stronę | Ogólny poziom aktywności na stronie |
| **Unikalne sesje** | Ile osobnych wizyt było | Liczba "wejść" na stronę |
| **Zalogowani użytkownicy** | Ile sesji należało do klientów z kontem | Stosunek do unikalnych sesji pokazuje, ile osób wraca po zalogowaniu |
| **Śr. zdarzeń/sesja** | Ile akcji wykonuje przeciętny odwiedzający | Wyższe = bardziej zaangażowani odwiedzający |

### Urządzenia

Podział na: **Mobile**, **Tablet**, **Desktop**.

W Polsce ponad 60% ruchu pochodzi z urządzeń mobilnych. Jeśli Twoja strona wygląda słabo na telefonie — tracisz klientów. Ten wykres pomaga to zidentyfikować.

### Typy stron

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

### Najpopularniejsze strony

Lista 10 stron z największą liczbą odwiedzin i unikalnych sesji. Pomaga odpowiedzieć na pytanie: **Co przyciąga ludzi na Twoją stronę?**

### Głębokość przewijania

Pokazuje, jak daleko użytkownicy scrollują Twoje strony:

| Milestone | Oznacza |
|----------|---------|
| 25% | Zobaczyli tylko górną część strony |
| 50% | Przewinęli do połowy |
| 75% | Przeczytali większość |
| 90% | Prawie do końca |
| 100% | Dotarli do samego dołu |

Jeśli tylko 20% użytkowników dociera do 50% strony — Twoje kluczowe informacje mogą być zbyt nisko.

### Wykres dzienny

Linia pokazuje dzienny ruch (odsłony stron). Pozwala zobaczyć:
- W które dni tygodnia masz największy ruch?
- Czy kampanie reklamowe przyniosły wzrost odwiedzin?
- Czy sezonowość wpływa na ruch?

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
| Skąd przyszedł odwiedzający (np. z Google, Facebooka) | Aby wiedzieć, które kanały marketingowe działają | 13 miesięcy |
| Czy ktoś zaczął, ale nie ukończył zakupu | Aby poprawić ścieżkę zakupową | 13 miesięcy |
| Czy zamówienie zostało złożone i opłacone | Aby połączyć ruch z przychodem | Bezterminowo (jako zamówienie) |

### Dane finansowe (Statystyki)

Dane w sekcji Statystyki są pobierane bezpośrednio z bazy zamówień — nie są "zbierane" osobno. Gdy klient złoży i opłaci zamówienie, kwota trafia do raportu. Nie ma tutaj żadnych dodatkowych mechanizmów śledzenia.

### Kiedy dane mogą być niekompletne?

Poniższe sytuacje powodują, że liczba odwiedzin może być **niższa niż rzeczywista**:

- Użytkownik ma zainstalowaną wtyczkę blokującą reklamy (np. uBlock Origin) — szacunkowo dotyczy 15% użytkowników
- Użytkownik włączył opcję "Nie śledź" w przeglądarce — nasz tracker to respektuje i nie zbiera żadnych danych
- Wolne łącze internetowe może uniemożliwić wysłanie danych analitycznych

> **To jest zamierzone** — wolimy zbierać nieco mniej danych, ale dbać o prywatność Twoich klientów.

---

## Jak czytać dane razem?

### Przykład 1: Sprawdzasz skuteczność akcji reklamowej

1. Otwórz **Raporty → Analityka**, wybierz "Ten tydzień"
2. Sprawdź wykres dzienny — czy w dniu startu kampanii wzrósł ruch?
3. Sprawdź, które strony były najczęściej odwiedzane — czy klienci trafiali tam, gdzie planowałeś?
4. Przejdź do **Raporty → Statystyki**, wybierz "Ten tydzień" — czy wzrost ruchu przełożył się na więcej zamówień?

### Przykład 2: Diagnozujesz porzucone koszyki

1. W **Raporty → Analityka** sprawdź "Typy stron" — ile sesji dotarło do `checkout`?
2. Porównaj z `confirmation` — ile osób ukończyło zamówienie?
3. Duża różnica oznacza, że wiele osób porzuca koszyk na etapie płatności
4. W **Raporty → Statystyki** sprawdź wartość zamówień — czy porzucone koszyki to niskie czy wysokie kwoty?

### Przykład 3: Który miesiąc był najlepszy?

1. Idź do **Raporty → Statystyki**
2. Przełącz między "Poprzedni miesiąc" a "Ten miesiąc"
3. Porównaj łączny przychód i liczbę zamówień
4. Pobierz oba jako CSV, aby zestawić w Excelu

---

## Najczęściej zadawane pytania

**Dlaczego liczba zamówień w "Statystykach" nie pasuje do zakładki "Zamówienia"?**

Statystyki liczą tylko zamówienia o statusie "opłacone". W zakładce Zamówienia widzisz wszystkie zamówienia, łącznie z oczekującymi i anulowanymi.

**Dlaczego dane z dzisiaj wyglądają inaczej wieczorem niż rano?**

Dane dzienne są przeliczane co godzinę. Zamówienie złożone o 9:00 może pojawić się w statystykach między 9:00 a 10:00.

**Jak daleko wstecz sięgają dane analityczne?**

Śledzenie zachowań odwiedzających (Analytics) zostało uruchomione w maju 2026. Dane finansowe (Statystyki) dostępne są od uruchomienia Twojego panelu.

**Czy moi pracownicy wliczają się w statystyki ruchu?**

Tak — jeśli Ty lub Twoi pracownicy odwiedzacie stronę sklepu, te odwiedziny będą widoczne w analityce. Nie ma obecnie filtra wykluczającego ruch wewnętrzny.

**Czy klient, który kupował wielokrotnie, liczy się jako jedna czy wiele sesji?**

Jako wiele sesji — każda wizyta to oddzielna sesja. Jeśli klient jest zalogowany, możemy go rozpoznać (kolumna "Zalogowani użytkownicy"), ale i tak każda wizyta to osobna sesja.

**Czy mogę zobaczyć, kto konkretnie odwiedził stronę?**

Nie — i to jest zamierzone. Anonimizujemy dane, aby chronić prywatność Twoich klientów (wymagania RODO). Możesz zobaczyć, ile osób odwiedziło stronę, ale nie kto konkretnie.

**Co to jest "sesja"?**

Sesja to jedna wizyta na stronie. Zaczyna się, gdy ktoś otwiera Twoją stronę, i kończy się, gdy zamknie przeglądarkę lub wyloguje się. Następnego dnia ta sama osoba zaczyna nową sesję.

**Dlaczego "Analityka" ma mniej opcji czasowych niż "Statystyki"?**

Dane o ruchu (Analytics) zbieramy od maja 2026. Dane finansowe (Statystyki) możemy generować za dowolny okres, bo odczytujemy je bezpośrednio z historii zamówień. Z czasem opcje analityki będą rozszerzone.

---

## Słowniczek

| Termin | Znaczenie w języku prostym |
|--------|---------------------------|
| **Sesja** | Jedna wizyta na stronie (jak jedna wizyta w sklepie stacjonarnym) |
| **Odsłona** | Wyświetlenie jednej strony (wejście na podstronę usługi to odsłona) |
| **Unikalny odwiedzający** | Jedna osoba, niezależnie od tego, ile stron przejrzała |
| **Bounce rate / Współczynnik odrzuceń** | Procent osób, które weszły i od razu wyszły bez kliknięcia |
| **UTM / Oznaczenie kampanii** | Kod w linku, który mówi systemowi "ten klient przyszedł z reklamy na Facebooku" |
| **Kaucja** | Zwrotny depozyt klienta — nie wlicza się do przychodów |
| **Snapshot** | Zapis danych wykonany przez system co godzinę (zamiast przeliczania na żywo) |
| **Konwersja** | Zamiana odwiedzającego w klienta (np. z przeglądającego w kupującego) |

---

*Ten przewodnik dotyczy wersji panelu Registro z modułem Analytics Phase 2 (czerwiec 2026). W przypadku pytań skontaktuj się ze swoim opiekunem Registro.*
