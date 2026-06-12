# Rental Pricing — Dual brutto/netto + Price On Request

## Kontekst

Funkcje dodane 2026-03-31 wzorowane na ramirent.pl:
- **C: Dual pricing** — cena brutto + netto per tenant VAT rate
- **I: Price on request** — flaga `price_on_request` ukrywa cenę, pokazuje formularz zapytania

---

## C — Dual pricing brutto/netto

### Konfiguracja (per tenant)

Panel admina → Settings → General → "Stawka VAT (%)" (domyślnie 23%).

Klucz: `general.vat_rate` w tabeli `settings`.

### Metody SettingsManager

```php
app(\App\Support\Settings\SettingsManager::class)->vatRate(): int       // zwraca np. 23
app(\App\Support\Settings\SettingsManager::class)->nettoPrice(float $brutto): float  // netto = brutto / (1 + vat/100)
```

### KRYTYCZNE — jak wywoływać z widoków Blade

```blade
{{-- ✅ PRAWIDŁOWO — pełna FQCN --}}
app(\App\Support\Settings\SettingsManager::class)->vatRate()

{{-- ❌ BŁĄD — 'settings' nie jest zarejestrowane jako alias --}}
app('settings')->vatRate()  // BindingResolutionException: Target class [settings] does not exist
```

`SettingsManager` jest zarejestrowany jako `app(SettingsManager::class)`, NIE pod stringiem `'settings'`.

### Gdzie wyświetlane

| Widok | Co |
|-------|----|
| `services/show.blade.php` | `(X,XX zł netto)` pod każdą ceną brutto |
| `cart/show.blade.php` | "Ceny brutto (w tym VAT X%)" |
| `checkout/show.blade.php` | "Ceny brutto, w tym VAT X%" |

### Seeding dla nowych organizacji

`SeedOrganizationDefaults` → `general.vat_rate = 23`

---

## I — Price On Request ("Cena do potwierdzenia")

### Model

Pole `price_on_request boolean default false` na tabeli `services`.

Tylko dla `service_type = item_rental` — `HasRentalBehavior` trait wymusza `false` na TimeSlot services.

### Gdzie widoczne

**Kafelki produktów — service-card + index:**
- `service-card.blade.php` — zamiast badge cenowego pokazuje "Cena do potwierdzenia" z ikoną chat
- `services/index.blade.php` — zamiast ceny: italic "Cena do potwierdzenia"

**Strona produktu (`services/show.blade.php`):**
- Ukrywa kafelki cenowe + formularz koszyka
- Pokazuje: tekst + przycisk "Zapytaj o cenę" → otwiera modal Alpine.js

### Modal Alpine.js

Wysyła `POST /uslugi/{service:slug}/zapytaj` (route: `service.inquiry`).

Pola: `name`, `email`, `phone` (opt.), `message` (opt.).

CSRF: czytany z `meta[name=csrf-token]`.

### Endpoint

`ServiceInquiryController::store()`:
1. `abort_unless($service->price_on_request, 422)` — guard przed bypasem przez URL
2. Walidacja 4 pól
3. Recipient: `checkout.inquiry_email` → fallback: `email.from_address` → log warning + 503 jeśli oba puste
4. Dispatch `InquiryNotification` (ShouldQueue, ShouldBeUnique, queue: `emails`)

### Konfiguracja recipient

Panel admina → Settings → Checkout → "Email dla zapytań o cenę".

Klucz: `checkout.inquiry_email`.

### Throttle

`throttle:5,1` — 5 requestów/minutę per IP.

---

## Architektura modelu Service

### HasRentalBehavior Trait

`app/Models/Concerns/HasRentalBehavior.php` — Bootable Trait:
- Boot guard: zeruje `price_on_request=false` gdy `service_type !== ItemRental` (na creating + updating)
- Metoda: `isRentalPriceOnRequest(): bool`

### ServiceType enum helpers

```php
ServiceType::ItemRental->isRental()   // true
ServiceType::TimeSlot->isTimeSlot()   // true
```

### RentalPricing Value Object

`app/ValueObjects/RentalPricing.php` — readonly VO z `nettoPrice(float, int): float`.

---

## Incydenty

### 2026-03-31: app('settings') BindingResolutionException

`frontend-ui-architect` użył `app('settings')` w widokach. SettingsManager nie ma alias `'settings'` — zawsze używaj pełnej FQCN.
