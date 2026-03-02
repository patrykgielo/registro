# Database Schema

**Last Updated:** November 2025
**Database:** MySQL 8.0 (Docker)
**Connection:** `registro-mysql`

## Overview

This document describes the complete database structure for the Registro application. The schema supports user authentication, appointment booking, vehicle management, email system, and system settings.

## Connection Details

```php
// .env configuration
DB_CONNECTION=mysql
DB_HOST=registro-mysql
DB_PORT=3306
DB_DATABASE=registro
DB_USERNAME=registro
DB_PASSWORD=password
```

## Quick MySQL Access

```bash
# Interactive MySQL shell
docker compose exec mysql mysql -u registro -ppassword registro

# Check table structure
docker compose exec mysql mysql -u registro -ppassword -e "DESCRIBE table_name;" registro

# Run query
docker compose exec mysql mysql -u registro -ppassword -e "SELECT * FROM users LIMIT 5;" registro
```

## Core Tables

### Users Table

```sql
users (
  id: bigint primary key,
  first_name: varchar(100),
  last_name: varchar(100),
  email: varchar(255) unique,
  email_verified_at: timestamp nullable,
  password: varchar(255),
  remember_token: varchar(100) nullable,
  created_at: timestamp,
  updated_at: timestamp
)
```

**Important:** No `name` column exists. Use `name` accessor in User model which concatenates `first_name` and `last_name`.

**Indexes:**
- `PRIMARY KEY (id)`
- `UNIQUE KEY email (email)`

**See Also:** [User Model Documentation](./user-model.md)

### Appointments Table

```sql
appointments (
  id: bigint primary key,
  user_id: bigint FK → users.id,
  service_id: bigint FK → services.id,
  vehicle_type_id: bigint FK → vehicle_types.id nullable,
  car_brand_id: bigint FK → car_brands.id nullable,
  car_model_id: bigint FK → car_models.id nullable,
  vehicle_year: year nullable,
  vehicle_custom_brand: varchar(100) nullable,
  vehicle_custom_model: varchar(100) nullable,

  -- Legacy address fields (pre-Google Maps integration)
  street_name: varchar(255) nullable,
  city: varchar(100) nullable,
  postal_code: varchar(20) nullable,

  -- Google Maps location fields (November 2025)
  location_address: varchar(500) nullable,
  location_latitude: double(10,8) nullable,
  location_longitude: double(11,8) nullable,
  location_place_id: varchar(255) nullable,
  location_components: json nullable,

  scheduled_at: datetime,
  completed_at: datetime nullable,
  status: enum('pending', 'confirmed', 'in_progress', 'completed', 'cancelled'),
  notes: text nullable,

  -- Email reminder tracking (November 2025)
  reminder_24h_sent_at: datetime nullable,
  reminder_2h_sent_at: datetime nullable,

  created_at: timestamp,
  updated_at: timestamp,
  deleted_at: timestamp nullable
)
```

**Indexes:**
- `PRIMARY KEY (id)`
- `INDEX user_id (user_id)`
- `INDEX service_id (service_id)`
- `INDEX vehicle_type_id (vehicle_type_id)`
- `INDEX location_coords_index (location_latitude, location_longitude)`
- `INDEX scheduled_at (scheduled_at)`
- `INDEX status (status)`
- `INDEX deleted_at (deleted_at)` (soft deletes)

## CMS Tables (Content Management System)

### Pages Table

```sql
pages (
  id: bigint primary key,
  title: varchar(255),
  slug: varchar(255) unique,
  body: text nullable,
  content: json nullable,
  layout: enum('default', 'full-width', 'minimal') default 'default',
  published_at: datetime nullable,
  meta_title: varchar(255) nullable,
  meta_description: text nullable,
  featured_image: varchar(255) nullable,
  created_at: timestamp,
  updated_at: timestamp
)
```

**Purpose:** Static content pages with customizable layouts (About Us, Services, Contact, etc.)

**Indexes:**
- `PRIMARY KEY (id)`
- `UNIQUE KEY slug (slug)`
- `INDEX published_at (published_at)`

**Notes:**
- Hybrid content: `body` (RichEditor) + `content` JSON (Builder blocks)
- `published_at` NULL = draft, <= now() = published
- Three layout options: default (with sidebars), full-width, minimal (narrow)

### Posts Table

```sql
posts (
  id: bigint primary key,
  title: varchar(255),
  slug: varchar(255) unique,
  excerpt: text nullable,
  body: text nullable,
  content: json nullable,
  category_id: bigint FK → categories.id nullable,
  published_at: datetime nullable,
  meta_title: varchar(255) nullable,
  meta_description: text nullable,
  featured_image: varchar(255) nullable,
  created_at: timestamp,
  updated_at: timestamp
)
```

**Purpose:** Blog posts and news articles with categories

**Indexes:**
- `PRIMARY KEY (id)`
- `UNIQUE KEY slug (slug)`
- `INDEX category_id (category_id)`
- `INDEX published_at (published_at)`

**Foreign Keys:**
- `category_id` references `categories(id)` ON DELETE SET NULL

**Notes:**
- `excerpt` displayed in post listings (optional)
- Category is optional but recommended for organization

### Promotions Table

```sql
promotions (
  id: bigint primary key,
  title: varchar(255),
  slug: varchar(255) unique,
  body: text nullable,
  content: json nullable,
  active: boolean default true,
  valid_from: datetime nullable,
  valid_until: datetime nullable,
  meta_title: varchar(255) nullable,
  meta_description: text nullable,
  featured_image: varchar(255) nullable,
  created_at: timestamp,
  updated_at: timestamp
)
```

**Purpose:** Special offers, discounts, and promotional campaigns

**Indexes:**
- `PRIMARY KEY (id)`
- `UNIQUE KEY slug (slug)`
- `INDEX active (active)`
- `INDEX valid_from (valid_from)`
- `INDEX valid_until (valid_until)`

**Notes:**
- `active` flag allows quick enable/disable without deleting
- Date range is optional (null = no time restrictions)
- Frontend only shows active=true AND within date range

### Portfolio Items Table

```sql
portfolio_items (
  id: bigint primary key,
  title: varchar(255),
  slug: varchar(255) unique,
  body: text nullable,
  content: json nullable,
  category_id: bigint FK → categories.id nullable,
  before_image: varchar(255) nullable,
  after_image: varchar(255) nullable,
  gallery: json nullable,
  published_at: datetime nullable,
  meta_title: varchar(255) nullable,
  meta_description: text nullable,
  created_at: timestamp,
  updated_at: timestamp
)
```

**Purpose:** Showcase completed detailing projects with before/after images

**Indexes:**
- `PRIMARY KEY (id)`
- `UNIQUE KEY slug (slug)`
- `INDEX category_id (category_id)`
- `INDEX published_at (published_at)`

**Foreign Keys:**
- `category_id` references `categories(id)` ON DELETE SET NULL

**JSON Fields:**
- `gallery` - Array of image file paths: `["portfolio/gallery/image1.jpg", "portfolio/gallery/image2.jpg"]`
- `content` - Builder blocks (typically client testimonials/quotes)

**Notes:**
- `before_image` and `after_image` are the hero feature
- `gallery` stores additional project photos as JSON array

### Categories Table

```sql
categories (
  id: bigint primary key,
  name: varchar(255),
  slug: varchar(255),
  description: text nullable,
  parent_id: bigint FK → categories.id nullable,
  type: enum('post', 'portfolio'),
  created_at: timestamp,
  updated_at: timestamp
)
```

**Purpose:** Hierarchical categories for Posts and Portfolio Items

**Indexes:**
- `PRIMARY KEY (id)`
- `UNIQUE KEY type_slug (type, slug)` - Slug unique per type
- `INDEX parent_id (parent_id)`
- `INDEX type (type)`

**Foreign Keys:**
- `parent_id` references `categories(id)` ON DELETE CASCADE

**Notes:**
- Supports nested categories (parent → children)
- `type` field separates Post categories from Portfolio categories
- Self-referencing relationship via `parent_id` (nullable for root categories)

## Vehicle Management Tables

### Vehicle Types Table

```sql
vehicle_types (
  id: bigint primary key,
  name: varchar(100),
  slug: varchar(100) unique,
  description: text nullable,
  examples: text nullable,
  sort_order: int default 0,
  is_active: boolean default true,
  created_at: timestamp,
  updated_at: timestamp
)
```

**Seeded Data:** 5 types (city_car, small_car, medium_car, large_car, delivery_van)

### Car Brands Table

```sql
car_brands (
  id: bigint primary key,
  name: varchar(100),
  slug: varchar(100) unique,
  status: enum('pending', 'active', 'inactive') default 'active',
  created_at: timestamp,
  updated_at: timestamp
)
```

### Car Models Table

```sql
car_models (
  id: bigint primary key,
  car_brand_id: bigint FK → car_brands.id,
  name: varchar(100),
  slug: varchar(100),
  year_from: int nullable,
  year_to: int nullable,
  status: enum('pending', 'active', 'inactive') default 'active',
  created_at: timestamp,
  updated_at: timestamp
)
```

**Indexes:**
- `INDEX car_brand_id (car_brand_id)`
- `UNIQUE KEY slug_per_brand (car_brand_id, slug)`

### Vehicle Type - Car Model Pivot Table

```sql
vehicle_type_car_model (
  id: bigint primary key,
  vehicle_type_id: bigint FK → vehicle_types.id,
  car_model_id: bigint FK → car_models.id,
  created_at: timestamp,
  updated_at: timestamp
)
```

**Indexes:**
- `INDEX vehicle_type_id (vehicle_type_id)`
- `INDEX car_model_id (car_model_id)`
- `UNIQUE KEY unique_assignment (vehicle_type_id, car_model_id)`

**Relationship:** Many-to-Many between VehicleType and CarModel

## Email System Tables

### Email Templates Table

```sql
email_templates (
  id: bigint primary key,
  key: varchar(100),
  language: varchar(10) default 'pl',
  subject: varchar(255),
  html_body: text,
  text_body: text nullable,
  blade_path: varchar(255) nullable,
  variables: json nullable,
  is_active: boolean default true,
  created_at: timestamp,
  updated_at: timestamp
)
```

**Indexes:**
- `UNIQUE KEY key_language (key, language)`

**Seeded Data:** 18 templates (9 types × PL/EN)

### Email Sends Table

```sql
email_sends (
  id: bigint primary key,
  template_key: varchar(100),
  recipient_email: varchar(255),
  recipient_name: varchar(255) nullable,
  subject: varchar(500),
  html_body: text,
  text_body: text nullable,
  metadata: json nullable,
  message_key: varchar(64) unique,
  status: enum('pending', 'sent', 'failed', 'bounced') default 'pending',
  sent_at: timestamp nullable,
  error_message: text nullable,
  created_at: timestamp,
  updated_at: timestamp
)
```

**Indexes:**
- `INDEX template_key (template_key)`
- `INDEX recipient_email (recipient_email)`
- `INDEX status (status)`
- `INDEX sent_at (sent_at)`
- `UNIQUE KEY message_key (message_key)`

**GDPR:** Logs older than 90 days are automatically deleted

### Email Events Table

```sql
email_events (
  id: bigint primary key,
  email_send_id: bigint FK → email_sends.id,
  event_type: enum('sent', 'delivered', 'bounced', 'complained', 'opened', 'clicked'),
  event_data: json nullable,
  occurred_at: timestamp,
  created_at: timestamp,
  updated_at: timestamp
)
```

**Indexes:**
- `INDEX email_send_id (email_send_id)`
- `INDEX event_type (event_type)`
- `INDEX occurred_at (occurred_at)`

### Email Suppressions Table

```sql
email_suppressions (
  id: bigint primary key,
  email: varchar(255) unique,
  reason: enum('bounced', 'complained', 'unsubscribed', 'manual'),
  notes: text nullable,
  created_at: timestamp,
  updated_at: timestamp
)
```

**Indexes:**
- `UNIQUE KEY email (email)`

**GDPR:** Never deleted (unsubscribe compliance)

## Settings Table

```sql
settings (
  id: bigint primary key,
  group: varchar(255),
  key: varchar(255),
  value: json,
  created_at: timestamp,
  updated_at: timestamp
)
```

**Indexes:**
- `INDEX group (group)`
- `INDEX key (key)`
- `UNIQUE KEY group_key (group, key)`

**Groups:**
- `booking` - Business hours, slot intervals, advance booking rules
- `map` - Google Maps default coordinates, zoom, country code
- `contact` - Email, phone, address
- `marketing` - Hero text, features, CTA content

## Services Table

```sql
services (
  id: bigint primary key,
  name: varchar(100),
  description: text nullable,
  duration_minutes: int,
  price: decimal(10,2),
  is_active: boolean default true,
  created_at: timestamp,
  updated_at: timestamp
)
```

## Relationships

### User → Appointments
- One-to-Many
- User can have multiple appointments
- Foreign key: `appointments.user_id → users.id`

### Service → Appointments
- One-to-Many
- Service can be used in multiple appointments
- Foreign key: `appointments.service_id → services.id`

### Vehicle Relationships
```
VehicleType (1) ←→ (N) vehicle_type_car_model (N) ←→ (1) CarModel (N) ←→ (1) CarBrand
     ↓                                                     ↓                    ↓
Appointment                                           Appointment          Appointment
```

### Email System Relationships
```
EmailTemplate (1) ← (N) EmailSend (1) ← (N) EmailEvent
                           ↓
                   EmailSuppression (check before sending)
```

## Migration Commands

```bash
# Run migrations (ALWAYS use docker compose exec app)
docker compose exec app php artisan migrate

# Rollback last migration
docker compose exec app php artisan migrate:rollback

# Fresh migrations with seeding
docker compose exec app php artisan migrate:fresh --seed

# ⚠️ CRITICAL: migrate:fresh --seed only runs DatabaseSeeder!
# You MUST manually run these additional seeders:
docker compose exec app php artisan db:seed --class=VehicleTypeSeeder
docker compose exec app php artisan db:seed --class=RolePermissionSeeder
docker compose exec app php artisan db:seed --class=ServiceAvailabilitySeeder
docker compose exec app php artisan db:seed --class=EmailTemplateSeeder
docker compose exec app php artisan db:seed --class=SettingSeeder

# Then recreate admin user
docker compose exec app php artisan make:filament-user
```

## Database Backups

```bash
# Backup database
docker compose exec mysql mysqldump -u registro -ppassword registro > backup_$(date +%Y%m%d).sql

# Restore database
docker compose exec -T mysql mysql -u registro -ppassword registro < backup_20251110.sql
```

## See Also

- [User Model](./user-model.md) - User model name accessor pattern
- [Vehicle Management](../features/vehicle-management/README.md) - Vehicle tables usage
- [Email System](../features/email-system/README.md) - Email tables usage
- [Settings System](../features/settings-system/README.md) - Settings table usage
- [Google Maps Integration](../features/google-maps/README.md) - Location fields usage
