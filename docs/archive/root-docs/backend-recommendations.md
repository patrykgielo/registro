# Backend Recommendations for Frontend Implementation

**Document Version:** 1.0
**Date:** 2025-10-12
**Project:** Paradocks Detailing Booking System

## Executive Summary

The Paradocks Laravel backend provides a solid foundation for a modern booking system with clean MVC architecture, good separation of concerns, and comprehensive admin panel. However, to support a conversion-optimized frontend matching 2024-2025 industry standards, several critical and high-priority enhancements are required.

**Current Backend Readiness: 60%**

The existing implementation can support basic frontend functionality, but modern features like service add-ons, dynamic pricing, payment integration, and comprehensive customer data collection require significant backend development.

---

## Critical Issues (Must Fix Before Frontend Development)

### 1. Missing Customer Phone Number
**Issue:** No phone field in users table
**Impact:** Cannot send SMS notifications, difficult customer communication
**Priority:** CRITICAL
**Effort:** Low (2 hours)

**Solution:**
```php
// Migration
Schema::table('users', function (Blueprint $table) {
    $table->string('phone', 20)->nullable()->after('email');
});

// Update User model
protected $fillable = ['name', 'email', 'password', 'phone'];

// Add validation in controllers/requests
'phone' => 'nullable|string|max:20|regex:/^[0-9\s\-\+\(\)]+$/',
```

---

### 2. No Vehicle Information System
**Issue:** Detailing business needs vehicle details (make, model, year, color)
**Impact:** Cannot properly identify customer vehicle, poor service quality
**Priority:** CRITICAL
**Effort:** Medium (8 hours)

**Solution:**
```php
// Create vehicles table migration
Schema::create('vehicles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
    $table->string('make', 50);
    $table->string('model', 50);
    $table->year('year');
    $table->string('color', 30)->nullable();
    $table->string('plate_number', 20)->nullable();
    $table->text('notes')->nullable();
    $table->boolean('is_primary')->default(false);
    $table->timestamps();

    $table->index(['customer_id', 'is_primary']);
});

// Add vehicle_id to appointments
Schema::table('appointments', function (Blueprint $table) {
    $table->foreignId('vehicle_id')->nullable()->after('customer_id')
          ->constrained()->nullOnDelete();
});

// Create Vehicle model
class Vehicle extends Model
{
    protected $fillable = [
        'customer_id', 'make', 'model', 'year',
        'color', 'plate_number', 'notes', 'is_primary'
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->year} {$this->make} {$this->model}";
    }
}

// Update User model
public function vehicles()
{
    return $this->hasMany(Vehicle::class, 'customer_id');
}

public function primaryVehicle()
{
    return $this->hasOne(Vehicle::class, 'customer_id')
                ->where('is_primary', true);
}
```

---

### 3. No Service Add-Ons System
**Issue:** Cannot offer optional add-ons (e.g., headlight restoration, pet hair removal)
**Impact:** Lost revenue, inflexible service offerings
**Priority:** CRITICAL
**Effort:** High (16 hours)

**Solution:**
```php
// Create service_add_ons table
Schema::create('service_add_ons', function (Blueprint $table) {
    $table->id();
    $table->foreignId('service_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->text('description')->nullable();
    $table->decimal('price', 10, 2);
    $table->integer('duration_minutes')->default(0);
    $table->boolean('is_active')->default(true);
    $table->integer('sort_order')->default(0);
    $table->timestamps();

    $table->index(['service_id', 'is_active']);
});

// Create appointment_add_ons pivot
Schema::create('appointment_add_ons', function (Blueprint $table) {
    $table->id();
    $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
    $table->foreignId('service_add_on_id')->constrained()->cascadeOnDelete();
    $table->decimal('price_at_booking', 10, 2);
    $table->timestamps();

    $table->unique(['appointment_id', 'service_add_on_id']);
});

// Create ServiceAddOn model
class ServiceAddOn extends Model
{
    protected $fillable = [
        'service_id', 'name', 'description', 'price',
        'duration_minutes', 'is_active', 'sort_order'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

// Update Appointment model
public function addOns()
{
    return $this->belongsToMany(ServiceAddOn::class, 'appointment_add_ons')
                ->withPivot('price_at_booking')
                ->withTimestamps();
}

// Create API endpoint
// app/Http/Controllers/Api/ServiceController.php
public function addOns(Service $service)
{
    $addOns = $service->addOns()->active()->ordered()->get();

    return response()->json([
        'add_ons' => $addOns,
    ]);
}

// Route
Route::get('/api/services/{service}/add-ons', [ServiceController::class, 'addOns']);
```

---

### 4. No Dynamic Pricing System
**Issue:** Cannot calculate total price with add-ons and store it
**Impact:** Unclear pricing, no price history
**Priority:** CRITICAL
**Effort:** Medium (12 hours)

**Solution:**
```php
// Add pricing fields to appointments
Schema::table('appointments', function (Blueprint $table) {
    $table->decimal('base_price', 10, 2)->default(0)->after('service_id');
    $table->decimal('add_ons_total', 10, 2)->default(0)->after('base_price');
    $table->decimal('total_price', 10, 2)->default(0)->after('add_ons_total');
});

// Create PricingCalculatorService
// app/Services/PricingCalculatorService.php
class PricingCalculatorService
{
    public function calculateAppointmentTotal(
        Service $service,
        array $addOnIds = []
    ): array {
        $basePrice = $service->price;
        $addOnsTotal = 0;
        $totalDuration = $service->duration_minutes;

        if (!empty($addOnIds)) {
            $addOns = ServiceAddOn::whereIn('id', $addOnIds)
                                  ->where('service_id', $service->id)
                                  ->where('is_active', true)
                                  ->get();

            $addOnsTotal = $addOns->sum('price');
            $totalDuration += $addOns->sum('duration_minutes');
        }

        $totalPrice = $basePrice + $addOnsTotal;

        return [
            'base_price' => $basePrice,
            'add_ons_total' => $addOnsTotal,
            'total_price' => $totalPrice,
            'total_duration' => $totalDuration,
        ];
    }
}

// Update AppointmentController@store
public function store(Request $request)
{
    $validated = $request->validate([
        'service_id' => 'required|exists:services,id',
        'staff_id' => 'required|exists:users,id',
        'vehicle_id' => 'nullable|exists:vehicles,id',
        'appointment_date' => 'required|date|after_or_equal:today',
        'start_time' => 'required|date_format:H:i',
        'end_time' => 'required|date_format:H:i|after:start_time',
        'notes' => 'nullable|string|max:1000',
        'add_on_ids' => 'nullable|array',
        'add_on_ids.*' => 'exists:service_add_ons,id',
    ]);

    // Calculate pricing
    $service = Service::findOrFail($validated['service_id']);
    $pricing = app(PricingCalculatorService::class)
        ->calculateAppointmentTotal($service, $validated['add_on_ids'] ?? []);

    // Validate availability with updated duration
    $validation = $this->appointmentService->validateAppointment(
        staffId: $validated['staff_id'],
        serviceId: $validated['service_id'],
        appointmentDate: $validated['appointment_date'],
        startTime: $validated['start_time'],
        endTime: $validated['end_time']
    );

    if (!$validation['valid']) {
        return back()->withErrors(['appointment' => $validation['errors']])->withInput();
    }

    // Create appointment
    $appointment = Appointment::create([
        'service_id' => $validated['service_id'],
        'customer_id' => Auth::id(),
        'staff_id' => $validated['staff_id'],
        'vehicle_id' => $validated['vehicle_id'] ?? null,
        'appointment_date' => $validated['appointment_date'],
        'start_time' => $validated['start_time'],
        'end_time' => $validated['end_time'],
        'status' => 'pending',
        'notes' => $validated['notes'] ?? null,
        'base_price' => $pricing['base_price'],
        'add_ons_total' => $pricing['add_ons_total'],
        'total_price' => $pricing['total_price'],
    ]);

    // Attach add-ons
    if (!empty($validated['add_on_ids'])) {
        $addOns = ServiceAddOn::whereIn('id', $validated['add_on_ids'])->get();
        $syncData = [];
        foreach ($addOns as $addOn) {
            $syncData[$addOn->id] = ['price_at_booking' => $addOn->price];
        }
        $appointment->addOns()->sync($syncData);
    }

    return redirect()->route('appointments.index')
        ->with('success', 'Wizyta została pomyślnie zarezerwowana!');
}

// Create API endpoint for price calculation
// app/Http/Controllers/Api/BookingController.php
public function calculatePrice(Request $request)
{
    $validated = $request->validate([
        'service_id' => 'required|exists:services,id',
        'add_on_ids' => 'nullable|array',
        'add_on_ids.*' => 'exists:service_add_ons,id',
    ]);

    $service = Service::findOrFail($validated['service_id']);
    $pricing = app(PricingCalculatorService::class)
        ->calculateAppointmentTotal($service, $validated['add_on_ids'] ?? []);

    return response()->json($pricing);
}

// Route
Route::post('/api/booking/calculate-price', [BookingController::class, 'calculatePrice']);
```

---

### 5. No Notification System
**Issue:** No email/SMS confirmations, reminders, or cancellation notices
**Impact:** Poor customer experience, increased no-shows
**Priority:** CRITICAL
**Effort:** High (20 hours)

**Solution:**
```php
// Create notifications
php artisan make:notification AppointmentConfirmed
php artisan make:notification AppointmentReminder
php artisan make:notification AppointmentCancelled

// app/Notifications/AppointmentConfirmed.php
class AppointmentConfirmed extends Notification
{
    use Queueable;

    protected $appointment;

    public function __construct(Appointment $appointment)
    {
        $this->appointment = $appointment;
    }

    public function via($notifiable)
    {
        $channels = ['mail'];

        if ($notifiable->phone) {
            $channels[] = 'vonage'; // or 'twilio'
        }

        return $channels;
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Potwierdzenie wizyty - Paradocks')
            ->greeting('Dzień dobry ' . $notifiable->name . '!')
            ->line('Twoja wizyta została potwierdzona.')
            ->line('Usługa: ' . $this->appointment->service->name)
            ->line('Data: ' . $this->appointment->appointment_date->format('d.m.Y'))
            ->line('Godzina: ' . $this->appointment->start_time->format('H:i'))
            ->line('Personel: ' . $this->appointment->staff->name)
            ->action('Zobacz szczegóły', route('appointments.index'))
            ->line('Dziękujemy za wybór naszych usług!');
    }

    public function toVonage($notifiable)
    {
        return (new VonageMessage)
            ->content('Wizyta potwierdzona: ' .
                     $this->appointment->appointment_date->format('d.m.Y') . ' o ' .
                     $this->appointment->start_time->format('H:i') . '. ' .
                     'Usługa: ' . $this->appointment->service->name);
    }
}

// Update AppointmentController@store
$appointment = Appointment::create([...]);

// Send confirmation email
$appointment->customer->notify(new AppointmentConfirmed($appointment));

// Update AppointmentController@cancel
$appointment->update([...]);

// Send cancellation notice
$appointment->customer->notify(new AppointmentCancelled($appointment));

// Create command for reminders
// app/Console/Commands/SendAppointmentReminders.php
class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';
    protected $description = 'Send reminders for appointments 24h before';

    public function handle()
    {
        $tomorrow = now()->addDay()->toDateString();

        $appointments = Appointment::whereIn('status', ['pending', 'confirmed'])
            ->where('appointment_date', $tomorrow)
            ->with(['customer', 'service', 'staff'])
            ->get();

        foreach ($appointments as $appointment) {
            $appointment->customer->notify(new AppointmentReminder($appointment));
            $this->info("Reminder sent for appointment #{$appointment->id}");
        }

        $this->info("Sent {$appointments->count()} reminders");
    }
}

// Register in app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // Send reminders daily at 10:00
    $schedule->command('appointments:send-reminders')
             ->dailyAt('10:00');
}

// Configure queue in .env
QUEUE_CONNECTION=database

// Create queue table
php artisan queue:table
php artisan migrate

// Run queue worker
php artisan queue:work
```

---

## High Priority Enhancements (Before Launch)

### 6. Customer Address for Mobile Detailing
**Issue:** No address fields for mobile service
**Impact:** Cannot provide mobile detailing services
**Priority:** HIGH
**Effort:** Low (4 hours)

**Solution:**
```php
// Add address fields to users table
Schema::table('users', function (Blueprint $table) {
    $table->text('address')->nullable()->after('phone');
    $table->string('city', 100)->nullable()->after('address');
    $table->string('postal_code', 20')->nullable()->after('city');
});

// Or create separate addresses table for multiple addresses
Schema::create('customer_addresses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
    $table->string('label', 50)->default('Home'); // Home, Work, Other
    $table->text('street_address');
    $table->string('city', 100);
    $table->string('state', 50)->nullable();
    $table->string('postal_code', 20);
    $table->text('notes')->nullable();
    $table->boolean('is_primary')->default(false);
    $table->timestamps();
});

// Add address_id to appointments
Schema::table('appointments', function (Blueprint $table) {
    $table->foreignId('address_id')->nullable()->after('vehicle_id')
          ->constrained('customer_addresses')->nullOnDelete();
});
```

---

### 7. Payment Integration
**Issue:** No payment processing, no deposit collection
**Impact:** High no-show rate, no online payment convenience
**Priority:** HIGH
**Effort:** High (24 hours)

**Solution:**
```php
// Install Stripe
composer require stripe/stripe-php

// Create payments table
Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
    $table->string('payment_intent_id')->nullable();
    $table->decimal('amount', 10, 2);
    $table->string('currency', 3)->default('PLN');
    $table->enum('status', ['pending', 'processing', 'succeeded', 'failed', 'refunded'])
          ->default('pending');
    $table->string('payment_method', 50)->nullable();
    $table->timestamp('paid_at')->nullable();
    $table->text('error_message')->nullable();
    $table->timestamps();

    $table->index('payment_intent_id');
    $table->index(['appointment_id', 'status']);
});

// Add payment fields to appointments
Schema::table('appointments', function (Blueprint $table) {
    $table->enum('payment_status', ['not_required', 'pending', 'partial', 'paid', 'refunded'])
          ->default('not_required')->after('total_price');
    $table->decimal('deposit_amount', 10, 2)->default(0)->after('payment_status');
});

// Create Payment model and service
// app/Models/Payment.php
class Payment extends Model
{
    protected $fillable = [
        'appointment_id', 'payment_intent_id', 'amount',
        'currency', 'status', 'payment_method', 'paid_at', 'error_message'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }
}

// app/Services/PaymentService.php
use Stripe\StripeClient;

class PaymentService
{
    protected $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret'));
    }

    public function createPaymentIntent(Appointment $appointment, float $amount)
    {
        $intent = $this->stripe->paymentIntents->create([
            'amount' => $amount * 100, // Cents
            'currency' => 'pln',
            'metadata' => [
                'appointment_id' => $appointment->id,
                'customer_email' => $appointment->customer->email,
            ],
        ]);

        $payment = Payment::create([
            'appointment_id' => $appointment->id,
            'payment_intent_id' => $intent->id,
            'amount' => $amount,
            'currency' => 'pln',
            'status' => 'pending',
        ]);

        return [
            'client_secret' => $intent->client_secret,
            'payment' => $payment,
        ];
    }

    public function confirmPayment(string $paymentIntentId)
    {
        $intent = $this->stripe->paymentIntents->retrieve($paymentIntentId);

        $payment = Payment::where('payment_intent_id', $paymentIntentId)->firstOrFail();

        if ($intent->status === 'succeeded') {
            $payment->update([
                'status' => 'succeeded',
                'paid_at' => now(),
                'payment_method' => $intent->payment_method,
            ]);

            $payment->appointment->update([
                'payment_status' => $payment->amount >= $payment->appointment->total_price
                    ? 'paid'
                    : 'partial',
            ]);
        } else {
            $payment->update([
                'status' => 'failed',
                'error_message' => $intent->last_payment_error->message ?? 'Unknown error',
            ]);
        }

        return $payment;
    }
}

// Add API endpoints
Route::post('/api/payments/create-intent', [PaymentController::class, 'createIntent']);
Route::post('/api/payments/confirm', [PaymentController::class, 'confirm']);

// Configure in config/services.php
'stripe' => [
    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET'),
],
```

---

### 8. Guest Booking Flow
**Issue:** Must register before booking (friction)
**Impact:** Lost conversions, abandoned bookings
**Priority:** HIGH
**Effort:** Medium (12 hours)

**Solution:**
```php
// Remove auth middleware from booking routes
Route::middleware(['web'])->group(function () {
    Route::get('/services/{service}/book', [BookingController::class, 'create'])
         ->name('booking.create');
    Route::post('/api/available-slots', [BookingController::class, 'getAvailableSlots'])
         ->name('booking.slots');
    Route::post('/bookings/guest', [BookingController::class, 'storeGuest'])
         ->name('booking.guest');
});

// Create guest booking method
// app/Http/Controllers/BookingController.php
public function storeGuest(Request $request)
{
    $validated = $request->validate([
        // Appointment fields
        'service_id' => 'required|exists:services,id',
        'staff_id' => 'required|exists:users,id',
        'vehicle_id' => 'nullable|exists:vehicles,id',
        'appointment_date' => 'required|date|after_or_equal:today',
        'start_time' => 'required|date_format:H:i',
        'end_time' => 'required|date_format:H:i|after:start_time',
        'notes' => 'nullable|string|max:1000',
        'add_on_ids' => 'nullable|array',

        // Customer fields
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users,email',
        'phone' => 'required|string|max:20',
        'password' => 'required|string|min:8|confirmed',

        // Vehicle fields (if new)
        'vehicle_make' => 'required_without:vehicle_id|string|max:50',
        'vehicle_model' => 'required_without:vehicle_id|string|max:50',
        'vehicle_year' => 'required_without:vehicle_id|integer|min:1900|max:' . (date('Y') + 1),
        'vehicle_color' => 'nullable|string|max:30',
    ]);

    DB::beginTransaction();
    try {
        // Create user
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->assignRole('customer');

        // Create vehicle
        $vehicle = Vehicle::create([
            'customer_id' => $user->id,
            'make' => $validated['vehicle_make'],
            'model' => $validated['vehicle_model'],
            'year' => $validated['vehicle_year'],
            'color' => $validated['vehicle_color'] ?? null,
            'is_primary' => true,
        ]);

        // Calculate pricing
        $service = Service::findOrFail($validated['service_id']);
        $pricing = app(PricingCalculatorService::class)
            ->calculateAppointmentTotal($service, $validated['add_on_ids'] ?? []);

        // Create appointment
        $appointment = Appointment::create([
            'service_id' => $validated['service_id'],
            'customer_id' => $user->id,
            'staff_id' => $validated['staff_id'],
            'vehicle_id' => $vehicle->id,
            'appointment_date' => $validated['appointment_date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'status' => 'pending',
            'notes' => $validated['notes'] ?? null,
            'base_price' => $pricing['base_price'],
            'add_ons_total' => $pricing['add_ons_total'],
            'total_price' => $pricing['total_price'],
        ]);

        // Attach add-ons
        if (!empty($validated['add_on_ids'])) {
            $addOns = ServiceAddOn::whereIn('id', $validated['add_on_ids'])->get();
            $syncData = [];
            foreach ($addOns as $addOn) {
                $syncData[$addOn->id] = ['price_at_booking' => $addOn->price];
            }
            $appointment->addOns()->sync($syncData);
        }

        DB::commit();

        // Log user in
        Auth::login($user);

        // Send confirmation
        $user->notify(new AppointmentConfirmed($appointment));

        return redirect()->route('appointments.index')
            ->with('success', 'Konto utworzone! Wizyta zarezerwowana!');

    } catch (\Exception $e) {
        DB::rollBack();
        return back()->withErrors(['booking' => 'Wystąpił błąd. Spróbuj ponownie.'])
                    ->withInput();
    }
}
```

---

## Medium Priority Improvements

### 9. Availability Exception Management
**Issue:** Cannot define holidays, vacations, or one-time closures
**Priority:** MEDIUM
**Effort:** Medium (10 hours)

See ADR-002 for detailed implementation.

### 10. Buffer Time Between Appointments
**Issue:** Back-to-back appointments with no cleanup time
**Priority:** MEDIUM
**Effort:** Low (4 hours)

See ADR-002 for detailed implementation.

### 11. Configurable Slot Intervals
**Issue:** Hardcoded 15-minute intervals
**Priority:** MEDIUM
**Effort:** Low (2 hours)

See ADR-002 for detailed implementation.

### 12. Guava Calendar Integration
**Issue:** Calendar package installed but not used
**Priority:** MEDIUM
**Effort:** Medium (8 hours)

**Solution:**
```php
// Create calendar controller
php artisan make:controller CalendarController

// Display calendar view
public function index()
{
    $appointments = Appointment::with(['service', 'customer', 'staff'])
        ->whereIn('status', ['pending', 'confirmed'])
        ->where('appointment_date', '>=', now()->startOfMonth())
        ->where('appointment_date', '<=', now()->endOfMonth())
        ->get();

    return view('calendar', compact('appointments'));
}

// In Filament panel - add calendar page
php artisan make:filament-page AppointmentCalendar

// Use Guava Calendar widget
// See: https://github.com/guava/calendar
```

---

## Performance Optimizations

### 13. Caching Strategy
**Priority:** MEDIUM
**Effort:** Low (4 hours)

```php
// Cache services list
$services = Cache::remember('services.active', 3600, function () {
    return Service::active()->ordered()->get();
});

// Cache available slots
$cacheKey = "slots.{$serviceId}.{$staffId}.{$date}";
$slots = Cache::remember($cacheKey, 300, function () use ($serviceId, $staffId, $date, $duration) {
    return $this->appointmentService->getAvailableTimeSlots($serviceId, $staffId, $date, $duration);
});

// Invalidate cache on appointment creation/cancellation
// In AppointmentObserver
public function created(Appointment $appointment)
{
    $this->clearAvailabilityCache($appointment);
}

protected function clearAvailabilityCache(Appointment $appointment)
{
    $date = $appointment->appointment_date->format('Y-m-d');
    $pattern = "slots.{$appointment->service_id}.{$appointment->staff_id}.{$date}";
    Cache::forget($pattern);
}
```

### 14. Database Query Optimization
**Priority:** MEDIUM
**Effort:** Low (2 hours)

```php
// Add missing indexes
Schema::table('services', function (Blueprint $table) {
    $table->index(['is_active', 'sort_order']);
});

// Eager load relationships to avoid N+1
$appointments = Appointment::with(['service', 'customer', 'staff', 'addOns'])
    ->get();

// Use chunk for large datasets
Appointment::where('status', 'completed')
    ->chunk(100, function ($appointments) {
        // Process in batches
    });
```

---

## Testing Requirements

### 15. Comprehensive Test Suite
**Priority:** HIGH
**Effort:** High (40 hours)

**Required Tests:**

**Unit Tests:**
```php
// tests/Unit/Services/AppointmentServiceTest.php
test('checks staff availability correctly', function () {
    // Test availability checking logic
});

test('generates time slots in 15-minute intervals', function () {
    // Test slot generation
});

// tests/Unit/Services/PricingCalculatorServiceTest.php
test('calculates total with add-ons correctly', function () {
    // Test pricing calculation
});
```

**Feature Tests:**
```php
// tests/Feature/BookingTest.php
test('customer can book appointment', function () {
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $service = Service::factory()->create();
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    actingAs($customer)
        ->post(route('appointments.store'), [
            'service_id' => $service->id,
            'staff_id' => $staff->id,
            'appointment_date' => now()->addDays(2)->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '10:00',
        ])
        ->assertRedirect(route('appointments.index'));

    $this->assertDatabaseHas('appointments', [
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'status' => 'pending',
    ]);
});

test('cannot book conflicting appointment', function () {
    // Test double-booking prevention
});

test('guest can create account and book', function () {
    // Test guest booking flow
});
```

**API Tests:**
```php
// tests/Feature/Api/AvailabilitySlotsTest.php
test('returns available slots for date', function () {
    $service = Service::factory()->create();
    $staff = User::factory()->create();

    $response = $this->postJson('/api/available-slots', [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'date' => now()->addDays(1)->format('Y-m-d'),
    ]);

    $response->assertOk()
             ->assertJsonStructure([
                 'slots' => [
                     '*' => ['start', 'end', 'datetime_start', 'datetime_end']
                 ],
                 'date'
             ]);
});
```

---

## Migration Strategy

### Phase 1: Foundation (Week 1-2)
1. Add phone number field
2. Create Vehicle model and migrations
3. Implement ServiceAddOn system
4. Create PricingCalculatorService
5. Update appointment creation to handle vehicles and add-ons

### Phase 2: Core Features (Week 3-4)
6. Implement notification system (email)
7. Add payment integration (Stripe)
8. Implement guest booking flow
9. Add address management

### Phase 3: Enhancements (Week 5-6)
10. Add availability exceptions
11. Implement buffer time
12. Add caching layer
13. Integrate Guava Calendar
14. SMS notifications (optional)

### Phase 4: Testing & Polish (Week 7-8)
15. Write comprehensive test suite
16. Performance optimization
17. Security audit
18. Documentation updates
19. Frontend integration testing

---

## Deployment Checklist

### Pre-Deployment
- [ ] Run all migrations in production
- [ ] Seed initial data (roles, sample services)
- [ ] Configure environment variables (.env)
- [ ] Set up queue worker (for notifications)
- [ ] Configure cron for scheduled tasks
- [ ] Test email sending (SMTP)
- [ ] Test SMS sending (if implemented)
- [ ] Configure Stripe production keys
- [ ] Enable HTTPS (SSL certificate)
- [ ] Set up database backups

### Configuration Required
```env
# .env additions
APP_URL=https://paradocks.com
QUEUE_CONNECTION=database

# Email
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@paradocks.com
MAIL_FROM_NAME="Paradocks Detailing"

# SMS (Vonage/Twilio)
VONAGE_KEY=your_key
VONAGE_SECRET=your_secret
VONAGE_SMS_FROM="Paradocks"

# Stripe
STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...

# Caching
CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

### Post-Deployment
- [ ] Monitor error logs
- [ ] Test booking flow end-to-end
- [ ] Verify notification delivery
- [ ] Check payment processing
- [ ] Monitor performance metrics
- [ ] Set up application monitoring (Sentry, Bugsnag)

---

## Security Considerations

### Required Actions
1. **Rate Limiting:** Add to booking endpoints
2. **CSRF Protection:** Already enabled, ensure frontend includes tokens
3. **SQL Injection:** Using Eloquent (safe by default)
4. **XSS Protection:** Blade escapes output (safe by default)
5. **Input Validation:** Add comprehensive validation to all endpoints
6. **Password Hashing:** Using bcrypt (safe by default)
7. **Two-Factor Auth:** Consider for admin/staff users
8. **Audit Logging:** Track sensitive actions (role changes, cancellations)

### Recommended Packages
```bash
# Security headers
composer require bepsvpt/secure-headers

# Audit logging
composer require spatie/laravel-activitylog

# Two-factor authentication
composer require pragmarx/google2fa-laravel
```

---

## Monitoring & Analytics

### Application Monitoring
```bash
# Error tracking
composer require sentry/sentry-laravel

# Application monitoring
composer require laravel/telescope

# Performance monitoring
composer require laravel/horizon (for queue monitoring)
```

### Business Metrics to Track
- Booking conversion rate
- Average booking value
- No-show rate
- Cancellation rate
- Popular services
- Peak booking times
- Customer lifetime value
- Staff utilization rate

---

## Documentation Updates Needed

### 1. API Documentation
- Create OpenAPI/Swagger spec for API endpoints
- Document request/response formats
- Provide code examples in multiple languages

### 2. Developer Guide
- Setup instructions
- Architecture overview
- Coding standards
- Testing guide
- Deployment guide

### 3. User Documentation
- Admin panel user guide
- Staff workflow guide
- Customer booking guide

---

## Estimated Timeline

| Phase | Tasks | Effort | Duration |
|-------|-------|--------|----------|
| Phase 1 | Critical Issues (1-5) | 58 hours | 2 weeks |
| Phase 2 | High Priority (6-8) | 40 hours | 1.5 weeks |
| Phase 3 | Medium Priority (9-12) | 24 hours | 1 week |
| Phase 4 | Testing & Polish | 40 hours | 1.5 weeks |
| **Total** | | **162 hours** | **6 weeks** |

**Assumptions:**
- 1 full-time developer
- 8 hours per working day
- 5 days per week

---

## Success Criteria

### Technical Metrics
- [ ] 100% test coverage for critical paths
- [ ] API response time < 200ms (95th percentile)
- [ ] Zero SQL N+1 queries
- [ ] All Lighthouse scores > 90

### Business Metrics
- [ ] Booking conversion rate > 70%
- [ ] Average booking value > 150 PLN
- [ ] No-show rate < 10%
- [ ] Customer satisfaction > 4.5/5

---

## Next Steps

1. **Review this document with stakeholders**
2. **Prioritize features based on business needs**
3. **Create detailed task breakdown in project management tool**
4. **Set up development environment**
5. **Begin Phase 1 implementation**
6. **Schedule regular check-ins with frontend team**

---

## Support & Questions

For questions about these recommendations:
- Review `/var/www/projects/paradocks/docs/project_map.md` for detailed architecture
- Check ADR files in `/var/www/projects/paradocks/docs/decision_log/`
- See `/var/www/projects/paradocks/docs/api-contract-frontend.md` for API details
- Contact backend architect for clarification

---

**Document End**
