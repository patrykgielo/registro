# Procesy Biznesowe — Przegląd

Ta sekcja dokumentuje procesy biznesowe Registro widziane od strony klienta
oraz od strony administratora: co przechodzi klient od początku do końca oraz
co na co dzień robi administrator/pracownik najemcy (tenant), aby prowadzić
swój biznes na platformie.

W odróżnieniu od sekcji Development (która dokumentuje *jak zbudowany jest
system*), te strony dokumentują *co się dzieje* — ścieżkę klienta (customer
journey), lejek sprzedażowy (sales funnel) oraz procesy operacyjne wykonywane
przez administratora. W całej sekcji znajdują się rzeczywiste diagramy
Mermaid z nazwami tras (route), metodami kontrolerów i wartościami statusów,
dzięki czemu ta sekcja pełni jednocześnie funkcję referencji inżynierskiej dla
każdego, kto buduje lub debuguje te procesy.

Przeniesione w 2026-07 z wycofanego drzewa `docs/` w katalogu głównym repo
(`architecture/user-journeys.md`,
`features/{checkout-order-flow,payment-flow,booking-wizard-flow,rental-flow,auth-onboarding-flow,admin-panel-flows}.md`)
i skorygowane względem aktualnego kodu (`app/StateMachines/OrderStatusStateMachine.php`,
`app/Filament/Resources/OrderResource.php`, `app/Http/Controllers/OrderController.php`,
`app/Http/Controllers/AppointmentController.php`) — sprawdź każdą stronę, aby zobaczyć,
co zmieniło się podczas migracji.

## Strony

| Strona | Zakres |
|------|--------|
| [Ścieżka klienta — Rezerwacja](customer-journey-booking.md) | Kreator rezerwacji terminu `ServiceType::TimeSlot` (4–5 kroków) |
| [Ścieżka klienta — Wynajem](customer-journey-rental.md) | `ServiceType::ItemRental` koszyk → checkout → płatność → odbiór → zwrot |
| [Ścieżka klienta — Zapytanie](customer-journey-inquiry.md) | Usługi `price_on_request` — brak samoobsługowej ceny, zamiast tego modal zapytania |
| [Anulowanie](customer-journey-cancellation.md) | Anulowanie inicjowane przez klienta i przez administratora, zarówno dla rezerwacji, jak i zamówień |
| [Ścieżka klienta — Wybór oddziału](customer-journey-locations.md) | **Częściowo wdrożone (fazy 0-2).** Oddział z adresem, godzinami i galerią jest widoczny na stronie. Wybór oddziału i dostępność per punkt — wstrzymane |
| [Ścieżka pracownika — Praca w oddziale](staff-journey-locations.md) | **Planowane (Faza 8, wstrzymana).** Przypisanie do oddziału, wydanie i zwrot, przeniesienia sprzętu między punktami |
| [Gość vs Zalogowany](guest-vs-authenticated.md) | Co może zrobić odwiedzający przed a po rejestracji — nie ma checkoutu jako gość |
| [Proces zakupowy (widok lejka)](purchase-process.md) | Pełny lejek sprzedażowy: katalog → strona produktu → checkout → P24 → potwierdzenie |
| [Onboarding i rejestracja](onboarding-registration.md) | Kreator rejestracji firmy (nowy tenant), rejestracja klienta, role, trial/subskrypcja |
| [Przegląd biznesowy dla administratora](admin-business-overview.md) | Co na co dzień robi administrator/pracownik: potwierdzanie, anulowanie, zwrot środków, zarządzanie kaucją |

Surowe tabele referencyjne przejść stanów (state-transition) dla wszystkich
encji (zamówienia, rezerwacje, wynajmy, koszyki, płatności) znajdziesz w
[Development → Status Machines](../architecture/status-machines.md).
