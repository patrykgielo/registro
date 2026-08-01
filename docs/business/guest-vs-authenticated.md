# Gość a użytkownik zalogowany

**Dla klientów:** możesz przeglądać wszystko — pełny katalog, strony
produktów, kalendarze dostępności — bez konta. Gdy tylko zechcesz faktycznie
coś kupić lub zarezerwować, zostaniesz poproszony o rejestrację lub
zalogowanie. Nie ma zakupów jako gość (guest checkout).

## Macierz dostępu

| Strona / akcja | Gość | Zalogowany |
|---------------|-------|----------------|
| Katalog wypożyczalni (`/wypozyczalnia/*`) | Pełny dostęp | Pełny dostęp |
| Katalog usług (`/uslugi`) | Pełny dostęp | Pełny dostęp |
| Szczegóły przedmiotu/usługi | Pełny dostęp | Pełny dostęp |
| API kalendarza dostępności | Pełny dostęp | Pełny dostęp |
| Zapytanie o cenę (price-on-request) | Pełny dostęp (throttled 5/min) | Pełny dostęp |
| Dodanie do koszyka | Przekierowanie do `/login` | Dozwolone |
| Koszyk i checkout | Przekierowanie do `/login` | Dozwolone |
| Kreator rezerwacji (booking wizard) | Przekierowanie do `/login` | Dozwolone |
| Profil, zamówienia, wizyty | Przekierowanie do `/login` | Dozwolone |

**Nie ma zakupów jako gość.** Klienci muszą się zarejestrować lub zalogować,
zanim dodadzą przedmioty do koszyka lub rozpoczną rezerwację. Rejestracja
jest natychmiastowa (e-mail + hasło, bez wymuszonej weryfikacji e-mail dla
klientów — patrz [Onboarding i rejestracja](onboarding-registration.md)),
a klient jest od razu przekierowywany do docelowego miejsca (strony produktu
lub kroku kreatora, z którego przyszedł).

## Dlaczego to ma znaczenie dla biznesu

- Każdy płacący klient ma konto — nie ma anonimowych zamówień do uzgadniania
  ani kompromisu między porzuceniem zakupów jako gość a tarciem
  (friction) w procesie do zarządzania.
- Marketing/analityka mogą śledzić zachowanie przeglądających gości
  (katalog, zapytania) oddzielnie od lejka zakupowego zalogowanych
  użytkowników — patrz kroki lejka w [Proces zakupowy](purchase-process.md).
- Przepływ zapytania (`price_on_request`) to jedyna akcja zbliżona do zakupu,
  którą gość *może* wykonać bez rejestracji — przydatne dla przedmiotów
  o wysokiej wartości lub wymagających indywidualnej wyceny, gdzie wymuszanie
  rejestracji z góry oznaczałoby utratę leada.

## Reset hasła (dowolny przepływ z autoryzacją)

`GET /password/reset` → `POST` → e-mail z linkiem → `GET /password/reset/{token}`
→ `POST`. Wykorzystuje `PasswordResetNotification` (`EmailServiceChannel`, `ShouldQueue`
+ `ShouldBeUnique`).

## Kluczowe pliki

`app/Http/Middleware/Authenticate.php` (zachowanie przekierowania do logowania),
`routes/web.php` (grupy middleware `auth` na trasach koszyka/checkoutu/rezerwacji),
`app/Http/Controllers/ServiceInquiryController.php` (jedyna akcja zbliżona do
zakupu dostępna dla gościa).
