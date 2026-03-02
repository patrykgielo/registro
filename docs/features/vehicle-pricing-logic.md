# Vehicle Type Pricing Logic (TODO - Future Iteration)

**Status:** 🔴 Not Implemented (Planned for next iteration)
**Priority:** MEDIUM
**Created:** 2025-10-31
**Related:** Vehicle Management System

---

## Requirements

Client requirement: **Typ pojazdu wpływa na cenę usługi**

Example:
- Auto miejskie → Mycie wnętrza → 150 zł
- Auto dostawcze → Mycie wnętrza → 250 zł

---

## Proposed Architecture

### 1. Database Schema

#### Pivot Table: `service_vehicle_type_prices`
```sql
CREATE TABLE service_vehicle_type_prices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id BIGINT UNSIGNED NOT NULL,
    vehicle_type_id BIGINT UNSIGNED NOT NULL,
    price_multiplier DECIMAL(5,2) NULL,  -- e.g., 1.5 for delivery vans
    fixed_price DECIMAL(10,2) NULL,      -- OR fixed price override
    created_at TIMESTAMP,
    updated_at TIMESTAMP,

    UNIQUE KEY unique_service_vehicle_type (service_id, vehicle_type_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id) ON DELETE CASCADE
)
```

**Validation:** At least ONE of `price_multiplier` OR `fixed_price` must be provided.

---

### 2. Pricing Logic

#### Option A: Price Multiplier (Recommended)
```php
// Service has base_price = 150 zł
// Vehicle Type "Auto dostawcze" has multiplier = 1.5
// Final price = 150 * 1.5 = 225 zł
```

#### Option B: Fixed Price Override
```php
// Service has base_price = 150 zł
// Vehicle Type "Auto dostawcze" has fixed_price = 250 zł
// Final price = 250 zł (ignores base price)
```

**Priority:** Fixed price > Multiplier > Base price

---

### 3. Backend Implementation

#### Model: `ServiceVehicleTypePrice`
```php
class ServiceVehicleTypePrice extends Model
{
    protected $fillable = [
        'service_id',
        'vehicle_type_id',
        'price_multiplier',
        'fixed_price',
    ];

    protected $casts = [
        'price_multiplier' => 'decimal:2',
        'fixed_price' => 'decimal:2',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function calculatePrice(float $basePrice): float
    {
        if ($this->fixed_price) {
            return (float) $this->fixed_price;
        }

        if ($this->price_multiplier) {
            return $basePrice * (float) $this->price_multiplier;
        }

        return $basePrice;
    }
}
```

#### API Endpoint: Calculate Price
```php
// GET /api/service-price?service_id=1&vehicle_type_id=2
public function calculateServicePrice(Request $request): JsonResponse
{
    $request->validate([
        'service_id' => 'required|exists:services,id',
        'vehicle_type_id' => 'required|exists:vehicle_types,id',
    ]);

    $service = Service::findOrFail($request->service_id);
    $basePrice = $service->price;

    // Check for vehicle type pricing override
    $pricing = ServiceVehicleTypePrice::where('service_id', $request->service_id)
        ->where('vehicle_type_id', $request->vehicle_type_id)
        ->first();

    $finalPrice = $pricing
        ? $pricing->calculatePrice($basePrice)
        : $basePrice;

    return response()->json([
        'success' => true,
        'data' => [
            'base_price' => $basePrice,
            'vehicle_type_id' => $request->vehicle_type_id,
            'final_price' => $finalPrice,
            'has_override' => $pricing !== null,
            'multiplier' => $pricing?->price_multiplier,
            'fixed_price' => $pricing?->fixed_price,
        ],
    ]);
}
```

---

### 4. Frontend Integration

#### Booking Wizard (Step 3)
When user selects vehicle type:
```javascript
// In selectVehicleType() function
async function selectVehicleType(type) {
    // ... existing code ...

    // Fetch updated price
    await updateServicePrice();
}

async function updateServicePrice() {
    if (!state.service.id || !state.vehicle.type_id) return;

    try {
        const url = `/api/service-price?service_id=${state.service.id}&vehicle_type_id=${state.vehicle.type_id}`;
        const response = await fetch(url);
        const data = await response.json();

        if (data.success) {
            // Update price in sidebar and summary
            state.service.calculated_price = data.data.final_price;
            updateSidebar();
            updateSummary();
        }
    } catch (error) {
        console.error('Error fetching price:', error);
    }
}
```

#### UI Updates
- Sidebar price updates in real-time when vehicle type selected
- Step 4 summary shows breakdown:
  - Base price: 150 zł
  - Vehicle type multiplier: 1.5x
  - **Final price: 225 zł**

---

### 5. Filament Admin Panel

#### ServiceResource: Relation Manager
Add `PricingRelationManager` to `ServiceResource`:

```php
// app/Filament/Resources/ServiceResource/RelationManagers/PricingRelationManager.php
class PricingRelationManager extends RelationManager
{
    protected static string $relationship = 'vehicleTypePrices';

    protected static ?string $title = 'Ceny według typu pojazdu';

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('vehicle_type_id')
                ->label('Typ pojazdu')
                ->relationship('vehicleType', 'name')
                ->required(),
            TextInput::make('price_multiplier')
                ->label('Mnożnik ceny')
                ->numeric()
                ->step(0.01)
                ->minValue(0.01)
                ->helperText('Np. 1.5 = +50%'),
            TextInput::make('fixed_price')
                ->label('Cena stała')
                ->numeric()
                ->step(0.01)
                ->minValue(0)
                ->helperText('Opcjonalnie - zastępuje mnożnik'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('vehicleType.name')->label('Typ pojazdu'),
            TextColumn::make('price_multiplier')->label('Mnożnik'),
            TextColumn::make('fixed_price')->label('Cena stała')->money('PLN'),
        ]);
    }
}
```

---

## Implementation Checklist (Future)

### Database
- [ ] Create migration: `create_service_vehicle_type_prices_table`
- [ ] Create model: `ServiceVehicleTypePrice`
- [ ] Add relationship to `Service` model: `vehicleTypePrices()`
- [ ] Add relationship to `VehicleType` model: `servicePrices()`

### Backend
- [ ] Create API endpoint: `GET /api/service-price`
- [ ] Add validation: at least one of multiplier OR fixed_price required
- [ ] Update `AppointmentController` to store calculated price

### Frontend
- [ ] Update `booking-wizard.js`: add `updateServicePrice()` function
- [ ] Update sidebar to show dynamic price
- [ ] Update Step 4 summary with price breakdown
- [ ] Add loading state during price calculation

### Admin Panel
- [ ] Create `PricingRelationManager` for ServiceResource
- [ ] Add table columns: vehicle_type, multiplier, fixed_price
- [ ] Add form validation: required multiplier OR fixed_price
- [ ] Test bulk create/edit pricing

### Testing
- [ ] Unit test: `calculatePrice()` method
- [ ] Feature test: API endpoint returns correct price
- [ ] Feature test: Booking wizard updates price on vehicle type change
- [ ] Feature test: Admin can manage service pricing

---

## Example Data

```php
// Service: Mycie wnętrza (base price: 150 zł)

ServiceVehicleTypePrice::create([
    'service_id' => 1, // Mycie wnętrza
    'vehicle_type_id' => 1, // Auto miejskie
    'price_multiplier' => 1.0, // No change
]);

ServiceVehicleTypePrice::create([
    'service_id' => 1,
    'vehicle_type_id' => 4, // Auto duże / SUV / Van
    'price_multiplier' => 1.3, // +30%
]);

ServiceVehicleTypePrice::create([
    'service_id' => 1,
    'vehicle_type_id' => 5, // Auto dostawcze
    'fixed_price' => 250.00, // Fixed price override
]);
```

**Result:**
- Auto miejskie → 150 zł (base price)
- Auto duże/SUV → 195 zł (150 * 1.3)
- Auto dostawcze → 250 zł (fixed override)

---

## Technical Considerations

### Performance
- Cache pricing data to avoid repeated DB queries
- Fetch pricing when user loads booking wizard (preload all prices for selected service)

### UX
- Show price update animation when vehicle type changes
- Display "Cena standardowa" vs "Cena dla tego typu pojazdu"
- Add tooltip explaining price difference

### Edge Cases
- What if no pricing override exists? → Use base price
- What if service has no base price? → Require pricing override for all types
- What if pricing data is incomplete? → Fallback to base price + warning log

---

## Migration Path

1. **Database**: Run migration (non-breaking, new table only)
2. **Admin Panel**: Allow admins to configure pricing
3. **Backend**: Deploy API endpoint (backward compatible)
4. **Frontend**: Update booking wizard (feature flag controlled)
5. **Testing**: Verify pricing calculation accuracy
6. **Rollout**: Enable feature flag for production

**Estimated effort:** 4-6 hours
**Risk level:** LOW (additive feature, no breaking changes)

---

## Notes

- This feature does NOT affect existing appointments
- Pricing is calculated at booking time and stored in appointment record
- Future: Consider seasonal pricing, discounts, promotions
- Future: Admin dashboard to visualize pricing strategy
