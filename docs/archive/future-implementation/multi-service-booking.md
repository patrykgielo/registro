# Multi-Service Booking System - Architecture Decision Document (ADD)

## Executive Summary

**Decision:** Implement multi-service booking using a **hybrid approach** that:
1. Maintains backward compatibility with existing single-service appointments
2. Introduces `appointment_items` table for multi-service support
3. Uses sequential service execution (sum of durations)
4. Implements price snapshotting for historical accuracy
5. Supports single-staff assignment (find staff with ALL required competencies)

**Status:** PROPOSAL - Awaiting approval
**Impact:** HIGH - Affects database schema, booking flow, pricing, availability calculation
**Risk Level:** MEDIUM - Live production data requires careful migration

---

## 1. Current Architecture Analysis

### 1.1 Database Schema (Current State)

```
appointments (SINGLE SERVICE)
├── id
├── service_id (FK → services.id, CASCADE)
├── customer_id (FK → users.id)
├── staff_id (FK → users.id)
├── appointment_date
├── start_time
├── end_time
├── status (pending|confirmed|cancelled|completed)
└── (31 additional fields: vehicle, location, contact, etc.)

services (31 fields)
├── id
├── name, description, slug
├── price (decimal 10,2)
├── price_from (decimal 10,2) - for variable pricing
├── duration_minutes (integer)
├── features (JSON)
└── (CMS fields, SEO fields, trust signals)

service_staff (pivot - staff competencies)
├── service_id (FK → services.id)
├── user_id (FK → users.id - staff members)
└── UNIQUE(service_id, user_id)
```

**Critical Constraints:**
- `appointments.service_id` has `cascadeOnDelete()` - deleting service deletes appointments
- NO price snapshot - relies on live `services.price`
- NO total_price field in appointments
- NO support for multiple services per appointment

### 1.2 Business Logic (Current State)

**AppointmentService.php** (608 lines):
- `getAvailableSlotsAcrossAllStaff()` - bulk queries for calendar (3-5 queries)
- `findBestAvailableStaff()` - assigns first available staff member
- `checkStaffAvailability()` - checks:
  1. Staff competency (`service_staff` pivot)
  2. Staff schedule (vacation, exceptions, base schedule)
  3. Appointment conflicts (overlapping time slots)

**BookingController.php** (5-step wizard):
1. Service selection (radio button - SINGLE)
2. Date & time (slots based on service duration)
3. Vehicle & location
4. Contact info
5. Review & confirm

**Key Observation:** All availability logic assumes single service with known duration.

---

## 2. Multi-Service Architecture Proposal

### 2.1 Database Design - Option A: Appointment Items (RECOMMENDED)

```sql
-- NEW TABLE: appointment_items (line items for multi-service)
CREATE TABLE appointment_items (
    id BIGINT UNSIGNED PRIMARY KEY,
    appointment_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,

    -- Price snapshot (historical accuracy)
    service_name VARCHAR(255) NOT NULL,
    price_snapshot DECIMAL(10,2) NOT NULL,
    duration_minutes INT NOT NULL,

    -- Execution order (sequential: service 1 → service 2 → service 3)
    sort_order INT DEFAULT 0,

    -- Metadata
    is_addon BOOLEAN DEFAULT FALSE, -- main service vs upsell

    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT,

    INDEX idx_appointment_items_appointment_id (appointment_id),
    INDEX idx_appointment_items_service_id (service_id)
);

-- MODIFY appointments table
ALTER TABLE appointments
    -- Keep service_id for backward compatibility (nullable for multi-service)
    MODIFY COLUMN service_id BIGINT UNSIGNED NULL,

    -- Add pricing fields
    ADD COLUMN subtotal DECIMAL(10,2) DEFAULT 0 AFTER end_time,
    ADD COLUMN discount_amount DECIMAL(10,2) DEFAULT 0 AFTER subtotal,
    ADD COLUMN total_price DECIMAL(10,2) DEFAULT 0 AFTER discount_amount,

    -- Add multi-service flag
    ADD COLUMN is_multi_service BOOLEAN DEFAULT FALSE AFTER total_price,

    -- Add index
    ADD INDEX idx_appointments_multi_service (is_multi_service);
```

**Why `appointment_items` instead of pivot table?**

| Feature | Pivot (`appointment_services`) | Items (`appointment_items`) |
|---------|-------------------------------|----------------------------|
| Price snapshot | ❌ Would need `withPivot()` | ✅ Natural fit |
| Sort order | ❌ Awkward for pivots | ✅ Native support |
| Addons flag | ❌ Mixing concerns | ✅ Clear semantics |
| E-commerce patterns | ❌ Non-standard | ✅ Industry standard |
| Future extensibility | ⚠️ Limited | ✅ Easy (quantity, discounts) |

**Decision:** Use `appointment_items` for clarity, extensibility, and e-commerce alignment.

### 2.2 Eloquent Relationships

```php
// app/Models/Appointment.php

/**
 * Items for multi-service appointments
 */
public function items(): HasMany
{
    return $this->hasMany(AppointmentItem::class)->orderBy('sort_order');
}

/**
 * Services via items (eager loading)
 */
public function services(): HasManyThrough
{
    return $this->hasManyThrough(
        Service::class,
        AppointmentItem::class,
        'appointment_id',
        'id',
        'id',
        'service_id'
    )->orderBy('appointment_items.sort_order');
}

/**
 * Legacy single service (backward compatibility)
 *
 * @deprecated Use services() for multi-service appointments
 */
public function service(): BelongsTo
{
    return $this->belongsTo(Service::class);
}

/**
 * Get total duration (sum of all services)
 */
public function getTotalDurationAttribute(): int
{
    if ($this->is_multi_service) {
        return $this->items->sum('duration_minutes');
    }

    return $this->service?->duration_minutes ?? 0;
}

/**
 * Get service names list
 */
public function getServiceNamesAttribute(): string
{
    if ($this->is_multi_service) {
        return $this->items->pluck('service_name')->implode(', ');
    }

    return $this->service?->name ?? '';
}

/**
 * Check if appointment contains specific service
 */
public function hasService(int $serviceId): bool
{
    if ($this->is_multi_service) {
        return $this->items->contains('service_id', $serviceId);
    }

    return $this->service_id === $serviceId;
}
```

```php
// app/Models/AppointmentItem.php (NEW MODEL)

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentItem extends Model
{
    protected $fillable = [
        'appointment_id',
        'service_id',
        'service_name',
        'price_snapshot',
        'duration_minutes',
        'sort_order',
        'is_addon',
    ];

    protected $casts = [
        'price_snapshot' => 'decimal:2',
        'duration_minutes' => 'integer',
        'sort_order' => 'integer',
        'is_addon' => 'boolean',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
```

### 2.3 Staff Assignment Strategy

**Strategy: Single-Staff with ALL Competencies**

```php
// app/Services/AppointmentService.php

/**
 * Find staff member who can perform ALL selected services
 *
 * @param array $serviceIds Array of service IDs
 * @return User|null Staff member if found
 */
public function findStaffWithAllCompetencies(
    array $serviceIds,
    Carbon $dateTime,
    int $totalDurationMinutes
): ?User {
    // Get staff members who have ALL required competencies
    $staffMembers = User::whereHas('roles', function ($query) {
        $query->where('name', 'staff');
    })
    ->whereHas('services', function ($query) use ($serviceIds) {
        $query->whereIn('service_id', $serviceIds);
    }, '=', count($serviceIds)) // Must have ALL services
    ->get();

    // Calculate end time
    $endTime = $dateTime->copy()->addMinutes($totalDurationMinutes);
    $date = $dateTime->copy()->startOfDay();

    // Find first available staff
    foreach ($staffMembers as $staff) {
        // Verify staff has ALL competencies (explicit check)
        $staffServiceIds = $staff->services->pluck('id')->toArray();
        if (count(array_intersect($serviceIds, $staffServiceIds)) !== count($serviceIds)) {
            continue;
        }

        // Check availability using existing method
        $isAvailable = true;
        foreach ($serviceIds as $serviceId) {
            if (!$this->checkStaffAvailability(
                $staff->id,
                $serviceId,
                $date,
                $dateTime,
                $endTime
            )) {
                $isAvailable = false;
                break;
            }
        }

        if ($isAvailable) {
            return $staff;
        }
    }

    return null;
}
```

**Alternative Strategies (NOT RECOMMENDED for v1):**

1. **Multi-Staff Appointment** (requires `appointments.secondary_staff_id[]`)
   - Complexity: HIGH
   - Use case: Large projects requiring team (e.g., full detailing + ceramic coating)

2. **Split Into Multiple Appointments** (automatic booking of 2+ appointments)
   - Complexity: MEDIUM
   - Problem: Customer confusion, calendar clutter

**Decision:** Use single-staff strategy. If no staff has all competencies, show error message suggesting to book separately.

### 2.4 Availability Calculation

**Sequential Execution Model:**

```
Service A (60 min) → Service B (45 min) → Service C (30 min)
Total duration: 135 minutes (2h 15min)

Timeline:
09:00 ──────────── Service A ────────────── 10:00
10:00 ──────── Service B ────────── 10:45
10:45 ──── Service C ──── 11:15
```

**Modified Slot Search:**

```php
// app/Services/AppointmentService.php

public function getAvailableSlotsForMultiService(
    array $serviceIds,
    Carbon $date
): array {
    // Calculate total duration
    $services = Service::whereIn('id', $serviceIds)->get();
    $totalDuration = $services->sum('duration_minutes');

    // Find staff who can do ALL services
    $eligibleStaff = User::whereHas('roles', function ($query) {
        $query->where('name', 'staff');
    })
    ->whereHas('services', function ($query) use ($serviceIds) {
        $query->whereIn('service_id', $serviceIds);
    }, '=', count($serviceIds))
    ->get();

    if ($eligibleStaff->isEmpty()) {
        return []; // No staff can handle all services
    }

    // Use existing bulk slot logic with modified duration
    return $this->getAvailableSlotsAcrossAllStaff(
        serviceId: $serviceIds[0], // Dummy for compatibility
        date: $date,
        serviceDurationMinutes: $totalDuration
    );
}
```

**Edge Cases:**

1. **No staff with all competencies:**
   - Show error: "Wybrane usługi wymagają rezerwacji osobnych wizyt"
   - Offer to book separately

2. **Duration exceeds business hours:**
   - Validate: `$totalDuration <= (businessEnd - businessStart)`
   - Show error: "Łączny czas usług przekracza godziny pracy"

3. **Service dependencies (future enhancement):**
   - Example: "Powłoka ceramiczna" requires "Korekta lakieru" first
   - Add `service_dependencies` JSON field to services table

### 2.5 Pricing Engine

**Price Calculation Strategy:**

```php
// app/Services/PricingService.php (NEW SERVICE)

namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Collection;

class PricingService
{
    /**
     * Calculate total price for selected services
     *
     * @param Collection $services Collection of Service models
     * @param array $options ['apply_bundle_discount' => bool]
     * @return array ['subtotal', 'discount', 'total', 'breakdown']
     */
    public function calculatePrice(Collection $services, array $options = []): array
    {
        $subtotal = 0;
        $breakdown = [];

        foreach ($services as $service) {
            $price = $service->price ?? $service->price_from ?? 0;
            $subtotal += $price;

            $breakdown[] = [
                'service_id' => $service->id,
                'service_name' => $service->name,
                'price' => $price,
                'duration_minutes' => $service->duration_minutes,
            ];
        }

        // Apply bundle discount (future enhancement)
        $discount = 0;
        if ($options['apply_bundle_discount'] ?? false) {
            $discount = $this->calculateBundleDiscount($services);
        }

        $total = $subtotal - $discount;

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'total' => round($total, 2),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Calculate bundle discount (placeholder for future)
     */
    protected function calculateBundleDiscount(Collection $services): float
    {
        // Example: 3+ services = 10% discount
        if ($services->count() >= 3) {
            return $services->sum('price') * 0.10;
        }

        return 0;
    }
}
```

**Price Snapshotting:**

```php
// When creating appointment
$pricingService = app(PricingService::class);
$pricing = $pricingService->calculatePrice($selectedServices);

$appointment = Appointment::create([
    'subtotal' => $pricing['subtotal'],
    'discount_amount' => $pricing['discount'],
    'total_price' => $pricing['total'],
    // ...
]);

foreach ($pricing['breakdown'] as $index => $item) {
    AppointmentItem::create([
        'appointment_id' => $appointment->id,
        'service_id' => $item['service_id'],
        'service_name' => $item['service_name'],
        'price_snapshot' => $item['price'],
        'duration_minutes' => $item['duration_minutes'],
        'sort_order' => $index,
    ]);
}
```

---

## 3. Booking Wizard Modifications

### 3.1 Step 1: Service Selection (Multi-Select)

**Before (radio buttons):**
```html
<input type="radio" name="service" value="{{ $service->id }}">
```

**After (checkboxes with cart):**
```html
<!-- Main service selection -->
<input type="checkbox"
       x-model="selectedServices"
       :value="serviceId"
       @change="updateCart()">

<!-- Sidebar cart -->
<div class="sticky top-4">
    <h3>Wybrane usługi</h3>
    <template x-for="service in cart">
        <div class="cart-item">
            <span x-text="service.name"></span>
            <span x-text="formatPrice(service.price)"></span>
        </div>
    </template>

    <div class="cart-summary">
        <div>Łączny czas: <span x-text="totalDuration"></span></div>
        <div>Suma: <span x-text="formatPrice(totalPrice)"></span></div>
    </div>
</div>
```

**Validation Rules:**
- Min: 1 service
- Max: 5 services (configurable in settings)
- Total duration: <= 8 hours (480 minutes)

### 3.2 Upsells & Add-ons

**Strategy: Show after main service selection**

```php
// app/Services/UpsellService.php (NEW)

public function getRecommendedAddons(array $selectedServiceIds): Collection
{
    // Example logic:
    // If "Mycie detailingowe" selected → suggest "Wosk ochronny"
    // If "Korekta lakieru" selected → suggest "Powłoka ceramiczna"

    $rules = [
        1 => [3, 5], // Service 1 recommends 3, 5
        2 => [4],
    ];

    $recommendations = [];
    foreach ($selectedServiceIds as $serviceId) {
        if (isset($rules[$serviceId])) {
            $recommendations = array_merge($recommendations, $rules[$serviceId]);
        }
    }

    return Service::whereIn('id', array_unique($recommendations))
        ->active()
        ->get();
}
```

**UI Flow:**
1. Customer selects "Mycie detailingowe"
2. Click "Dalej"
3. Show modal: "Klienci często wybierają również:"
   - Wosk ochronny (+150 PLN)
   - Czyszczenie wnętrza (+200 PLN)
4. Customer can add to cart or skip

### 3.3 Step 2: Date & Time (Modified)

**Challenge:** Slot availability depends on total duration.

**Solution:**
```javascript
// Recalculate slots when services change
Alpine.data('bookingDatetime', () => ({
    selectedServices: [],
    totalDuration: 0,

    async loadSlots(date) {
        const response = await fetch('/api/booking/slots', {
            method: 'POST',
            body: JSON.stringify({
                service_ids: this.selectedServices,
                date: date
            })
        });

        this.slots = await response.json();
    }
}));
```

**Backend endpoint:**
```php
// app/Http/Controllers/BookingController.php

public function getMultiServiceSlots(Request $request)
{
    $validated = $request->validate([
        'service_ids' => 'required|array|min:1|max:5',
        'service_ids.*' => 'exists:services,id',
        'date' => 'required|date|after_or_equal:today',
    ]);

    $slots = $this->appointmentService->getAvailableSlotsForMultiService(
        $validated['service_ids'],
        Carbon::parse($validated['date'])
    );

    return response()->json(['slots' => $slots]);
}
```

---

## 4. Migration Strategy (CRITICAL for Production)

### 4.1 Phase 1: Additive Changes (Zero Downtime)

```php
// Migration 1: Add new fields to appointments
Schema::table('appointments', function (Blueprint $table) {
    $table->decimal('subtotal', 10, 2)->default(0)->after('end_time');
    $table->decimal('discount_amount', 10, 2)->default(0)->after('subtotal');
    $table->decimal('total_price', 10, 2)->default(0)->after('discount_amount');
    $table->boolean('is_multi_service')->default(false)->after('total_price');

    $table->index('is_multi_service');
});

// Migration 2: Create appointment_items table
Schema::create('appointment_items', function (Blueprint $table) {
    // ... (see 2.1)
});

// Migration 3: Make service_id nullable
Schema::table('appointments', function (Blueprint $table) {
    $table->unsignedBigInteger('service_id')->nullable()->change();
});
```

**Safe to Deploy:** ✅ Old code still works.

### 4.2 Phase 2: Data Backfill

```php
// Migration 4: Backfill existing appointments
public function up(): void
{
    // Create appointment_items for all existing single-service appointments
    DB::transaction(function () {
        Appointment::whereNotNull('service_id')
            ->chunk(100, function ($appointments) {
                foreach ($appointments as $appointment) {
                    // Create single item
                    AppointmentItem::create([
                        'appointment_id' => $appointment->id,
                        'service_id' => $appointment->service_id,
                        'service_name' => $appointment->service->name,
                        'price_snapshot' => $appointment->service->price,
                        'duration_minutes' => $appointment->service->duration_minutes,
                        'sort_order' => 0,
                        'is_addon' => false,
                    ]);

                    // Update pricing fields
                    $appointment->update([
                        'subtotal' => $appointment->service->price,
                        'discount_amount' => 0,
                        'total_price' => $appointment->service->price,
                        'is_multi_service' => false,
                    ]);
                }
            });
    });
}
```

### 4.3 Phase 3: Code Deployment

**Update model accessors:**
```php
// OLD (deprecated)
$appointment->service->price

// NEW (backward compatible)
$appointment->total_price // Always works
$appointment->service_names // "Mycie detailingowe, Wosk ochronny"
$appointment->items // Collection of AppointmentItem
```

**Update Filament resource:**
```php
// AppointmentResource.php

TextColumn::make('services')
    ->label('Usługi')
    ->formatStateUsing(fn (Appointment $record) =>
        $record->is_multi_service
            ? $record->service_names
            : $record->service?->name
    ),

TextColumn::make('total_price')
    ->label('Cena')
    ->money('PLN'),
```

### 4.4 Phase 4: Cleanup (Optional)

**After 3 months (when all old appointments completed):**
```php
// Remove service_id column (breaking change)
Schema::table('appointments', function (Blueprint $table) {
    $table->dropForeign(['service_id']);
    $table->dropColumn('service_id');
});
```

**Decision:** Keep `service_id` indefinitely for backward compatibility.

---

## 5. Email Notifications

### 5.1 Template Updates

**Before:**
```blade
Witaj {{ $appointment->customer->name }},

Potwierdzamy rezerwację:
- Usługa: {{ $appointment->service->name }}
- Data: {{ $appointment->appointment_date->format('d.m.Y') }}
- Godzina: {{ $appointment->start_time->format('H:i') }}
- Cena: {{ $appointment->service->price }} PLN
```

**After:**
```blade
Witaj {{ $appointment->customer->name }},

Potwierdzamy rezerwację:

@if($appointment->is_multi_service)
    Wybrane usługi:
    @foreach($appointment->items as $item)
        - {{ $item->service_name }} ({{ $item->duration_minutes }} min) - {{ $item->price_snapshot }} PLN
    @endforeach

    Łączny czas: {{ $appointment->total_duration }} min
    Suma: {{ $appointment->total_price }} PLN

    @if($appointment->discount_amount > 0)
        Rabat: -{{ $appointment->discount_amount }} PLN
    @endif
@else
    - Usługa: {{ $appointment->service->name }}
    - Czas trwania: {{ $appointment->service->duration_minutes }} min
    - Cena: {{ $appointment->total_price }} PLN
@endif

Data: {{ $appointment->appointment_date->format('d.m.Y') }}
Godzina: {{ $appointment->start_time->format('H:i') }} - {{ $appointment->end_time->format('H:i') }}
```

### 5.2 Event System (Backward Compatible)

**No changes needed** - `AppointmentCreated` event still fires:
```php
protected $dispatchesEvents = [
    'created' => AppointmentCreated::class,
];
```

Listener checks `is_multi_service` flag and renders accordingly.

---

## 6. Filament Admin Panel

### 6.1 AppointmentResource Form

```php
// Before
Select::make('service_id')
    ->relationship('service', 'name')
    ->required(),

// After
Repeater::make('items')
    ->relationship('items')
    ->schema([
        Select::make('service_id')
            ->relationship('service', 'name')
            ->required()
            ->reactive()
            ->afterStateUpdated(function (Set $set, $state) {
                $service = Service::find($state);
                $set('service_name', $service->name);
                $set('price_snapshot', $service->price);
                $set('duration_minutes', $service->duration_minutes);
            }),

        TextInput::make('price_snapshot')
            ->numeric()
            ->disabled(),

        Toggle::make('is_addon')
            ->label('Add-on'),
    ])
    ->minItems(1)
    ->maxItems(5)
    ->defaultItems(1)
    ->collapsible(),

TextInput::make('total_price')
    ->numeric()
    ->disabled()
    ->dehydrated(),
```

### 6.2 AppointmentResource Table

```php
Tables\Columns\TextColumn::make('service_names')
    ->label('Usługi')
    ->limit(50)
    ->tooltip(fn (Appointment $record) =>
        $record->items->map(fn ($item) =>
            "{$item->service_name} ({$item->duration_minutes} min)"
        )->implode("\n")
    ),

Tables\Columns\TextColumn::make('total_duration')
    ->label('Czas')
    ->suffix(' min'),

Tables\Columns\TextColumn::make('total_price')
    ->label('Cena')
    ->money('PLN'),
```

---

## 7. Edge Cases & Risk Assessment

### 7.1 Critical Risks

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| **Data Loss** (migration error) | LOW | CRITICAL | - Full DB backup before migration<br>- Test on staging<br>- Rollback script |
| **Cascade Delete** (service deleted → appointments deleted) | MEDIUM | HIGH | - Change FK to `RESTRICT`<br>- Soft delete services |
| **Price Discrepancy** (old appointments show wrong price) | HIGH | MEDIUM | - Backfill migration<br>- Always use `total_price` in views |
| **Staff Assignment Failure** (no staff with all competencies) | MEDIUM | HIGH | - Clear error messages<br>- Suggest splitting booking |
| **Performance Degradation** (N+1 queries) | MEDIUM | MEDIUM | - Eager load `items` relation<br>- Cache availability |

### 7.2 Edge Cases

**Case 1: Service Deleted After Booking**
```
OLD: CASCADE DELETE → appointment deleted (data loss!)
NEW: RESTRICT → Admin can't delete service with appointments
SOLUTION: Soft delete services + archive old appointments
```

**Case 2: Service Price Changed After Booking**
```
OLD: Live price → invoices show wrong amount
NEW: Price snapshot → historical accuracy preserved
```

**Case 3: Customer Selects 5 Services (8h duration)**
```
VALIDATION:
- Max total duration: 480 minutes (8h)
- If exceeded: "Łączny czas przekracza 8h. Podziel na 2 wizyty."
```

**Case 4: No Staff with All Competencies**
```
Service A requires Staff X
Service B requires Staff Y
No staff can do BOTH

ERROR MESSAGE:
"Wybrane usługi wymagają osobnych wizyt.
Pracownik X wykonuje usługę A.
Pracownik Y wykonuje usługę B.
Zarezerwuj wizyty oddzielnie lub skontaktuj się z nami."
```

**Case 5: Migration During Active Bookings**
```
SCENARIO: Customer booking while migration runs
SOLUTION:
- Maintenance mode during migration
- Or: Use DB transactions + locks
```

---

## 8. Performance Considerations

### 8.1 Query Optimization

**Problem:** N+1 queries when loading appointments
```php
// BAD (N+1)
$appointments = Appointment::all();
foreach ($appointments as $appt) {
    echo $appt->service->name; // Query per appointment
}

// GOOD (2 queries)
$appointments = Appointment::with(['items.service'])->get();
foreach ($appointments as $appt) {
    echo $appt->service_names; // Uses eager-loaded items
}
```

**Recommended Eager Loading:**
```php
// AppointmentResource.php
protected static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()
        ->with(['items.service', 'customer', 'staff']);
}
```

### 8.2 Caching Strategy

**Cache availability data:**
```php
// 15-minute cache (appointments change frequently)
Cache::remember(
    "availability_services_{$serviceIds}_{$date}",
    now()->addMinutes(15),
    fn() => $this->calculateAvailability($serviceIds, $date)
);
```

**Invalidate cache on appointment create/update:**
```php
// Appointment model
protected static function booted(): void
{
    static::saved(function (Appointment $appointment) {
        Cache::forget("availability_service_{$appointment->service_id}_{$appointment->appointment_date}");
    });
}
```

### 8.3 Database Indexes

```sql
-- Existing indexes (KEEP)
INDEX idx_appointments_date_time (appointment_date, start_time)
INDEX idx_appointments_staff_date (staff_id, appointment_date)

-- New indexes (ADD)
INDEX idx_appointments_multi_service (is_multi_service)
INDEX idx_appointment_items_appointment (appointment_id)
INDEX idx_appointment_items_service (service_id)
```

---

## 9. Testing Strategy

### 9.1 Unit Tests

```php
// tests/Unit/AppointmentTest.php

test('appointment calculates total duration for multi-service', function () {
    $appointment = Appointment::factory()
        ->has(AppointmentItem::factory()->count(3))
        ->create(['is_multi_service' => true]);

    expect($appointment->total_duration)->toBe(135); // 60 + 45 + 30
});

test('appointment finds staff with all competencies', function () {
    $service1 = Service::factory()->create();
    $service2 = Service::factory()->create();

    $staffWithBoth = User::factory()->staff()->create();
    $staffWithBoth->services()->attach([$service1->id, $service2->id]);

    $staffWithOne = User::factory()->staff()->create();
    $staffWithOne->services()->attach($service1->id);

    $staff = app(AppointmentService::class)->findStaffWithAllCompetencies(
        [$service1->id, $service2->id],
        now()->addDay(),
        120
    );

    expect($staff->id)->toBe($staffWithBoth->id);
});
```

### 9.2 Feature Tests

```php
// tests/Feature/MultiServiceBookingTest.php

test('customer can book multiple services', function () {
    $user = User::factory()->create();
    $services = Service::factory()->count(3)->create();

    $response = $this->actingAs($user)->post(route('booking.confirm'), [
        'service_ids' => $services->pluck('id')->toArray(),
        'date' => now()->addDay()->format('Y-m-d'),
        'time_slot' => '09:00',
        // ... other fields
    ]);

    $response->assertRedirect(route('booking.confirmation'));

    $appointment = Appointment::latest()->first();
    expect($appointment->is_multi_service)->toBeTrue();
    expect($appointment->items)->toHaveCount(3);
    expect($appointment->total_price)->toBe(450.00); // 150 + 150 + 150
});

test('booking fails when no staff has all competencies', function () {
    $service1 = Service::factory()->create();
    $service2 = Service::factory()->create();

    $staff1 = User::factory()->staff()->create();
    $staff1->services()->attach($service1->id);

    $staff2 = User::factory()->staff()->create();
    $staff2->services()->attach($service2->id);

    $response = $this->actingAs(User::factory()->create())
        ->post(route('booking.confirm'), [
            'service_ids' => [$service1->id, $service2->id],
            // ...
        ]);

    $response->assertSessionHasErrors();
});
```

### 9.3 Migration Tests

```php
test('backfill migration creates items for existing appointments', function () {
    // Create old appointment (single service)
    $service = Service::factory()->create(['price' => 200]);
    $appointment = Appointment::factory()->create([
        'service_id' => $service->id,
        'total_price' => 0, // Old schema doesn't have this
    ]);

    // Run migration
    Artisan::call('migrate', ['--step' => true]);

    $appointment->refresh();

    expect($appointment->items)->toHaveCount(1);
    expect($appointment->items->first()->price_snapshot)->toBe(200.00);
    expect($appointment->total_price)->toBe(200.00);
});
```

---

## 10. Implementation Roadmap

### Phase 1: Foundation (Week 1)
- [ ] Create `appointment_items` migration
- [ ] Create `AppointmentItem` model
- [ ] Add fields to `appointments` table
- [ ] Update Appointment model relationships
- [ ] Write unit tests

### Phase 2: Business Logic (Week 2)
- [ ] Implement `PricingService`
- [ ] Implement `findStaffWithAllCompetencies()`
- [ ] Implement `getAvailableSlotsForMultiService()`
- [ ] Update `BookingController` for multi-service
- [ ] Write feature tests

### Phase 3: Frontend (Week 3)
- [ ] Update Step 1 (checkboxes + cart sidebar)
- [ ] Implement upsell modal
- [ ] Update Step 2 (recalculate slots on service change)
- [ ] Add validation (max 5 services, max 8h duration)
- [ ] Update confirmation page

### Phase 4: Admin Panel (Week 4)
- [ ] Update AppointmentResource form (Repeater)
- [ ] Update AppointmentResource table (service_names column)
- [ ] Add multi-service filter
- [ ] Update exports (include all services)

### Phase 5: Migration & Deployment (Week 5)
- [ ] Full database backup
- [ ] Test on staging environment
- [ ] Run additive migrations (Phase 1)
- [ ] Run backfill migration (Phase 2)
- [ ] Deploy code (Phase 3)
- [ ] Monitor for errors (1 week)

### Phase 6: Enhancements (Week 6+)
- [ ] Bundle discounts (3+ services = 10% off)
- [ ] Service dependencies (A requires B)
- [ ] Multi-staff assignments (team bookings)
- [ ] Analytics dashboard (popular bundles)

---

## 11. Decision Summary

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Data Model** | `appointment_items` table | E-commerce standard, extensible |
| **Backward Compatibility** | Keep `service_id` nullable | Zero downtime, gradual migration |
| **Price Storage** | Snapshot in `appointment_items` | Historical accuracy |
| **Execution Model** | Sequential (sum durations) | Simplest, matches reality |
| **Staff Assignment** | Single staff with ALL competencies | Avoids coordination complexity |
| **Foreign Key** | `ON DELETE RESTRICT` for services | Prevent accidental data loss |
| **Migration Strategy** | 3-phase (additive → backfill → deploy) | Zero downtime, reversible |

---

## 12. Open Questions for Product Owner

1. **Bundle Discounts:**
   - Should we implement automatic discounts for 3+ services?
   - What percentage? (Proposal: 10%)
   - Should this be configurable per service combination?

2. **Service Dependencies:**
   - Are there services that REQUIRE other services first?
   - Example: "Powłoka ceramiczna" requires "Korekta lakieru"?

3. **Maximum Limits:**
   - Max services per appointment: 5? (proposal)
   - Max total duration: 8 hours? (proposal)

4. **Staff Preferences:**
   - Should customers be able to choose preferred staff?
   - Or always use automatic "best available" assignment?

5. **Separate Bookings:**
   - If no staff can do all services, should we:
     a) Show error + manual booking
     b) Automatically create 2 separate appointments
     c) Offer to split with single click

6. **Pricing Display:**
   - Should we show "package deals" on service pages?
   - Example: "Mycie + Wosk + Wnętrze = 500 PLN (zamiast 600 PLN)"

---

## 13. Approval Checklist

Before implementing, confirm:
- [ ] Database schema approved
- [ ] Migration strategy approved
- [ ] Staff assignment strategy approved
- [ ] Pricing logic approved
- [ ] UI/UX wireframes reviewed
- [ ] Performance targets defined
- [ ] Rollback plan documented

**Estimated Effort:** 5-6 weeks (1 senior developer)
**Risk Level:** MEDIUM
**Backward Compatibility:** ✅ Full
**Data Migration Required:** ✅ Yes (backfill script provided)

---

**Document Status:** DRAFT - Awaiting Product Owner Review
**Author:** Senior Laravel Architect
**Date:** 2025-12-20
**Version:** 1.0
