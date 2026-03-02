# Architecture Analysis: Guest Checkout — Auth Coupling Points

**Data:** 2026-02-01
**Cel:** Mapa wszystkich miejsc w kodzie sprzężonych z auth(), wymagających modyfikacji dla guest checkout

---

## 1. BookingController (15 coupling points)

**Plik:** `app/Http/Controllers/BookingController.php` (745 linii)

| # | Linia | Typ | Opis | Zmiana |
|---|-------|-----|------|--------|
| 1 | 35 | middleware | `$this->middleware('auth')` | Usunąć, dodać warunkowy auth |
| 2 | 162 | read | `if (auth()->check())` — prefill contact | Zachować, dodać else branch |
| 3 | 163 | read | `$user = auth()->user()` — get user | Warunkowy: user lub null |
| 4 | 507 | read | `$user = auth()->user()` — confirm() | Warunkowy: user lub guest data |
| 5 | 521 | audit | Log user ID | Warunkowy: user_id lub 'guest' |
| 6 | 522 | audit | Log user ID kontynuacja | j.w. |
| 7 | 529 | query | `where('customer_id', $user->id)` — duplikaty | Zmienić na email+date+service check |
| 8 | 598 | transaction | Blok tworzenia appointment — start | Dodać guest branch |
| 9 | 625 | write | `'customer_id' => $user->id` | Warunkowy: user->id lub null |
| 10 | 628-640 | write | Contact fields from user profile | Warunkowy: z profilu lub z formularza |
| 11 | 650 | event | Dispatch AppointmentCreated | Bez zmian (event jest agnostyczny) |
| 12 | 662 | transaction | Blok tworzenia — end | Dodać guest notification dispatch |
| 13 | 687 | read | Session token verification | Bez zmian |
| 14 | 712 | security | `$appointment->customer_id !== auth()->id()` | Dodać alternatywny check po guest_token |
| 15 | 735 | security | `$appointment->customer_id !== auth()->id()` (iCal) | j.w. — guest_token check |

### Strategia refactoringu

```php
// Obecny pattern (PRZED):
$user = auth()->user();
$appointment = Appointment::create([
    'customer_id' => $user->id,
    'first_name'  => $user->first_name,
    // ...
]);

// Nowy pattern (PO):
$user = auth()->user(); // może być null
$isGuest = is_null($user);

$appointment = Appointment::create([
    'customer_id' => $user?->id, // nullable
    'is_guest'    => $isGuest,
    'guest_token' => $isGuest ? Str::uuid() : null,
    'first_name'  => $isGuest ? $validated['first_name'] : $user->first_name,
    // ...
]);
```

---

## 2. AppointmentController (5 coupling points)

**Plik:** `app/Http/Controllers/AppointmentController.php` (197 linii)

| # | Linia | Typ | Opis | Zmiana |
|---|-------|-----|------|--------|
| 1 | 18 | middleware | `$this->middleware('auth')` | Zachować — to jest panel "Moje rezerwacje" |
| 2 | 24 | read | `Auth::user()->customerAppointments()` | Bez zmian — wymaga auth |
| 3 | 105 | read | `$user = Auth::user()` | Bez zmian |
| 4 | 158 | write | `'customer_id' => Auth::id()` | Bez zmian — ten controller = auth users |
| 5 | 181 | security | `$appointment->customer_id !== Auth::id()` | Bez zmian |

**Decyzja:** AppointmentController pozostaje auth-only. Goście zarządzają rezerwacjami przez GuestBookingController (nowy) z token-based auth.

---

## 3. Routes — web.php (14 chronionych tras)

**Plik:** `routes/web.php` (linie 109-143)

| # | Route | Method | Middleware | Zmiana |
|---|-------|--------|-----------|--------|
| 1 | `/services/{service}/book` | GET | auth | Usunąć auth |
| 2 | `/booking/available-slots` | GET | auth | Usunąć auth |
| 3 | `/booking/step/{step}` | GET | auth | Usunąć auth |
| 4 | `/booking/change-service` | GET | auth | Usunąć auth |
| 5 | `/booking/step/{step}` | POST | auth, throttle:30,1 | Usunąć auth |
| 6 | `/booking/save-progress` | POST | auth, throttle:30,1 | Usunąć auth |
| 7 | `/booking/restore-progress` | GET | auth | Usunąć auth |
| 8 | `/booking/unavailable-dates` | GET | auth | Usunąć auth |
| 9 | `/booking/confirm` | POST | auth, throttle:10,1 | Usunąć auth, wzmocnić throttle |
| 10 | `/booking/confirmation` | GET | auth | Usunąć auth |
| 11 | `/booking/ical/{appointment}` | GET | auth | Warunkowy: auth OR guest_token |
| 12 | `/my-appointments` | GET | auth | Zachować auth |
| 13 | `/appointments` | POST | auth | Zachować auth |
| 14 | `/appointments/{appointment}/cancel` | POST | auth | Zachować auth |

### Nowe trasy (guest-specific)

```php
// Guest management routes (token-based)
Route::prefix('guest')->group(function () {
    Route::get('/booking/{guest_token}', [GuestBookingController::class, 'show'])
        ->name('guest.booking.show');
    Route::post('/booking/{guest_token}/cancel', [GuestBookingController::class, 'cancel'])
        ->name('guest.booking.cancel');
    Route::get('/booking/{guest_token}/ical', [GuestBookingController::class, 'downloadIcal'])
        ->name('guest.booking.ical');
});

// AJAX auth routes (for inline login)
Route::prefix('ajax')->middleware('throttle:10,1')->group(function () {
    Route::post('/check-email', [AjaxAuthController::class, 'checkEmail'])
        ->name('ajax.check-email');
    Route::post('/login', [AjaxAuthController::class, 'login'])
        ->name('ajax.login');
    Route::post('/magic-link', [AjaxAuthController::class, 'sendMagicLink'])
        ->name('ajax.magic-link');
});

// Email verification for guests
Route::get('/booking/verify/{token}', [GuestBookingController::class, 'verifyEmail'])
    ->name('guest.booking.verify');
```

---

## 4. Appointment Model (4 coupling points)

**Plik:** `app/Models/Appointment.php` (370 linii)

| # | Linia | Typ | Opis | Zmiana |
|---|-------|-----|------|--------|
| 1 | 77 | fillable | `'customer_id'` w $fillable | Zachować |
| 2 | 120-122 | relationship | `customer()` → belongsTo(User) | Zachować (nullable) |
| 3 | 186-189 | scope | `scopeForCustomer(int $customerId)` | Zachować, dodać scopeForGuest |
| 4 | ~98-104 | fillable | Contact fields (already nullable) | Zachować |

### Nowe elementy modelu

```php
// Nowe pola w $fillable
'is_guest',
'guest_token',
'guest_email_verified_at',

// Nowe scopy
public function scopeGuests(Builder $query): Builder
{
    return $query->where('is_guest', true);
}

public function scopeForGuestToken(Builder $query, string $token): Builder
{
    return $query->where('guest_token', $token);
}

// Helper
public function isGuest(): bool
{
    return (bool) $this->is_guest;
}
```

---

## 5. Database Schema (2 constrainty)

**Plik:** `database/migrations/2025_10_06_190503_create_appointments_table.php`

| # | Constraint | Obecny | Zmiana |
|---|-----------|--------|--------|
| 1 | `customer_id` | `foreignId()->constrained('users')->cascadeOnDelete()` | `nullable()->constrained('users')->nullOnDelete()` |
| 2 | Index | `index(['customer_id', 'appointment_date'])` | Zachować + dodać index na `guest_token` |

### Migration plan

```php
// Nowa migracja: xxxx_make_customer_id_nullable_add_guest_fields.php

Schema::table('appointments', function (Blueprint $table) {
    // 1. Drop existing foreign key
    $table->dropForeign(['customer_id']);

    // 2. Make nullable + new constraint
    $table->foreignId('customer_id')
        ->nullable()
        ->change();

    $table->foreign('customer_id')
        ->references('id')
        ->on('users')
        ->nullOnDelete();

    // 3. Add guest fields
    $table->boolean('is_guest')->default(false)->after('status');
    $table->uuid('guest_token')->nullable()->unique()->after('is_guest');
    $table->timestamp('guest_email_verified_at')->nullable()->after('guest_token');

    // 4. Index
    $table->index('guest_token');
    $table->index('is_guest');
});
```

---

## 6. Views — Blade (3 coupling points)

**Plik:** `resources/views/booking-wizard/steps/contact.blade.php` (468 linii)

| # | Linia | Typ | Opis | Zmiana |
|---|-------|-----|------|--------|
| 1 | 47-159 | prefill | Inputy z `$bookingData` (auth user data) | Warunkowy: auth prefill vs empty |
| 2 | 117-119 | readonly | Email readonly dla auth users | Warunkowy: readonly jeśli auth, editable jeśli guest |
| 3 | 245 | terms | Terms checkbox | Bez zmian |

### Nowe elementy view

- Email-first: dodać AJAX check po blur na email field
- Login modal: Alpine.js overlay z hasło/magic-link
- Auto-fill: po AJAX login → wypełnić formularz danymi usera
- CSRF refresh: po login → zaktualizować meta tag + hidden inputs

---

## 7. Notifications (1 coupling point)

**Plik:** `app/Notifications/AppointmentCreatedNotification.php` (109 linii)

| # | Linia | Typ | Opis | Zmiana |
|---|-------|-----|------|--------|
| 1 | 76 | relationship | Loads appointment with `customer` relationship | Warunkowy: customer relationship lub null |

### Nowe notifications

```php
// GuestBookingConfirmation — On-Demand
Notification::route('mail', $appointment->email)
    ->notify(new GuestBookingConfirmation($appointment));

// GuestBookingCancellation — On-Demand
Notification::route('mail', $appointment->email)
    ->notify(new GuestBookingCancellation($appointment));
```

---

## 8. Podsumowanie zmian

### Pliki do MODYFIKACJI (existing)

| Plik | Coupling points | Złożoność |
|------|----------------|-----------|
| `BookingController.php` | 15 → 15 zmian | KRYTYCZNA |
| `web.php` | 14 → 10 zmian + 8 nowych tras | WYSOKA |
| `Appointment.php` | 4 → 2 zmian + 4 nowe elementy | ŚREDNIA |
| `contact.blade.php` | 3 → 3 zmian + 2 nowe sekcje | ŚREDNIA |
| `AppointmentResource.php` | 0 → 3 nowe elementy (is_guest badge, filter, column) | NISKA |
| `AppointmentCreatedNotification.php` | 1 → 1 zmiana (null-safe) | NISKA |

### Pliki NOWE

| Plik | Cel |
|------|-----|
| Migration (nullable + guest fields) | Schema changes |
| `AjaxAuthController.php` | AJAX email check + login |
| `GuestBookingController.php` | Magic link management |
| `GuestBookingService.php` | Guest booking business logic |
| `GuestBookingConfirmation.php` | Email notification |
| `GuestBookingCancellation.php` | Cancel notification |
| `booking-login-modal.blade.php` | Alpine.js login overlay |
| `guest/booking-management.blade.php` | Token-based booking view |
| `account-benefits-checklist.blade.php` | Reusable component: lista korzyści konta |
| `config/recaptcha.php` | reCAPTCHA configuration |
| Tests (2-3 files) | 25-30 test cases |

---

## 9. Komponent UX: Zestawienie korzyści konta

### Uzasadnienie (research 80+ źródeł)

- **Format:** Inline checklist z ikonami ✓ (bullet points +20-30% konwersji vs wall of text)
- **NIE tabelka** — słaba na mobile, sugeruje dwie równorzędne opcje
- **NIE modal/popup** — "needy design pattern" (NN/g), obniża zaufanie
- **Timing:** POST-PURCHASE — 42% serwisów pokazuje za wcześnie i traci klientów (Baymard)
- **Framing:** Pozytywny ("zyskujesz") — loss aversion działa, ale negatywny framing odstrasza w kontekście checkout
- **Oczekiwana konwersja:** 15-22% gości zakłada konto (Creative Market case study)

### Pliki

**`resources/views/components/account-benefits-checklist.blade.php`** (NEW)
- Reusable Blade component z listą korzyści
- Responsive: pionowa lista na mobile, bottom sheet trigger po 3s
- Props: `$showForm` (boolean) — czy wyświetlić formularz "podaj hasło"
- Touch target min 44px na CTA

**`resources/views/booking-wizard/confirmation.blade.php`** (MODIFIED)
- Import komponentu `<x-account-benefits-checklist :show-form="true" />`
- Formularz: email (readonly, z rezerwacji) + hasło + "Utwórz konto"
- Link "Może później" (non-blocking, dismiss)

**`app/Notifications/GuestBookingConfirmation.php`** (integracja)
- Sekcja "Załóż konto i zyskaj:" z listą korzyści (HTML email)
- CTA: magic link do założenia konta jednym kliknięciem

### Treść (specyficzna dla Registro)

```
Z KONTEM ZYSKUJESZ:
✓ Przypomnienia SMS o nadchodzącej wizycie
✓ Rezerwacja ponowna w 10 sekund z historii
✓ Karta stałego klienta — zniżki na usługi
✓ Informacje o promocjach i nowościach
✓ Zapisane auta w profilu — bez wpisywania za każdym razem
✓ Pełna historia rezerwacji w panelu "Moje rezerwacje"
```

### Ryzyka techniczne

1. **Migration rollback:** `customer_id` nullable zmienia constraint — wymaga careful migration z down() method
2. **Session regeneration:** AJAX login regeneruje session ID — booking data musi przetrwać (Laravel migruje dane domyślnie, ale warto przetestować)
3. **CSRF token refresh:** Po AJAX login token się zmienia — DOM musi być zaktualizowany zanim user submituje kolejny step
4. **Idempotency:** Obecny check po `customer_id` nie działa dla gości — potrzebny nowy mechanizm (email + date + service_id)
5. **Race condition:** Dwa requesty confirm() od tego samego gościa — DB unique constraint na `guest_token` + application-level lock
