# Vehicle Management System

System zarządzania pojazdami - typ pojazdu, marka, model, rok.

## Quick Overview

- **5 Vehicle Types** (seeded): city_car, small_car, medium_car, large_car, delivery_van
- **Dynamic Brands & Models** (admin-managed via Filament)
- **Many-to-Many** relation: Vehicle Type ↔ Car Model (pivot table)
- **Customer Declaration** - typ pojazdu to deklaracja klienta, nie wymuszamy validacji

## Database Schema

```sql
vehicle_types (5 seeded types)
car_brands (admin-managed)
car_models (admin-managed)
vehicle_type_car_model (pivot)
appointments (vehicle_type_id, car_brand_id, car_model_id, vehicle_year)
```

## Booking Integration

**Booking Wizard - Step 3:**
1. Customer selects Vehicle Type (card UI)
2. Selects Brand from dropdown (all brands - NO filtering by type)
3. Selects Model from dropdown (all models for selected brand - NO filtering by type)
4. Selects Year (1990-2025)

**API Endpoints:**
```
GET /api/vehicle-types  → All active types
GET /api/car-brands     → All active brands
GET /api/car-models?car_brand_id={id} → Models for brand
GET /api/vehicle-years  → [2025...1990]
```

## Filament Resources

### VehicleTypeResource
- Edit only (no create - seeded data)
- Fields: name, slug, examples, sort_order, is_active

### CarBrandResource
- Full CRUD
- Status: pending/active/inactive
- Bulk approve action

### CarModelResource
- Full CRUD with vehicle type assignment (checkboxes)
- Belongs to CarBrand
- Many-to-many with VehicleTypes via pivot

## Backend Validation

**AppointmentController@store** validates:

```php
'vehicle_type_id' => 'required|exists:vehicle_types,id',
'car_brand_id' => 'nullable|exists:car_brands,id',
'car_brand_name' => 'nullable|string|max:100',  // For custom
'car_model_id' => 'nullable|exists:car_models,id',
'car_model_name' => 'nullable|string|max:100',  // For custom
'vehicle_year' => 'required|integer|min:1990|max:' . (date('Y') + 1),
```

**Logic:**
- If `car_brand_id` provided → use database brand
- Otherwise → save `car_brand_name` as custom value
- If `car_model_id` provided → use database model
- Otherwise → save `car_model_name` as custom value
- Vehicle type always required (customer declaration)

## Filament Admin Panel

**Navigation Group:** "Cars" (3 resources)

### VehicleTypeResource

- **Icon:** `heroicon-o-tag`
- **Location:** `app/Filament/Resources/VehicleTypeResource.php`
- **Features:**
  - **Edit only** (no Create - seeded data from VehicleTypeSeeder)
  - Cannot delete (prevents breaking appointment references)
  - Toggle active/inactive status
  - Reorder with sort_order field

**Form Fields:**
- `name` (TextInput, required) - Display name (e.g., "Auto miejskie")
- `slug` (TextInput, disabled) - System identifier (e.g., "city_car")
- `description` (Textarea) - Type description
- `examples` (Textarea) - Example vehicles for this type
- `sort_order` (TextInput, numeric) - Display order in booking wizard
- `is_active` (Toggle) - Show/hide in booking flow

**Table Columns:**
- name, slug, examples (preview), sort_order, is_active (badge)

**Filters:**
- Active/Inactive toggle

**Bulk Actions:**
- Delete (restricted - only if no appointments reference this type)

**Important Notes:**
- 5 types seeded: city_car, small_car, medium_car, large_car, delivery_van
- Editing slug may break frontend integrations - avoid changing
- sort_order determines card display order in booking wizard Step 3

---

### CarBrandResource

- **Icon:** `heroicon-o-building-office`
- **Location:** `app/Filament/Resources/CarBrandResource.php`
- **Features:**
  - **Full CRUD** (Create, Read, Update, Delete)
  - Bulk approve action (pending → active)
  - Auto-slug generation from name
  - Status workflow: pending → active → inactive

**Form Fields:**
- `name` (TextInput, required) - Brand name (e.g., "Toyota")
- `slug` (TextInput) - Auto-generated from name, editable
- `status` (Select) - pending/active/inactive

**Table Columns:**
- name, slug, models_count (count relation), status (badge)

**Filters:**
- Status (All, Pending, Active, Inactive)

**Bulk Actions:**
- Delete (cascade deletes models)
- **Approve** - Set status to 'active' for pending brands

**Status Badge Colors:**
- pending: warning (yellow)
- active: success (green)
- inactive: danger (gray)

**Auto-Slug Logic:**
- Generated on create from name (Str::slug)
- Example: "Volkswagen" → "volkswagen"
- Editable after generation
- Must be unique

**Use Cases:**
- **Pending status:** Customer submits unknown brand via custom field → Admin creates brand → Assigns to pending → Reviews → Approves
- **Active status:** Brand available in booking wizard dropdowns
- **Inactive status:** Discontinued brands hidden from dropdowns but preserved in historical data

---

### CarModelResource

- **Icon:** `heroicon-o-truck`
- **Location:** `app/Filament/Resources/CarModelResource.php`
- **Features:**
  - **Full CRUD** with vehicle type assignment
  - Belongs to CarBrand (required)
  - Many-to-many with VehicleTypes via `vehicle_type_car_model` pivot
  - Quick-create brand modal from form
  - Bulk approve action
  - Filter by brand, vehicle type, status

**Form Fields:**
- `car_brand_id` (Select, required) - Brand selection with search
  - **Quick Create:** "+" button to create brand inline
- `name` (TextInput, required) - Model name (e.g., "Corolla")
- `slug` (TextInput) - Auto-generated, editable
- `vehicleTypes` (CheckboxList) - Assign to one or multiple types
  - Displays: city_car, small_car, medium_car, large_car, delivery_van
  - Multiple selection allowed (e.g., Golf → small_car + medium_car)
- `year_from` (TextInput, numeric) - First production year
- `year_to` (TextInput, numeric, nullable) - Last production year (null = still in production)
- `status` (Select) - pending/active/inactive

**Table Columns:**
- brand.name (relation, searchable)
- name (searchable)
- vehicleTypes (badges, multiple)
- year_from - year_to (formatted)
- status (badge)

**Filters:**
- **Brand** (SelectFilter) - Show models for specific brand
- **Vehicle Type** (SelectFilter) - Show models assigned to specific type
- **Status** (SelectFilter) - pending/active/inactive

**Bulk Actions:**
- Delete (checks for appointment references)
- **Approve** - Set status to 'active' for pending models

**Relationships:**
- **vehicleTypes()** - Synced via CheckboxList (Filament handles pivot)
- **brand()** - BelongsTo (required foreign key)

**Important Notes:**
- Slug unique per brand (database constraint: unique(car_brand_id, slug))
- Same model name can exist for different brands (e.g., "Transit" for Ford, Mercedes)
- Vehicle type assignment is for **reference only** - NOT enforced in booking validation
  - Rationale: Customer declares vehicle type; admin assigns types for catalog organization
  - Example: Toyota Corolla can be both small_car (1990s) and medium_car (2010s+)

**Quick Create Brand:**
- Click "+" next to brand dropdown
- Modal opens with CarBrand form
- Save → Brand immediately available in dropdown
- Newly created brand has status 'pending' by default

---

### AppointmentResource (updated)

**Vehicle Section Added:**

**Form Section:** "Pojazd" (collapsed by default)

**Form Fields:**
- `vehicle_type_id` (Select, required) - Displays all active vehicle types
- `car_brand_id` (Select) - Displays all active brands (searchable)
- `car_model_id` (Select) - Displays models for selected brand (dependent dropdown)
- `vehicle_year` (Select) - Year range 1990 to current_year + 1
- `vehicle_custom_brand` (TextInput, read-only) - Shows if customer entered custom brand
- `vehicle_custom_model` (TextInput, read-only) - Shows if customer entered custom model

**Table Column:**
- `vehicle_display` (Text, toggleable) - Computed accessor
  - Format: "Toyota Corolla (2020)"
  - Falls back to custom values if database IDs null
  - Example: "Custom Brand Custom Model (2015)"

**Dependent Dropdown Logic:**
- When `car_brand_id` changes → `car_model_id` dropdown filters to show only models for that brand
- Uses Filament reactive() method
- `car_model_id` resets when brand changes

**Display in View:**
- Vehicle info shown in appointment details
- Links to VehicleType/CarBrand/CarModel resources (if admin has permission)

## Troubleshooting

### Vehicle Types Not Loading in Booking Wizard

**Symptoms:**
- Step 3 of booking wizard shows no vehicle type cards
- Browser console shows 200 OK but empty data

**Diagnosis:**
```bash
# Check if types were seeded
docker compose exec mysql mysql -u registro -ppassword -e \
  "SELECT id, name, slug, is_active FROM vehicle_types;" \
  registro

# Should show 5 types with is_active = 1
```

**Solutions:**
1. Run seeder:
   ```bash
   docker compose exec app php artisan db:seed --class=VehicleTypeSeeder
   ```

2. Check if types marked inactive:
   ```sql
   UPDATE vehicle_types SET is_active = 1;
   ```

3. Verify API endpoint returns data:
   ```bash
   curl -H "Cookie: laravel_session=..." https://registro.local:8444/api/vehicle-types
   ```

---

### Brands Not Showing in Dropdown

**Symptoms:**
- Vehicle type selected, but brand dropdown remains empty
- No JavaScript errors in console

**Diagnosis:**
```bash
# Check if brands exist with active status
docker compose exec mysql mysql -u registro -ppassword -e \
  "SELECT id, name, status FROM car_brands WHERE status='active';" \
  registro
```

**Solutions:**
1. Add sample brands via Tinker:
   ```bash
   docker compose exec app php artisan tinker
   >>> CarBrand::create(['name' => 'Toyota', 'slug' => 'toyota', 'status' => 'active']);
   >>> CarBrand::create(['name' => 'Volkswagen', 'slug' => 'volkswagen', 'status' => 'active']);
   >>> CarBrand::create(['name' => 'Ford', 'slug' => 'ford', 'status' => 'active']);
   >>> exit
   ```

2. Activate pending brands via Filament:
   - Admin Panel → Cars → Car Brands
   - Filter: Status = Pending
   - Select all → Bulk Actions → Approve

3. Check API endpoint:
   ```bash
   curl -H "Cookie: ..." https://registro.local:8444/api/car-brands
   # Should return array of brands with status='active'
   ```

---

### Models Empty After Brand Selection

**Symptoms:**
- Brand dropdown works, model dropdown remains empty after selection
- Backend returns 200 OK but empty array

**Diagnosis:**
```bash
# Check if models exist for selected brand
docker compose exec mysql mysql -u registro -ppassword -e \
  "SELECT cm.id, cm.name, cm.car_brand_id, cm.status, cb.name as brand_name
   FROM car_models cm
   LEFT JOIN car_brands cb ON cm.car_brand_id = cb.id
   WHERE cm.status='active'
   ORDER BY cb.name, cm.name;" \
  registro
```

**Root Cause:**
- No models created for this brand in database
- Models exist but have status='pending' or 'inactive'

**Solutions:**
1. Add sample models via Tinker:
   ```bash
   docker compose exec app php artisan tinker
   >>> $toyota = CarBrand::where('slug', 'toyota')->first();
   >>> $corolla = CarModel::create([
         'car_brand_id' => $toyota->id,
         'name' => 'Corolla',
         'slug' => 'corolla',
         'year_from' => 1966,
         'year_to' => null,
         'status' => 'active'
       ]);
   >>> $corolla->vehicleTypes()->attach([2, 3]); // small_car + medium_car
   >>> exit
   ```

2. Activate pending models via Filament:
   - Admin Panel → Cars → Car Models
   - Filter: Status = Pending, Brand = Toyota
   - Select all → Bulk Actions → Approve

3. **Important:** Assign models to vehicle types (optional but recommended):
   - Edit model in Filament
   - Check at least one vehicle type checkbox
   - This is for catalog organization only (not enforced in booking)

---

### Validation Fails: "Wybierz typ pojazdu"

**Symptoms:**
- Frontend validation prevents Step 3 → Step 4 navigation
- Error message: "Wybierz typ pojazdu" (Select vehicle type)
- Vehicle type card appears selected visually but validation still fails

**Diagnosis:**
- Check browser console for `state.vehicle` object:
  ```javascript
  console.log(state.vehicle);
  // Should show: { type_id: 1, brand_id: 2, model_id: 5, year: 2020 }
  ```

**Root Causes:**
1. JavaScript event handler not firing on card click
2. `state.vehicle.type_id` not being set
3. Validation running before state update completes

**Solutions:**
1. Check JavaScript errors:
   ```javascript
   // Open browser DevTools → Console
   // Look for errors in booking-wizard.js
   ```

2. Verify event handler attached:
   ```javascript
   // In booking-wizard.js, ensure selectVehicleType() is called:
   document.querySelectorAll('[data-vehicle-type]').forEach(card => {
       card.addEventListener('click', (e) => {
           const typeId = e.currentTarget.dataset.vehicleType;
           selectVehicleType(JSON.parse(e.currentTarget.dataset.vehicleTypeData));
       });
   });
   ```

3. Add debug logging:
   ```javascript
   function selectVehicleType(type) {
       console.log('Selecting vehicle type:', type);
       state.vehicle.type_id = type.id;
       console.log('State after selection:', state.vehicle);
   }
   ```

---

### API Returns 403 Forbidden / Vehicle Selection Not Rendering

**Symptoms:**
- Browser console shows: `403 Forbidden` on `/api/vehicle-types`, `/api/car-brands`, `/api/car-models`
- Vehicle type cards don't render in Step 3
- Brand/model dropdowns remain empty
- JavaScript `fetchVehicleTypes()` fails silently

**Root Cause:**
Vehicle API endpoints have overly restrictive middleware (`role:super-admin`) that blocks regular authenticated users.

**Diagnosis:**
```bash
# Check route middleware
php artisan route:list --path=api/vehicle
php artisan route:list --path=api/car

# Look for middleware column showing: web,auth,role:super-admin
```

**Fix in `routes/web.php`:**

```php
// ❌ WRONG - blocks regular users (customers, staff)
Route::prefix('api')->name('api.')->middleware(['auth', 'role:super-admin'])->group(function () {
    Route::get('/vehicle-types', [VehicleDataController::class, 'vehicleTypes']);
    Route::get('/car-brands', [VehicleDataController::class, 'brands']);
    Route::get('/car-models', [VehicleDataController::class, 'models']);
    Route::get('/vehicle-years', [VehicleDataController::class, 'years']);
});

// ✅ CORRECT - allows all authenticated users
Route::prefix('api')->name('api.')->middleware(['auth'])->group(function () {
    Route::get('/vehicle-types', [VehicleDataController::class, 'vehicleTypes']);
    Route::get('/car-brands', [VehicleDataController::class, 'brands']);
    Route::get('/car-models', [VehicleDataController::class, 'models']);
    Route::get('/vehicle-years', [VehicleDataController::class, 'years']);
});
```

**Why `auth` only is correct:**
- These endpoints are **read-only** (no data modification)
- They return public catalog data (vehicle types, brands, models)
- Required for normal booking flow (all logged-in users create appointments)
- Data is not sensitive (equivalent to public car catalogs)
- Only authenticated users can book (already protected by booking wizard auth)

**After fix:**
```bash
# Clear route cache
php artisan optimize:clear

# Verify routes updated
php artisan route:list --path=api/vehicle

# Test as regular user (not super-admin)
# Login as customer → Navigate to /booking/create → Step 3 should render
```

**Security Note:**
If you need to restrict vehicle data management (Create, Update, Delete), apply permissions in:
- Filament Resources (already done - requires `manage vehicles` permission)
- Admin panel access (already done - `canAccessPanel()` method)

API read endpoints should remain open to authenticated users for functional booking flow.

---

## Seeding Data

### Vehicle Types (Required)

```bash
# Seed 5 vehicle types (city_car, small_car, medium_car, large_car, delivery_van)
docker compose exec app php artisan db:seed --class=VehicleTypeSeeder
```

**Seeded Types:**
1. **city_car** - Auto miejskie (small city cars: Fiat 500, Smart, VW Up)
2. **small_car** - Auto małe (compact: Ford Fiesta, VW Polo, Toyota Yaris)
3. **medium_car** - Auto średnie (mid-size: VW Golf, Toyota Corolla, Ford Focus)
4. **large_car** - Auto duże (large: BMW 5, Audi A6, Mercedes E-Class)
5. **delivery_van** - Samochód dostawczy (vans: VW Transporter, Ford Transit, Mercedes Sprinter)

**Important:**
- Run seeder **after every `migrate:fresh`**
- These types are referenced in booking wizard frontend code
- Changing slugs will break frontend integration
- Only mark inactive (don't delete) if you want to hide types

### Add Brands & Models Manually

**Via Tinker:**

```bash
docker compose exec app php artisan tinker
```

```php
// Create brands
$toyota = CarBrand::create(['name' => 'Toyota', 'slug' => 'toyota', 'status' => 'active']);
$vw = CarBrand::create(['name' => 'Volkswagen', 'slug' => 'volkswagen', 'status' => 'active']);
$ford = CarBrand::create(['name' => 'Ford', 'slug' => 'ford', 'status' => 'active']);

// Create models for Toyota
$corolla = CarModel::create([
    'car_brand_id' => $toyota->id,
    'name' => 'Corolla',
    'slug' => 'corolla',
    'year_from' => 1966,
    'year_to' => null, // Still in production
    'status' => 'active',
]);

// Assign to vehicle types (2 = small_car, 3 = medium_car)
$corolla->vehicleTypes()->attach([2, 3]);

// Create more models
$yaris = CarModel::create([
    'car_brand_id' => $toyota->id,
    'name' => 'Yaris',
    'slug' => 'yaris',
    'year_from' => 1999,
    'year_to' => null,
    'status' => 'active',
]);
$yaris->vehicleTypes()->attach([2]); // Only small_car

$golf = CarModel::create([
    'car_brand_id' => $vw->id,
    'name' => 'Golf',
    'slug' => 'golf',
    'year_from' => 1974,
    'year_to' => null,
    'status' => 'active',
]);
$golf->vehicleTypes()->attach([2, 3]); // small_car + medium_car

exit
```

**Via Filament Admin Panel:**

1. Navigate to `/admin/cars/brands`
2. Click "New Car Brand"
3. Enter: Name = "BMW", Slug = "bmw" (auto-generated), Status = "active"
4. Save

5. Navigate to `/admin/cars/models`
6. Click "New Car Model"
7. Select Brand: BMW
8. Enter: Name = "3 Series", Slug = "3-series"
9. Check Vehicle Types: medium_car, large_car
10. Year From: 1975, Year To: (leave empty)
11. Status: active
12. Save

## Usage in Code

### Accessing Vehicle Data in Controllers

```php
use App\Models\Appointment;

$appointment = Appointment::with(['vehicleType', 'carBrand', 'carModel'])->find($id);

// Get formatted vehicle string
echo $appointment->vehicle_display;
// Output: "Toyota Corolla (2020)"

// Get individual fields
echo $appointment->vehicleType->name;    // "Auto średnie"
echo $appointment->carBrand->name;       // "Toyota"
echo $appointment->carModel->full_name;  // "Toyota Corolla"
echo $appointment->vehicle_year;          // 2020

// Handle custom brand/model (when not in database)
if ($appointment->vehicle_custom_brand) {
    echo "Custom brand: " . $appointment->vehicle_custom_brand;
}

if ($appointment->vehicle_custom_model) {
    echo "Custom model: " . $appointment->vehicle_custom_model;
}
```

### Querying Appointments by Vehicle

```php
// Find all appointments for specific vehicle type
$deliveryVanAppointments = Appointment::whereHas('vehicleType', function ($query) {
    $query->where('slug', 'delivery_van');
})->get();

// Find appointments for specific brand
$toyotaAppointments = Appointment::whereHas('carBrand', function ($query) {
    $query->where('name', 'Toyota');
})->get();

// Find appointments for specific model
$corollaAppointments = Appointment::whereHas('carModel', function ($query) {
    $query->where('slug', 'corolla');
})->get();

// Find appointments with custom vehicles (pending approval)
$customVehicles = Appointment::query()
    ->whereNotNull('vehicle_custom_brand')
    ->orWhereNotNull('vehicle_custom_model')
    ->get();

// Admin can review and create brand/model from these
```

### Getting Models for Specific Brand (API)

```php
// VehicleDataController@models method
public function models(Request $request)
{
    $brandId = $request->query('car_brand_id');

    $models = CarModel::query()
        ->active() // scope: where('status', 'active')
        ->when($brandId, fn($q) => $q->forBrand($brandId)) // scope
        ->with('brand')
        ->orderBy('name')
        ->get()
        ->map(fn($model) => [
            'id' => $model->id,
            'name' => $model->name,
            'full_name' => $model->full_name, // "Toyota Corolla"
            'year_from' => $model->year_from,
            'year_to' => $model->year_to,
        ]);

    return response()->json($models);
}
```

## Quick Commands

```bash
# Seed vehicle types (required after migrate:fresh)
docker compose exec app php artisan db:seed --class=VehicleTypeSeeder

# Add sample data via Tinker
docker compose exec app php artisan tinker
>>> $vw = CarBrand::create(['name' => 'Volkswagen', 'slug' => 'volkswagen', 'status' => 'active']);
>>> $golf = CarModel::create(['car_brand_id' => $vw->id, 'name' => 'Golf', 'slug' => 'golf', 'status' => 'active']);
>>> $golf->vehicleTypes()->attach([2, 3]); // Assign to small_car + medium_car

# Check appointments with vehicle data
docker compose exec mysql mysql -u registro -ppassword -e \
  "SELECT a.id, vt.name as type, cb.name as brand, cm.name as model, a.vehicle_year
   FROM appointments a
   LEFT JOIN vehicle_types vt ON a.vehicle_type_id = vt.id
   LEFT JOIN car_brands cb ON a.car_brand_id = cb.id
   LEFT JOIN car_models cm ON a.car_model_id = cm.id
   WHERE a.vehicle_type_id IS NOT NULL
   ORDER BY a.created_at DESC
   LIMIT 10;" \
  registro

# Check vehicle type assignments for a model
docker compose exec mysql mysql -u registro -ppassword -e \
  "SELECT cm.name as model, vt.name as type
   FROM car_models cm
   INNER JOIN vehicle_type_car_model vtcm ON cm.id = vtcm.car_model_id
   INNER JOIN vehicle_types vt ON vtcm.vehicle_type_id = vt.id
   WHERE cm.slug = 'golf';" \
  registro

# Find custom vehicles needing admin review
docker compose exec mysql mysql -u registro -ppassword -e \
  "SELECT id, vehicle_custom_brand, vehicle_custom_model, vehicle_year, created_at
   FROM appointments
   WHERE vehicle_custom_brand IS NOT NULL OR vehicle_custom_model IS NOT NULL
   ORDER BY created_at DESC
   LIMIT 10;" \
  registro
```

## See Also

- [Booking System](../booking-system/README.md) - Wizard integration with vehicle selection
- [API Reference](../../architecture/api-reference.md) - Vehicle API endpoints
- [Database Schema](../../architecture/database-schema.md) - Vehicle tables
