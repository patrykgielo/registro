# Deep Analysis: Booking Wizard Architecture

## Executive Summary

This is a **SESSION-BASED** booking wizard with **5 steps**. Vehicle type selection currently happens in **Step 3** alongside location. The proposal to move vehicle selection to service pages is **FEASIBLE but requires careful session management**.

---

## 1. STATE MANAGEMENT ARCHITECTURE

### Primary: PHP Session Storage (`session('booking')`)

**How it works:**
- Laravel session stores booking data server-side in `storage/framework/sessions/`
- Session key: `'booking'` (array)
- Persists across page reloads and step navigation
- Each step stores its data via `BookingController::storeStep()`

**Session Structure:**
```php
session('booking', [
    // Step 1
    'service_id' => 123,
    'current_step' => 1,

    // Step 2
    'date' => '2025-01-15',
    'time_slot' => '10:00',

    // Step 3
    'vehicle_type_id' => 4,           // ← CURRENT LOCATION
    'vehicle_brand' => 'BMW',         // Optional
    'vehicle_model' => '320d',        // Optional
    'vehicle_year' => 2020,           // Optional
    'location_address' => 'ul. Marszałkowska 1, 00-001 Warszawa',
    'location_latitude' => 52.2297,
    'location_longitude' => 21.0122,
    'location_place_id' => 'ChIJ...',
    'location_components' => {...},

    // Step 4
    'first_name' => 'Jan',
    'last_name' => 'Kowalski',
    'email' => 'jan@example.com',
    'phone' => '+48501234567',
    'notify_email' => true,
    'notify_sms' => true,
    'marketing_consent' => false,

    // Metadata
    'updated_at' => '2025-01-15 10:30:00',
]);
```

### Secondary: JavaScript State (booking-wizard.js)

**Purpose:** Frontend UX only (NOT used for form submission)
- Lines 12-68: Vanilla JS state object
- Used for: Step transitions, validation, UI updates
- **CRITICAL:** Hidden form inputs are the source of truth for submission

**JavaScript is NOT involved in vehicle type selection** - it's a pure server-side form POST.

---

## 2. COMPLETE BOOKING FLOW ANALYSIS

### Entry Points

1. **Service Page CTA** (`resources/views/services/show.blade.php`)
   - Line 147: `<a href="{{ route('booking.create', $service) }}">`
   - Route: `GET /services/{service}/book`
   - Controller: `BookingController::create(Service $service)`
   - **Action:** Sets `session(['booking.service_id' => $service->id])` and redirects to Step 1

2. **Direct Wizard Access**
   - Route: `GET /booking/step/1`
   - User manually navigates to wizard

### Step-by-Step Data Flow

#### **STEP 1: Service Selection**

**Route:** `GET /booking/step/1`

**Controller:** `BookingController::showStep(1)` (lines 127-138)
```php
// If service already in session (from CTA), skip to step 2
if (session('booking.service_id')) {
    return redirect()->route('booking.step', 2);
}
```

**View:** `resources/views/booking-wizard/steps/service.blade.php`
- Lines 36-44: Radio buttons for each service
- **Auto-submit:** `onchange="this.form.requestSubmit()"` (line 43)

**Form Submit:** `POST /booking/step/1`
- Validation: `service_id` required (line 211)
- **Storage:** `session(['booking.service_id' => $validated['service_id']])`
- **Redirect:** Step 2

---

#### **STEP 2: Date & Time**

**Route:** `GET /booking/step/2`

**Controller:** `BookingController::showStep(2)` (lines 140-146)

**View:** `resources/views/booking-wizard/steps/datetime.blade.php`
- Flatpickr calendar for date selection
- AJAX call to `/booking/available-slots` to fetch time slots

**Form Submit:** `POST /booking/step/2`
- Validation: `date` and `time_slot` required (lines 220-223)
- **Storage:** `session(['booking.date' => ..., 'booking.time_slot' => ...])`
- **Redirect:** Step 3

---

#### **STEP 3: Vehicle & Location** ← **CURRENT VEHICLE SELECTION LOCATION**

**Route:** `GET /booking/step/3`

**Controller:** `BookingController::showStep(3)` (lines 148-153)
```php
return view('booking-wizard.steps.vehicle-location', [
    'vehicleTypes' => VehicleType::active()->orderBy('sort_order')->get(),
    'googleMapsApiKey' => config('services.google_maps.api_key'),
    'googleMapsMapId' => config('services.google_maps.map_id'),
]);
```

**View:** `resources/views/booking-wizard/steps/vehicle-location.blade.php`

**Vehicle Type Selection UI:**
- Lines 82-122: Button opens bottom sheet modal
- Lines 383-421: Bottom sheet with vehicle type cards
- Alpine.js component handles selection state (lines 486-793)
- **Form Field:** `<input type="hidden" name="vehicle_type_id" x-model="selectedVehicleType">` (line 108)

**Form Submit:** `POST /booking/step/3`

**Controller:** `BookingController::storeStep(3)` (lines 231-273)

**CRITICAL VALIDATION SEQUENCE:**
1. **Input Validation** (lines 232-242):
   ```php
   $validated = $request->validate([
       'vehicle_type_id' => 'required|exists:vehicle_types,id',
       'vehicle_brand' => 'nullable|string|max:100',
       'vehicle_model' => 'nullable|string|max:100',
       'vehicle_year' => 'nullable|integer|min:1900|max:' . (date('Y') + 1),
       'location_address' => 'required|string|max:255',
       'location_latitude' => 'required|numeric|between:-90,90',
       'location_longitude' => 'required|numeric|between:-180,180',
       'location_place_id' => 'nullable|string|max:255',
       'location_components' => 'nullable|string',
   ]);
   ```

2. **Service Area Validation** (lines 244-257):
   ```php
   $areaValidation = $this->serviceAreaValidator->validate(
       $validated['location_latitude'],
       $validated['location_longitude']
   );

   if (!$areaValidation['valid']) {
       return response()->json([
           'success' => false,
           'error' => $areaValidation['message'],
       ], 422);
   }
   ```

3. **Session Storage** (lines 260-271):
   ```php
   session([
       'booking.vehicle_type_id' => $validated['vehicle_type_id'],
       'booking.vehicle_brand' => $validated['vehicle_brand'] ?? null,
       'booking.vehicle_model' => $validated['vehicle_model'] ?? null,
       'booking.vehicle_year' => $validated['vehicle_year'] ?? null,
       'booking.location_address' => $validated['location_address'],
       'booking.location_latitude' => $validated['location_latitude'],
       'booking.location_longitude' => $validated['location_longitude'],
       'booking.location_place_id' => $validated['location_place_id'] ?? null,
       'booking.location_components' => $validated['location_components'] ?? null,
       'booking.current_step' => 3,
   ]);
   ```

4. **Redirect:** Step 4

---

#### **STEP 4: Contact Information**

**Route:** `GET /booking/step/4`

**Controller:** `BookingController::showStep(4)` (lines 155-184)
- Pre-fills contact data from `auth()->user()` if authenticated

**Form Submit:** `POST /booking/step/4`
- Validation: name, email, phone required (lines 276-285)
- **Storage:** `session(['booking.first_name' => ...])`
- **Redirect:** Step 5 (Review)

---

#### **STEP 5: Review & Confirm**

**Route:** `GET /booking/step/5`

**Controller:** `BookingController::showStep(5)` (lines 186-195)

**View:** `resources/views/booking-wizard/steps/review.blade.php`
- Displays all booking data from session
- Final CTA: "Potwierdź Rezerwację"

**Form Submit:** `POST /booking/confirm`

**Controller:** `BookingController::confirm()` (lines 471-601)

**CRITICAL RE-VALIDATION (Security):**
1. Session validation (lines 473-479)
2. **Service area re-validation** (lines 481-497)
3. Slot availability check (lines 503-515)
4. Staff assignment (lines 517-527)
5. **Appointment creation** (lines 559-583):
   ```php
   Appointment::create([
       'customer_id' => auth()->id(),
       'service_id' => $booking['service_id'],
       'staff_id' => $staff->id,
       'appointment_date' => $appointmentDateTime->format('Y-m-d'),
       'start_time' => $appointmentDateTime->format('H:i:s'),
       'end_time' => $appointmentDateTime->copy()->addMinutes($service->duration_minutes)->format('H:i:s'),
       'status' => 'pending',
       'vehicle_type_id' => $booking['vehicle_type_id'],  // ← FROM SESSION
       'vehicle_custom_brand' => $booking['vehicle_brand'] ?? null,
       'vehicle_custom_model' => $booking['vehicle_model'] ?? null,
       'vehicle_year' => $booking['vehicle_year'] ?? null,
       // ... location data ...
   ]);
   ```

6. Session cleanup (line 598): `session()->forget('booking')`
7. Redirect to confirmation page

---

## 3. VEHICLE TYPE SELECTION - CURRENT IMPLEMENTATION

### Database Schema

**Table:** `appointments`

**Relevant columns:**
```sql
vehicle_type_id         INT UNSIGNED (foreign key to vehicle_types.id)
vehicle_custom_brand    VARCHAR(100) NULLABLE
vehicle_custom_model    VARCHAR(100) NULLABLE
vehicle_year            INT UNSIGNED NULLABLE
car_brand_id            INT UNSIGNED NULLABLE (foreign key)
car_model_id            INT UNSIGNED NULLABLE (foreign key)
```

### Appointment Model Relationships

**File:** `app/Models/Appointment.php`

Lines 140-153:
```php
public function vehicleType()
{
    return $this->belongsTo(VehicleType::class);
}

public function carBrand()
{
    return $this->belongsTo(CarBrand::class);
}

public function carModel()
{
    return $this->belongsTo(CarModel::class);
}
```

### How Vehicle Data Flows to Database

**Source:** `BookingController::confirm()` (line 567)
```php
'vehicle_type_id' => $booking['vehicle_type_id'],  // From session('booking.vehicle_type_id')
```

**Populated by:** `BookingController::storeStep(3)` (line 261)
```php
session(['booking.vehicle_type_id' => $validated['vehicle_type_id']]);
```

**Validated in:** Step 3 form submission (line 232)
```php
'vehicle_type_id' => 'required|exists:vehicle_types,id',
```

---

## 4. MOVING VEHICLE SELECTION TO SERVICE PAGE - INTEGRATION ANALYSIS

### Proposed Flow

**BEFORE (Current):**
```
Service Page (CTA) → Step 1 (Skip if service in session) → Step 2 (Date/Time) → Step 3 (VEHICLE + Location) → Step 4 (Contact) → Step 5 (Review)
```

**AFTER (Proposed):**
```
Service Page (SELECT VEHICLE + CTA) → Step 1 (Skip) → Step 2 (Date/Time) → Step 3 (Location only) → Step 4 (Contact) → Step 5 (Review)
```

### Integration Points

#### 1. **Service Page Modification**

**File:** `resources/views/services/show.blade.php`

**Current CTA (line 147):**
```html
<a href="{{ route('booking.create', $service) }}">
    Zarezerwuj Termin
</a>
```

**Proposed Addition (Before CTA):**
```html
<!-- NEW: Vehicle Type Selector (Bottom Sheet or Inline) -->
<form method="POST" action="{{ route('booking.preselect-vehicle') }}">
    @csrf
    <input type="hidden" name="service_id" value="{{ $service->id }}">

    <!-- Vehicle Type Bottom Sheet Trigger -->
    <button type="button" @click="openVehicleTypeSelector">
        Wybierz typ pojazdu
    </button>

    <!-- Hidden field populated by Alpine.js -->
    <input type="hidden" name="vehicle_type_id" x-model="selectedVehicleType">

    <!-- CTA (disabled until vehicle selected) -->
    <button type="submit" :disabled="!selectedVehicleType">
        Zarezerwuj Termin
    </button>
</form>
```

#### 2. **New Route for Pre-selection**

**File:** `routes/web.php`

**Add:**
```php
Route::post('/booking/preselect-vehicle', [BookingController::class, 'preselectVehicle'])
    ->name('booking.preselect-vehicle');
```

#### 3. **New Controller Method**

**File:** `app/Http/Controllers/BookingController.php`

**Add after `create()` method:**
```php
public function preselectVehicle(Request $request)
{
    $validated = $request->validate([
        'service_id' => 'required|exists:services,id',
        'vehicle_type_id' => 'required|exists:vehicle_types,id',
    ]);

    // Store both service and vehicle type in session
    session([
        'booking.service_id' => $validated['service_id'],
        'booking.vehicle_type_id' => $validated['vehicle_type_id'],
        'booking.current_step' => 0, // Not started yet
    ]);

    // Redirect to Step 2 (skip service selection, vehicle already selected)
    return redirect()->route('booking.step', 2);
}
```

#### 4. **Modify Step 3 View (Vehicle & Location → Location Only)**

**File:** `resources/views/booking-wizard/steps/vehicle-location.blade.php`

**Changes needed:**

**A) Conditional Vehicle Section Display (lines 69-122):**
```blade
@if(!session('booking.vehicle_type_id'))
    {{-- Section 1: Vehicle Type (Required if not pre-selected) --}}
    <div class="vehicle-location__section mb-8">
        <!-- Existing vehicle selector -->
    </div>
@else
    {{-- Show pre-selected vehicle type as read-only --}}
    <div class="bg-green-50 rounded-xl p-4 border border-green-200 mb-8">
        <div class="flex items-center gap-3">
            <svg class="w-6 h-6 text-green-600">...</svg>
            <div>
                <div class="text-sm text-green-700">Wybrany typ pojazdu:</div>
                <div class="text-base font-bold text-green-900">{{ $vehicleType->name }}</div>
            </div>
        </div>
    </div>

    {{-- Hidden input to preserve pre-selected value --}}
    <input type="hidden" name="vehicle_type_id" value="{{ session('booking.vehicle_type_id') }}">
@endif
```

**B) Update Controller for Step 3 (lines 148-153):**
```php
case 3: // Vehicle & Location (or Location only if vehicle pre-selected)
    $vehicleType = null;
    if (session('booking.vehicle_type_id')) {
        $vehicleType = VehicleType::find(session('booking.vehicle_type_id'));
    }

    return view('booking-wizard.steps.vehicle-location', [
        'vehicleTypes' => VehicleType::active()->orderBy('sort_order')->get(),
        'vehicleType' => $vehicleType, // Pre-selected vehicle (if any)
        'googleMapsApiKey' => config('services.google_maps.api_key'),
        'googleMapsMapId' => config('services.google_maps.map_id'),
    ]);
```

**C) Update Validation for Step 3 (line 232):**
```php
// Make vehicle_type_id optional if already in session
$rules = [
    'vehicle_type_id' => session('booking.vehicle_type_id')
        ? 'nullable|exists:vehicle_types,id'  // Optional (already have it)
        : 'required|exists:vehicle_types,id', // Required (first time)
    'vehicle_brand' => 'nullable|string|max:100',
    'vehicle_model' => 'nullable|string|max:100',
    'vehicle_year' => 'nullable|integer|min:1900|max:' . (date('Y') + 1),
    'location_address' => 'required|string|max:255',
    'location_latitude' => 'required|numeric|between:-90,90',
    'location_longitude' => 'required|numeric|between:-180,180',
    'location_place_id' => 'nullable|string|max:255',
    'location_components' => 'nullable|string',
];

$validated = $request->validate($rules);

// Preserve pre-selected vehicle_type_id if not submitted
if (!$validated['vehicle_type_id'] && session('booking.vehicle_type_id')) {
    session(['booking.vehicle_type_id' => session('booking.vehicle_type_id')]);
}
```

---

## 5. RISKS & BREAKING CHANGES ANALYSIS

### ✅ LOW RISK (Session-based, no breaking changes)

1. **Session Management is Flexible**
   - Adding `vehicle_type_id` to session BEFORE Step 3 is perfectly safe
   - Step 3 validation already handles `vehicle_type_id` from POST data
   - No database schema changes required

2. **Backward Compatibility**
   - Old flow still works: Users can access wizard directly without pre-selecting vehicle
   - New flow is opt-in: Only users who click CTA on service page pre-select vehicle

3. **Validation Re-use**
   - Same validation rules apply (`required|exists:vehicle_types,id`)
   - No new validation logic needed

### ⚠️ MEDIUM RISK (Requires Testing)

1. **Step 3 UI Conditional Logic**
   - If vehicle pre-selected: Hide vehicle selector, show read-only confirmation
   - If not pre-selected: Show full vehicle selector (current behavior)
   - **Risk:** Alpine.js state synchronization if user navigates back to service page

2. **Session State Reset**
   - If user navigates back to service page from wizard, should we preserve or reset vehicle selection?
   - **Recommendation:** Preserve session data (user can change vehicle on service page if needed)

3. **Service Area Validation Dependency**
   - Service area validation happens in Step 3 POST (line 244-257)
   - Vehicle type selection on service page is BEFORE location is known
   - **No conflict:** Vehicle type does not affect service area validation

### 🔴 HIGH RISK (Must Address)

1. **User Flow Confusion**
   - **Scenario:** User selects vehicle on service page → Navigates to Step 2 (Date/Time) → Realizes they selected wrong vehicle
   - **Current solution:** User must navigate back to service page to re-select
   - **Better solution:** Allow vehicle editing in Step 3 even if pre-selected
   - **Recommendation:** Add "Zmień typ pojazdu" button in Step 3 that unhides the selector

2. **Multiple Entry Points**
   - Service page pre-selection (new flow)
   - Direct wizard access (old flow - no pre-selection)
   - Bookmark Step 2 URL (edge case - session may be empty)
   - **Risk:** Session validation needs to handle all cases

3. **Step Skipping Logic**
   - Current: `showStep(1)` redirects to Step 2 if `service_id` in session (lines 130-133)
   - New: Need similar logic for vehicle pre-selection
   - **Risk:** User bookmarks Step 3, but session has vehicle → Should we show location only or full form?

---

## 6. RECOMMENDED IMPLEMENTATION PLAN

### Phase 1: Session Pre-population (Low Risk)

**Goal:** Allow service page to pre-populate vehicle selection without modifying Step 3 UI.

**Changes:**
1. Add route: `POST /booking/preselect-vehicle`
2. Add controller method: `BookingController::preselectVehicle()`
3. Modify service page CTA to POST vehicle_type_id

**Testing:**
- Verify session contains `booking.vehicle_type_id` after CTA click
- Verify Step 3 still validates and stores vehicle_type_id
- Verify appointment creation uses session value

**Rollback Strategy:** Remove route and controller method, revert service page CTA.

### Phase 2: Step 3 Conditional UI (Medium Risk)

**Goal:** Show read-only vehicle confirmation if pre-selected, otherwise show selector.

**Changes:**
1. Modify `vehicle-location.blade.php` to conditionally render vehicle section
2. Update Step 3 controller to pass `$vehicleType` (pre-selected) to view
3. Update Step 3 validation to handle optional vehicle_type_id

**Testing:**
- Test new flow: Service page → Pre-select vehicle → Wizard shows read-only
- Test old flow: Direct wizard access → Wizard shows full selector
- Test edit flow: Pre-selected vehicle → User clicks "Zmień" → Selector unhides

**Rollback Strategy:** Remove conditional logic, revert to always showing vehicle selector.

### Phase 3: UX Enhancements (High Risk - Optional)

**Goal:** Allow users to edit pre-selected vehicle in Step 3.

**Changes:**
1. Add "Zmień typ pojazdu" button in Step 3 when vehicle is pre-selected
2. Alpine.js logic to toggle between read-only and edit mode
3. Session logic to overwrite `booking.vehicle_type_id` if user changes it

**Testing:**
- Test pre-selection → Edit → Change vehicle → Verify session updates
- Test pre-selection → Edit → Keep vehicle → Verify no regression

**Rollback Strategy:** Remove edit button, keep read-only behavior.

---

## 7. CRITICAL QUESTIONS FOR STAKEHOLDER

1. **Should users be able to change vehicle type in Step 3 after pre-selecting on service page?**
   - **Option A (Simple):** Pre-selected vehicle is locked → User must go back to service page to change
   - **Option B (Flexible):** Pre-selected vehicle is editable in Step 3 via "Zmień" button
   - **Recommendation:** Option B for better UX

2. **What happens if user bookmarks Step 2 URL?**
   - **Current:** Redirect to Step 1 if no service_id in session
   - **Proposed:** Keep same behavior (session validation prevents incomplete bookings)

3. **Should vehicle selection on service page be REQUIRED or OPTIONAL before CTA?**
   - **Option A (Required):** CTA disabled until vehicle selected
   - **Option B (Optional):** CTA always enabled, vehicle selection is skippable
   - **Recommendation:** Option A (matches wizard's required validation)

4. **Mobile UI for vehicle selector on service page:**
   - **Option A:** Inline radio buttons (simple, but clutters page)
   - **Option B:** Bottom sheet modal (matches wizard UX, cleaner)
   - **Recommendation:** Option B (consistent with wizard Step 3)

---

## 8. FINAL VERDICT

### Feasibility: ✅ **HIGHLY FEASIBLE**

**Why:**
- Session-based architecture is flexible
- No database schema changes required
- Backward compatibility maintained
- Existing validation rules can be reused

### Complexity: ⚠️ **MEDIUM**

**Why:**
- Requires conditional UI logic in Step 3
- Need to handle multiple entry points (service page vs. direct wizard)
- User flow testing required (pre-selection, editing, session reset)

### Breaking Changes: ✅ **NONE**

**Why:**
- New flow is additive (service page pre-selection)
- Old flow still works (direct wizard access)
- Session validation prevents incomplete bookings

### Recommended Approach: **PHASED ROLLOUT**

1. **Phase 1 (Week 1):** Backend session pre-population (low risk)
2. **Phase 2 (Week 2):** Step 3 conditional UI (medium risk)
3. **Phase 3 (Week 3):** UX enhancements (optional, if stakeholder wants edit button)

---

## 9. CODE SNIPPETS FOR IMPLEMENTATION

### A) New Route

**File:** `routes/web.php` (add after line 111)
```php
Route::middleware(['auth'])->group(function () {
    // Existing booking routes...

    // NEW: Pre-select vehicle from service page
    Route::post('/booking/preselect-vehicle', [BookingController::class, 'preselectVehicle'])
        ->name('booking.preselect-vehicle')
        ->middleware(['throttle:30,1']); // Same rate limit as other booking endpoints
});
```

### B) New Controller Method

**File:** `app/Http/Controllers/BookingController.php` (add after `create()` method, line 49)
```php
/**
 * Pre-select vehicle type from service page
 *
 * Allows users to select vehicle type BEFORE entering booking wizard.
 * Stores selection in session and redirects to Step 2 (skipping Step 1 service selection).
 */
public function preselectVehicle(Request $request)
{
    $validated = $request->validate([
        'service_id' => 'required|exists:services,id',
        'vehicle_type_id' => 'required|exists:vehicle_types,id',
    ]);

    // Store both service and vehicle type in session
    session([
        'booking.service_id' => $validated['service_id'],
        'booking.vehicle_type_id' => $validated['vehicle_type_id'],
        'booking.current_step' => 0, // Not started yet
    ]);

    // Redirect to Step 2 (Date & Time) - skip Step 1 (service selection)
    return redirect()->route('booking.step', 2);
}
```

### C) Service Page UI Component

**File:** `resources/views/components/service-vehicle-selector.blade.php` (NEW COMPONENT)
```blade
@props(['service', 'vehicleTypes'])

<div x-data="{ selectedVehicleType: {{ session('booking.vehicle_type_id', 'null') }}, showBottomSheet: false }">
    {{-- Vehicle Type Selection Button --}}
    <button
        type="button"
        @click="showBottomSheet = true"
        class="w-full flex items-center justify-between px-6 py-4 bg-white hover:bg-gray-50 border-2 border-gray-300 rounded-xl transition-all"
        :class="selectedVehicleType ? 'border-primary-400 bg-primary-50' : ''"
    >
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                </svg>
            </div>
            <div class="text-left">
                <div class="text-sm font-medium text-gray-600">Typ pojazdu</div>
                <div class="text-base font-bold text-gray-900" x-text="selectedVehicleType ? 'Wybrano' : 'Wybierz typ pojazdu'"></div>
            </div>
        </div>
        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
        </svg>
    </button>

    {{-- CTA Button (disabled until vehicle selected) --}}
    <form method="POST" action="{{ route('booking.preselect-vehicle') }}" class="mt-4">
        @csrf
        <input type="hidden" name="service_id" value="{{ $service->id }}">
        <input type="hidden" name="vehicle_type_id" x-model="selectedVehicleType">

        <button
            type="submit"
            :disabled="!selectedVehicleType"
            class="w-full px-8 py-4 rounded-full font-semibold text-lg transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed"
            :class="selectedVehicleType ? 'bg-primary-600 text-white hover:bg-primary-700' : 'bg-gray-300 text-gray-500'"
        >
            Zarezerwuj Termin
        </button>
    </form>

    {{-- Bottom Sheet: Vehicle Type Selector --}}
    <div
        x-show="showBottomSheet"
        @click.away="showBottomSheet = false"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 transform translate-y-full"
        x-transition:enter-end="opacity-100 transform translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 transform translate-y-0"
        x-transition:leave-end="opacity-0 transform translate-y-full"
        class="fixed inset-x-0 bottom-0 z-50 bg-white rounded-t-2xl shadow-2xl p-6 max-h-[80vh] overflow-y-auto"
        style="display: none;"
    >
        <h3 class="text-xl font-bold text-gray-900 mb-4">Wybierz typ pojazdu</h3>

        <div class="space-y-3">
            @foreach($vehicleTypes as $type)
                <button
                    type="button"
                    @click="selectedVehicleType = {{ $type->id }}; showBottomSheet = false"
                    class="w-full text-left p-4 bg-white hover:bg-primary-50 border-2 border-gray-200 hover:border-primary-400 rounded-xl transition-all"
                    :class="selectedVehicleType === {{ $type->id }} ? 'border-primary-400 bg-primary-50 ring-4 ring-primary-200' : ''"
                >
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                        </div>
                        <div>
                            <h4 class="text-base font-bold text-gray-900">{{ $type->name }}</h4>
                            <p class="text-sm text-gray-600">{{ $type->description }}</p>
                        </div>
                    </div>
                </button>
            @endforeach
        </div>
    </div>
</div>
```

### D) Modify Service Page

**File:** `resources/views/services/show.blade.php` (replace CTA section, around line 144-152)
```blade
{{-- Footer CTA (iOS Style) --}}
<div class="mt-16 p-8 md:p-12 bg-gradient-to-br from-primary via-blue-600 to-indigo-700 rounded-2xl shadow-2xl">
    <h2 class="text-3xl md:text-4xl font-bold text-white mb-4 text-center">Gotowy rozpocząć?</h2>
    <p class="text-white/90 text-lg mb-8 max-w-2xl mx-auto text-center">Wybierz typ pojazdu i zarezerwuj termin online</p>

    {{-- NEW: Vehicle Type Selector Component --}}
    <div class="max-w-md mx-auto">
        <x-service-vehicle-selector
            :service="$service"
            :vehicle-types="App\Models\VehicleType::active()->orderBy('sort_order')->get()"
        />
    </div>
</div>
```

---

## 10. TESTING CHECKLIST

### Unit Tests
- [ ] `BookingController::preselectVehicle()` validates service_id
- [ ] `BookingController::preselectVehicle()` validates vehicle_type_id
- [ ] `BookingController::preselectVehicle()` stores session correctly
- [ ] `BookingController::preselectVehicle()` redirects to Step 2

### Integration Tests
- [ ] **New Flow:** Service page → Select vehicle → CTA → Step 2 → Step 3 (location only) → Confirm
- [ ] **Old Flow:** Direct wizard → Step 1 → Step 2 → Step 3 (vehicle + location) → Confirm
- [ ] **Edit Flow:** Service page → Select vehicle A → Step 3 → Change to vehicle B → Confirm
- [ ] **Session Reset:** Service page → Select vehicle → Abandon wizard → Return to service page → Select different vehicle
- [ ] **Validation:** Service page → Select invalid vehicle_type_id → Verify error

### Manual Testing
- [ ] Mobile UI: Bottom sheet opens correctly on service page
- [ ] Desktop UI: Vehicle selector is keyboard-accessible
- [ ] Session persistence: Refresh Step 2 → Verify vehicle still selected
- [ ] Back navigation: Step 3 → Back to Step 2 → Back to service page → Verify vehicle still selected
- [ ] Error handling: Submit Step 3 without location (but with pre-selected vehicle) → Verify location validation error

---

## 11. MONITORING & METRICS

**Track these metrics after deployment:**

1. **Conversion Rate:**
   - % of users who complete booking after selecting vehicle on service page
   - Compare to: % of users who complete booking via direct wizard access

2. **Drop-off Points:**
   - % of users who abandon after selecting vehicle on service page
   - % of users who abandon at Step 3 (with/without pre-selected vehicle)

3. **Edit Behavior (if Phase 3 implemented):**
   - % of users who change pre-selected vehicle in Step 3
   - Most commonly changed vehicle types (indicates UX issues)

4. **Session Errors:**
   - Number of 422 errors due to missing session data
   - Number of redirects to Step 1 due to missing service_id/vehicle_type_id

---

**END OF ANALYSIS**
