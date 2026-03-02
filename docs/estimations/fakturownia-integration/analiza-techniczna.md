# Analiza Techniczna: Integracja z Fakturownia.pl + KSeF

**Data:** 2026-02-03
**Autor:** Research agents (Fakturownia API + KSeF + codebase analysis)
**Zrodla:** 67+ zrodel (dokumentacja Fakturownia, GitHub API, KSeF portal, pomoc.fakturownia.pl, podatki.gov.pl, analizy branzowe)

---

## 1. KONTEKST: KSeF W POLSCE 2026

### Timeline

| Data | Wydarzenie |
|------|-----------|
| 1 lutego 2026 | KSeF obowiazkowy dla duzych firm (>200M PLN obrotu/2024) |
| 1 lutego 2026 | ODBIERANIE faktur przez KSeF obowiazkowe dla WSZYSTKICH |
| 1 kwietnia 2026 | KSeF obowiazkowy dla WSZYSTKICH firm (wlacznie z Registro) |
| 31 grudnia 2026 | Koniec okresu laski — zero kar w 2026 |
| 1 stycznia 2027 | Kary: 100% kwoty VAT na fakturze lub 18.7% kwoty brutto |

### B2C vs B2B — kluczowe rozroznienie

**B2C (konsumenci bez NIP) = WYLACZONE z obowiazkowego KSeF**

| Klient | Dokument | KSeF obowiazkowy? | Delivery |
|--------|----------|-------------------|----------|
| Osoba fizyczna (bez NIP) | Faktura konsumencka | **NIE** | PDF/email/papier |
| Firma (z NIP) | Faktura VAT | **TAK** (od 04.2026) | KSeF + PDF z QR |
| Osoba fizyczna z NIP <450 PLN | Paragon z NIP | **NIE** (do 2027) | Z kasy fiskalnej |

**Wplyw na Registro:** Wiekszosc klientow car detailing to osoby fizyczne. Faktury B2C mozna dalej wystawiac tradycyjnie (PDF/email). Tylko klienci firmowi (z NIP) wymagaja KSeF.

### Proforma / Korekta / Offline

- **Proforma**: NIE idzie do KSeF (nie jest faktura VAT)
- **Faktura korygujaca**: idzie do KSeF jesli oryginalna byla w KSeF. Pole `correction_reason` obowiazkowe
- **Offline24 mode**: jesli awaria internetu — wyslij do KSeF do konca nastepnego dnia roboczego
- **Awaryjny mode**: 7 dni po oficjalnej awarii KSeF
- **Fakturownia obsluguje offline modes automatycznie**

### Kary w 2026

**W 2026 roku NIE MA kar za:**
- Opoznione wyslanie faktury do KSeF
- Bledne dane w formacie
- Nieprawidlowe uzycie trybu offline
- Bledy podczas korzystania z systemu

**Kary OBOWIAZUJACE juz w 2026:**
- Brak oznaczenia "MPP" (split payment): 30% kara VAT
- Faktura do paragonu bez NIP: 100% kara VAT
- Bledy w JPK_VAT: 500 PLN za blad

---

## 2. FAKTUROWNIA.PL API — PELNA DOKUMENTACJA

### 2.1 Autentykacja

- **Metoda**: API Token w kazdym requeście
- **Format**: `api_token=YOUR_TOKEN` jako parametr lub w body
- **Token**: Generowany w Settings -> API w panelu Fakturowni
- **Base URL**: `https://YOUR_DOMAIN.fakturownia.pl`

### 2.2 Tworzenie faktury

```
POST https://YOUR_DOMAIN.fakturownia.pl/invoices.json
Content-Type: application/json
```

**Request body:**
```json
{
  "api_token": "API_TOKEN",
  "invoice": {
    "kind": "vat",
    "number": null,
    "sell_date": "2026-02-03",
    "issue_date": "2026-02-03",
    "payment_to_kind": 14,
    "payment_type": "transfer",
    "buyer_name": "Firma Sp. z o.o.",
    "buyer_tax_no": "1234567890",
    "buyer_email": "klient@firma.pl",
    "buyer_company": true,
    "buyer_street": "ul. Przykladowa 1",
    "buyer_post_code": "00-001",
    "buyer_city": "Warszawa",
    "buyer_phone": "+48501234567",
    "positions": [
      {
        "name": "Detailing samochodu - Komplet",
        "quantity": 1,
        "total_price_gross": 500.00,
        "tax": 23
      }
    ],
    "gov_save_and_send": true
  }
}
```

### 2.3 Typy dokumentow (`kind`)

| Kind | Nazwa | KSeF? |
|------|-------|-------|
| `vat` | Faktura VAT | TAK (jesli B2B) |
| `proforma` | Faktura proforma | NIE |
| `receipt` | Paragon | NIE |
| `correction` | Faktura korekta | TAK (jesli oryginalna w KSeF) |
| `advance` | Faktura zaliczkowa | TAK |
| `final` | Faktura koncowa | TAK |

### 2.4 Pola pozycji (`positions[]`)

| Pole | Typ | Wymagane | Opis |
|------|-----|----------|------|
| `name` | string | TAK | Nazwa uslugi (max 256 znakow dla KSeF) |
| `quantity` | decimal | TAK | Ilosc |
| `total_price_gross` | decimal | TAK* | Cena brutto calkowita |
| `total_price_net` | decimal | TAK* | Cena netto calkowita |
| `tax` | string | TAK | Stawka VAT: 23, 8, 5, 0, "zw", "np" |
| `unit` | string | NIE | Jednostka (np. "szt.", "usl.") |
| `discount` | decimal | NIE | Rabat (%) |

*Wymagane jedno z: `total_price_gross` LUB `total_price_net`

### 2.5 Metody platnosci (`payment_type`)

| Wartosc | Opis |
|---------|------|
| `transfer` | Przelew bankowy |
| `cash` | Gotowka |
| `card` | Karta platnicza |
| `payu` | PayU |
| `paypal` | PayPal |
| `compensation` | Kompensata |
| `other` | Inna |

### 2.6 KSeF przez Fakturownie

**Pola do wyslania do KSeF:**
- `gov_save_and_send: true` → automatyczne wyslanie
- `buyer_company: true/false` → KRYTYCZNE dla routingu KSeF

**Pola zwrotne:**
- `gov_status`: `processing` | `ok` | `rejected` | `demo_processing` | `demo_ok`
- `gov_id`: numer KSeF (np. "1234-567-89")
- `gov_verification_link`: link weryfikacyjny

**Walidacja KSeF:**
- Fakturownia waliduje fakture przed wyslaniem do KSeF
- `validate_invoices_for_gov: true` (domyslne) — blokuje tworzenie jesli nie przejdzie walidacji
- Mozna wylaczyc: `validate_invoices_for_gov: false`

### 2.7 PDF i email

**Pobieranie PDF:**
```
GET /invoices/{id}.pdf?api_token=API_TOKEN
```

**Publiczny link (bez autentykacji):**
```
https://YOUR_DOMAIN.fakturownia.pl/invoice/{TOKEN}.pdf
```

**Wysylanie emailem:**
```
POST /invoices/{id}/send_by_email.json
{
  "api_token": "API_TOKEN",
  "email_cc": "kopia@firma.pl",
  "email_pdf": true
}
```

### 2.8 Klienci (Clients API)

```
POST /clients.json  — tworzenie
GET /clients.json   — lista
GET /clients/{id}.json — szczegoly
PUT /clients/{id}.json — aktualizacja
```

**Kluczowe pola klienta:**
- `name`, `tax_no` (NIP), `email`, `phone`
- `street`, `post_code`, `city`, `country`
- `external_id` — mozliwosc mapowania do Registro user ID

### 2.9 Webhooks

- Eventy: invoice created, client created, payment received
- **Tylko dla faktur sprzedazowych** (nie kosztowych)
- Konfiguracja w ustawieniach konta Fakturowni

### 2.10 Kody bledow

| Kod HTTP | Znaczenie |
|----------|-----------|
| 200/201 | Sukces |
| 400 | Bledny request |
| 401 | Zly API token |
| 404 | Nie znaleziono |
| 422 | Blad walidacji (np. brak seller data, zly NIP) |
| 500 | Blad serwera |

**Czeste bledy 422:**
- `seller_name - nie moze byc puste` → uzupelnic dane firmy w Fakturowni
- `positions.name - nie moze byc puste` → brak nazwy pozycji
- `buyer_tax_no` zly format → walidowac NIP przed wyslaniem
- Phone > 16 znakow → skrocic numer telefonu

### 2.11 Limity

- Brak udokumentowanych rate limits
- Paginacja: domyslnie 25, max 100 per page
- Zalecenie: max 10-20 rownoczesnych polaczen

### 2.12 Numeracja faktur

- Domyslna: `nr/yyyy` (np. 1/2026)
- Konfigurowalny format: `nr/mm/yyyy`, `FV/nr/yyyy`
- Mozliwosc podania wlasnego numeru: pole `number`
- Rozne formaty per department

### 2.13 Brak sandboxa

Fakturownia NIE MA dedykowanego sandboxa. Zalecenie:
- Oddzielne konto testowe (`registro-test.fakturownia.pl`)
- Tryb DEMO KSeF (od 01.02.2026) — testowanie bez wplywu na produkcyjny KSeF

---

## 3. PHP PACKAGES DLA LARAVEL

### Opcja 1: `abb/fakturownia` (REKOMENDOWANE)

```bash
composer require abb/fakturownia
```

- PHP 7.4+, curl + json extensions
- Dojrzaly, utrzymywany
- Pelne CRUD: invoices, clients, products
- Obsluga bledow: `ApiException`
- Pobieranie PDF

```php
use Abb\Fakturownia\Client\ConfigBuilder;
use Abb\Fakturownia\Fakturownia;

$config = ConfigBuilder::create()
    ->withSubdomain(config('services.fakturownia.domain'))
    ->withApiToken(config('services.fakturownia.token'))
    ->build();

$api = new Fakturownia($config);

// Tworzenie faktury
$invoice = $api->invoices()->create([
    'kind' => 'vat',
    'buyer_name' => 'Firma Sp. z o.o.',
    'buyer_tax_no' => '1234567890',
    'positions' => [
        ['name' => 'Detailing', 'quantity' => 1, 'total_price_gross' => 500, 'tax' => 23]
    ],
    'gov_save_and_send' => true
]);

// Pobieranie PDF
$pdf = $api->invoices()->getPdf($invoice['id']);
```

### Opcja 2: `mattm/fakturownia-for-laravel`

```bash
composer require mattm/fakturownia-for-laravel
```

- Laravel service provider
- `.env` config: `FAKTUROWNIA_DOMAIN`, `FAKTUROWNIA_TOKEN`
- Helper classes

### Rekomendacja

Uzyc `abb/fakturownia` jako base client + wlasny `FakturowniaClient` wrapper w `app/Services/Invoice/` dla business logic specyficznej dla Registro.

---

## 4. ANALIZA KODU — STAN OBECNY

### 4.1 Co juz istnieje

| Element | Status | Lokalizacja |
|---------|--------|-------------|
| Dane billingowe klienta | ✅ Istnieje (01.2026) | User model: `nip`, `company_name`, `billing_street/building/apartment/postal_code/city` |
| Edycja danych w profilu | ✅ Istnieje | `profile/partials/tab-personal.blade.php` — sekcja "Dane do faktury (opcjonalne)" |
| Ceny uslug | ✅ Istnieje | `Service.price` (decimal:2), `Service.price_from` (decimal:2) |
| Appointment z service | ✅ Istnieje | `Appointment.service_id` FK |
| Email po rezerwacji | ✅ Istnieje | `AppointmentCreatedNotification` via EmailServiceChannel |
| Vehicle data na appointment | ✅ Istnieje | `vehicle_type_id`, `vehicle_brand`, `vehicle_model` |
| Contact data snapshot | ✅ Istnieje | `first_name`, `last_name`, `email`, `phone` na appointment |

### 4.2 Czego brakuje (KRYTYCZNE)

| Element | Wplyw | Priorytet |
|---------|-------|-----------|
| **Model Invoice / tabela** | Nie ma gdzie przechowywac faktur | KRYTYCZNY |
| **price_at_booking na Appointment** | Zmiana cennika retroaktywnie zmienia kwoty | KRYTYCZNY |
| **VAT rate na Service** | Hardcoded 23% | WYSOKI |
| **wants_invoice na Appointment** | Nie wiadomo czy klient chce fakture | WYSOKI |
| **payment_status na Appointment** | Nie wiadomo czy zaplacono | WYSOKI |
| **Integracja Fakturownia API** | Brak calkowity | KRYTYCZNY |
| **Walidacja NIP** | Tylko pole string, brak checksumu | SREDNI |
| **Billing data w Filament** | Admin nie widzi NIP/company | SREDNI |
| **InvoiceResource w Filament** | Brak zarzadzania fakturami | WYSOKI |

### 4.3 Migracja danych billingowych (juz istniejaca)

```
2026_01_11_100001_add_billing_address_to_users_table.php
```

Pola: `nip` (15 chars), `company_name` (255), `billing_street` (255), `billing_building_number` (20), `billing_apartment_number` (20), `billing_postal_code` (10), `billing_city` (100)

### 4.4 Profil klienta — formularz danych billingowych

Plik: `resources/views/profile/partials/tab-personal.blade.php`
- Sekcja "Dane do faktury (opcjonalne)"
- Pola: company_name, NIP (10 cyfr), billing address
- Wszystkie pola opcjonalne
- Dane zapisywane do User model

### 4.5 Appointment — brak danych finansowych

Appointment model (`app/Models/Appointment.php`) NIE MA:
- `price` / `price_at_booking` — cena brana z `$appointment->service->price` dynamicznie
- `wants_invoice` — nie wiadomo czy klient chce fakture
- `payment_status` — nie wiadomo czy zaplacono
- `invoice_id` — nie ma powiazania z faktura

### 4.6 Service — brak VAT

Service model (`app/Models/Service.php`) MA:
- `price` (decimal:2)
- `price_from` (decimal:2)

NIE MA:
- `vat_rate` — brak stawki VAT (assumed 23%)
- Ceny sa NET czy GROSS? — nie jest jasne z kodu

### 4.7 Filament admin

- `UserResource` — NIE pokazuje NIP, company_name, billing address
- `AppointmentResource` — NIE pokazuje ceny, platnosci, faktur
- `ServiceResource` — NIE pokazuje VAT rate
- Brak `InvoiceResource`

---

## 5. ARCHITEKTURA INTEGRACJI — PROPOZYCJA

### 5.1 Flow glowny

```
1. Appointment status → "completed"
      ↓
2. Observer/Event sprawdza wants_invoice
      ↓
3. CreateInvoiceJob (queue: "invoices")
      ↓
4. InvoiceService::createFromAppointment()
   - Snapshot buyer data (NIP, name, address)
   - Calculate net/gross/VAT
   - Create local Invoice record (status: draft)
      ↓
5. FakturowniaClient::createInvoice()
   - POST /invoices.json
   - gov_save_and_send: true (jesli B2B)
      ↓
6. Update Invoice record
   - fakturownia_id, invoice_number, view_url
   - status: issued
   - gov_status: processing
      ↓
7. Webhook / polling → gov_status: ok
      ↓
8. Email do klienta z linkiem do PDF
```

### 5.2 Manual override (admin)

```
Admin → AppointmentResource → "Wystaw fakture"
    ↓
Modal z pre-filled danymi (mozliwosc edycji)
    ↓
Dispatch CreateInvoiceJob
    ↓
(ten sam flow co auto)
```

### 5.3 Invoice model — schema

```
invoices:
  id (bigint PK)
  appointment_id (FK → appointments, nullable)
  customer_id (FK → users)

  -- Fakturownia integration --
  fakturownia_id (bigint, nullable)
  invoice_number (string, nullable)
  kind (enum: vat, proforma, correction, receipt)
  status (enum: draft, issued, sent, paid, cancelled)

  -- Financial data (snapshot) --
  amount_net (decimal 10,2)
  vat_amount (decimal 10,2)
  amount_gross (decimal 10,2)
  vat_rate (decimal 5,2, default 23)
  currency (string 3, default PLN)

  -- Buyer data (snapshot) --
  buyer_name (string)
  buyer_nip (string, nullable)
  buyer_company_name (string, nullable)
  buyer_email (string)
  billing_address (json)
  is_company (boolean, default false)

  -- URLs --
  view_url (string, nullable)
  pdf_url (string, nullable)

  -- KSeF --
  gov_status (enum: null, processing, ok, rejected)
  gov_id (string, nullable)

  -- Timestamps --
  issued_at (datetime, nullable)
  paid_at (datetime, nullable)
  sent_at (datetime, nullable)
  timestamps()
  softDeletes()
```

### 5.4 Appointment updates

```
appointments (add columns):
  price_at_booking (decimal 10,2, nullable)
  wants_invoice (boolean, default false)
  payment_status (enum: pending, paid, default pending)
```

### 5.5 Service updates

```
services (add column):
  vat_rate (decimal 5,2, default 23)
```

### 5.6 Serwisy

**InvoiceService** (`app/Services/Invoice/InvoiceService.php`):
- `createFromAppointment(Appointment): Invoice` — snapshot danych, utworzenie rekordu
- `sendToFakturownia(Invoice): void` — wyslanie do API
- `handleWebhook(array): void` — obsluga callbackow
- `markAsPaid(Invoice): void` — aktualizacja statusu
- `createCorrection(Invoice, string reason): Invoice` — korekta

**FakturowniaClient** (`app/Services/Invoice/FakturowniaClient.php`):
- Wrapper na `abb/fakturownia`
- Konfiguracja z `config/services.php`
- Logowanie wszystkich API calls
- Retry logic (3 proby, exponential backoff)

**NipValidator** (`app/Services/Invoice/NipValidator.php`):
- Walidacja checksumu NIP (algorytm wagowy: 6,5,7,2,3,4,5,6,7)
- Zwraca bool

### 5.7 Queue Job

**CreateInvoiceJob** (`app/Jobs/CreateInvoiceJob.php`):
- Queue: `invoices`
- Max tries: 3
- Backoff: [60, 120, 300] sekund
- Failed → log + notification do admina

### 5.8 Filament admin

**InvoiceResource:**
- Lista faktur (numer, klient, kwota, status, KSeF status)
- Podglad szczegolowy
- Przycisk "Wyslij ponownie email"
- Filtrowanie: status, data, klient

**AppointmentResource (rozszerzenie):**
- Kolumna: cena, status platnosci
- Akcja: "Wystaw fakture" (manual trigger)
- Relation manager: powiazane faktury

**UserResource (rozszerzenie):**
- Tab/Section: Dane billingowe (NIP, company, address)
- Relation manager: faktury klienta

### 5.9 Booking flow

**Review step (step 5):**
- Checkbox: "Chce fakture VAT" (pre-filled z profilu)
- Jesli zaznaczone + NIP w profilu → pokaz dane billingowe
- Jesli zaznaczone + brak NIP → pokaz formularz NIP/company/address
- Zapisz `wants_invoice` w sesji → na appointment

### 5.10 Konfiguracja

```php
// config/services.php
'fakturownia' => [
    'domain' => env('FAKTUROWNIA_DOMAIN'),
    'token' => env('FAKTUROWNIA_TOKEN'),
    'auto_ksef' => env('FAKTUROWNIA_AUTO_KSEF', true),
    'default_payment_days' => env('FAKTUROWNIA_PAYMENT_DAYS', 14),
],
```

```env
# .env
FAKTUROWNIA_DOMAIN=registro
FAKTUROWNIA_TOKEN=your_api_token
FAKTUROWNIA_AUTO_KSEF=true
FAKTUROWNIA_PAYMENT_DAYS=14
```

---

## 6. PYTANIA OTWARTE (do ustalenia przed implementacja)

1. **Platnosci online?** Czy PayU bedzie wdrazone razem? Wplywa na `payment_status` i `payment_type`
2. **Email z faktura?** Fakturownia wysyla automatycznie czy Registro kontroluje?
3. **Proforma?** Czy potrzebna faktura proforma przy rezerwacji?
4. **Subdomena Fakturowni?** Jaka subdomena klienta
5. **Dane sprzedawcy** — czy uzupelnione w Fakturowni? (NIP, adres, bank)
6. **Ceny NET czy GROSS?** Czy `Service.price` = netto czy brutto?

---

## 7. SYNERGIA Z INNYMI FEATURE'AMI

### Dynamic Vehicle Pricing

Oba feature'y wymagaja `price_at_booking` na appointment.
Wspolna migracja → oszczednosc ~3-4h.

### Multi-Service Booking

Jesli appointment ma wiele uslug → faktura z wieloma pozycjami.
Invoice model powinien miec `invoice_items` (nie tylko jedna pozycja).
Decyzja: czy robic to teraz czy w przyszlosci?

---

## 8. ZRODLA RESEARCHU

### Fakturownia API
- https://app.fakturownia.pl/api
- https://github.com/fakturownia/API
- https://github.com/fakturownia/API/blob/master/README.md
- https://github.com/fakturownia/API/blob/master/KSeF.md
- https://pomoc.fakturownia.pl/416252-Wyslanie-faktury-e-mailem
- https://pomoc.fakturownia.pl/1239014-Integracja-klientow-webhooks

### KSeF
- https://ksef.podatki.gov.pl/
- https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/zakres-obowiazkowego-ksef/
- https://www.gov.pl/web/ias-bialystok/obowiazkowy-ksef-przesuniety-na-1-lutego-2026-r
- https://fakturownia.pl/ksef
- https://pomoc.fakturownia.pl/200856394-Jak-polaczyc-Fakturownie-z-KSeF-2-0
- https://www.vatax.pl/blog/ksef-fiskus-nie-bedzie-karac
- https://taxcoach.pl/en/blog/consulting/ksef-2026

### PHP Packages
- https://github.com/mariusz-zajac/fakturownia (abb/fakturownia)
- https://github.com/MattMoszczynski/Fakturownia-For-Laravel
- https://packagist.org/packages/abb/fakturownia

### B2C/B2B/Paragony
- https://poradnikprzedsiebiorcy.pl/-jakich-faktur-nie-dotyczy-ksef
- https://miabiznes.pl/en/faq/ksef-9/
- https://fakturasmart.pl/magazyn/ksef/ktore-osoby-fizyczne-zobowiazane-2026
