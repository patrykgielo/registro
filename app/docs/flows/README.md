# Przepływy — referencja techniczna

Ścieżki klienta, pracownika i administratora opisane **od strony implementacji**: trasy,
kontrolery, statusy, zdarzenia, powiadomienia. Dla programisty, testera i supportu.

**To nie jest materiał dla klienta.** Opis funkcji językiem korzyści — do ofert, prezentacji
i marketingu — żyje w [`docs/oferta/`](../../../docs/oferta/README.md). Gdy zmieniasz zachowanie
widoczne dla klienta, aktualizujesz **oba** miejsca: tutaj mechanikę, tam obietnicę.

Historia: do 2026-09-27 te strony leżały w `docs/business/` jako para `.md` + `.en.md` i miały
służyć jednocześnie klientowi i programiście. Przeniesione tutaj, wersje angielskie usunięte
(są w historii gita).

## Strony

| Strona | Zakres |
|------|--------|
| [Rezerwacja terminu](customer-journey-booking.md) | Kreator rezerwacji `ServiceType::TimeSlot` |
| [Wynajem](customer-journey-rental.md) | `ServiceType::ItemRental`: koszyk → checkout → płatność → wydanie → zwrot |
| [Zapytanie o cenę](customer-journey-inquiry.md) | Usługi `price_on_request` — modal zapytania zamiast ceny |
| [Anulowanie](customer-journey-cancellation.md) | Anulowanie przez klienta i administratora, rezerwacje i zamówienia |
| [Wybór oddziału](customer-journey-locations.md) | Kontekst oddziału, przełącznik, dostępność per oddział, oddział odbioru |
| [Praca w oddziale](staff-journey-locations.md) | Egzemplarze, wydanie i zwrot w oddziale |
| [Gość vs zalogowany](guest-vs-authenticated.md) | Co wymaga konta |
| [Proces zakupowy](purchase-process.md) | Lejek: katalog → produkt → koszyk → checkout → płatność → potwierdzenie |
| [Onboarding i rejestracja](onboarding-registration.md) | Zakładanie tenanta przez operatora, rejestracja klienta, role, pola billingowe |
| [Panel administratora](admin-business-overview.md) | Operacje administratora: potwierdzanie, anulowanie, zwroty, kaucje |

Tabele przejść stanów wszystkich encji: [Status Machines](../../../docs/architecture/status-machines.md).

## Zasada aktualności

Te strony opisują kod **na `develop`**. Nie wpisuj tu statusów w rodzaju „faza X zaplanowana,
PR #N" — starzeją się z każdym merge'em. Stan wdrożenia funkcji prowadzi jedno miejsce:
katalog funkcji w [`docs/oferta/README.md`](../../../docs/oferta/README.md); szczegóły faz —
dokument planu danej funkcji w `app/docs/features/`.
