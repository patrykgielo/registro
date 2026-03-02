# Analiza Techniczna: System Karty Stałego Klienta

**Data:** 2026-02-03
**Task ClickUp:** [86c78c52b](https://app.clickup.com/t/86c78c52b)

---

## 1. Kontekst i wymagania

### Stare wymagania (system kodów rabatowych):
- Admin tworzy kody (LATO2025, VIP50)
- Klient wpisuje kod przy rezerwacji
- Rabat % lub kwotowy, limity użyć, ważność
- Statystyki kodów

### Nowe wymagania (karta stałego klienta):
- Klient dostaje 5% po pierwszej wizycie (auto-aktywacja)
- Zbiera budżet (progress wydanych pieniędzy)
- Po przekroczeniu progu — wyższy rabat (np. kolejne 5%)
- Admin: konfigurator progów (wiele szczebli)
- System przyznawania kart + generator kodu + custom nazwa kodu
- Profil klienta: postępy, kwota do następnego progu
- Kod działa na każdy koszyk (zawsze)
- Karta fizyczna z kodem (QR) + grafika poziomu
- Re-issue karty co 6 miesięcy

### Kluczowe decyzje:
- **Rezygnacja z osobnych kodów promo** — tylko karta lojalnościowa
- **Aktywacja po pierwszej wizycie** (appointment completed)
- **Ceny = brutto** — rabat naliczany od ceny z VAT
- **Grafika: profil online + generacja do druku** (PDF/PNG z QR)

---

## 2. Architektura

### 2.1 Nowe modele

```
LoyaltyTier
├── id
├── name (string) - "Bronze", "Silver", "Gold"
├── slug (string) - "bronze", "silver", "gold"
├── min_spending (decimal) - próg wydatków (500, 2000, 5000)
├── discount_percentage (decimal) - 5, 10, 15
├── color (string) - hex color
├── icon (string) - emoji lub icon class
├── sort_order (int)
├── is_active (bool)
└── timestamps

LoyaltyCard
├── id
├── user_id (FK)
├── loyalty_tier_id (FK)
├── card_number (string) - unique, format: PARA-XXXXXX
├── discount_code (string) - unique, kod do wpisania lub custom
├── status (enum) - pending, active, suspended, cancelled
├── activated_at (timestamp)
├── physical_card_issued_at (timestamp, nullable)
├── physical_card_reissued_at (timestamp, nullable)
└── timestamps

LoyaltyTierHistory
├── id
├── user_id (FK)
├── from_tier_id (FK, nullable)
├── to_tier_id (FK)
├── reason (enum) - first_visit, spending_threshold, manual, downgrade
├── spending_at_change (decimal)
└── created_at
```

### 2.2 Modyfikowane modele

```
User (dodane relacje)
├── loyaltyCard() -> hasOne(LoyaltyCard)
├── tierHistory() -> hasMany(LoyaltyTierHistory)
├── getTotalSpendingAttribute() - obliczone z appointments
└── getCurrentTierAttribute() - przez loyaltyCard

Appointment (dodane pola)
├── loyalty_card_id (FK, nullable)
├── discount_code (string, nullable)
├── discount_percentage (decimal, nullable)
├── discount_amount (decimal, nullable)
├── price_before_discount (decimal)
└── final_price (decimal)
```

### 2.3 Serwisy

```php
LoyaltyService
├── calculateSpending(User $user, int $months = 12): float
├── evaluateTier(User $user): LoyaltyTier
├── activateCard(User $user): LoyaltyCard
├── upgradeTier(LoyaltyCard $card, LoyaltyTier $newTier): void
├── downgradeTier(LoyaltyCard $card, LoyaltyTier $newTier): void
├── shouldActivate(User $user): bool
└── getProgressToNextTier(LoyaltyCard $card): array

DiscountCodeService
├── generate(string $prefix = 'PARA'): string
├── validate(string $code): ?LoyaltyCard
├── isUnique(string $code): bool
└── setCustomCode(LoyaltyCard $card, string $code): void

PriceCalculationService
├── applyLoyaltyDiscount(float $price, LoyaltyCard $card): array
├── calculateDiscount(float $price, float $percentage): float
└── formatPriceBreakdown(float $original, float $discount): array
```

---

## 3. Kluczowe decyzje architektoniczne

### 3.1 Spending tracking

**Decyzja:** Obliczanie z appointments (bez osobnej tabeli)

```sql
SELECT SUM(final_price)
FROM appointments
WHERE user_id = ?
  AND status = 'completed'
  AND appointment_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
```

**Uzasadnienie:**
- Jedno źródło prawdy (appointments)
- Brak synchronizacji między tabelami
- Cache na poziomie modelu dla wydajności

**Index:**
```sql
CREATE INDEX idx_appointments_loyalty ON appointments
(user_id, status, appointment_date, final_price);
```

### 3.2 Tier evaluation

**Decyzja:** Event-driven + scheduled

1. **Event-driven:** Po `AppointmentCompleted`:
   - Nalicz wydatki
   - Sprawdź czy przekroczono próg
   - Awansuj tier jeśli tak
   - Aktywuj kartę jeśli pierwsza wizyta

2. **Scheduled:** Miesięczna re-ewaluacja:
   - Sprawdź wszystkich aktywnych klientów
   - Degraduj jeśli spadli poniżej progu (z grace period w opcji B)

### 3.3 Kod rabatowy

**Decyzja:** 1 aktywny kod per klient

- Format domyślny: `PARA-XXXXXX` (6 znaków alfanumerycznych)
- Możliwość custom kodu (np. `GOLD-KOWALSKI`)
- Kod powiązany z tierem, nie zmienia się przy awansie
- Walidacja: unique w systemie

### 3.4 Discount stacking

**Decyzja:** Brak stackingu

- Tylko 1 rabat na koszyk (karta lojalnościowa)
- Nie łączy się z niczym (bo nie ma osobnych promo kodów)
- Jeśli w przyszłości będą inne rabaty → priorytet: loyalty > promo

### 3.5 Ceny brutto

**Decyzja:** Rabat % od ceny brutto

```php
$originalPrice = $service->price; // brutto (300 PLN)
$discountPercentage = $loyaltyCard->tier->discount_percentage; // 15%
$discountAmount = $originalPrice * ($discountPercentage / 100); // 45 PLN
$finalPrice = $originalPrice - $discountAmount; // 255 PLN
```

---

## 4. Integracja z istniejącym kodem

### 4.1 BookingController Step 5 (review)

**Obecny kod:**
```php
$serviceFee = 0; // placeholder
```

**Po integracji:**
```php
// Auto-detect loyalty card
$loyaltyDiscount = null;
if (auth()->check() && $user = auth()->user()) {
    if ($card = $user->loyaltyCard) {
        $loyaltyDiscount = app(PriceCalculationService::class)
            ->applyLoyaltyDiscount($servicePrice, $card);
    }
}

// Store in session
session(['booking.loyalty_discount' => $loyaltyDiscount]);
```

### 4.2 Booking session data

**Dodane pola:**
```php
session([
    'booking.loyalty_card_id' => $card->id,
    'booking.discount_code' => $card->discount_code,
    'booking.discount_percentage' => $card->tier->discount_percentage,
    'booking.discount_amount' => $discountAmount,
    'booking.price_before_discount' => $originalPrice,
    'booking.final_price' => $finalPrice,
]);
```

### 4.3 BookingController confirm()

**Zapis na Appointment:**
```php
$appointment = Appointment::create([
    // ... existing fields ...
    'loyalty_card_id' => session('booking.loyalty_card_id'),
    'discount_code' => session('booking.discount_code'),
    'discount_percentage' => session('booking.discount_percentage'),
    'discount_amount' => session('booking.discount_amount'),
    'price_before_discount' => session('booking.price_before_discount'),
    'final_price' => session('booking.final_price'),
]);
```

### 4.4 Event: AppointmentCompleted

```php
// app/Listeners/ProcessLoyaltyAfterAppointment.php

public function handle(AppointmentCompleted $event): void
{
    $appointment = $event->appointment;
    $user = $appointment->user;

    if (!$user) return;

    $loyaltyService = app(LoyaltyService::class);

    // Aktywuj kartę jeśli pierwsza wizyta
    if ($loyaltyService->shouldActivate($user)) {
        $loyaltyService->activateCard($user);
    }

    // Sprawdź awans tieru
    if ($card = $user->loyaltyCard) {
        $newTier = $loyaltyService->evaluateTier($user);
        if ($newTier->id !== $card->loyalty_tier_id) {
            if ($newTier->min_spending > $card->tier->min_spending) {
                $loyaltyService->upgradeTier($card, $newTier);
            }
        }
    }
}
```

---

## 5. UX Koszyka — Pełny Scope

### 5.1 Kluczowa zasada

Rabat lojalnościowy musi być **automatyczny i widoczny** dla zalogowanych użytkowników z kartą. Brak manualnego wpisywania kodu.

### 5.2 Wymagane komponenty UI

**1. Auto-detect + Auto-apply (backend)**
```php
// Na load Step 5
if (auth()->check() && auth()->user()->loyaltyCard) {
    $discountPercentage = auth()->user()->loyaltyCard->tier->discount_percentage;
    // Auto-apply bez akcji użytkownika
}
```

**2. Persistent Loyalty Badge**
```blade
@if(auth()->check() && auth()->user()->loyaltyCard)
<div class="loyalty-badge bg-{{ $tierColor }}-50 border-{{ $tierColor }}-200 rounded-lg p-3">
    <span class="text-2xl">{{ $tierIcon }}</span>
    <div>
        <strong>Karta {{ $tierName }}</strong>
        <small class="text-gray-600">{{ $discountPercentage }}% zniżka aktywna</small>
    </div>
</div>
@endif
```

**3. Discount Line Item (w podsumowaniu ceny)**
```
Usługa:                    300 PLN
Karta Gold (15%):          -45 PLN  ✓
────────────────────────────────────
Do zapłaty:                255 PLN
```

**4. Savings Summary Banner**
```blade
<div class="bg-green-50 border border-green-200 rounded-lg p-4 mt-4">
    <span class="text-green-600 font-medium">
        💰 Oszczędzasz {{ $discountAmount }} PLN ({{ $discountPercentage }}%)
        dzięki Karcie {{ $tierName }}
    </span>
</div>
```

**5. Manual Code Field (backup)**
- Zwinięty domyślnie dla użytkowników z kartą
- Info: "Twój rabat został automatycznie naliczony"
- Widoczny dla gości i użytkowników bez karty

### 5.3 Stany UI

| Stan użytkownika | Zachowanie UI |
|------------------|---------------|
| Zalogowany + karta | Badge + auto-rabat + savings banner |
| Zalogowany + brak karty | Brak badge, pole kodu widoczne |
| Niezalogowany | Pole kodu widoczne, zachęta do logowania |
| Pierwsza wizyta (pending) | Info "Po tej wizycie aktywujesz Kartę!" |

### 5.4 AJAX Real-time Updates

```javascript
// Gdy zmienia się koszyk (np. dodanie/usunięcie usługi)
function updatePriceBreakdown() {
    fetch('/api/booking/calculate-price', {
        method: 'POST',
        body: JSON.stringify({
            services: selectedServices,
            loyalty_card_id: loyaltyCardId
        })
    })
    .then(response => response.json())
    .then(data => {
        updatePriceDisplay(data);
    });
}
```

---

## 6. Filament Admin Panel

### 6.1 LoyaltyTierResource

```php
// Columns
TextColumn::make('name'),
TextColumn::make('min_spending')->money('PLN'),
TextColumn::make('discount_percentage')->suffix('%'),
ColorColumn::make('color'),
TextColumn::make('cards_count')->counts('cards'),

// Form
TextInput::make('name')->required(),
TextInput::make('slug')->required()->unique(),
TextInput::make('min_spending')->numeric()->required(),
TextInput::make('discount_percentage')->numeric()->required(),
ColorPicker::make('color'),
TextInput::make('icon'),
Toggle::make('is_active'),
```

### 6.2 LoyaltyCardResource

```php
// Columns
TextColumn::make('user.name'),
TextColumn::make('tier.name')->badge()->color(fn ($record) => $record->tier->color),
TextColumn::make('card_number'),
TextColumn::make('discount_code'),
TextColumn::make('status')->badge(),
TextColumn::make('activated_at')->date(),

// Filters
SelectFilter::make('tier'),
SelectFilter::make('status'),

// Actions
Action::make('issue_physical_card'),
Action::make('regenerate_code'),
```

### 6.3 CustomerResource (zakładka lojalnościowa)

```php
// W EditCustomer lub ViewCustomer
Tabs\Tab::make('Lojalność')
    ->schema([
        Section::make('Karta Stałego Klienta')
            ->schema([
                Placeholder::make('tier')
                    ->content(fn ($record) => $record->loyaltyCard?->tier->name ?? 'Brak karty'),
                Placeholder::make('discount')
                    ->content(fn ($record) => $record->loyaltyCard?->tier->discount_percentage . '%'),
                Placeholder::make('spending')
                    ->content(fn ($record) => number_format($record->total_spending, 2) . ' PLN'),
                Placeholder::make('progress')
                    ->content(fn ($record) => $this->getProgressBar($record)),
            ]),
    ]),
```

---

## 7. Notyfikacje

### 7.1 Opcja A (podstawowe)

```php
// CardActivatedNotification
"Witaj w programie Karta Stałego Klienta!
Twoja Karta {{ $tierName }} jest aktywna.
Od teraz masz {{ $discountPercentage }}% rabatu na wszystkie usługi."

// TierUpgradedNotification
"Gratulacje! Twoja Karta awansowała na poziom {{ $newTierName }}!
Twój nowy rabat: {{ $newDiscountPercentage }}%"
```

### 7.2 Opcja B (rozbudowane)

```php
// MilestoneNotification (50%, 75%, 90% do następnego poziomu)
"Jesteś już w {{ $percentage }}% drogi do Karty {{ $nextTierName }}!
Wydaj jeszcze {{ $remaining }} PLN, aby odblokować {{ $nextDiscountPercentage }}% rabatu."

// DowngradeWarningNotification
"Uwaga! Twoja Karta {{ $currentTierName }} wygasa za {{ $daysRemaining }} dni.
Wydaj {{ $required }} PLN, aby utrzymać {{ $currentDiscountPercentage }}% rabatu."
```

---

## 8. Aspekty prawne (PL)

### 8.1 Regulamin programu

**Wymagane elementy:**
- Zasady przystąpienia do programu
- Opis poziomów i progów
- Zasady naliczania rabatu
- Czas trwania programu
- Zasady zmiany regulaminu (30 dni wyprzedzenia)
- Prawa uczestnika (RODO)

### 8.2 RODO

**Wymagania:**
- Zgoda na przetwarzanie danych (już istnieje w User model: `marketing_consent`)
- Prawo dostępu do danych
- Prawo do usunięcia (anonimizacja karty, nie usunięcie)
- Consent tracking (timestamp zgody)

### 8.3 VAT

**Zasada:** Rabat w momencie sprzedaży obniża podstawę VAT.

```
Faktura:
- Usługa detailingu: 300 PLN brutto (243,90 PLN netto + 56,10 PLN VAT)
- Rabat Karta Gold 15%: -45 PLN brutto (-36,59 PLN netto - 8,41 PLN VAT)
- Razem: 255 PLN brutto (207,32 PLN netto + 47,68 PLN VAT)
```

### 8.4 PIT

**Zasada:** Nagrody/rabaty < 2000 PLN/rok = zwolnione z PIT.
W przypadku car detailing praktycznie niemożliwe przekroczenie.

---

## 9. Migracje

### 9.1 loyalty_tiers

```php
Schema::create('loyalty_tiers', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->decimal('min_spending', 10, 2)->default(0);
    $table->decimal('discount_percentage', 5, 2);
    $table->string('color')->nullable();
    $table->string('icon')->nullable();
    $table->unsignedInteger('sort_order')->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

### 9.2 loyalty_cards

```php
Schema::create('loyalty_cards', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->foreignId('loyalty_tier_id')->constrained()->onDelete('restrict');
    $table->string('card_number')->unique();
    $table->string('discount_code')->unique();
    $table->enum('status', ['pending', 'active', 'suspended', 'cancelled'])->default('pending');
    $table->timestamp('activated_at')->nullable();
    $table->timestamp('physical_card_issued_at')->nullable();
    $table->timestamp('physical_card_reissued_at')->nullable();
    $table->timestamps();

    $table->index(['user_id', 'status']);
});
```

### 9.3 loyalty_tier_history

```php
Schema::create('loyalty_tier_history', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->foreignId('from_tier_id')->nullable()->constrained('loyalty_tiers');
    $table->foreignId('to_tier_id')->constrained('loyalty_tiers');
    $table->enum('reason', ['first_visit', 'spending_threshold', 'manual', 'downgrade']);
    $table->decimal('spending_at_change', 10, 2);
    $table->timestamp('created_at');

    $table->index(['user_id', 'created_at']);
});
```

### 9.4 appointments (dodane pola)

```php
Schema::table('appointments', function (Blueprint $table) {
    $table->foreignId('loyalty_card_id')->nullable()->constrained()->nullOnDelete();
    $table->string('discount_code')->nullable();
    $table->decimal('discount_percentage', 5, 2)->nullable();
    $table->decimal('discount_amount', 10, 2)->nullable();
    $table->decimal('price_before_discount', 10, 2)->nullable();
    $table->decimal('final_price', 10, 2)->nullable();

    $table->index(['loyalty_card_id']);
});
```

---

## 10. Testy

### 10.1 Unit Tests

```php
// LoyaltyServiceTest
- test_calculates_spending_for_last_12_months()
- test_excludes_cancelled_appointments_from_spending()
- test_evaluates_correct_tier_based_on_spending()
- test_activates_card_after_first_completed_appointment()
- test_upgrades_tier_when_threshold_exceeded()
- test_does_not_downgrade_within_grace_period() // Opcja B

// DiscountCodeServiceTest
- test_generates_unique_code()
- test_validates_existing_code()
- test_allows_custom_code_if_unique()
- test_rejects_duplicate_custom_code()

// PriceCalculationServiceTest
- test_applies_correct_discount_percentage()
- test_returns_formatted_price_breakdown()
```

### 10.2 Feature Tests

```php
// BookingWithLoyaltyTest
- test_logged_in_user_with_card_sees_auto_applied_discount()
- test_guest_user_can_enter_code_manually()
- test_discount_persists_through_booking_session()
- test_discount_saved_on_appointment_after_confirm()
- test_ajax_updates_price_when_services_change()

// LoyaltyActivationTest
- test_card_activates_after_first_completed_appointment()
- test_card_does_not_activate_for_cancelled_appointment()
- test_tier_upgrades_after_threshold_exceeded()
- test_notification_sent_on_activation()
- test_notification_sent_on_tier_upgrade()
```

---

## 11. Referencje (research 2026)

### Best practices:
- Booking.com Genius: auto-apply, blue badge, no codes
- Amazon Prime: persistent savings indicator
- Starbucks: tier badge w checkout

### Źródła:
- Voucherify: Coupon & promotions UI/UX best practices
- ConvertCart: eCommerce Checkout Process Optimization 2026
- Baymard: Flight Booking UX Benchmark 2025
- arrivia: Loyalty Program UX Design Principles
