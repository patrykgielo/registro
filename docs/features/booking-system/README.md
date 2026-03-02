# Booking System

Multi-step booking wizard for car detailing appointments.

**Last Updated:** 2025-11-11
**Status:** Production Ready

## Overview

The booking system is a 4-step wizard that guides customers through the appointment creation process. It uses vanilla JavaScript (no frameworks), integrates with Google Maps for location selection, and captures vehicle information for service planning.

**Key Features:**
- 4-step wizard with validation
- Service selection with pricing
- Date/time slot selection with availability checking
- Google Maps Places Autocomplete for service location
- Vehicle information capture (type, brand, model, year)
- Review and confirmation step
- Vanilla JavaScript implementation (no frameworks - lightweight, fast)
- State management with plain JavaScript object
- API-driven (RESTful endpoints)
- Mobile-responsive design

## Architecture

### Frontend Flow (4-Step Wizard)

#### Step 1: Service Selection

**Purpose:** Customer chooses a detailing service

**UI Components:**
- Service cards with name, description, duration, price
- "Select" button for each service
- Active service highlighted

**Data Fetched:**
- GET /api/services - All active services ordered by sort_order

**Validation:**
- Service must be selected before proceeding

**State Updated:**
```javascript
state.service = {
    id: 1,
    name: "Detailing zewnętrzny",
    description: "Complete exterior detailing...",
    duration_minutes: 120,
    price: 299.00
}
```

---

#### Step 2: Date & Time Selection

**Purpose:** Customer picks appointment date and time slot

**UI Components:**
- Date picker (native HTML5 date input or Guava Calendar integration - future)
- Time slot grid (buttons for each available slot)
- Business hours displayed
- Unavailable slots disabled/hidden

**Data Fetched:**
- GET /api/availability?date=YYYY-MM-DD - Available time slots for selected date
  - Returns 30-minute interval slots (configurable via settings)
  - Filters out booked slots and slots outside business hours

**Validation:**
- Date must be today or future
- Date must be within advance booking window (configurable, default 24 hours)
- Time slot must be selected

**State Updated:**
```javascript
state.dateTime = {
    date: "2025-11-15",
    time: "14:00",
    display_date: "15 listopada 2025",
    display_time: "14:00"
}
```

**Business Rules:**
- Slots generated from business hours (settings: booking.business_hours_start/end)
- Slot interval from settings (default: 30 minutes)
- Advance booking hours from settings (default: 24 hours minimum)
- Maximum service duration from settings (default: 480 minutes / 8 hours)

---

#### Step 3: Location & Vehicle

**Purpose:** Capture service location and vehicle details

**Section 1: Service Location (Google Maps Integration)**

**UI Components:**
- Google Maps Places Autocomplete input
- Google Map display with marker
- Address component fields (read-only, auto-filled from Google)

**Flow:**
1. Customer types address in autocomplete input
2. Google suggests matching places
3. Customer selects from dropdown
4. place_changed event fires
5. System captures:
   - Full formatted address
   - Latitude/longitude coordinates
   - Google Place ID
   - Structured address components (street, city, postal code, country)
6. Map updates with marker at selected location
7. Map centers and zooms to marker (zoom level 17)

**Validation:**
- Address must be selected from Google autocomplete (not free text)
- Coordinates must be captured
- Place ID must be present

**State Updated:**
```javascript
state.location = {
    address: "ul. Marszałkowska 1, Warszawa, Poland",
    latitude: 52.2297,
    longitude: 21.0122,
    place_id: "ChIJAQAAAF3MHkcRoqB_3TQBCAM",
    components: {
        route: { long_name: "Marszałkowska", short_name: "Marsz." },
        locality: { long_name: "Warszawa", short_name: "Warszawa" },
        postal_code: { long_name: "00-001", short_name: "00-001" },
        country: { long_name: "Poland", short_name: "PL" }
    }
}
```

**Section 2: Vehicle Information**

**UI Components:**
- Vehicle type cards (5 types: city car, small car, medium car, large car, delivery van)
- Brand dropdown (searchable, all active brands)
- Model dropdown (filtered by selected brand)
- Year dropdown (1990 to current_year + 1)

**Flow:**
1. Customer selects vehicle type by clicking card
2. Brand dropdown becomes active
3. Customer selects brand
4. Model dropdown filters to show only models for selected brand
5. Customer selects model
6. Customer selects year

**API Endpoints:**
- GET /api/vehicle-types - All active vehicle types
- GET /api/car-brands - All active brands
- GET /api/car-models?car_brand_id={id} - Models for specific brand
- GET /api/vehicle-years - Array [2025, 2024, ..., 1990]

**Validation:**
- Vehicle type required
- Brand required (or custom brand if "Other" selected)
- Model required (or custom model if "Other" selected)
- Year required

**State Updated:**
```javascript
state.vehicle = {
    type_id: 3,              // medium_car
    type_name: "Auto średnie",
    brand_id: 5,
    brand_name: "Toyota",
    model_id: 12,
    model_name: "Corolla",
    year: 2020
}
```

**Important Notes:**
- Vehicle type is customer declaration only (not enforced against database)
- Brand/model dropdowns show all items (NO filtering by vehicle type)
- Custom brand/model support (for unknown vehicles pending admin approval)

---

#### Step 4: Summary & Confirmation

**Purpose:** Review all selections and submit appointment

**UI Components:**
- Service summary (name, duration, price)
- Date/time summary
- Location summary (formatted address)
- Vehicle summary (Brand Model (Year))
- Terms acceptance checkbox
- Submit button

**Validation:**
- All previous steps must be valid
- Terms must be accepted

**Submission:**
```javascript
// POST /appointments
{
    service_id: 1,
    appointment_date: "2025-11-15",
    appointment_time: "14:00",
    location_address: "ul. Marszałkowska 1, Warszawa, Poland",
    location_latitude: 52.2297,
    location_longitude: 21.0122,
    location_place_id: "ChIJ...",
    location_components: {...},
    vehicle_type_id: 3,
    car_brand_id: 5,
    car_model_id: 12,
    vehicle_year: 2020,
    notes: "" // Optional customer notes
}
```

**Success Response:**
- Redirect to /appointments/{id} or /my-appointments
- Success message: "Appointment booked successfully"
- Email confirmation sent (AppointmentCreatedNotification)

**Error Handling:**
- Validation errors displayed inline
- Slot no longer available → Show error, return to Step 2
- Server error → Show generic error message

---

### Backend Controllers

#### BookingController

**File:** app/Http/Controllers/BookingController.php

**Route:** GET /booking/create

**Method: create()**
```php
public function create(SettingsManager $settings)
{
    // Fetch configuration
    $bookingConfig = $settings->bookingConfiguration();
    $mapConfig = $settings->mapConfiguration();
    $marketingContent = $settings->marketingContent();

    return view('booking.create', compact(
        'bookingConfig',
        'mapConfig',
        'marketingContent'
    ));
}
```

**Passes to View:**
- bookingConfig - Business hours, slot interval, advance booking hours
- mapConfig - Default map center, zoom, country code, map ID
- marketingContent - Hero title/subtitle, CTA text

---

#### AppointmentController

**File:** app/Http/Controllers/AppointmentController.php

**Route:** POST /appointments

**Validation:**
```php
$validated = $request->validate([
    'service_id' => 'required|exists:services,id',
    'appointment_date' => 'required|date|after_or_equal:today',
    'appointment_time' => 'required|date_format:H:i',
    'location_address' => 'required|string|max:500',
    'location_latitude' => 'required|numeric|between:-90,90',
    'location_longitude' => 'required|numeric|between:-180,180',
    'location_place_id' => 'nullable|string|max:255',
    'location_components' => 'nullable|json',
    'vehicle_type_id' => 'required|exists:vehicle_types,id',
    'car_brand_id' => 'nullable|exists:car_brands,id',
    'car_model_id' => 'nullable|exists:car_models,id',
    'vehicle_year' => 'required|integer|min:1990|max:' . (date('Y') + 1),
    'notes' => 'nullable|string|max:1000',
]);
```

**Logic:**
1. Validate all fields
2. Check service exists and is active
3. Verify time slot still available
4. Create appointment (status: pending, customer_id: Auth::id())
5. Dispatch AppointmentCreated event
6. Return success response

---

### JavaScript Architecture

**File:** resources/js/booking-wizard.js

**State Object:**
```javascript
const state = {
    currentStep: 1,
    maxStep: 1,
    service: null,
    dateTime: { date: null, time: null },
    location: { address: null, latitude: null, longitude: null, place_id: null, components: null },
    vehicle: { type_id: null, brand_id: null, model_id: null, year: null },
    mapInitialized: false,
    mapInstance: null,
    markerInstance: null
};
```

**Key Functions:**
- navigateToStep(step) - Step navigation with validation
- validateStep1/2/3/4() - Step-specific validation
- setupPlaceAutocomplete() - Google Maps Autocomplete integration
- initializeMap() - Lazy map initialization
- updateMapMarker() - Update marker position
- fetchVehicleTypes/Brands/Models() - API calls
- submitAppointment() - Form submission

---

## Database Schema

**appointments table:**
```sql
CREATE TABLE appointments (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    service_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    status ENUM('pending', 'confirmed', 'cancelled', 'completed') DEFAULT 'pending',
    
    -- Location (Google Maps)
    location_address VARCHAR(500) NULL,
    location_latitude DOUBLE(10,8) NULL,
    location_longitude DOUBLE(11,8) NULL,
    location_place_id VARCHAR(255) NULL,
    location_components JSON NULL,
    
    -- Vehicle
    vehicle_type_id BIGINT UNSIGNED NULL,
    car_brand_id BIGINT UNSIGNED NULL,
    car_model_id BIGINT UNSIGNED NULL,
    vehicle_year YEAR NULL,
    vehicle_custom_brand VARCHAR(100) NULL,
    vehicle_custom_model VARCHAR(100) NULL,
    
    notes TEXT NULL,
    cancellation_reason TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    
    INDEX appointments_date_time_index (appointment_date, appointment_time),
    INDEX appointments_location_coords_index (location_latitude, location_longitude)
);
```

---

## API Endpoints

```
GET  /booking/create                  BookingController@create (display wizard)
POST /appointments                    AppointmentController@store (create appointment)
GET  /api/services                    All active services
GET  /api/availability                AvailabilityController@index (time slots)
GET  /api/vehicle-types               VehicleDataController@vehicleTypes
GET  /api/car-brands                  VehicleDataController@brands
GET  /api/car-models                  VehicleDataController@models
GET  /api/vehicle-years               VehicleDataController@years
```

---

## Integration Points

### Google Maps Integration

**Modern JavaScript API** (NOT Web Components)
- google.maps.places.Autocomplete class
- AdvancedMarkerElement (latest marker API)
- Captures: address, coordinates, place_id, components

**Configuration:**
- API key: GOOGLE_MAPS_API_KEY in .env
- Map ID: GOOGLE_MAPS_MAP_ID (required)
- Default center/zoom from settings (map group)

**See:** [Google Maps Integration](../google-maps/README.md)

---

### Vehicle Management Integration

**Vehicle Type Selection:**
- Customer declares vehicle type (not enforced)
- Used for future pricing logic

**Brand/Model Dropdowns:**
- All active brands shown (NO filtering by vehicle type)
- Models filtered by selected brand only
- Custom brand/model fallback

**See:** [Vehicle Management](../vehicle-management/README.md)

---

### Settings Integration

**Configuration Consumed:**
- booking.business_hours_start/end
- booking.slot_interval_minutes
- booking.advance_booking_hours
- map.default_latitude/longitude/zoom/country_code/map_id
- marketing.* (hero text, CTA)

**See:** [Settings System](../settings-system/README.md)

---

### Email Notifications

**Triggered Events:**
- AppointmentCreated - Email sent to customer
- Template: appointment-created (PL/EN)

**See:** [Email System](../email-system/README.md)

---

## Troubleshooting

### Step Navigation Issues

**Problem:** Buttons don't work, validation blocks without error

**Solutions:**
- Check browser console for JavaScript errors
- Debug state object: console.log(state)
- Verify validation functions return boolean
- Check event listeners attached

---

### Google Maps Not Loading

**Problem:** Gray box, no autocomplete suggestions

**Solutions:**
- Verify GOOGLE_MAPS_API_KEY in .env
- Check Google Cloud Console:
  - APIs enabled: Maps JavaScript API, Places API
  - Referrer restrictions include registro.local
- Clear browser cache

**See:** [Google Maps Troubleshooting](../google-maps/README.md#troubleshooting)

---

### Vehicle Data Not Loading

**Problem:** 403 Forbidden on /api/vehicle-types

**Solutions:**
- Check routes use auth middleware (NOT role:super-admin)
- Routes must allow all authenticated users

**See:** [Vehicle Management Troubleshooting](../vehicle-management/README.md#api-returns-403-forbidden)

---

### Time Slots Empty

**Problem:** No slots appear after date selection

**Solutions:**
- Verify settings seeded (SettingSeeder)
- Check business hours configured
- Test API: curl https://registro.local:8444/api/availability?date=YYYY-MM-DD
- Verify date not in past

---

## Files

**Backend:**
- app/Http/Controllers/BookingController.php
- app/Http/Controllers/AppointmentController.php
- app/Http/Controllers/Api/AvailabilityController.php
- app/Http/Controllers/Api/VehicleDataController.php
- app/Models/Appointment.php

**Frontend:**
- resources/views/booking/create.blade.php
- resources/js/booking-wizard.js
- resources/js/app.js

---

## See Also

- [Google Maps Integration](../google-maps/README.md)
- [Vehicle Management](../vehicle-management/README.md)
- [Settings System](../settings-system/README.md)
- [Email System](../email-system/README.md)
- [Database Schema](../../architecture/database-schema.md)
