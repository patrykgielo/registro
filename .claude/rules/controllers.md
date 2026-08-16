---
paths:
  - "app/Http/Controllers/*.php"
  - "app/Http/Controllers/Api/**"
---

# Controller Rules

## Thin Controllers Principle

Controllers powinny być "cienkie" - deleguj logikę biznesową do Services.

```php
// ✅ PRAWIDŁOWO - delegacja do service
public function store(BookingRequest $request)
{
    $appointment = $this->appointmentService->create($request->validated());
    return redirect()->route('booking.confirmation', $appointment);
}

// ❌ ŹLE - logika biznesowa w kontrolerze
public function store(Request $request)
{
    // 50 linii logiki walidacji, tworzenia, emaili...
}
```

## Constructor Dependency Injection

```php
class BookingController extends Controller
{
    public function __construct(
        protected AppointmentService $appointmentService,
        protected SettingsManager $settings
    ) {}
}
```

## Form Request Validation

```php
// ✅ Używaj Form Requests dla kompleksowej walidacji
public function store(BookingRequest $request)
{
    $data = $request->validated();
}

// ❌ Walidacja inline (tylko dla prostych przypadków)
public function store(Request $request)
{
    $request->validate([...]); // OK dla prostych
}
```

## SettingsManager dla Runtime Config

```php
// ✅ PRAWIDŁOWO
$slotDuration = $this->settings->get('booking.slot_duration', 30);

// ❌ ŹLE - hardcoded
$slotDuration = 30;

// ❌ ŹLE - config() gdy wartość może się zmienić runtime
$slotDuration = config('booking.slot_duration');
```

## Response Types

```php
// Web controllers
return view('booking.create', compact('services', 'staff'));
return redirect()->route('booking.confirmation', $appointment);
return back()->withErrors(['error' => $message]);

// API controllers
return response()->json(['data' => $resource], 201);
return response()->json(['error' => $message], 422);
```

## No Direct Model Queries

```php
// ✅ PRAWIDŁOWO - deleguj do service
$slots = $this->appointmentService->getAvailableSlots($date);

// ❌ ŹLE - queries bezpośrednio w kontrolerze
$slots = Appointment::where('date', $date)
    ->whereIn('status', ['pending', 'confirmed'])
    ->get();
```

## Middleware w Routes (nie w konstruktorze)

```php
// ✅ routes/web.php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'index']);
});

// ❌ W konstruktorze (legacy pattern)
public function __construct()
{
    $this->middleware('auth');
}
```

## Resource Controllers

Używaj resource methods gdy pasują:
- `index()` - lista
- `create()` - formularz tworzenia
- `store()` - zapisz nowy
- `show()` - szczegóły
- `edit()` - formularz edycji
- `update()` - zapisz zmiany
- `destroy()` - usuń

## API Controllers

```php
// app/Http/Controllers/Api/
namespace App\Http\Controllers\Api;

class ServiceAreaController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        // Zwracaj JSON, używaj HTTP status codes
        return response()->json([
            'available' => true,
            'message' => 'Obsługujemy ten obszar'
        ]);
    }
}
```

## Error Handling

```php
try {
    $result = $this->service->riskyOperation();
} catch (DomainException $e) {
    return back()->withErrors(['error' => $e->getMessage()]);
}
```

## `catch (\Exception)` NIE łapie `\Error` — wokół SDK zawsze `\Throwable`

Incydent 2026-08-16: pusta konfiguracja P24 → SDK rzucił `TypeError` (to `\Error`, nie
`\Exception`), przeleciał przez `catch (\Exception)` w `CheckoutController::submit()` → klient
dostał **500**, zamówienie osierocone w `pending_payment` (blokuje sprzęt do TTL), koszyk zostawiony
jako `converted` czyli pusty i bezużyteczny. Ścieżka kompensacyjna (anuluj zamówienie + reaktywuj
koszyk) **istniała i po prostu się nie wykonała**.

**Reguła:** blok `catch` wokół wywołania biblioteki zewnętrznej, którego JEDYNĄ reakcją jest
kompensacja/rollback + komunikat dla użytkownika — łap `\Throwable`. `\Exception` zostawiaj tylko
tam, gdzie świadomie chcesz przepuścić `\Error` wyżej.

Dotyczy każdego pliku z `declare(strict_types=1)` wołającego vendor SDK: strict mode obowiązuje w
miejscu WYWOŁANIA, więc zły typ argumentu to `TypeError` u nas, nawet gdy sam vendor jest w trybie
weak.

**Nie każda porażka to „spróbuj ponownie".** Rozróżniaj przejściową (odmowa/timeout bramki — retry
ma sens) od konfiguracyjnej (brak poświadczeń — retry nie zadziała NIGDY, klient zapętli się na
tworzenie i anulowanie zamówienia). Typowany wyjątek (`PaymentGatewayNotConfiguredException`) +
osobny komunikat, nie jeden generyczny tekst.
