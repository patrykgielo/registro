# API Contract for Frontend Integration

**Version:** 1.1
**Last Updated:** 2025-10-31
**Base URL:** `https://registro.local:8444`
**API Prefix:** `/api` (for JSON endpoints)

## Overview

This document defines the contract between the Registro Laravel backend and the frontend interface. It specifies data formats, validation rules, error responses, and expected behaviors for all API endpoints.

## Authentication

### Session-Based Authentication
The application uses Laravel's session-based authentication for web requests.

**CSRF Protection:**
- All POST, PUT, PATCH, DELETE requests require CSRF token
- Token available in meta tag: `<meta name="csrf-token" content="{{ csrf_token() }}">`
- Include in headers: `X-CSRF-TOKEN: {token}`

**Authentication Check:**
- Use Laravel's `auth` middleware
- Authenticated user available via `Auth::user()`
- Redirect to `/login` for unauthenticated users

### Sanctum API Tokens (Future)
Not currently implemented, but available for SPA/mobile apps.

---

## Data Format Standards

### Date and Time Formats

| Type | Format | Example | Notes |
|------|--------|---------|-------|
| Date | `YYYY-MM-DD` | `2025-10-15` | ISO 8601 |
| Time | `HH:mm` | `14:30` | 24-hour format |
| DateTime | `YYYY-MM-DD HH:mm:ss` | `2025-10-15 14:30:00` | Timestamps |
| Day of Week | Integer 0-6 | `0` = Sunday, `6` = Saturday | Carbon format |

### Numeric Formats

| Type | Format | Example | Notes |
|------|--------|---------|-------|
| Price | Decimal(10,2) | `149.99` | Two decimal places |
| Duration | Integer | `90` | Minutes |
| ID | Integer | `123` | Auto-increment |

### Status Enums

**Appointment Status:**
- `pending` - Awaiting confirmation
- `confirmed` - Confirmed by staff
- `cancelled` - Cancelled by customer or staff
- `completed` - Service completed

**Payment Status (Future):**
- `pending` - Payment not yet processed
- `succeeded` - Payment successful
- `failed` - Payment failed
- `refunded` - Payment refunded

---

## API Endpoints

### 1. Get Available Time Slots

**Endpoint:** `POST /api/available-slots`

**Purpose:** Fetch available booking time slots for a specific service, staff member, and date.

**Authentication:** Required (`auth` middleware)

**Request Headers:**
```http
Content-Type: application/json
X-CSRF-TOKEN: {csrf_token}
```

**Request Body:**
```json
{
  "service_id": 1,
  "staff_id": 2,
  "date": "2025-10-15"
}
```

**Validation Rules:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| service_id | integer | Yes | exists:services,id |
| staff_id | integer | Yes | exists:users,id |
| date | string | Yes | date, after_or_equal:today |

**Success Response (200 OK):**
```json
{
  "slots": [
    {
      "start": "09:00",
      "end": "10:30",
      "datetime_start": "2025-10-15 09:00",
      "datetime_end": "2025-10-15 10:30"
    },
    {
      "start": "09:15",
      "end": "10:45",
      "datetime_start": "2025-10-15 09:15",
      "datetime_end": "2025-10-15 10:45"
    },
    {
      "start": "11:00",
      "end": "12:30",
      "datetime_start": "2025-10-15 11:00",
      "datetime_end": "2025-10-15 12:30"
    }
  ],
  "date": "2025-10-15"
}
```

**Empty Slots Response (200 OK):**
```json
{
  "slots": [],
  "date": "2025-10-15"
}
```

**Error Response (422 Unprocessable Entity):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "service_id": ["The selected service id is invalid."],
    "date": ["The date field must be a date after or equal to today."]
  }
}
```

**Error Response (401 Unauthorized):**
```json
{
  "message": "Unauthenticated."
}
```

**Notes:**
- Slots are generated in 15-minute intervals
- Slots are filtered by staff availability and existing appointments
- Empty array returned if no slots available (not an error)
- Service duration automatically calculated from service record

**Usage Example (JavaScript):**
```javascript
const response = await fetch('/api/available-slots', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
  },
  body: JSON.stringify({
    service_id: 1,
    staff_id: 2,
    date: '2025-10-15'
  })
});

const data = await response.json();
console.log(data.slots); // Array of available time slots
```

---

### 2. Create Appointment

**Endpoint:** `POST /appointments`

**Purpose:** Create a new appointment booking.

**Authentication:** Required (`auth` middleware)

**Request Headers:**
```http
Content-Type: application/x-www-form-urlencoded
X-CSRF-TOKEN: {csrf_token}
```

**Request Body (Form Data):**
```
service_id=1
staff_id=2
appointment_date=2025-10-15
start_time=09:00
end_time=10:30
notes=Please call when arriving
```

**Validation Rules:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| service_id | integer | Yes | exists:services,id |
| staff_id | integer | Yes | exists:users,id |
| appointment_date | string | Yes | date, after_or_equal:today |
| start_time | string | Yes | date_format:H:i |
| end_time | string | Yes | date_format:H:i, after:start_time |
| notes | string | No | max:1000 |

**Business Validation:**
- Appointment date cannot be in the past
- Start time must be before end time
- Staff must have availability configured for the day/service
- Time slot must not conflict with existing appointments

**Success Response (302 Redirect):**
```
Redirect to: /my-appointments
Session: success = "Wizyta została pomyślnie zarezerwowana! Status: Oczekująca na potwierdzenie."
```

**Error Response (302 Redirect with Input):**
```
Redirect to: previous page
Session: errors = {
  "appointment": [
    "Wybrany termin nie jest dostępny. Personel jest zajęty lub nie pracuje w tym czasie."
  ]
}
Input: original form data preserved
```

**Error Response (422 Validation Error):**
```
Redirect to: previous page
Session: errors = {
  "service_id": ["The selected service id is invalid."],
  "appointment_date": ["The appointment date field must be a date after or equal to today."]
}
```

**Created Record:**
```php
Appointment {
  id: 123,
  service_id: 1,
  customer_id: 10, // Auto-filled from Auth::id()
  staff_id: 2,
  appointment_date: "2025-10-15",
  start_time: "09:00:00",
  end_time: "10:30:00",
  status: "pending", // Always created as pending
  notes: "Please call when arriving",
  cancellation_reason: null,
  created_at: "2025-10-12 14:25:00",
  updated_at: "2025-10-12 14:25:00"
}
```

**Usage Example (HTML Form):**
```html
<form method="POST" action="{{ route('appointments.store') }}">
  @csrf
  <input type="hidden" name="service_id" value="1">
  <input type="hidden" name="staff_id" value="2">
  <input type="date" name="appointment_date" min="{{ date('Y-m-d') }}">
  <input type="time" name="start_time">
  <input type="time" name="end_time">
  <textarea name="notes" maxlength="1000"></textarea>
  <button type="submit">Zarezerwuj</button>
</form>
```

---

### 3. View My Appointments

**Endpoint:** `GET /my-appointments`

**Purpose:** Display all appointments for the authenticated customer.

**Authentication:** Required (`auth` middleware)

**Request:** No parameters

**Response:** HTML page (Blade template)

**Data Passed to View:**
```php
$appointments = [
  {
    id: 123,
    service: {
      id: 1,
      name: "Premium Detail",
      description: "Complete interior and exterior detailing",
      duration_minutes: 120,
      price: "199.99"
    },
    customer: {
      id: 10,
      name: "Jan Kowalski",
      email: "jan@example.com"
    },
    staff: {
      id: 2,
      name: "Anna Nowak",
      email: "anna@staff.com"
    },
    appointment_date: "2025-10-15",
    start_time: "09:00:00",
    end_time: "11:00:00",
    status: "confirmed",
    notes: "Please call when arriving",
    cancellation_reason: null,
    created_at: "2025-10-12 14:25:00",
    updated_at: "2025-10-12 15:30:00",
    // Computed attributes
    is_upcoming: true,
    is_past: false,
    can_be_cancelled: true
  },
  // ... more appointments
]
```

**Sorting:** `appointment_date DESC, start_time DESC`

**Relationships Eager Loaded:** `service`, `staff`

---

### 4. Cancel Appointment

**Endpoint:** `POST /appointments/{appointment}/cancel`

**Purpose:** Cancel an existing appointment.

**Authentication:** Required (`auth` middleware)

**Authorization:** Customer must own the appointment

**Request Headers:**
```http
Content-Type: application/x-www-form-urlencoded
X-CSRF-TOKEN: {csrf_token}
```

**Request Body:** Empty (appointment ID in URL)

**Validation:**
- Appointment must belong to authenticated user
- Appointment status must be 'pending' or 'confirmed'
- Appointment date must be today or in the future

**Success Response (302 Redirect):**
```
Redirect to: previous page
Session: success = "Wizyta została anulowana."
```

**Error Response (403 Forbidden):**
```
Abort: 403 Unauthorized action.
```

**Error Response (302 Redirect with Error):**
```
Redirect to: previous page
Session: errors = {
  "appointment": ["Ta wizyta nie może być anulowana."]
}
```

**Updated Record:**
```php
Appointment {
  // ... other fields unchanged
  status: "cancelled",
  cancellation_reason: "Anulowane przez klienta",
  updated_at: "2025-10-12 16:45:00"
}
```

**Usage Example (HTML Form):**
```html
<form method="POST" action="{{ route('appointments.cancel', $appointment) }}">
  @csrf
  <button type="submit" onclick="return confirm('Czy na pewno anulować wizytę?')">
    Anuluj wizytę
  </button>
</form>
```

---

### 5. Home Page (Public)

**Endpoint:** `GET /`

**Purpose:** Display public home page with available services.

**Authentication:** Not required (public)

**Request:** No parameters

**Response:** HTML page (Blade template)

**Data Passed to View:**
```php
$services = [
  {
    id: 1,
    name: "Basic Wash",
    description: "Exterior wash and interior vacuum",
    duration_minutes: 30,
    price: "49.99",
    is_active: true,
    sort_order: 1,
    created_at: "2025-10-01 10:00:00",
    updated_at: "2025-10-01 10:00:00"
  },
  {
    id: 2,
    name: "Premium Detail",
    description: "Complete interior and exterior detailing with wax",
    duration_minutes: 120,
    price: "199.99",
    is_active: true,
    sort_order: 2,
    created_at: "2025-10-01 10:00:00",
    updated_at: "2025-10-01 10:00:00"
  }
]
```

**Query:** Only active services (`is_active = true`), ordered by `sort_order` ASC

---

### 6. Booking Page

**Endpoint:** `GET /services/{service}/book`

**Purpose:** Display booking form for a specific service.

**Authentication:** Required (`auth` middleware)

**Request Parameters:**
- `{service}` - Service ID in URL path

**Response:** HTML page (Blade template)

**Data Passed to View:**
```php
$service = {
  id: 1,
  name: "Premium Detail",
  description: "Complete interior and exterior detailing",
  duration_minutes: 120,
  price: "199.99",
  is_active: true,
  sort_order: 2
};

$staffMembers = [
  {
    id: 2,
    name: "Anna Nowak",
    email: "anna@staff.com"
  },
  {
    id: 3,
    name: "Piotr Wiśniewski",
    email: "piotr@staff.com"
  }
];
```

**Staff Members Filter:**
- Only users with roles: 'staff', 'admin', or 'super-admin'
- Only users who have configured availability for this service

---

## Web Routes (HTML Pages)

### Public Routes

| Method | Path | Controller | View | Purpose |
|--------|------|------------|------|---------|
| GET | `/` | Closure (routes/web.php) | pages.show (CMS) | Homepage via CMS page system |

### Authentication Routes (Laravel UI)

| Method | Path | Controller | Purpose |
|--------|------|------------|---------|
| GET | `/login` | LoginController@showLoginForm | Login form |
| POST | `/login` | LoginController@login | Process login |
| POST | `/logout` | LoginController@logout | Logout user |
| GET | `/register` | RegisterController@showRegistrationForm | Registration form |
| POST | `/register` | RegisterController@register | Create new user |
| GET | `/password/reset` | ForgotPasswordController@showLinkRequestForm | Password reset request |
| POST | `/password/email` | ForgotPasswordController@sendResetLinkEmail | Send reset email |
| GET | `/password/reset/{token}` | ResetPasswordController@showResetForm | Password reset form |
| POST | `/password/reset` | ResetPasswordController@reset | Process reset |

### Protected Routes (Require Authentication)

| Method | Path | Controller | View | Purpose |
|--------|------|------------|------|---------|
| GET | `/services/{service}/book` | BookingController@create | booking.create | Booking form |
| GET | `/my-appointments` | AppointmentController@index | appointments.index | Customer's appointments |

---

## Error Response Format

### Validation Errors (422)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": [
      "Error message 1",
      "Error message 2"
    ],
    "another_field": [
      "Error message"
    ]
  }
}
```

### Business Logic Errors (302 Redirect)
Laravel redirects back with errors in session:
```php
// In Blade template
@if ($errors->any())
  <div class="alert alert-danger">
    <ul>
      @foreach ($errors->all() as $error)
        <li>{{ $error }}</li>
      @endforeach
    </ul>
  </div>
@endif
```

### Authentication Errors (401)
```json
{
  "message": "Unauthenticated."
}
```
Or redirect to `/login` for web requests.

### Authorization Errors (403)
```json
{
  "message": "This action is unauthorized."
}
```
Or abort(403) for web requests.

### Not Found (404)
```json
{
  "message": "Resource not found."
}
```

### Server Errors (500)
```json
{
  "message": "Server Error"
}
```
In development, full stack trace is shown if `APP_DEBUG=true`.

---

## Success Messages

### Flash Messages (Session)
Laravel uses session flash messages for success notifications:

```php
// In Blade template
@if (session('success'))
  <div class="alert alert-success">
    {{ session('success') }}
  </div>
@endif
```

**Common Success Messages:**
- `"Wizyta została pomyślnie zarezerwowana! Status: Oczekująca na potwierdzenie."`
- `"Wizyta została anulowana."`

---

## Data Relationships

### Service Model
```php
Service {
  id: integer
  name: string
  description: text|null
  duration_minutes: integer
  price: decimal(10,2)
  is_active: boolean
  sort_order: integer
  created_at: datetime
  updated_at: datetime

  // Relationships
  appointments: Appointment[]
  serviceAvailabilities: ServiceAvailability[]
}
```

### User Model
```php
User {
  id: integer
  name: string
  email: string
  email_verified_at: datetime|null
  password: string (hashed)
  remember_token: string|null
  created_at: datetime
  updated_at: datetime

  // Relationships
  staffAppointments: Appointment[]        // as staff_id
  customerAppointments: Appointment[]     // as customer_id
  serviceAvailabilities: ServiceAvailability[]
  roles: Role[]                           // Spatie

  // Computed
  isCustomer(): boolean
  isStaff(): boolean
  isAdmin(): boolean
}
```

### Appointment Model
```php
Appointment {
  id: integer
  service_id: integer
  customer_id: integer
  staff_id: integer
  appointment_date: date
  start_time: time
  end_time: time
  status: enum('pending','confirmed','cancelled','completed')
  notes: text|null
  cancellation_reason: text|null
  created_at: datetime
  updated_at: datetime

  // Relationships
  service: Service
  customer: User
  staff: User

  // Computed Attributes
  is_upcoming: boolean
  is_past: boolean
  can_be_cancelled: boolean
}
```

### ServiceAvailability Model
```php
ServiceAvailability {
  id: integer
  service_id: integer
  user_id: integer
  day_of_week: integer        // 0=Sunday, 6=Saturday
  start_time: time
  end_time: time
  created_at: datetime
  updated_at: datetime

  // Relationships
  service: Service
  user: User
}
```

---

## Frontend Implementation Guidelines

### 1. CSRF Token Handling
Always include CSRF token in POST/PUT/PATCH/DELETE requests:

```javascript
// Add to all fetch requests
headers: {
  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
}
```

### 2. Availability Checking Flow
```javascript
// Step 1: User selects service
const serviceId = 1;

// Step 2: Show staff members who can perform this service
const staffMembers = await fetch(`/services/${serviceId}/book`);

// Step 3: User selects staff and date
const selectedStaffId = 2;
const selectedDate = '2025-10-15';

// Step 4: Fetch available slots
const response = await fetch('/api/available-slots', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': getCsrfToken()
  },
  body: JSON.stringify({
    service_id: serviceId,
    staff_id: selectedStaffId,
    date: selectedDate
  })
});

const { slots } = await response.json();

// Step 5: Display slots to user
displaySlots(slots);

// Step 6: User selects slot and submits form
submitAppointment({
  service_id: serviceId,
  staff_id: selectedStaffId,
  appointment_date: selectedDate,
  start_time: selectedSlot.start,
  end_time: selectedSlot.end,
  notes: userNotes
});
```

### 3. Date Picker Configuration
```javascript
// Minimum date: today
const minDate = new Date().toISOString().split('T')[0];

// Format for API: YYYY-MM-DD
const formatDate = (date) => date.toISOString().split('T')[0];

// Display format: User's locale
const displayDate = (dateString) => {
  return new Date(dateString).toLocaleDateString('pl-PL', {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  });
};
```

### 4. Time Slot Display
```javascript
// Display in 12-hour format with AM/PM
const formatTime = (time24) => {
  const [hours, minutes] = time24.split(':');
  const hour = parseInt(hours);
  const ampm = hour >= 12 ? 'PM' : 'AM';
  const hour12 = hour % 12 || 12;
  return `${hour12}:${minutes} ${ampm}`;
};

// Or keep 24-hour format for Polish locale
const formatTime24 = (time) => time; // "09:00"
```

### 5. Loading States
```javascript
// Show loading indicator during API calls
async function fetchAvailableSlots(serviceId, staffId, date) {
  showLoading(true);
  try {
    const response = await fetch('/api/available-slots', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': getCsrfToken()
      },
      body: JSON.stringify({ service_id: serviceId, staff_id: staffId, date })
    });

    if (!response.ok) {
      throw new Error('Failed to fetch slots');
    }

    return await response.json();
  } catch (error) {
    showError('Nie udało się pobrać dostępnych terminów');
    return { slots: [] };
  } finally {
    showLoading(false);
  }
}
```

### 6. Error Handling
```javascript
// Display validation errors
function displayValidationErrors(errors) {
  Object.keys(errors).forEach(field => {
    const fieldElement = document.querySelector(`[name="${field}"]`);
    const errorContainer = fieldElement.parentElement.querySelector('.error');

    if (errorContainer) {
      errorContainer.textContent = errors[field][0];
      errorContainer.style.display = 'block';
    }
  });
}

// Example usage after failed request
const response = await fetch('/appointments', { method: 'POST', ... });
if (!response.ok && response.status === 422) {
  const { errors } = await response.json();
  displayValidationErrors(errors);
}
```

### 7. Debouncing API Calls
```javascript
// Debounce availability checks to avoid excessive requests
const debounce = (func, delay) => {
  let timeoutId;
  return (...args) => {
    clearTimeout(timeoutId);
    timeoutId = setTimeout(() => func(...args), delay);
  };
};

const debouncedFetchSlots = debounce(fetchAvailableSlots, 300);

// Usage
dateInput.addEventListener('change', () => {
  debouncedFetchSlots(serviceId, staffId, dateInput.value);
});
```

### 8. Optimistic UI Updates
```javascript
// Show success immediately, rollback on error
async function cancelAppointment(appointmentId) {
  const appointmentCard = document.querySelector(`#appointment-${appointmentId}`);

  // Optimistic update
  appointmentCard.classList.add('cancelled');
  appointmentCard.querySelector('.status').textContent = 'Anulowana';

  try {
    const response = await fetch(`/appointments/${appointmentId}/cancel`, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': getCsrfToken() }
    });

    if (!response.ok) throw new Error('Cancellation failed');

    showSuccess('Wizyta została anulowana');
  } catch (error) {
    // Rollback on error
    appointmentCard.classList.remove('cancelled');
    appointmentCard.querySelector('.status').textContent = 'Potwierdzona';
    showError('Nie udało się anulować wizyty');
  }
}
```

---

## Common Validation Messages (Polish)

| Field | Error | Message |
|-------|-------|---------|
| Any required | Missing | "Pole {field} jest wymagane." |
| Date | Past date | "Nie można zarezerwować wizyty w przeszłości." |
| Time | Invalid range | "Czas rozpoczęcia musi być przed czasem zakończenia." |
| Availability | Conflict | "Wybrany termin nie jest dostępny. Personel jest zajęty lub nie pracuje w tym czasie." |
| Email | Invalid | "Pole email musi być prawidłowym adresem e-mail." |
| Email | Taken | "Ten adres e-mail jest już zajęty." |

---

## Future API Endpoints (Planned)

These endpoints are not yet implemented but are planned for future releases:

### Service Add-Ons
```
GET    /api/services/{id}/add-ons          - Get available add-ons for service
POST   /api/booking/calculate-price        - Calculate total price with add-ons
```

### Guest Booking
```
POST   /api/booking/guest                  - Create booking as guest (then register)
```

### Vehicle Management (✅ IMPLEMENTED - 2025-10-31)
```
GET    /api/vehicle-types                  - Get all active vehicle types
GET    /api/car-brands?vehicle_type_id     - Get brands (filtered by type)
GET    /api/car-models?car_brand_id&vehicle_type_id - Get models (filtered)
GET    /api/vehicle-years                  - Get years array (1990-current)
```

### Calendar
```
GET    /api/calendar/available-dates       - Get dates with availability (for month view)
```

### Payments (Stripe Integration)
```
POST   /api/payments/create-intent         - Create Stripe payment intent
POST   /api/payments/confirm               - Confirm payment
GET    /api/payments/{id}/status           - Check payment status
```

### Slot Reservation
```
POST   /api/booking/reserve-slot           - Temporarily reserve a time slot (5 min hold)
DELETE /api/booking/release-slot           - Release reserved slot
```

---

## Rate Limiting

**Current:** No rate limiting implemented

**Recommendation:** Implement rate limiting to prevent abuse:
```php
// In RouteServiceProvider or routes file
Route::middleware(['throttle:60,1'])->group(function () {
    // 60 requests per minute
});

// For API endpoints
Route::middleware(['throttle:api'])->group(function () {
    // Use default API throttle from config
});
```

---

## Caching Strategy

**Current:** No caching implemented

**Recommendation:** Cache frequently accessed data:
```php
// Cache services list (rarely changes)
$services = Cache::remember('services.active', 3600, function () {
    return Service::active()->ordered()->get();
});

// Cache available slots (5 minute cache)
$slots = Cache::remember(
    "slots.{$serviceId}.{$staffId}.{$date}",
    300,
    function () use ($serviceId, $staffId, $date) {
        return $this->appointmentService->getAvailableTimeSlots(...);
    }
);
```

---

## WebSocket / Real-Time Updates (Future)

**Current:** Not implemented

**Recommendation:** Use Laravel Echo + Pusher for real-time updates:
```javascript
// Listen for appointment updates
Echo.private(`user.${userId}`)
  .listen('AppointmentConfirmed', (e) => {
    updateAppointmentStatus(e.appointment.id, 'confirmed');
  })
  .listen('AppointmentCancelled', (e) => {
    updateAppointmentStatus(e.appointment.id, 'cancelled');
  });

// Listen for slot availability changes
Echo.channel('availability')
  .listen('SlotsUpdated', (e) => {
    refreshAvailableSlots();
  });
```

---

## Security Headers

**Recommendation:** Add security headers in nginx or middleware:
```
X-Frame-Options: SAMEORIGIN
X-Content-Type-Options: nosniff
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
```

---

## CORS Configuration (for SPA/Mobile)

**Current:** Not configured

**If needed for SPA:**
```php
// config/cors.php
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
```

---

## Testing the API

### Using cURL

**Get available slots:**
```bash
curl -X POST https://registro.local:8444/api/available-slots \
  -H "Content-Type: application/json" \
  -H "X-CSRF-TOKEN: {token}" \
  -b cookies.txt \
  -d '{"service_id":1,"staff_id":2,"date":"2025-10-15"}'
```

**Create appointment:**
```bash
curl -X POST https://registro.local:8444/appointments \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -H "X-CSRF-TOKEN: {token}" \
  -b cookies.txt \
  -d "service_id=1&staff_id=2&appointment_date=2025-10-15&start_time=09:00&end_time=10:30&notes=Test"
```

### Using Postman

1. Import collection from `/docs/postman_collection.json` (if created)
2. Set environment variables: `BASE_URL`, `CSRF_TOKEN`
3. Enable cookie jar for session management

---

## Support & Questions

For questions about the API contract:
1. Check this document first
2. Review `/var/www/projects/registro/docs/project_map.md` for detailed backend architecture
3. Check ADR files in `/var/www/projects/registro/docs/decision_log/` for architectural decisions
4. Contact backend team for clarification

---

## Changelog

### Version 1.1 (2025-10-31)
- ✅ Added Vehicle Management API endpoints (vehicle-types, car-brands, car-models)
- ✅ Updated Customer Data Collection status to PARTIAL IMPLEMENTATION
- ✅ Documented VehicleType, CarBrand, CarModel data models
- ✅ Moved Vehicle Management from "Future" to "Implemented"

### Version 1.0 (2025-10-12)
- Initial API contract documentation
- Documented existing endpoints
- Added future endpoint placeholders
- Defined data formats and standards
