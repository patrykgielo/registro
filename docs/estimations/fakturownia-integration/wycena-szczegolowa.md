# Wycena Szczegolowa: Integracja z Fakturownia.pl + KSeF

**Data:** 2026-02-03
**Task ClickUp:** [86c7ynhf5] (INTEGRACJA Z FAKTUROWNIA - NAJWAZNIEJSZE)
**Stawka:** 160 PLN/h netto (196,80 PLN/h brutto, 23% VAT)
**Research:** 67+ zrodel (Fakturownia API docs, KSeF portal, pomoc.fakturownia.pl, podatki.gov.pl, analizy branzowe, PHP packages)
**Analiza techniczna:** `docs/estimations/fakturownia-integration/analiza-techniczna.md`

---

## KONTEKST I PILNOSC

### Timeline KSeF

| Data | Wydarzenie |
|------|-----------|
| 1 lutego 2026 | KSeF obowiazkowy dla duzych firm (>200M PLN) |
| **1 kwietnia 2026** | **KSeF obowiazkowy dla WSZYSTKICH firm (wlacznie z Registro)** |
| 31 grudnia 2026 | Koniec okresu laski (zero kar w 2026) |
| 1 stycznia 2027 | Kary: 100% kwoty VAT na fakturze |

**Wniosek:** Integracja musi byc gotowa przed 1 kwietnia 2026 — zostalo ~8 tygodni.

### Kluczowe ustalenie: B2C = wylaczone z KSeF

Wiekszosc klientow car detailing to osoby fizyczne (bez NIP). Faktury B2C mozna wystawiac tradycyjnie (PDF/email). **Tylko klienci firmowi (z NIP) wymagaja KSeF.** To znaczaco upraszcza implementacje.

### Ustalenia z klientem

- **Brak kasy fiskalnej** — faktura = jedyny dokument sprzedazy
- **Faktura:** auto po statusie "completed" + manual override z panelu admina
- **System faktur:** Fakturownia.pl (klient ma aktywne konto)

---

## LOGIKA FEATURE

```
1. Klient rezerwuje usluge (booking wizard)
   -> checkbox "Chce fakture VAT" (pre-filled z profilu)
   -> jesli TAK: dane billingowe (NIP, firma, adres) z profilu lub formularz

2. Appointment status -> "completed"
   -> jesli wants_invoice = true:
   -> CreateInvoiceJob (async, queue)
   -> snapshot danych kupujacego + cena z appointment

3. InvoiceService tworzy fakture w Fakturownia.pl API
   -> kind: "vat" (B2B z NIP) lub "vat" (B2C bez NIP)
   -> gov_save_and_send: true (jesli B2B -> auto KSeF)

4. Fakturownia zwraca: fakturownia_id, invoice_number, view_url
   -> jesli B2B: gov_status = processing -> ok (KSeF)
   -> email do klienta z linkiem do PDF

5. Admin: moze recznie "Wystaw fakture" dla dowolnego appointment
```

**Przyklad B2B:** Firma XYZ rezerwuje "Komplet" za 500 PLN. Appointment completed -> faktura VAT 406.50 netto + 93.50 VAT = 500 brutto -> wyslana do Fakturowni -> automatycznie do KSeF -> klient dostaje email z PDF.

**Przyklad B2C:** Jan Kowalski (osoba fizyczna) rezerwuje "Mycie" za 150 PLN. Appointment completed -> faktura na osobe fizyczna -> Fakturownia generuje PDF -> email do klienta. Bez KSeF.

---

## OBECNY STAN KODU

| Element | Stan | Plik |
|---------|------|------|
| Dane billingowe klienta (NIP, company, adres) | ✅ Istnieje (01.2026) | User model, migracja 2026-01-11 |
| Edycja danych w profilu klienta | ✅ Istnieje | `tab-personal.blade.php` sekcja "Dane do faktury" |
| Ceny uslug | ✅ Istnieje | Service.price (decimal:2) |
| Appointment z service_id | ✅ Istnieje | Appointment model |
| Email po rezerwacji | ✅ Istnieje | AppointmentCreatedNotification |
| Vehicle data na appointment | ✅ Istnieje | vehicle_type_id, brand, model |

### Czego brakuje (KRYTYCZNE)

| Element | Wplyw |
|---------|-------|
| **Model Invoice / tabela faktur** | Brak calkowity — nie ma gdzie przechowywac faktur |
| **price_at_booking na Appointment** | Zmiana cennika retroaktywnie zmienia kwoty faktur |
| **VAT rate na Service** | Hardcoded 23% — brak w bazie |
| **wants_invoice na Appointment** | Nie wiadomo czy klient chce fakture |
| **payment_status na Appointment** | Nie wiadomo czy zaplacono |
| **Integracja z Fakturownia API** | Brak calkowity |
| **Walidacja NIP (checksum)** | Pole string, brak walidacji algorytmem wagowym |
| **Billing data w Filament admin** | Admin nie widzi NIP/company klienta |
| **InvoiceResource w Filament** | Brak zarzadzania fakturami w panelu |

---

## RESEARCH — KLUCZOWE USTALENIA

### Fakturownia API

- REST API z tokenem, base URL: `https://SUBDOMAIN.fakturownia.pl`
- Tworzenie faktury: `POST /invoices.json` — pola: kind, buyer_*, positions[], gov_save_and_send
- KSeF automatycznie: `gov_save_and_send: true` → Fakturownia wysyla do KSeF (bezplatnie)
- PDF: publiczny link bez autentykacji `https://DOMAIN.fakturownia.pl/invoice/TOKEN.pdf`
- PHP package: `abb/fakturownia` (composer) — dojrzaly, CRUD invoices/clients/products
- Brak sandboxa — zalecenie: oddzielne konto testowe

### KSeF routing

- `buyer_company: true` + NIP → KSeF (obowiazkowe od 04.2026)
- `buyer_company: false` (osoba fizyczna) → tradycyjna faktura (PDF/email)
- Proforma → NIE idzie do KSeF
- Korekta → idzie do KSeF jesli oryginalna byla w KSeF

### Dobre praktyki

- Snapshot danych kupujacego na fakturze (nie referencja do User)
- Snapshot ceny na appointment (nie dynamiczna z Service)
- Retry logic na API calls (3 proby, exponential backoff)
- Queue job dla tworzenia faktur (nie blokuj HTTP request)
- Walidacja NIP checksumem przed wyslaniem do API (unikniecie 422)

---

## ZAKRES PRAC — WYCENA Z OPCJAMI

### Wspolna baza (obowiazkowa w obu opcjach)

| # | Zadanie | Godziny | Uzasadnienie |
|---|---------|---------|-------------|
| 1 | Migration: tabela `invoices` (pelny schema — 25+ kolumn, indexy, FK) | 2h | Kluczowa tabela, snapshot buyer data, status KSeF, URLs |
| 2 | Migration: `vat_rate` (decimal) do `services` | 0.5h | Musi byc konfigurowalny per usluga |
| 3 | Migration: `price_at_booking`, `wants_invoice`, `payment_status` do `appointments` | 1h | Snapshot ceny + flaga faktury + status platnosci |
| 4 | Backfill: istniejace appointments → cena z service.price | 1h | Edge cases: soft-deleted services, null service_id |
| 5 | Invoice model (fillable, casts, enums, relationships, scopes) | 2h | Status enum, kind enum, gov_status, relacje z Appointment/User |
| 6 | Appointment model: nowe pola (fillable, casts, relacja invoices()) | 1h | price_at_booking cast decimal, wants_invoice bool |
| 7 | Service model: vat_rate + metody grossPrice/netPrice | 1h | Kalkulacja net/gross z vat_rate |
| 8 | NipValidator: checksum (algorytm wagowy 6,5,7,2,3,4,5,6,7) + Laravel Rule | 1.5h | Walidacja przed API call — zapobiega 422 |
| 9 | FakturowniaClient wrapper (config, abb/fakturownia, retry 3x, logging) | 3h | Exponential backoff, ApiException handling, structured logging |
| 10 | InvoiceService::createFromAppointment() — snapshot, kalkulacja, zapis | 3h | Snapshot buyer data, net/vat/gross calc, local Invoice record |
| 11 | InvoiceService::sendToFakturownia() — API call, update local record | 2h | Mapowanie pol Registro → Fakturownia, error handling |
| 12 | CreateInvoiceJob (queue: invoices, retries: 3, backoff, failed notification) | 2h | Async, nie blokuje HTTP, admin notification on failure |
| 13 | Booking flow: checkbox "Chce fakture" + warunkowe pola NIP/firma/adres | 3h | Pre-fill z profilu, walidacja warunkowa, sesja |
| 14 | BookingController confirm(): save price_at_booking + wants_invoice | 2h | Server-side recalculation, snapshot na appointment |
| 15 | config/services.php Fakturownia + .env.example | 0.5h | FAKTUROWNIA_DOMAIN, FAKTUROWNIA_TOKEN, PAYMENT_DAYS |
| **Wspolna baza** | | **25.5h** | |

### Opcja A: MVP — Podstawowa integracja (38h dev)

Minimum potrzebne do wystawiania faktur od 1 kwietnia 2026.

| # | Zadanie (ponad baze) | Godziny | Uzasadnienie |
|---|----------------------|---------|-------------|
| A1 | Filament InvoiceResource: lista + podglad (basic filters: status, data) | 3h | Read-only, podglad faktury, link do PDF na Fakturowni |
| A2 | Filament AppointmentResource: akcja "Wystaw fakture" (prosty trigger) | 2h | Dispatch CreateInvoiceJob z domyslnymi danymi |
| A3 | Filament UserResource: sekcja danych billingowych | 1.5h | NIP, company_name, billing address — read/edit |
| A4 | Filament ServiceResource: pole vat_rate | 0.5h | Input numeric z domyslnym 23 |
| A5 | Testy: migracje, NipValidator, InvoiceService basic, booking flow | 5h | Unit + Feature, mock Fakturownia API |
| **Opcja A — A-specific** | | **12h** | |

**Opcja A dev lacznie: 25.5 + 12 = 37.5h → 38h**

**Co klient dostaje:**
- Automatyczne wystawianie faktur po zakonczeniu wizyty
- Reczne wystawianie faktur z panelu admina
- Automatyczne wysylanie B2B do KSeF (przez Fakturownie)
- Klient wybiera "Chce fakture" w procesie rezerwacji
- Walidacja NIP (checksum)
- Cena zapisana na rezerwacji (nie zmienia sie po zmianie cennika)
- Lista faktur w panelu admina
- Link do PDF faktury na Fakturowni

**Ograniczenia vs Opcja B:**
- Brak automatycznego emaila z faktura z Registro (Fakturownia moze wyslac)
- Brak faktur korygujacych z panelu
- Brak webhookow — status KSeF wymaga recznego sprawdzenia
- Brak synchronizacji klientow z Fakturownia
- Prostsza akcja "Wystaw fakture" (bez modalnego okna z podgladem)
- Mniejszy zakres testow

### Opcja B: Pelna integracja (56h dev)

Kompletny system fakturowania z zaawansowanymi funkcjami.

| # | Zadanie (ponad baze) | Godziny | Uzasadnienie |
|---|----------------------|---------|-------------|
| B1 | Filament InvoiceResource (pelny: lista, podglad, wyslij email, pobierz PDF, filtry zaawansowane) | 5h | Advanced filters, bulk actions, export |
| B2 | Filament AppointmentResource: "Wystaw fakture" z modalem + edycja danych przed wystawieniem | 3h | Modal z pre-filled danymi, mozliwosc korekty NIP/kwoty |
| B3 | Filament UserResource: billing data + sync z Fakturownia client | 2h | Automatyczne tworzenie/aktualizacja klienta w Fakturowni |
| B4 | Filament ServiceResource: vat_rate field | 0.5h | Jak w A |
| B5 | Webhook handler: route, controller, weryfikacja, status update | 3h | Automatyczna aktualizacja gov_status z Fakturowni |
| B6 | Faktura korygujaca: InvoiceService::createCorrection() + admin action | 3h | correction_reason obowiazkowe, powiazanie z oryginalna |
| B7 | KSeF monitoring: status badges w admin, alerty dla rejected | 2h | Visual indicators, auto-refresh, notification na rejected |
| B8 | Email z faktura z Registro (custom template, PDF link, branding) | 2h | Dedykowana notyfikacja, kontrola nad trescia |
| B9 | Sync klientow Registro → Fakturownia (create/update via API) | 2h | external_id mapping, auto-sync przy pierwszej fakturze |
| B10 | Testy: kompletne (all A + webhook, korekty, email, sync, KSeF statuses) | 7h | Comprehensive mocking, edge cases, failure scenarios |
| **Opcja B — B-specific** | | **29.5h → 30h** | |

**Opcja B dev lacznie: 25.5 + 30 = 55.5h → 56h**

**Co klient dostaje (ponad Opcja A):**
- Automatyczny email z faktura z wlasnym brandingiem Registro
- Faktury korygujace z panelu admina
- Automatyczna aktualizacja statusu KSeF (webhooks)
- Wizualne znaczniki statusu KSeF w panelu (processing/ok/rejected)
- Alert gdy faktura zostanie odrzucona przez KSeF
- Synchronizacja klientow Registro → Fakturownia
- Edycja danych przed wystawieniem faktury (modal)
- Rozszerzony zakres testow automatycznych

---

## QA, MANUAL TESTING, CONTINGENCY

### 1. Manual QA — przejscie pelnego flow przez developera

| Scenariusz testowy | Czas |
|-------------------|------|
| Booking flow: rezerwacja z "Chce fakture" (B2B z NIP) — pelny wizard | 1h |
| Booking flow: rezerwacja z "Chce fakture" (B2C bez NIP) — pelny wizard | 1h |
| Booking flow: rezerwacja BEZ "Chce fakture" — weryfikacja ze faktura NIE powstaje | 0.5h |
| Auto-invoice: zmiana statusu na completed → weryfikacja faktury w Fakturowni | 1h |
| Manual invoice: trigger z panelu admina → weryfikacja w Fakturowni | 1h |
| Faktura B2B: weryfikacja gov_status (KSeF) w panelu i na Fakturowni | 1h |
| NIP validation: poprawny NIP, bledny NIP, pusty NIP — walidacja w profilu i wizardzie | 0.5h |
| Edge cases: brak danych billingowych, zmiana cennika po rezerwacji, anulowany appointment | 1h |
| Panel admin: InvoiceResource lista, filtry, podglad, PDF link | 1h |
| **Manual QA lacznie** | **8h** |

### 2. Bug fixing po QA

API integration = wyzsze ryzyko bledow niz czysto frontendowe feature'y:

| Element | Czas |
|---------|------|
| Bug fixing po manual QA (estymacja: 4-6 bugow, API edge cases) | 4h |
| Re-test po naprawach | 1h |
| **Bug fixing lacznie** | **5h** |

### 3. Contingency — bufor ryzyka

Standard branzowy: **15%** na nieoczekiwane problemy.

| Opcja | Baza dev | Contingency 15% |
|-------|----------|-----------------|
| Opcja A | 38h | 6h |
| Opcja B | 56h | 9h |

Contingency pokrywa: niespodziewane odpowiedzi API Fakturowni, bledy walidacji KSeF, problemy z formatem danych, edge cases w booking flow, regresje w istniejacych funkcjach.

### 4. Wsparcie podczas weryfikacji klienta

| Element | Czas |
|---------|------|
| Konfiguracja konta Fakturownia (token API, dane sprzedawcy) | 1h |
| Adresowanie feedbacku klienta po weryfikacji (estymacja: 3-5 uwag) | 3h |
| **Wsparcie klienta lacznie** | **4h** |

---

## PODSUMOWANIE KOSZTOW (FINALNA)

### Opcja A: MVP — Podstawowa integracja

| Kategoria | Godziny |
|-----------|---------|
| Wspolna baza (15 zadan) | 25.5h |
| A-specific (5 zadan) | 12h |
| Manual QA | 8h |
| Bug fixing po QA | 5h |
| Contingency 15% | 6h |
| Wsparcie klienta | 4h |
| **OPCJA A LACZNIE** | **60.5h → 61h = 9,760 PLN netto** |

### Opcja B: Pelna integracja

| Kategoria | Godziny |
|-----------|---------|
| Wspolna baza (15 zadan) | 25.5h |
| B-specific (10 zadan) | 30h |
| Manual QA | 8h |
| Bug fixing po QA | 5h |
| Contingency 15% | 9h |
| Wsparcie klienta | 4h |
| **OPCJA B LACZNIE** | **81.5h → 82h = 13,120 PLN netto** |

### Tabela porownawcza

| | Opcja A: MVP | Opcja B: Pelna |
|---|-------------|---------------|
| Godziny dev | 38h | 56h |
| QA + bug fixing | 13h | 13h |
| Contingency | 6h | 9h |
| Wsparcie | 4h | 4h |
| **Lacznie godziny** | **61h** | **82h** |
| **Netto** | **9,760 PLN** | **13,120 PLN** |
| **Brutto (23% VAT)** | **12,004.80 PLN** | **16,137.60 PLN** |

### Rekomendacja

**Opcja A (MVP) jest rekomendowana** ze wzgledu na:
1. **Presja czasowa:** KSeF od 1 kwietnia 2026 (~8 tygodni)
2. **2026 = okres laski:** Brak kar za bledy — czas na dopracowanie
3. **Opcja B moze byc dorobilona pozniej** jako osobny task po wdrozeniu MVP
4. **Fakturownia obsluguje email/PDF** — Registro nie musi tego robic w MVP

### Synergia z Dynamic Vehicle Pricing

Oba feature'y wymagaja `price_at_booking` na appointments.
**Jesli realizowane RAZEM: oszczednosc ~3-4h** na wspolnej migracji i backfill.

Przy wspolnej realizacji:
- Opcja A: 61h - 3h = **58h = 9,280 PLN netto** (oszczednosc 480 PLN)
- Opcja B: 82h - 3h = **79h = 12,640 PLN netto** (oszczednosc 480 PLN)

---

## RYZYKA

| Ryzyko | Prawdop. | Wplyw | Mitygacja |
|--------|----------|-------|-----------|
| Fakturownia API niedostepne / timeout | NISKIE | WYSOKI | Retry 3x, queue job, admin notification |
| KSeF odrzuca fakture (bledny format) | SREDNIE | SREDNI | Walidacja NIP pre-send, 2026 = okres laski |
| Zmiana API Fakturowni (brak wersjonowania) | NISKIE | SREDNI | Pin package version, monitoring |
| Booking wizard regresja (checkbox "Chce fakture") | SREDNIE | SREDNI | Feature tests, manual QA |
| Ceny NET vs GROSS niejasnosc | SREDNIE | WYSOKI | Ustalenie z klientem PRZED implementacja |
| Brak sandboxa — testy na zywym koncie | SREDNIE | SREDNI | Oddzielne konto testowe |

---

## PLIKI DO UTWORZENIA / MODYFIKACJI

### Nowe pliki

- `database/migrations/xxxx_create_invoices_table.php`
- `database/migrations/xxxx_add_vat_rate_to_services.php`
- `database/migrations/xxxx_add_invoice_fields_to_appointments.php`
- `database/migrations/xxxx_backfill_appointment_prices.php`
- `app/Models/Invoice.php`
- `app/Services/Invoice/InvoiceService.php`
- `app/Services/Invoice/FakturowniaClient.php`
- `app/Services/Invoice/NipValidator.php`
- `app/Rules/ValidNip.php`
- `app/Jobs/CreateInvoiceJob.php`
- `app/Filament/Resources/InvoiceResource.php`
- `app/Filament/Resources/InvoiceResource/Pages/`
- `tests/Unit/NipValidatorTest.php`
- `tests/Feature/InvoiceServiceTest.php`
- `tests/Feature/BookingInvoiceFlowTest.php`

### Modyfikowane pliki

- `app/Models/Appointment.php` — price_at_booking, wants_invoice, payment_status
- `app/Models/Service.php` — vat_rate, grossPrice(), netPrice()
- `app/Models/User.php` — relacja invoices()
- `app/Http/Controllers/BookingController.php` — save price + wants_invoice
- `app/Filament/Resources/UserResource.php` — billing data section
- `app/Filament/Resources/AppointmentResource.php` — cena, faktura, "Wystaw fakture" action
- `app/Filament/Resources/ServiceResource.php` — pole vat_rate
- `resources/views/booking-wizard/steps/review.blade.php` — checkbox "Chce fakture"
- `config/services.php` — sekcja fakturownia
- `.env.example` — FAKTUROWNIA_DOMAIN, FAKTUROWNIA_TOKEN, FAKTUROWNIA_PAYMENT_DAYS
- `composer.json` — require abb/fakturownia

### Opcja B — dodatkowe pliki

- `app/Http/Controllers/Webhooks/FakturowniaWebhookController.php`
- `app/Notifications/InvoiceCreatedNotification.php`
- `routes/webhooks.php` (lub dodanie do web.php)
- `tests/Feature/FakturowniaWebhookTest.php`
- `tests/Feature/InvoiceCorrectionTest.php`

---

## OTWARTE PYTANIA (do ustalenia z klientem PRZED implementacja)

1. **Ceny NET czy GROSS?** Czy Service.price = netto czy brutto? (wplywa na kalkulacje VAT)
2. **Platnosci online?** Czy PayU bedzie wdrazone razem? (wplywa na payment_status)
3. **Email z faktura:** Fakturownia wysyla automatycznie czy Registro kontroluje?
4. **Proforma?** Czy potrzebna faktura proforma przy rezerwacji?
5. **Subdomena Fakturowni?** Jaka subdomena klienta (do konfiguracji API)
6. **Dane sprzedawcy:** Czy uzupelnione w Fakturowni? (NIP, adres, rachunek bankowy)
7. **Konto testowe:** Czy klient zalozy registro-test.fakturownia.pl do testow?

---

## PLAN REALIZACJI

1. Po akceptacji wyceny → `feature/fakturownia-integration`
2. Konfiguracja konta testowego Fakturownia
3. Implementacja wspolnej bazy (migracje, modele, serwisy)
4. Integracja API + booking flow
5. Panel admin (Filament)
6. Manual QA + bug fixing
7. Deploy na staging → weryfikacja klienta
8. Release → production (przed 1 kwietnia 2026)
