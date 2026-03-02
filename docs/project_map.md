# Project Map - Registro Booking System

**Last Updated:** 2025-11-30
**Laravel Version:** 12.32.5
**PHP Version:** 8.2+
**Node.js:** 20.19 LTS
**Database:** SQLite (development), MySQL 8.0 (production)
**Deployment Version:** v0.2.11

---

## Documentation Structure

This project maintains comprehensive documentation in `/var/www/projects/registro/app/docs/`. All documentation is organized by purpose and audience.

### Core Documentation (Start Here)

| Document | Purpose | Audience | Last Updated |
|----------|---------|----------|--------------|
| [README.md](README.md) | Project overview, quick start | All | 2025-11-29 |
| [dependencies.md](dependencies.md) | Complete version inventory | Developers | 2025-11-30 |
| [project_map.md](project_map.md) | This file - system topology | Developers | 2025-11-30 |

### Deployment Documentation

**Location:** `docs/deployment/`

| Document | Purpose | Critical Info |
|----------|---------|---------------|
| [deployment-history.md](deployment/deployment-history.md) | v0.2.1→v0.2.11 journey, lessons learned | ✅ Read before first deployment |
| [environment-variables.md](deployment/environment-variables.md) | Complete env var reference for all 6 services | ✅ Critical for troubleshooting |
| [docker-infrastructure.md](deployment/docker-infrastructure.md) | 6-service architecture, multi-stage Dockerfile | Technical details |
| [known-issues.md](deployment/known-issues.md) | All deployment gotchas and workarounds | ✅ Read when deployment fails |
| [cicd-pipeline.md](deployment/cicd-pipeline.md) | GitHub Actions workflow details | CI/CD configuration |
| [PRE_DEPLOYMENT_CHECKLIST.md](deployment/PRE_DEPLOYMENT_CHECKLIST.md) | Step-by-step deployment guide | Pre-deployment validation |

**Architecture Decision Records (ADRs):**
- [ADR-007: UFW-Docker Security](deployment/ADR-007-ufw-docker-security.md)
- [ADR-008: Storage Volume Removal](deployment/ADR-008-storage-volume-removal.md)
- [ADR-009: Vite Manifest Symlink](deployment/ADR-009-vite-manifest-symlink.md)
- [ADR-012: GitHub Actions Security Hardening](deployment/ADR-012-github-actions-security-hardening.md)

### Feature Documentation

**Location:** `docs/features/`

| Feature | Documentation | Status |
|---------|--------------|--------|
| **Email System** | [email-system/README.md](features/email-system/README.md) | ✅ Complete |
| **Booking System** | [booking-system/README.md](features/booking-system/README.md) | ✅ Complete |
| **Staff Scheduling** | [staff-scheduling/README.md](features/staff-scheduling/README.md) | ✅ Complete |
| **Vehicle Management** | [vehicle-management/README.md](features/vehicle-management/README.md) | ✅ Complete |
| **Google Maps** | [google-maps/README.md](features/google-maps/README.md) | ✅ Complete |
| **CMS System** | [cms-system/README.md](features/cms-system/README.md) | ✅ Complete |
| **Customer Profile** | [customer-profile/README.md](features/customer-profile/README.md) | ✅ Complete |
| **Maintenance Mode** | [maintenance-mode/README.md](features/maintenance-mode/README.md) | ✅ Complete |
| **Settings System** | [settings-system/README.md](features/settings-system/README.md) | ✅ Complete |

### Operational Guides

**Location:** `docs/guides/`

| Guide | Purpose |
|-------|---------|
| [quick-start.md](guides/quick-start.md) | One-command local setup |
| [docker.md](guides/docker.md) | Docker architecture, commands |
| [commands.md](guides/commands.md) | All artisan commands |
| [production-build.md](guides/production-build.md) | Vite production build |
| [troubleshooting.md](guides/troubleshooting.md) | Common issues & fixes |
| [staff-availability.md](guides/staff-availability.md) | Staff scheduling UX guide |

### Environment-Specific Documentation

**Location:** `docs/environments/`

- **staging/** - Live staging server documentation (72.60.17.138)
  - [00-SERVER-INFO.md](environments/staging/00-SERVER-INFO.md)
  - [01-DEPLOYMENT-LOG.md](environments/staging/01-DEPLOYMENT-LOG.md)
  - [02-CONFIGURATIONS.md](environments/staging/02-CONFIGURATIONS.md)
  - [03-CREDENTIALS.md](environments/staging/03-CREDENTIALS.md) 🔒 **Sensitive**
  - [04-SERVICES.md](environments/staging/04-SERVICES.md)
  - [05-ISSUES-WORKAROUNDS.md](environments/staging/05-ISSUES-WORKAROUNDS.md)
  - [06-MAINTENANCE.md](environments/staging/06-MAINTENANCE.md)
  - [07-NEXT-STEPS.md](environments/staging/07-NEXT-STEPS.md)

### Critical Deployment Knowledge

**Before Your First Deployment, Read These (In Order):**

1. **[deployment-history.md](deployment/deployment-history.md)** - Learn from 11 deployments (v0.2.1→v0.2.11)
   - The `--no-recreate` trap (env vars not updated)
   - DB_CONNECTION must be explicit
   - REDIS_PASSWORD required everywhere
   - Permission CATCH-22 (UID mismatch)
   - OPcache restart requirement

2. **[environment-variables.md](deployment/environment-variables.md)** - Understand Docker env var hierarchy
   - Why `.env` file is NOT enough
   - Service-by-service requirements
   - Complete variable reference

3. **[known-issues.md](deployment/known-issues.md)** - Quick fixes for common problems
   - Database connection refused
   - Queue jobs not processing
   - Permission denied errors
   - Code changes not applying

**Key Insight from 11 Deployments:**
Docker Compose services (horizon, scheduler) do NOT read `.env` file. You MUST explicitly pass environment variables in `docker-compose.yml`. This was the #1 failure mode in v0.2.1-v0.2.6.

---

## Architecture Pattern

**Pattern:** MVC with Service Layer Architecture

The application follows a traditional **Model-View-Controller (MVC)** pattern enhanced with a dedicated **Service Layer** for complex business logic. This approach keeps controllers thin, models focused on data relationships, and business logic isolated in reusable service classes.

**Key Architectural Decisions:**
- **Thin Controllers:** Controllers handle HTTP concerns (request/response, validation)
- **Service Layer:** Complex business logic extracted to `AppointmentService`
- **Rich Models:** Models use query scopes and accessors for data retrieval patterns
- **Filament Admin:** Complete admin panel using Laravel Filament v4.2.3
- **Role-Based Access Control:** Spatie Laravel Permission package

## Core Models

### User
**Location:** `/var/www/projects/registro/app/app/Models/User.php`

**Purpose:** Authentication and authorization for customers, staff, and administrators

**Relationships:**
- `staffAppointments()` → hasMany(Appointment) - Appointments where user is staff
- `customerAppointments()` → hasMany(Appointment) - Appointments where user is customer
- `staffSchedules()` → hasMany(StaffSchedule) - Base weekly schedule pattern
- `staffDateExceptions()` → hasMany(StaffDateException) - Single-day overrides
- `staffVacationPeriods()` → hasMany(StaffVacationPeriod) - Multi-day absences
- Uses Spatie `HasRoles` trait for role management

**Key Methods:**
- `canAccessPanel(Panel $panel): bool` - Filament admin access control
- `isCustomer(): bool` - Role checking helper
- `isStaff(): bool` - Role checking helper
- `isAdmin(): bool` - Role checking helper

**Fillable Attributes:** name, email, password

**Casts:** email_verified_at (datetime), password (hashed)

**Roles Defined:**
- `super-admin` - Full system access
- `admin` - Administrative access
- `staff` - Service provider access
- `customer` - Customer booking access

**Notes:**
- Implements `FilamentUser` interface for admin panel access
- Admin panel accessible only to super-admin, admin, and staff roles
- No phone number or address fields (potential gap for customer data)

---

### Service
**Location:** `/var/www/projects/registro/app/app/Models/Service.php`

**Purpose:** Represents detailing services offered (e.g., Basic Wash, Premium Detail, Ceramic Coating)

**Relationships:**
- `appointments()` → hasMany(Appointment)
- `staff()` → belongsToMany(User) via `service_staff` pivot - Staff who can perform this service

**Key Scopes:**
- `scopeActive($query)` - Filter active services only
- `scopeOrdered($query)` - Order by sort_order field

**Fillable Attributes:**
- `name` (string) - Service name
- `description` (text, nullable) - Service description
- `duration_minutes` (integer) - Expected service duration
- `price` (decimal) - Base price
- `is_active` (boolean) - Whether service is currently offered
- `sort_order` (integer) - Display order

**Casts:**
- `is_active` → boolean
- `price` → decimal:2

**Notes:**
- **MISSING:** No support for service tiers (Basic/Premium/Elite)
- **MISSING:** No add-ons or package system
- **MISSING:** No image/gallery support for service visualization
- **MISSING:** No category or service type classification

---

### Appointment
**Location:** `/var/www/projects/registro/app/app/Models/Appointment.php`

**Purpose:** Represents a booked appointment/visit

**Relationships:**
- `service()` → belongsTo(Service)
- `customer()` → belongsTo(User, 'customer_id')
- `staff()` → belongsTo(User, 'staff_id')

**Key Scopes:**
- `scopeStatus($query, string $status)` - Filter by status
- `scopePending($query)` - Pending appointments
- `scopeConfirmed($query)` - Confirmed appointments
- `scopeCancelled($query)` - Cancelled appointments
- `scopeCompleted($query)` - Completed appointments
- `scopeUpcoming($query)` - Future pending/confirmed appointments
- `scopeForCustomer($query, int $customerId)` - Customer's appointments
- `scopeForStaff($query, int $staffId)` - Staff member's appointments
- `scopeDateRange($query, string $startDate, string $endDate)` - Date range filter

**Fillable Attributes:**
- `service_id` (foreignId)
- `customer_id` (foreignId)
- `staff_id` (foreignId)
- `appointment_date` (date)
- `start_time` (time)
- `end_time` (time)
- `status` (enum: pending, confirmed, cancelled, completed)
- `notes` (text, nullable)
- `cancellation_reason` (text, nullable)

**Casts:**
- `appointment_date` → date
- `start_time` → datetime:H:i
- `end_time` → datetime:H:i

**Computed Attributes:**
- `is_upcoming` - Whether appointment is in the future
- `is_past` - Whether appointment is in the past
- `can_be_cancelled` - Whether appointment can be cancelled

**Database Indexes:**
- Composite: (appointment_date, start_time)
- Composite: (staff_id, appointment_date)
- Composite: (customer_id, appointment_date)

**Notes:**
- ✅ **IMPLEMENTED:** Vehicle information (type, brand, model, year) via VehicleType/CarBrand/CarModel
- ✅ **IMPLEMENTED:** Google Maps location data (address, coordinates, place_id)
- **MISSING:** No pricing breakdown (base + add-ons)
- **MISSING:** No deposit/payment tracking
- **MISSING:** No reminder system metadata

---

### ~~ServiceAvailability~~ (DEPRECATED - REMOVED 2025-12-12)

**Status:** ❌ **REMOVED** - Dead code eliminated

**Reason for Removal:**
- Created combinatorial explosion: 10 services × 7 days × 5 staff = 350 configurations
- Never used by booking logic (AppointmentService used StaffScheduleService instead)
- Inferior to StaffSchedule system implemented 2025-11-19
- Database table had 0 records at time of removal

**Replaced By:** **StaffSchedule + StaffDateException + StaffVacationPeriod** architecture

See:
- [Staff Scheduling Guide](guides/staff-scheduling-guide.md)
- [ADR-015: Staff Availability System Redesign](decisions/ADR-015-staff-availability-system-redesign.md)

**Configuration Reduction:** 350 clicks → 30 clicks (91% improvement)

---

### VehicleType
**Location:** `/var/www/projects/registro/app/app/Models/VehicleType.php`

**Purpose:** Categorizes vehicles into types (city car, delivery van, etc.) for service planning

**Relationships:**
- `carModels()` → belongsToMany(CarModel) via `vehicle_type_car_model` pivot
- `appointments()` → hasMany(Appointment)

**Key Scopes:**
- `scopeActive($query)` - Filter active types only
- `scopeOrdered($query)` - Order by sort_order field

**Fillable Attributes:**
- `name` (string) - Display name (e.g., "Auto miejskie")
- `slug` (string, unique) - URL-friendly identifier (e.g., "city_car")
- `description` (text, nullable) - Type description
- `examples` (string, nullable) - Example vehicles
- `sort_order` (integer) - Display order
- `is_active` (boolean) - Whether type is active

**Casts:**
- `is_active` → boolean
- `sort_order` → integer

**Seeded Data:** 5 types (city_car, small_car, medium_car, large_car, delivery_van)

**Notes:**
- Seeded data only, not user-created
- Used for future pricing logic (vehicle type affects service price)

---

### CarBrand
**Location:** `/var/www/projects/registro/app/app/Models/CarBrand.php`

**Purpose:** Represents car manufacturers (Toyota, VW, Ford, etc.)

**Relationships:**
- `models()` → hasMany(CarModel)
- `appointments()` → hasMany(Appointment)

**Key Scopes:**
- `scopeActive($query)` - Active brands only
- `scopePending($query)` - Pending approval brands
- `scopeStatus($query, string $status)` - Filter by status

**Fillable Attributes:**
- `name` (string) - Brand name
- `slug` (string, unique) - URL-friendly identifier
- `status` (enum: pending, active, inactive) - Approval status

**Casts:**
- `status` → string

**Notes:**
- Status 'pending' for user-submitted brands awaiting admin approval
- Auto-slug generation in Filament form

---

### CarModel
**Location:** `/var/www/projects/registro/app/app/Models/CarModel.php`

**Purpose:** Represents specific car models (Corolla, Golf, Transit, etc.)

**Relationships:**
- `brand()` → belongsTo(CarBrand)
- `vehicleTypes()` → belongsToMany(VehicleType) via pivot
- `appointments()` → hasMany(Appointment)

**Key Scopes:**
- `scopeActive($query)` - Active models only
- `scopePending($query)` - Pending approval models
- `scopeForBrand($query, int $brandId)` - Models for specific brand
- `scopeForVehicleType($query, int $typeId)` - Models for specific vehicle type

**Computed Attributes:**
- `full_name` - Returns "Brand Model" (e.g., "Toyota Corolla")

**Fillable Attributes:**
- `car_brand_id` (foreignId)
- `name` (string) - Model name
- `slug` (string) - URL-friendly identifier (unique per brand)
- `year_from` (year, nullable) - First production year
- `year_to` (year, nullable) - Last production year
- `status` (enum: pending, active, inactive)

**Casts:**
- `year_from` → integer
- `year_to` → integer
- `status` → string

**Database Constraints:**
- Unique: (car_brand_id, slug) - Same slug allowed for different brands

**Notes:**
- Many-to-many with VehicleType (one model can belong to multiple types)
- Example: VW Golf can be "Auto małe" AND "Auto średnie"

---

### Page
**Location:** `/var/www/projects/registro/app/app/Models/Page.php`

**Purpose:** Static content pages with customizable layouts (About Us, Services, Contact, etc.)

**Relationships:** None (standalone content)

**Key Scopes:**
- `scopePublished($query)` - Filter pages with published_at <= now()
- `scopeDraft($query)` - Filter pages without published_at

**Fillable Attributes:**
- `title` - Page title
- `slug` - URL-friendly identifier (unique)
- `body` - Main content (TEXT, RichEditor)
- `content` - Advanced blocks (JSON, Builder)
- `layout` - enum('default', 'full-width', 'minimal')
- `published_at` - Publication date (nullable for drafts)
- `meta_title`, `meta_description`, `featured_image` - SEO fields

**Routes:** `GET /strona/{slug}` → PageController@show

**Notes:**
- Hybrid content system: `body` for simple text + `content` for advanced blocks
- Three layout options: default (with sidebars), full-width, minimal (narrow)

---

### Post
**Location:** `/var/www/projects/registro/app/app/Models/Post.php`

**Purpose:** Blog posts and news articles with categories

**Relationships:**
- `category()` → belongsTo(Category) - Post category

**Key Scopes:**
- `scopePublished($query)` - Published posts only
- `scopeDraft($query)` - Draft posts only
- `scopeInCategory($query, $categoryId)` - Filter by category

**Fillable Attributes:**
- `title`, `slug` - Title and URL identifier
- `excerpt` - Short description (TEXT, displayed in lists)
- `body` - Main content (TEXT, RichEditor)
- `content` - Advanced blocks (JSON, Builder)
- `category_id` - Foreign key to categories table
- `published_at` - Publication date
- `meta_title`, `meta_description`, `featured_image` - SEO fields

**Routes:** `GET /aktualnosci/{slug}` → PostController@show

**Notes:**
- Used for blog/news section
- Category is optional but recommended for organization

---

### Promotion
**Location:** `/var/www/projects/registro/app/app/Models/Promotion.php`

**Purpose:** Special offers, discounts, and promotional campaigns

**Relationships:** None (standalone content)

**Key Scopes:**
- `scopeActive($query)` - Active promotions (active = true)
- `scopeValid($query)` - Within valid_from/valid_until range
- `scopeActiveAndValid($query)` - Both active AND valid

**Fillable Attributes:**
- `title`, `slug` - Title and URL identifier
- `body` - Main content (TEXT, RichEditor)
- `content` - Advanced blocks (JSON, Builder)
- `active` - Boolean toggle for enabling/disabling
- `valid_from`, `valid_until` - Date range (nullable, no limits if empty)
- `meta_title`, `meta_description`, `featured_image` - SEO fields

**Routes:** `GET /promocje/{slug}` → PromotionController@show

**Methods:**
- `isActiveAndValid(): bool` - Check if promotion is active AND within date range

**Notes:**
- `active` flag allows quick enable/disable without deleting
- Date range is optional (null = no time restrictions)

---

### PortfolioItem
**Location:** `/var/www/projects/registro/app/app/Models/PortfolioItem.php`

**Purpose:** Showcase completed detailing projects with before/after images

**Relationships:**
- `category()` → belongsTo(Category) - Portfolio category

**Key Scopes:**
- `scopePublished($query)` - Published items only
- `scopeDraft($query)` - Draft items only
- `scopeInCategory($query, $categoryId)` - Filter by category

**Fillable Attributes:**
- `title`, `slug` - Project title and URL identifier
- `body` - Project description (TEXT, RichEditor)
- `content` - Client testimonials/quotes (JSON, Builder)
- `category_id` - Foreign key to categories table
- `before_image` - Image before work (single file)
- `after_image` - Image after work (single file)
- `gallery` - Additional images (JSON array of file paths)
- `published_at` - Publication date
- `meta_title`, `meta_description` - SEO fields

**Casts:**
- `gallery` → array - JSON array of image paths

**Routes:** `GET /portfolio/{slug}` → PortfolioController@show

**Notes:**
- Before/After images are the hero feature
- Gallery field stores additional project photos as JSON array
- Content field typically used for client testimonials (quote blocks)

---

### Category
**Location:** `/var/www/projects/registro/app/app/Models/Category.php`

**Purpose:** Hierarchical categories for Posts and Portfolio Items

**Relationships:**
- `parent()` → belongsTo(Category) - Parent category (nullable)
- `children()` → hasMany(Category) - Child categories
- `posts()` → hasMany(Post) - Posts in this category
- `portfolioItems()` → hasMany(PortfolioItem) - Portfolio items in this category

**Key Scopes:**
- `scopePostCategories($query)` - Categories with type = 'post'
- `scopePortfolioCategories($query)` - Categories with type = 'portfolio'
- `scopeRootCategories($query)` - Top-level categories (parent_id = null)

**Fillable Attributes:**
- `name` - Category name
- `slug` - URL-friendly identifier (unique)
- `description` - Category description (TEXT, nullable)
- `parent_id` - Parent category ID (nullable for root categories)
- `type` - enum('post', 'portfolio') - Category type

**Database Constraints:**
- Unique: slug (per type)
- Foreign key: parent_id → categories.id (nullable)

**Notes:**
- Supports nested categories (parent → children)
- Type field separates Post categories from Portfolio categories
- Can be managed in Filament admin: `/admin/categories`

---

## Controllers

### HomeController
**Location:** `/var/www/projects/registro/app/app/Http/Controllers/HomeController.php`

**Purpose:** Displays public home page with active services

**Routes:**
- `GET /` → `index()` (route name: 'home')

**Methods:**
- `index()` - Fetch and display active services ordered by sort_order

**Notes:**
- Very simple controller, no authentication required
- Only shows active services

---

### BookingController
**Location:** `/var/www/projects/registro/app/app/Http/Controllers/BookingController.php`

**Purpose:** Handle booking flow and availability checks

**Middleware:** `auth` - All routes require authentication

**Dependencies:**
- `AppointmentService` - Injected via constructor

**Routes:**
- `GET /services/{service}/book` → `create()` (route name: 'booking.create')
- `POST /booking/available-slots` → `getAvailableSlots()` (route name: 'booking.slots')

**Methods:**

1. `create(Service $service)`
   - Display booking form for a specific service
   - Fetch staff members who have availability for the service
   - Returns view: `booking.create`

2. `getAvailableSlots(Request $request)`
   - **API Endpoint** - Returns JSON
   - Validates: service_id, staff_id, date
   - Returns available time slots for given date/service/staff
   - Uses `AppointmentService::getAvailableTimeSlots()`

**Notes:**
- Good separation of concerns with AppointmentService
- API endpoint for real-time slot checking exists
- **MISSING:** No guest booking capability (auth required)
- **MISSING:** No multi-step booking flow

---

### AppointmentController
**Location:** `/var/www/projects/registro/app/app/Http/Controllers/AppointmentController.php`

**Purpose:** Manage appointment lifecycle (create, view, cancel)

**Middleware:** `auth` - All routes require authentication

**Dependencies:**
- `AppointmentService` - Injected via constructor

**Routes:**
- `GET /my-appointments` → `index()` (route name: 'appointments.index')
- `POST /appointments` → `store()` (route name: 'appointments.store')
- `POST /appointments/{appointment}/cancel` → `cancel()` (route name: 'appointments.cancel')

**Methods:**

1. `index()`
   - Show customer's appointments
   - Loads with service and staff relationships
   - Ordered by appointment_date DESC, start_time DESC

2. `store(Request $request)`
   - Validates booking request
   - Calls `AppointmentService::validateAppointment()`
   - Creates appointment with status 'pending'
   - Redirects to appointments.index with success message

3. `cancel(Appointment $appointment)`
   - Authorizes: customer_id must match Auth::id()
   - Checks: `can_be_cancelled` attribute
   - Updates status to 'cancelled' with reason
   - Returns back with success message

**Validation Rules (store):**
- `service_id` - required, exists:services
- `staff_id` - required, exists:users
- `appointment_date` - required, date, after_or_equal:today
- `start_time` - required, H:i format
- `end_time` - required, H:i format, after:start_time
- `notes` - nullable, string, max:1000

**Notes:**
- Good authorization checking for cancellations
- Business logic properly delegated to service layer
- **MISSING:** No update/reschedule functionality
- **MISSING:** No payment integration
- **MISSING:** No email notifications

---

### PageController
**Location:** `/var/www/projects/registro/app/app/Http/Controllers/PageController.php`

**Purpose:** Display published static pages on frontend

**Methods:**
1. `show(string $slug)` - Display single page
   - Finds page by slug
   - Filters: published_at <= now()
   - Returns: `pages.show` view with `$page` variable
   - Throws 404 if not found or not published

**Routes:**
- `GET /strona/{slug}` → show()

**Notes:**
- No authentication required (public content)
- Only published pages are accessible
- Uses `firstOrFail()` for automatic 404 handling

---

### PostController
**Location:** `/var/www/projects/registro/app/app/Http/Controllers/PostController.php`

**Purpose:** Display published blog posts/news articles on frontend

**Methods:**
1. `show(string $slug)` - Display single post
   - Finds post by slug
   - Filters: published_at <= now()
   - Eager loads: category relationship
   - Returns: `posts.show` view with `$post` variable
   - Throws 404 if not found or not published

**Routes:**
- `GET /aktualnosci/{slug}` → show()

**Notes:**
- No authentication required (public content)
- Category is eager-loaded for display
- Frontend shows category badge + post metadata

---

### PromotionController
**Location:** `/var/www/projects/registro/app/app/Http/Controllers/PromotionController.php`

**Purpose:** Display active and valid promotions on frontend

**Methods:**
1. `show(string $slug)` - Display single promotion
   - Finds promotion by slug
   - Filters: active = true AND valid date range
   - Complex query: (valid_from <= now OR null) AND (valid_until >= now OR null)
   - Returns: `promotions.show` view with `$promotion` variable
   - Throws 404 if not found or not active/valid

**Routes:**
- `GET /promocje/{slug}` → show()

**Notes:**
- No authentication required (public content)
- Only shows active promotions within valid date range
- Date range is optional (null = no restrictions)

---

### PortfolioController
**Location:** `/var/www/projects/registro/app/app/Http/Controllers/PortfolioController.php`

**Purpose:** Display published portfolio projects on frontend

**Methods:**
1. `show(string $slug)` - Display single portfolio item
   - Finds portfolio item by slug
   - Filters: published_at <= now()
   - Eager loads: category relationship
   - Returns: `portfolio.show` view with `$portfolioItem` variable
   - Throws 404 if not found or not published

**Routes:**
- `GET /portfolio/{slug}` → show()

**Notes:**
- No authentication required (public content)
- Showcases before/after images + gallery
- Content field typically contains client testimonials

---

## Business Logic Layer

### AppointmentService
**Location:** `/var/www/projects/registro/app/app/Services/AppointmentService.php`

**Purpose:** Centralized business logic for appointment management and availability checking

**Key Methods:**

1. `checkStaffAvailability(int $staffId, int $serviceId, Carbon $date, Carbon $startTime, Carbon $endTime, ?int $excludeAppointmentId = null): bool`
   - Verifies staff has configured availability for the day/service
   - Checks for conflicting appointments (comprehensive overlap detection)
   - Returns true if slot is available

2. `getAvailableTimeSlots(int $serviceId, int $staffId, Carbon $date, int $serviceDurationMinutes): array`
   - Returns array of available time slots for given date
   - Slots are in 15-minute intervals
   - Each slot includes: start, end, datetime_start, datetime_end
   - Filters out unavailable slots using checkStaffAvailability()

3. `validateAppointment(...): array`
   - Validates appointment data
   - Checks: date not in past, start before end, staff availability
   - Returns: ['valid' => bool, 'errors' => array]
   - Error messages in Polish

**Business Rules Implemented:**
- Staff must have configured availability window
- Appointment must fall within availability window
- No overlapping appointments for same staff member
- 15-minute slot intervals
- Date cannot be in the past

**Notes:**
- Well-designed, testable service class
- Good separation from controllers
- **MISSING:** No buffer time between appointments
- **MISSING:** No business hours validation
- **MISSING:** No capacity management
- **FIXED INTERVAL:** 15-minute slots hardcoded (line 116)

---

## Database Schema

### Table: users
**Columns:**
- id (bigint, PK, auto-increment)
- name (varchar)
- email (varchar, unique)
- email_verified_at (timestamp, nullable)
- password (varchar)
- remember_token (varchar, nullable)
- created_at, updated_at (timestamps)

**Relationships:**
- Has many: appointments (as customer_id)
- Has many: appointments (as staff_id)
- Has many: service_availabilities (as user_id)
- Has many: model_has_roles (Spatie permission)

---

### Table: services
**Columns:**
- id (bigint, PK, auto-increment)
- name (varchar)
- description (text, nullable)
- duration_minutes (integer, default: 60)
- price (decimal(10,2), default: 0.00)
- is_active (boolean, default: true)
- sort_order (integer, default: 0)
- created_at, updated_at (timestamps)

**Indexes:**
- Primary key: id
- None explicitly defined (potential performance issue for large datasets)

---

### Table: appointments
**Columns:**
- id (bigint, PK, auto-increment)
- service_id (foreignId, references services.id, cascade on delete)
- customer_id (foreignId, references users.id, cascade on delete)
- staff_id (foreignId, references users.id, cascade on delete)
- appointment_date (date)
- start_time (time)
- end_time (time)
- status (enum: pending, confirmed, cancelled, completed, default: pending)
- notes (text, nullable)
- cancellation_reason (text, nullable)
- created_at, updated_at (timestamps)

**Indexes:**
- Primary key: id
- Foreign keys: service_id, customer_id, staff_id
- Composite: (appointment_date, start_time)
- Composite: (staff_id, appointment_date)
- Composite: (customer_id, appointment_date)

**Notes:**
- Excellent indexing for common query patterns
- Cascade delete ensures referential integrity

---

### ~~Table: service_availabilities~~ (DROPPED 2025-12-12)

**Status:** ❌ **TABLE DROPPED** via migration `2025_12_12_014637_drop_service_availabilities_table.php`

**Reason:** Dead code table, 0 records at deletion, never used by booking system

**Replaced By:**
- `staff_schedules` - Base weekly patterns
- `staff_date_exceptions` - Single-day overrides
- `staff_vacation_periods` - Multi-day absences
- `service_staff` - Service-to-staff pivot (informational only)

**Migration Rollback:** `php artisan migrate:rollback --step=1` will recreate table structure (no data to restore)

---

### Table: permissions & roles (Spatie)
Standard Spatie Laravel Permission tables:
- permissions
- roles
- model_has_permissions
- model_has_roles
- role_has_permissions

**Roles in Use:**
- super-admin
- admin
- staff
- customer

---

### Table: sms_sends
**Columns:**
- id (bigint, PK, auto-increment)
- template_key (varchar) - Template identifier (e.g., 'booking_confirmation')
- phone_to (varchar(20)) - Recipient phone number (+48...)
- message_body (text) - Final rendered SMS message
- status (enum: pending, sent, failed, invalid_number, default: pending)
- sms_id (varchar(100), nullable) - SMSAPI message ID for tracking
- message_length (integer, nullable) - Character count
- message_parts (integer, nullable) - Number of SMS parts (multi-part messages)
- message_key (varchar(32), unique, nullable) - MD5 hash for idempotency
- metadata (json, nullable) - Additional data (appointment_id, etc.)
- error_message (text, nullable) - Error details if failed
- created_at, updated_at (timestamps)

**Indexes:**
- Primary key: id
- Index: status
- Index: phone_to
- Index: created_at
- Unique: message_key

**Purpose:** Tracks all sent SMS messages with delivery status

---

### Table: sms_templates
**Columns:**
- id (bigint, PK, auto-increment)
- key (varchar) - Template identifier (e.g., 'booking_confirmation')
- language (varchar(2), default: 'pl') - Template language (pl|en)
- message_body (text) - SMS message template with {{placeholders}}
- variables (json, nullable) - Array of available variables
- max_length (integer, default: 160) - Max SMS length (160 GSM, 70 Unicode)
- active (boolean, default: true) - Enable/disable template
- created_at, updated_at (timestamps)

**Indexes:**
- Primary key: id
- Composite: (key, language)
- Index: active

**Purpose:** Bilingual SMS message templates with variable interpolation

---

### Table: sms_events
**Columns:**
- id (bigint, PK, auto-increment)
- sms_send_id (foreignId, references sms_sends.id, cascade on delete)
- event_type (enum: sent, delivered, failed, invalid_number, expired)
- occurred_at (timestamp) - When event occurred
- metadata (json, nullable) - Full webhook payload from SMSAPI
- created_at, updated_at (timestamps)

**Indexes:**
- Primary key: id
- Foreign key: sms_send_id
- Composite: (sms_send_id, event_type)

**Purpose:** Webhook delivery events from SMSAPI for tracking

---

### Table: sms_suppressions
**Columns:**
- id (bigint, PK, auto-increment)
- phone (varchar(20), unique) - Suppressed phone number (+48...)
- reason (enum: invalid_number, opted_out, failed_repeatedly, manual)
- suppressed_at (timestamp) - When number was suppressed
- created_at, updated_at (timestamps)

**Indexes:**
- Primary key: id
- Unique: phone

**Purpose:** Opt-out and invalid number management (prevents spam)

---

## SMS System

### Overview
Complete SMS notification system integrated with SMSAPI.pl for automated appointment notifications.

**Status:** ✅ Production Ready (November 2025)

**Documentation:** `docs/features/sms-system/README.md` | **ADR:** `docs/decisions/ADR-007-sms-system-implementation.md`

### Architecture
**Pattern:** Service Layer with Gateway Interface

**Components:**
- **SmsService** (`app/Services/Sms/SmsService.php`) - Core SMS sending logic with template rendering
- **SmsGatewayInterface** (`app/Services/Sms/SmsGatewayInterface.php`) - Contract for SMS gateway implementations
- **SmsApiGateway** (`app/Services/Sms/SmsApiGateway.php`) - SMSAPI.pl HTTP API integration (Guzzle client)
- **Models** - SmsSend, SmsTemplate, SmsEvent, SmsSuppression (4 tables)
- **Webhook** - SmsApiWebhookController for delivery status tracking
- **Seeder** - SmsTemplateSeeder (14 templates: 7 types × 2 languages)

### Database Models

**SmsTemplate** (`app/Models/SmsTemplate.php`)
- Stores SMS message templates with `{{placeholders}}`
- Fields: `key`, `language`, `message_body`, `variables`, `max_length`, `active`
- Methods: `render($data)`, `exceedsMaxLength()`, `truncateMessage()`
- 14 records: 7 types × 2 languages (PL, EN)

**SmsSend** (`app/Models/SmsSend.php`)
- History of sent SMS messages
- Fields: `phone_number`, `message_body`, `status`, `smsapi_message_id`, `message_key`, `cost`, `metadata`
- Statuses: `pending`, `sent`, `failed`, `delivered`
- Idempotent via `message_key` (MD5 hash prevents duplicates)

**SmsEvent** (`app/Models/SmsEvent.php`)
- Audit trail of SMS lifecycle events
- Fields: `sms_send_id`, `event_type`, `smsapi_status`, `error_message`, `raw_response`
- Event types: `sent`, `failed`, `delivered`, `undelivered`

**SmsSuppression** (`app/Models/SmsSuppression.php`)
- Opt-out blacklist (phone numbers that should not receive SMS)
- Fields: `phone_number`, `reason`, `suppressed_at`
- Reasons: `opt_out`, `invalid_number`, `manual`

### SMS Template Types
**Location:** 14 templates seeded by `database/seeders/SmsTemplateSeeder.php`

**Template Keys:**
- `appointment-created` - Customer creates appointment
- `appointment-confirmed` - Admin manually confirms appointment
- `appointment-rescheduled` - Date/time changed
- `appointment-cancelled` - Appointment cancelled
- `appointment-reminder-24h` - 24 hours before appointment
- `appointment-reminder-2h` - 2 hours before appointment
- `appointment-followup` - Post-service feedback request

**Languages:** Polish (pl), English (en)

**Variables:**
- `customer_name`, `service_name`, `appointment_date`, `appointment_time`
- `location_address`, `app_name`, `contact_phone`

**Character Limits:**
- GSM-7: 160 characters (1 SMS)
- Unicode (Polish): 70 characters (1 SMS)

### Service Layer

**SmsService** (`app/Services/Sms/SmsService.php`)
- `sendFromTemplate($templateKey, $language, $phoneNumber, $data, $metadata)` - Main method
- `sendTestSms($phoneNumber, $language)` - Test SMS for admin
- `renderTemplate($template, $data)` - Blade rendering with fallback
- Checks suppression list before sending
- Generates `message_key` for idempotency (prevents duplicate SMS)
- Creates SmsSend record and logs events

**SmsApiGateway** (`app/Services/Sms/SmsApiGateway.php`)
- HTTP client for SMSAPI.pl RESTful API (Guzzle)
- `send($phoneNumber, $messageBody, $from)` - POST to https://api.smsapi.pl/sms.do
- `checkCredits()` - Get account balance
- `getMessageStatus($messageId)` - Check delivery status
- Handles authentication (Bearer token), test mode, error responses

**SmsGatewayInterface** (`app/Services/Sms/SmsGatewayInterface.php`)
- Contract for gateway implementations (allows switching providers)
- Methods: `send()`, `checkCredits()`, `getMessageStatus()`

### Webhook Handler
**Endpoint:** `POST /api/webhooks/smsapi`
**Controller:** `app/Http/Controllers/Api/SmsApiWebhookController.php`

**Flow:**
1. Receives HTTP POST from SMSAPI.pl with delivery status
2. Extracts `id` (SMSAPI message ID) from payload
3. Finds `SmsSend` by `smsapi_message_id`
4. Updates `status` to `delivered` or `failed`
5. Sets `delivered_at` or `failed_at` timestamp
6. Creates `SmsEvent` for audit trail
7. Returns `200 OK` to SMSAPI.pl

**Payload Example:**
```json
{
  "id": "sms-123456",
  "status": "DELIVERED",
  "error": null,
  "date_sent": 1635789012,
  "date_delivered": 1635789120,
  "number": "+48501234567"
}
```

**Event Types:**
- `sent` - Message sent from SMSAPI gateway
- `delivered` - Successfully delivered to recipient
- `failed` - Delivery failed
- `undelivered` - Not delivered (invalid number, phone off, etc.)

### Admin Panel Resources

**SmsTemplateResource** (`app/Filament/Resources/SmsTemplateResource.php`)
- CRUD for SMS templates
- Actions: **Test Send** (sends test SMS from template)
- Filters: Active/Inactive, Language (PL/EN), Template Type
- Columns: Key, Language, Message Preview, Max Length, Active
- Form validation: Max 160 characters, required variables

**SmsSendResource** (`app/Filament/Resources/SmsSendResource.php`)
- View-only SMS history (no create/edit)
- Filters: Status, Template Key, Date Range
- Search: Phone number, message body
- Actions: **View Details** (full message, metadata, events), **Resend** (retry failed)

**SmsEventResource** (`app/Filament/Resources/SmsEventResource.php`)
- Audit trail of SMS events
- Filters: Event Type, Date Range
- Columns: Event Type, SMSAPI Status, Error Message, Created At
- Expandable: Shows raw SMSAPI response JSON

**SmsSuppressionResource** (`app/Filament/Resources/SmsSuppressionResource.php`)
- CRUD for suppression list (blacklist)
- Filters: Reason (opt_out, invalid_number, manual)
- Actions: **Add to Blacklist**, **Remove** (un-suppress)

### System Settings
**Page:** System Settings → SMS Tab (`app/Filament/Pages/SystemSettings.php`)

**SMSAPI Configuration:**
- SMS Enabled (toggle)
- API Token (secure, revealable)
- Service (pl/com)
- Sender Name (max 11 chars, alphanumeric)
- Test Mode (sandbox for development)

**SMS Notification Settings:**
- Booking Confirmation SMS
- Admin Confirmation SMS
- 24-Hour Reminder SMS
- 2-Hour Reminder SMS
- Follow-up SMS

**Actions:**
- **Test Email Connection** - Send test email to admin
- **Test SMS Connection** - Send test SMS to admin (uses admin's `phone_e164`)

### Seeder
**SmsTemplateSeeder** (`database/seeders/SmsTemplateSeeder.php`)
- Creates 14 SMS templates (7 types × 2 languages)
- Idempotent (uses `updateOrCreate`)
- Run with: `php artisan db:seed --class=SmsTemplateSeeder`

**Added to CLAUDE.md:** Required seeder after `migrate:fresh`

### Configuration

**Environment Variables:**
```bash
SMSAPI_TOKEN=Bearer_xxxxx          # API token from SMSAPI.pl
SMSAPI_SERVICE=pl                  # or 'com' for international
SMSAPI_SENDER_NAME=Registro       # Max 11 chars
SMSAPI_TEST_MODE=false             # true for sandbox mode
```

**Settings (Database):**
Stored in `settings` table via SettingsManager:
- `sms.enabled` - SMS system on/off
- `sms.api_token` - SMSAPI.pl Bearer token
- `sms.service` - pl or com
- `sms.sender_name` - Sender name (max 11 chars)
- `sms.test_mode` - Sandbox mode
- `sms.notification_booking_confirmation` - Toggle
- `sms.notification_admin_confirmation` - Toggle
- `sms.notification_reminder_24h` - Toggle
- `sms.notification_reminder_2h` - Toggle
- `sms.notification_followup` - Toggle

### Key Features

**Idempotency:**
- `message_key` = MD5(phone + template + language + appointment_id + timestamp)
- Prevents duplicate SMS if job retried or event triggered twice

**Suppression List:**
- Checked before sending (skips suppressed numbers)
- Users can opt-out (add to suppression list)
- Invalid numbers auto-added after failures

**Character Limits:**
- GSM-7: 160 chars (1 SMS), 161-306 chars (2 SMS), 307-459 chars (3 SMS)
- Unicode (Polish): 70 chars (1 SMS), 71-134 chars (2 SMS), 135-201 chars (3 SMS)
- Multi-part SMS cost 2-3× more

**Test Mode:**
- SMS not sent to real phones (simulated by SMSAPI.pl)
- No credits deducted
- Useful for development/testing

**Pricing:**
- ~0.10 PLN per SMS (160 chars) in Poland
- ~0.20 PLN for 2-part SMS (161-306 chars)
- ~0.30 PLN for 3-part SMS (307-459 chars)

### Future Enhancements (Backlog)
- [ ] Queue SMS sending (SendSmsJob) - currently synchronous
- [ ] SMS analytics dashboard (costs, delivery rates, trends)
- [ ] Batch SMS sending (send to multiple numbers at once)
- [ ] SMS scheduling (schedule SMS for future delivery)
- [ ] Two-way SMS (allow replies, requires phone number as sender)
- [ ] A/B testing for SMS templates

### Configuration
**Settings Group:** `sms` in settings table

**Keys:**
- enabled, api_token, service, sender_name, test_mode
- send_booking_confirmation, send_admin_confirmation
- send_reminder_24h, send_reminder_2h, send_follow_up

**Access:** Via `SettingsManager::group('sms')`

### Integration Points
**Appointment Model:**
- Added fields: sent_24h_reminder_sms, sent_2h_reminder_sms, sent_followup_sms
- Dispatches AppointmentConfirmed event on status change to 'confirmed'

**User Model:**
- Requires `phone` field for SMS notifications
- Phone stored in international format (+48...)

### Dependencies
**Composer:**
```json
"smsapi/php-client": "^3.0"
```

**SMSAPI SDK:**
- OAuth 2.0 Bearer Token authentication
- PSR-17/18 compliant HTTP client
- Support for pl and com service endpoints

---

## Email System

Complete transactional email system with queue-based delivery.

### Models

**EmailTemplate** (`app/Models/EmailTemplate.php`)
- Stores email templates with key, subject, body_html, body_text
- Supports PL/EN language variants
- 18 templates (9 types × 2 languages)

**EmailSend** (`app/Models/EmailSend.php`)
- Logs all sent emails with status, attempts, metadata
- Tracks delivery status (pending, sent, failed, bounced)
- Stores message_key for idempotency

**EmailEvent** (`app/Models/EmailEvent.php`)
- Logs email events (opened, clicked, bounced, complained)
- Links to EmailSend via email_send_id

**EmailSuppression** (`app/Models/EmailSuppression.php`)
- Stores suppressed email addresses (bounces, complaints)
- Prevents sending to problematic addresses

### Services

**EmailService** (`app/Services/Email/EmailService.php`)
- Main entry point for sending emails
- Template rendering with variable substitution
- Queue integration for async delivery

**SmtpMailer** (`app/Services/Email/SmtpMailer.php`)
- SMTP gateway implementation
- Uses Laravel's built-in mail functionality
- Gmail SMTP with App Password support

### Filament Resources

- `EmailTemplateResource` - Template management
- `EmailSendResource` - Send history and status
- `EmailEventResource` - Event tracking
- `EmailSuppressionResource` - Suppression list management

---

## Staff Scheduling System

Calendar-based staff availability management.

### Models

**StaffSchedule** (`app/Models/StaffSchedule.php`)
- Base weekly patterns (e.g., Mon-Fri 9:00-17:00)
- Effective date ranges for schedule changes
- Links to User (staff) via user_id

**StaffDateException** (`app/Models/StaffDateException.php`)
- Single-day overrides (sick days, extra work)
- Can mark day as available or unavailable
- Custom hours for specific dates

**StaffVacationPeriod** (`app/Models/StaffVacationPeriod.php`)
- Multi-day vacation/leave periods
- Approval workflow (pending, approved, rejected)
- Blocks all availability during period

### Service

**StaffScheduleService** (`app/Services/StaffScheduleService.php`)
- Core availability checking logic
- Priority: Vacation → Exception → Base Schedule
- Used by AppointmentService for slot generation

### Pivot Table

**service_staff** - Links services to staff who can perform them
- service_id → services.id
- user_id → users.id (staff role)

### Filament Resources

- `StaffScheduleResource` - Base schedule patterns
- `StaffDateExceptionResource` - Day overrides (hidden from nav)
- `StaffVacationPeriodResource` - Vacation management

---

## API Endpoints

### Public Web Routes
```
GET  /                         HomeController@index             (public)
```

### CMS Routes (Public)
```
GET  /strona/{slug}            PageController@show              pages.show
GET  /aktualnosci/{slug}       PostController@show              posts.show
GET  /promocje/{slug}          PromotionController@show         promotions.show
GET  /portfolio/{slug}         PortfolioController@show         portfolio.show
```

### Authentication Routes
Laravel's default authentication routes (Auth::routes()):
```
GET  /login                    Auth\LoginController@showLoginForm
POST /login                    Auth\LoginController@login
POST /logout                   Auth\LoginController@logout
GET  /register                 Auth\RegisterController@showRegistrationForm
POST /register                 Auth\RegisterController@register
GET  /password/reset           Auth\ForgotPasswordController@showLinkRequestForm
POST /password/email           Auth\ForgotPasswordController@sendResetLinkEmail
GET  /password/reset/{token}   Auth\ResetPasswordController@showResetForm
POST /password/reset           Auth\ResetPasswordController@reset
```

### Protected Routes (require auth)
```
GET  /services/{service}/book  BookingController@create         booking.create
POST /booking/available-slots  BookingController@getAvailableSlots  booking.slots
GET  /my-appointments          AppointmentController@index      appointments.index
POST /appointments             AppointmentController@store      appointments.store
POST /appointments/{id}/cancel AppointmentController@cancel     appointments.cancel
```

### Vehicle API Routes (Public)
```
GET  /api/vehicle-types        VehicleApiController@vehicleTypes
GET  /api/car-brands           VehicleApiController@carBrands
GET  /api/car-models           VehicleApiController@carModels
GET  /api/vehicle-years        VehicleApiController@vehicleYears
```

### SMS Webhook Routes
```
POST /api/webhooks/smsapi/delivery-status  SmsWebhookController@deliveryStatus
POST /api/webhooks/smsapi/incoming         SmsWebhookController@incoming
```

### API Endpoint Details

#### POST /booking/available-slots
**Purpose:** Get available booking time slots

**Request:**
```json
{
  "service_id": 1,
  "staff_id": 2,
  "date": "2025-10-15"
}
```

**Response:**
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
    }
  ],
  "date": "2025-10-15"
}
```

**Notes:**
- Returns slots in 15-minute intervals
- Already filters out unavailable times
- Good for real-time frontend availability checking

---

## Filament Resources (Admin Panel)

### ServiceResource
**Location:** `/var/www/projects/registro/app/app/Filament/Resources/ServiceResource.php`

**Purpose:** Admin management of detailing services

**Features:**
- Full CRUD operations
- Form fields: name, description, duration_minutes, price, is_active, sort_order
- Table columns: name, duration_minutes, price, is_active, sort_order, timestamps
- Actions: Edit, Delete (bulk)

**Notes:**
- Basic implementation
- **MISSING:** No relation manager for service_availabilities
- **MISSING:** No image/gallery management

---

### AppointmentResource
**Location:** `/var/www/projects/registro/app/app/Filament/Resources/AppointmentResource.php`

**Purpose:** Admin management of appointments

**Features:**
- Full CRUD operations
- Polish language labels
- Auto-calculate end_time based on service duration
- Status badges with color coding
- Create customer inline option
- Filters: status, service, staff
- Actions: View, Edit, Delete
- Validation: prevents booking in past, checks staff availability

**Form Intelligence:**
- Reactive fields update end_time when service/start_time changes
- Shows cancellation_reason field only when status is cancelled
- Customer/Staff dropdowns filtered by role

**Table Features:**
- Columns: service, customer, staff, date, times, status
- Default sort: appointment_date DESC
- Badge colors: warning=pending, success=confirmed, danger=cancelled, secondary=completed

**Notes:**
- Well-designed admin interface
- Good UX with reactive fields
- **MISSING:** Calendar view integration (though Guava Calendar is installed)
- **MISSING:** Bulk status updates

---

### UserResource
**Location:** `app/Filament/Resources/UserResource.php`

**Purpose:** User management with role assignment

**Features:**
- Full CRUD operations
- User details: first_name, last_name, email, phone
- Role assignment via Spatie Permission
- Filters: role, active status

---

### CustomerResource
**Location:** `app/Filament/Resources/CustomerResource.php`

**Purpose:** Customer management for appointments

---

### EmployeeResource
**Location:** `app/Filament/Resources/EmployeeResource.php`

**Purpose:** Employee (staff) management with scheduling tabs

**Features:**
- Employee details with role assignment
- Tabs: Usługi, Harmonogramy, Wyjątki, Urlopy
- Service-staff assignments via pivot table

---

### RoleResource
**Location:** `app/Filament/Resources/RoleResource.php`

**Purpose:** Role and permission management via Spatie

---

### Vehicle Management Resources

#### VehicleTypeResource
**Location:** `app/Filament/Resources/VehicleTypeResource.php`

**Purpose:** Vehicle type management (SUV, Sedan, Van, etc.)

---

#### CarBrandResource
**Location:** `app/Filament/Resources/CarBrandResource.php`

**Purpose:** Car brand management (BMW, Audi, etc.)

---

#### CarModelResource
**Location:** `app/Filament/Resources/CarModelResource.php`

**Purpose:** Car model management with brand relationship

---

### Staff Scheduling Resources

#### StaffScheduleResource
**Location:** `app/Filament/Resources/StaffScheduleResource.php`

**Purpose:** Base schedule patterns (recurring weekly schedules)

**Features:**
- Employee selection
- Day of week selection
- Start/end times
- Effective date ranges
- Header action: "Zarządzaj wyjątkami"

---

#### StaffDateExceptionResource
**Location:** `app/Filament/Resources/StaffDateExceptionResource.php`

**Purpose:** Single-day schedule exceptions

**Notes:**
- Hidden from main navigation (accessible via StaffScheduleResource header action)
- UX improvement per UX-MIGRATION-001

---

#### StaffVacationPeriodResource
**Location:** `app/Filament/Resources/StaffVacationPeriodResource.php`

**Purpose:** Vacation period management

**Features:**
- Start/end dates
- Approval status workflow
- Reason field

---

### Email System Resources

#### EmailTemplateResource
**Location:** `app/Filament/Resources/EmailTemplateResource.php`

**Purpose:** Email template management (18 templates: 9 types × PL/EN)

---

#### EmailSendResource
**Location:** `app/Filament/Resources/EmailSendResource.php`

**Purpose:** Email send logs viewing

---

#### EmailEventResource
**Location:** `app/Filament/Resources/EmailEventResource.php`

**Purpose:** Email event tracking (opens, clicks, bounces)

---

#### EmailSuppressionResource
**Location:** `app/Filament/Resources/EmailSuppressionResource.php`

**Purpose:** Email suppression list management

---

### SMS System Resources

#### SmsTemplateResource
**Location:** `app/Filament/Resources/SmsTemplateResource.php`

**Purpose:** SMS template management (14 templates: 7 types × PL/EN)

---

#### SmsSendResource
**Location:** `app/Filament/Resources/SmsSendResource.php`

**Purpose:** SMS send logs viewing

---

#### SmsEventResource
**Location:** `app/Filament/Resources/SmsEventResource.php`

**Purpose:** SMS event tracking

---

#### SmsSuppressionResource
**Location:** `app/Filament/Resources/SmsSuppressionResource.php`

**Purpose:** SMS suppression list management

---

### CMS Resources (in subfolders)

#### PageResource
**Location:** `app/Filament/Resources/Pages/PageResource.php`

**Purpose:** Static page management

**Features:**
- RichEditor body content
- Builder blocks (image, gallery, video, CTA, columns, quotes)
- SEO fields: meta_title, meta_description, featured_image
- Layout options: default, full-width, minimal
- Publishing workflow: draft → scheduled → published

---

#### PostResource
**Location:** `app/Filament/Resources/Posts/PostResource.php`

**Purpose:** Blog/news post management

**Features:**
- RichEditor body + Builder blocks
- Category assignment
- SEO fields
- Excerpt for listings
- Publishing workflow

---

#### PromotionResource
**Location:** `app/Filament/Resources/Promotions/PromotionResource.php`

**Purpose:** Promotional offer management

**Features:**
- RichEditor body + Builder blocks
- Active flag
- Valid from/until dates
- SEO fields

---

#### PortfolioItemResource
**Location:** `app/Filament/Resources/PortfolioItems/PortfolioItemResource.php`

**Purpose:** Portfolio project showcase

**Features:**
- RichEditor body + Builder blocks
- Before/after images
- Gallery JSON field
- Category assignment
- SEO fields

---

#### CategoryResource
**Location:** `app/Filament/Resources/Categories/CategoryResource.php`

**Purpose:** Hierarchical category management

**Features:**
- Type field: 'post' or 'portfolio'
- Parent-child relationships
- Tree view display

---

## Installed Packages & Integrations

### Core Laravel Packages
- **Laravel Framework:** 12.x
- **Laravel Sanctum:** API authentication (not actively used)
- **Spatie Laravel Permission:** v6.21 - Role and permission management

### Admin Panel
- **Laravel Filament:** v4.2.3 - Complete admin panel framework

### Calendar
- **Guava Calendar:** v2.0 - Calendar component for Laravel Filament
- **Status:** Installed for calendar-based availability management

### Development Tools
- Laravel Pint (code formatting)
- PHPUnit 11.5+ (testing)

### Frontend
- Vite (asset bundling)
- Tailwind CSS 4.0 (styling)

---

## Configuration Notes

### Authentication
- Standard Laravel authentication
- Session-based (web guard)
- Email verification available but not enforced

### Queue System
- Redis driver with Laravel Horizon
- Queue-based email and SMS delivery
- Horizon dashboard: `/horizon`

### Cache
- Redis for session and cache storage
- File-based fallback available

### Database
- MySQL 8.0 in Docker (container: `registro-mysql`)
- Good indexing on appointments table

---

## Identified Gaps for Modern Frontend Features

Based on analysis of the current backend architecture, here are the gaps that need to be addressed to support modern frontend booking experiences:

### 1. Real-Time Availability Checking
**Status:** PARTIAL SUPPORT

**Existing:**
- `/booking/available-slots` endpoint exists
- Returns time slots in 15-minute intervals
- Checks staff availability and conflicts

**Missing:**
- No WebSocket/Pusher integration for real-time updates
- No optimistic locking to prevent double-booking
- No slot reservation mechanism (hold slot while user completes booking)

**Impact:** Medium - Current API works but lacks real-time coordination

---

### 2. Service Package Tiers
**Status:** NOT SUPPORTED

**Existing:**
- Single-tier services only
- Flat pricing per service

**Missing:**
- No service tier concept (Basic/Premium/Elite)
- No package bundles or combinations
- No tiered pricing structure
- No feature comparison data for frontend display

**Impact:** HIGH - Requires database schema changes

**Required Changes:**
- Add `service_tier` field or separate `ServiceTier` model
- Add `service_packages` table for bundles
- Update pricing structure to support multiple tiers

---

### 3. Add-Ons and Dynamic Pricing
**Status:** NOT SUPPORTED

**Existing:**
- Single base price per service
- No add-on concept

**Missing:**
- No add-ons table/model (e.g., "Headlight Restoration", "Pet Hair Removal")
- No pricing calculation system for base + add-ons
- No appointment_add_ons pivot table
- No price breakdown in appointment record

**Impact:** HIGH - Requires new models and pricing logic

**Required Changes:**
- Create `ServiceAddOn` model
- Create `appointment_add_ons` pivot table
- Add `total_price` field to appointments
- Implement pricing calculation service

---

### 4. Customer Data Collection
**Status:** ✅ PARTIAL IMPLEMENTATION

**Existing:**
- ✅ User profile fields: first_name, last_name, phone_e164, address fields
- ✅ Google Maps location data on appointments
- ✅ Vehicle information: VehicleType, CarBrand, CarModel, year
- Notes field on appointment

**Missing:**
- No vehicle color or plate number tracking
- No customer preferences/notes
- No marketing consent fields

**Impact:** MEDIUM - Core features implemented, minor gaps remain

**Completed:**
- ✅ Extended users table with phone, address fields (migration: 2025_10_18)
- ✅ Created VehicleType/CarBrand/CarModel system
- ✅ Vehicle fields on appointments table
- ✅ Google Maps integration for service location

---

### 5. Notification System
**Status:** NOT IMPLEMENTED

**Existing:**
- None

**Missing:**
- No email notification system
- No SMS notification capability
- No notification preferences
- No reminder scheduling
- No confirmation emails
- No cancellation notifications

**Impact:** HIGH - Essential for professional service

**Required Changes:**
- Create notification classes (AppointmentConfirmed, AppointmentReminder, etc.)
- Configure mail driver
- Integrate SMS provider (Twilio, Vonage)
- Add `notification_preferences` to users
- Implement notification scheduling (Laravel queue + scheduler)

---

### 6. Analytics & Conversion Tracking
**Status:** NOT IMPLEMENTED

**Existing:**
- Basic appointment data
- created_at timestamps

**Missing:**
- No funnel tracking (viewed service → started booking → completed)
- No abandonment tracking
- No source tracking (how customer found service)
- No referral tracking
- No conversion event logging

**Impact:** MEDIUM - Nice to have for business intelligence

**Required Changes:**
- Create `analytics_events` table
- Implement event tracking middleware
- Add source/referral fields to appointments
- Create analytics dashboard in Filament

---

### 7. Payment & Deposit System
**Status:** NOT IMPLEMENTED

**Existing:**
- Only service price stored
- No payment tracking

**Missing:**
- No payment integration (Stripe, PayPal)
- No deposit/prepayment system
- No payment status tracking
- No refund handling
- No invoice generation

**Impact:** HIGH - Critical for reducing no-shows

**Required Changes:**
- Create `payments` table
- Integrate payment gateway (Stripe recommended)
- Add payment_status to appointments
- Implement deposit collection flow
- Create invoice generation service

---

### 8. Booking Flow Enhancement
**Status:** BASIC IMPLEMENTATION

**Existing:**
- Single-step booking form
- Authentication required

**Missing:**
- No multi-step wizard
- No guest booking (collect info, then create account)
- No booking summary/review step
- No calendar view for date selection
- No service comparison view

**Impact:** MEDIUM - UX improvement

**Required Changes:**
- Implement multi-step form (service → date/time → customer info → review → confirm)
- Add guest booking capability
- Integrate Guava Calendar for date picking
- Create booking summary view

---

### 9. Staff & Capacity Management
**Status:** BASIC IMPLEMENTATION

**Existing:**
- Staff-to-service availability mapping
- One staff member per appointment

**Missing:**
- No team-based appointments (multiple staff)
- No capacity management (parallel appointments)
- No staff skills/certifications tracking
- No automatic staff assignment
- No break time management

**Impact:** MEDIUM - Scalability concern

**Required Changes:**
- Add `appointment_staff` pivot table for multiple staff
- Add capacity field to services
- Implement smart staff assignment algorithm
- Add break time configuration to service_availabilities

---

### 10. Exception & Holiday Management
**Status:** NOT IMPLEMENTED

**Existing:**
- Recurring weekly availability only

**Missing:**
- No holiday/closed day definitions
- No one-time availability exceptions
- No vacation/time-off management
- No special hours (e.g., extended hours for events)

**Impact:** MEDIUM - Operational flexibility

**Required Changes:**
- Create `availability_exceptions` table (date, user_id, type, start_time, end_time)
- Create `business_hours_exceptions` table (holidays, closures)
- Update availability checking logic to consider exceptions

---

## Summary of Required Backend Changes

### Critical (Must Have Before Frontend Implementation)
1. ✅ Customer phone number field - COMPLETED (2025-10-18)
2. ✅ Vehicle information model - COMPLETED (2025-10-31)
3. Service add-ons system
4. Dynamic pricing calculation
5. Notification system (email minimum)

### High Priority (Should Have)
1. Service tier/package system
2. Payment integration
3. Deposit handling
4. Guest booking flow
5. Address management for mobile detailing

### Medium Priority (Nice to Have)
1. Analytics tracking
2. Capacity management
3. Exception/holiday management
4. WebSocket real-time updates
5. Guava Calendar integration

### Low Priority (Future Enhancement)
1. Referral tracking
2. Advanced analytics dashboard
3. Team-based appointments
4. Skills/certification tracking

---

## Recommendations

### Immediate Actions
1. **Extend User Model** - Add phone number (required for SMS)
2. **Create Vehicle Model** - Essential for detailing business
3. **Create AddOn System** - Enable flexible service customization
4. **Implement Notifications** - At minimum, email confirmations
5. **Add API Documentation** - OpenAPI/Swagger for frontend team

### Database Migrations Needed
```php
// 1. Add phone to users
Schema::table('users', function (Blueprint $table) {
    $table->string('phone', 20)->nullable()->after('email');
    $table->text('address')->nullable();
    $table->string('city', 100)->nullable();
    $table->string('postal_code', 20)->nullable();
});

// 2. Create vehicles table
Schema::create('vehicles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
    $table->string('make', 50);
    $table->string('model', 50);
    $table->year('year');
    $table->string('color', 30)->nullable();
    $table->string('plate_number', 20)->nullable();
    $table->text('notes')->nullable();
    $table->timestamps();
});

// 3. Update appointments table
Schema::table('appointments', function (Blueprint $table) {
    $table->foreignId('vehicle_id')->nullable()->after('customer_id')
          ->constrained()->nullOnDelete();
    $table->decimal('total_price', 10, 2)->default(0)->after('service_id');
    $table->string('payment_status', 20)->default('pending')->after('total_price');
});

// 4. Create service_add_ons table
Schema::create('service_add_ons', function (Blueprint $table) {
    $table->id();
    $table->foreignId('service_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->text('description')->nullable();
    $table->decimal('price', 10, 2);
    $table->boolean('is_active')->default(true);
    $table->integer('sort_order')->default(0);
    $table->timestamps();
});

// 5. Create appointment_add_ons pivot
Schema::create('appointment_add_ons', function (Blueprint $table) {
    $table->id();
    $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
    $table->foreignId('service_add_on_id')->constrained()->cascadeOnDelete();
    $table->decimal('price_at_booking', 10, 2);
    $table->timestamps();
});

// 6. Create notifications table (Laravel default)
php artisan notifications:table

// 7. Create payments table
Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
    $table->string('payment_intent_id')->nullable();
    $table->decimal('amount', 10, 2);
    $table->string('currency', 3)->default('USD');
    $table->string('status', 20); // pending, succeeded, failed, refunded
    $table->string('payment_method', 50)->nullable();
    $table->timestamp('paid_at')->nullable();
    $table->timestamps();

    $table->index('payment_intent_id');
    $table->index('status');
});
```

### New Service Classes Needed
1. `PricingCalculatorService` - Calculate total with add-ons
2. `NotificationService` - Wrapper for multi-channel notifications
3. `PaymentService` - Payment gateway integration
4. `VehicleService` - Vehicle management logic
5. `BookingWorkflowService` - Multi-step booking orchestration

### API Endpoints to Add
```
GET    /api/services/{id}/add-ons          Get available add-ons for service
GET    /api/services/{id}/tiers            Get pricing tiers (future)
POST   /api/booking/reserve-slot           Reserve a time slot temporarily
POST   /api/booking/calculate-price        Calculate total with add-ons
POST   /api/booking/guest                  Guest booking flow
GET    /api/customer/vehicles              Get customer's vehicles
POST   /api/customer/vehicles              Add new vehicle
GET    /api/calendar/available-dates       Get dates with availability
POST   /api/payments/create-intent         Create Stripe payment intent
POST   /api/payments/confirm               Confirm payment
```

### Performance Optimizations
1. Add Redis cache for availability checks
2. Index services.is_active for faster queries
3. Consider database read replicas for high traffic
4. Implement API rate limiting
5. Add eager loading in relationships to avoid N+1 queries

### Testing Recommendations
1. Create Feature tests for booking flow
2. Create Unit tests for AppointmentService
3. Create Unit tests for new PricingCalculatorService
4. Add API tests for all new endpoints
5. Implement browser tests for critical user flows (Dusk)

---

## Integration Points for Frontend

### Data Format Standards

**Date Format:** YYYY-MM-DD (ISO 8601)
**Time Format:** HH:mm (24-hour)
**DateTime Format:** YYYY-MM-DD HH:mm:ss
**Currency:** Decimal with 2 places
**Phone:** E.164 format recommended

### API Response Format
All API responses follow Laravel standard:

**Success:**
```json
{
  "data": { ... },
  "message": "Success message"
}
```

**Error:**
```json
{
  "message": "Validation error",
  "errors": {
    "field_name": ["Error message"]
  }
}
```

### Authentication
- Session-based for web interface
- Sanctum tokens available for SPA/mobile (not currently configured)
- CSRF token required for all POST/PUT/DELETE requests

### Validation Rules Reference
See controller validation rules above for exact requirements.

### Recommended Frontend State Management
- Maintain booking form state across steps
- Cache available slots for selected date/staff
- Debounce API calls for availability checks
- Implement optimistic UI updates with rollback

---

## Security Considerations

### Current Implementation
- CSRF protection enabled
- SQL injection protection (Eloquent ORM)
- XSS protection (Blade templates)
- Authorization checks in controllers
- Role-based access control (Spatie)

### Recommendations
1. Add rate limiting to booking endpoints
2. Implement booking fraud detection (multiple rapid bookings)
3. Add input sanitization for notes fields
4. Implement audit logging for admin actions
5. Add two-factor authentication for staff/admin
6. Configure proper CORS for API endpoints
7. Implement webhook signature verification for payments

---

## Localization

**Current Language:** Polish (pl)

**Localized Elements:**
- Filament resource labels
- Appointment status labels
- Validation error messages
- Success messages

**Not Localized:**
- Database content (services, descriptions)
- Email templates (not yet implemented)

**Recommendation:** Implement Laravel's localization for multi-language support if targeting broader market.

---

## Conclusion

The Registro booking system has a solid foundation with clean MVC architecture and good separation of concerns. The existing implementation handles basic booking functionality well, but requires significant enhancements to support a modern, conversion-optimized frontend experience.

**Architecture Strengths:**
- Clean service layer architecture
- Good use of query scopes
- Proper authorization checks
- Well-indexed database
- Comprehensive Filament admin panel

**Architecture Weaknesses:**
- Missing critical business features (add-ons, tiers, payments)
- No notification system
- Limited customer data collection
- No exception handling for availability
- Fixed 15-minute slot intervals (not configurable)

**Frontend Integration Readiness:** 60%

The backend can support a basic modern frontend, but will need the critical and high-priority enhancements listed above to deliver a truly competitive booking experience matching 2024-2025 industry standards.
