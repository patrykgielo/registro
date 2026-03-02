# Vehicle-Based Dynamic Pricing Implementation Plan

**Date**: 2025-12-29
**Project**: Paradocks Car Detailing Booking System
**Feature**: Product Variant/Configurator Pattern for Service Pricing

---

## Executive Summary

This plan outlines the comprehensive implementation of **vehicle-based dynamic pricing** using a **product configurator pattern** similar to Apple Store or electronics shops. The user's vision requires customers to select vehicle type BEFORE entering the booking wizard, with prices displayed as variants (+X PLN) on the service page itself.

**Key Insight**: This is NOT just a backend feature. This fundamentally changes:
1. Service page UX (variant selector tiles)
2. Homepage service cards (display base price or price range)
3. Booking wizard (vehicle selection moves from Step 3 to Service Page)
4. Admin panel (price configuration per service+vehicle type)

---

## Part 1: Deep UX & Conversion Research Findings

### Research Methodology

Analyzed 8+ sources covering:
- Product configurator UX best practices (2025)
- SaaS pricing page tier selectors
- Car detailing booking system pricing structures
- Service variant selector patterns
- Mobile-first conversion optimization

### Key Finding #1: Multi-Dimensional Configuration Best Practices

**Source**: [Vervaunt eCommerce Product Builders](https://vervaunt.com/ecommerce-product-builders-configurable-products-considerations-ux-best-practices-examples)

**Critical Insights**:
- Multi-dimensional builders should be **split into steps** with clear journey and endpoint
- Having **very clear steps** (with tooltips) and activated CTA once options are selected creates effective experience
- **Pricing should be clearly updated** as item is configured
- On mobile, price should **always be visible** (sticky navigation bar top/bottom)
- Dynamic price updates provide **transparency and speed up decision-making**

**Application to Paradocks**:
- Vehicle type selector should be **first step on service page** (before "Zarezerwuj Termin")
- Price updates in real-time as vehicle type is selected
- Mobile: Sticky footer with price + CTA after selection

### Key Finding #2: Tiles vs Cards for Variant Selection

**Source**: [Mobbin Tile UI Design](https://mobbin.com/glossary/tile) + [Red Hat Design System](https://ux.redhat.com/elements/tile/guidelines/)

**Critical Distinctions**:
- **Tile purpose**: Allow users to **select one or more items** from a list of options
- **Card purpose**: Display collection of items for **viewing more information**
- **Tiles are commonly used for**: Price selection, filtering & sorting, selecting user preferences

**Design Rules**:
- Tiles have **selected and unselected state**
- Use **same variants** for a tile group (avoid mixing styles)
- Use **same number of content slots** to make them easy to scan
- Each tile should have **only one destination or action**

**Application to Paradocks**:
- Use **Selectable Tiles** (NOT cards) for vehicle type selection
- Each tile shows:
  - Vehicle type name ("Auto miejskie")
  - Price difference ("+150 PLN" or "Base Price")
  - Selected state indicator (border, background color, checkmark)
- Consistent layout across all 5 tiles

### Key Finding #3: Pricing Display Psychology

**Source**: [Smashing Magazine Pricing Plans UX](https://www.smashingmagazine.com/2022/07/designing-better-pricing-page/)

**Critical Insights**:
- **41.4% of successful startups use exactly 3 pricing tiers** (sweet spot for choice without overwhelm)
- **"Anchor, hero, decoy" pricing psychology**: Highest price makes middle tier appear more reasonable
- **12-15% increases in middle-tier selection** when properly implementing "Most Popular" badges
- Use **strong visual cues**: contrasting color, prominent border, "Most Popular" banner

**Application to Paradocks**:
- 5 vehicle types is acceptable (not overwhelming for this use case)
- Mark "Auto średnie" (medium car) as **"Najpopularniejsze"** (Most Popular)
- Use visual hierarchy: Most popular tile has accent color border
- Display price differences as **"+X PLN"** (not absolute prices) to anchor to base price

### Key Finding #4: Mobile Conversion Rate Optimization

**Source**: [UserPilot Pricing Page Best Practices](https://userpilot.com/blog/pricing-page-best-practices/)

**Critical Statistics**:
- **40-60% of pricing page traffic comes from mobile devices**
- **61% of users unlikely to return to mobile site with access problems**
- **40% will visit competitor's site** instead after bad mobile experience

**Mobile-Specific Design**:
- **Stacked pricing cards** (vertical layout on mobile)
- **Sticky CTAs** keep CTA visible at top/bottom while scrolling
- **Thumb-friendly touch targets**: Minimum **44px x 44px** (Apple standard)
- **Accordions for feature comparisons** to save vertical space

**Application to Paradocks**:
- Desktop: 5 tiles in row (if they fit) OR 2 rows of 2-3 tiles
- Mobile: **Vertical stack** of 5 tiles (full-width)
- Sticky footer on mobile: "Wybierz typ pojazdu" or "Zarezerwuj - 450 PLN" after selection
- Each tile: **Minimum 44px height** for touch target

### Key Finding #5: Real-Time Price Updates & Conversion Impact

**Source**: [Smashing Magazine Configurator UX](https://www.smashingmagazine.com/2018/02/designing-a-perfect-responsive-configurator/)

**Critical Insights**:
- During interaction, customers **switch back and forth** between product view and options
- Display **price next to product rendering** to avoid unnecessary jumps
- Keep price **updated in real-time** to avoid confusion or duplicate clicks
- **Cowboy Bikes example**: Clearly displays impact of each addon on cost and shipping time

**Application to Paradocks**:
- Real-time price calculation on client-side (no API call needed)
- Display format: **"Mycie Podstawowe - 450 PLN"** (updates as vehicle type changes)
- Show breakdown: "Cena bazowa: 300 PLN + Auto duże: +150 PLN = 450 PLN" (optional tooltip/modal)

### Key Finding #6: Car Detailing Industry Pricing Structure

**Source**: [Car Detailing Cost Guide 2025](https://topline-autospa.com/car-detailing-cost-guide/) + [HouseCall Pro Pricing Guide](https://www.housecallpro.com/resources/how-much-to-charge-for-car-detailing/)

**Industry Standards**:
- **Vehicle size remains primary consideration** for pricing
- **Larger vehicles (SUVs, trucks) command $100+ premium** due to surface area
- Pricing structure based on **vehicle size categories**:
  - Small/Compact: Coupes, compact cars, small sedans
  - Medium: Midsize sedans, compact SUVs, crossovers
  - Large: Extended cab trucks, vans, full-size SUVs
- **Fixed pricing per service** (not hourly rates)

**Application to Paradocks**:
- Current 5 vehicle types align with industry standards
- Price differences should be **substantial** (100-200 PLN) to reflect labor/material costs
- Transparent pricing builds trust (no hidden fees)

### Key Finding #7: Booking Platform Conversion Optimization

**Source**: [RALabs Booking UX Best Practices](https://ralabs.org/blog/booking-ux-best-practices/)

**Conversion Rate Benchmarks**:
- Average conversion rate in travel industry: **0.2% - 2%**
- Top-performing websites: **5% or higher**
- Good benchmark: **3% and above**

**What Drives Conversions**:
- **Simplified booking forms** nearly halved abandonment rates
- **Faster page loads** cut booking times by over a minute
- **Enhanced trust signals** combined with mobile payment integrations **tripled conversion rate**
- **Smart defaults** like pre-selected durations and "Best Value" labels improve task completion

**Application to Paradocks**:
- Pre-select "Najpopularniejsze" vehicle type (Auto średnie) on page load
- Add trust signals: "2,347 zadowolonych klientów" (social proof)
- Display average rating: "4.8/5 (123 opinii)" per service
- Fast, client-side price calculation (no loading spinners)

---

## Part 2: Architecture Analysis

### Current State Assessment

**Service Model** (`app/Models/Service.php`):
- Single `price` field (decimal:2)
- No relationship to `vehicle_types` table
- CMS fields already implemented (slug, body, content, featured_image, published_at)

**VehicleType Model** (`app/Models/VehicleType.php`):
- 5 types seeded: city_car, small_car, medium_car, large_car, delivery_van
- Fields: name, slug, description, examples, sort_order, is_active
- No pricing relationship to services

**Booking Wizard** (`docs/features/booking-system/README.md`):
- 4-step wizard: Service → Date/Time → Location+Vehicle → Summary
- Vehicle selection currently in **Step 3**
- No price calculation based on vehicle type

**Service Pages** (`docs/features/service-pages/README.md`):
- Dedicated pages at `/uslugi/{slug}`
- Single "Zarezerwuj Termin - {price} zł" CTA
- No vehicle variant selector

### Required Database Changes

#### Option A: Pivot Table (Recommended for Flexibility)

**New Table**: `service_vehicle_type_pricing`

```sql
CREATE TABLE service_vehicle_type_pricing (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    service_id BIGINT UNSIGNED NOT NULL,
    vehicle_type_id BIGINT UNSIGNED NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    UNIQUE KEY unique_service_vehicle (service_id, vehicle_type_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id) ON DELETE CASCADE,
    INDEX idx_service (service_id),
    INDEX idx_vehicle_type (vehicle_type_id)
);
```

**Pros**:
- Flexible: Each service can have different prices for different vehicle types
- Easy to query: `Service::find(1)->pricing()->where('vehicle_type_id', 3)->first()->price`
- Admin-friendly: RelationManager in Filament for price configuration
- Future-proof: Can add `is_available` boolean if service not offered for certain vehicle types

**Cons**:
- Requires 5 records per service (10 services × 5 vehicle types = 50 records)
- Slightly more complex queries

#### Option B: JSON Column (NOT Recommended)

**Modified Table**: `services` table

```sql
ALTER TABLE services ADD COLUMN pricing_by_vehicle_type JSON NULL AFTER price;
```

**Example Data**:
```json
{
  "1": "300.00",  // city_car
  "2": "350.00",  // small_car
  "3": "400.00",  // medium_car
  "4": "450.00",  // large_car
  "5": "550.00"   // delivery_van
}
```

**Pros**:
- Single record per service
- No additional table

**Cons**:
- Hard to query and filter
- Difficult to validate in Filament
- Not relational (breaks normalization)
- Harder to maintain over time

**Decision**: Use **Option A (Pivot Table)** for better maintainability and admin UX.

### Model Relationship Changes

**Service Model** (add relationship):
```php
public function vehicleTypePricing()
{
    return $this->hasMany(ServiceVehicleTypePricing::class);
}

// Or if using belongsToMany pattern:
public function vehicleTypes()
{
    return $this->belongsToMany(VehicleType::class, 'service_vehicle_type_pricing')
                ->withPivot('price')
                ->withTimestamps();
}

// Helper method for frontend
public function getPriceForVehicleType(int $vehicleTypeId): ?float
{
    return $this->vehicleTypePricing()
                ->where('vehicle_type_id', $vehicleTypeId)
                ->value('price');
}
```

**New Model**: `ServiceVehicleTypePricing`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceVehicleTypePricing extends Model
{
    protected $table = 'service_vehicle_type_pricing';

    protected $fillable = ['service_id', 'vehicle_type_id', 'price'];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function vehicleType()
    {
        return $this->belongsTo(VehicleType::class);
    }
}
```

### API Endpoint Changes

**New Endpoint**: `GET /api/services/{service}/pricing`

**Purpose**: Fetch all vehicle type prices for a service (for client-side price calculation)

**Response**:
```json
{
  "service_id": 1,
  "service_name": "Mycie Podstawowe",
  "base_price": 300.00,
  "pricing": [
    {
      "vehicle_type_id": 1,
      "vehicle_type_name": "Auto miejskie",
      "vehicle_type_slug": "city_car",
      "price": 300.00,
      "is_base": true
    },
    {
      "vehicle_type_id": 2,
      "vehicle_type_name": "Auto małe",
      "vehicle_type_slug": "small_car",
      "price": 350.00,
      "price_difference": "+50 PLN"
    },
    {
      "vehicle_type_id": 3,
      "vehicle_type_name": "Auto średnie",
      "vehicle_type_slug": "medium_car",
      "price": 400.00,
      "price_difference": "+100 PLN",
      "is_popular": true
    },
    {
      "vehicle_type_id": 4,
      "vehicle_type_name": "Auto duże",
      "vehicle_type_slug": "large_car",
      "price": 450.00,
      "price_difference": "+150 PLN"
    },
    {
      "vehicle_type_id": 5,
      "vehicle_type_name": "Auto dostawcze",
      "vehicle_type_slug": "delivery_van",
      "price": 550.00,
      "price_difference": "+250 PLN"
    }
  ]
}
```

**Controller**: `app/Http/Controllers/Api/ServicePricingController.php`

```php
public function show(Service $service): JsonResponse
{
    $pricing = $service->vehicleTypes()
        ->ordered()
        ->get()
        ->map(function ($vehicleType) use ($service) {
            $price = $vehicleType->pivot->price;
            $basePriceRef = $service->vehicleTypes()->ordered()->first()->pivot->price ?? $price;

            return [
                'vehicle_type_id' => $vehicleType->id,
                'vehicle_type_name' => $vehicleType->name,
                'vehicle_type_slug' => $vehicleType->slug,
                'price' => $price,
                'is_base' => $price == $basePriceRef,
                'price_difference' => $price > $basePriceRef
                    ? '+' . number_format($price - $basePriceRef, 0) . ' PLN'
                    : null,
                'is_popular' => $vehicleType->slug === 'medium_car', // Or from DB flag
            ];
        });

    return response()->json([
        'service_id' => $service->id,
        'service_name' => $service->name,
        'base_price' => $pricing->first()['price'] ?? 0,
        'pricing' => $pricing,
    ]);
}
```

### Booking Flow Changes

**Problem**: Vehicle selection currently happens in Step 3 (after Date/Time selection).

**Solution**: Move vehicle type selection to Service Page (before entering booking wizard).

**New Flow**:
1. **Service Page** (`/uslugi/{slug}`):
   - Customer selects vehicle type from tiles
   - Price updates in real-time
   - "Zarezerwuj Termin - 450 PLN" button enabled after selection
   - Button URL: `/services/{service}/book?vehicle_type_id=3`

2. **Booking Wizard** (`/services/{service}/book`):
   - Step 1: Date/Time (vehicle type pre-selected, read-only display)
   - Step 2: Location + Vehicle Details (brand, model, year - vehicle TYPE is locked)
   - Step 3: Summary & Confirmation

**BookingController Changes**:
```php
public function create(Service $service, Request $request)
{
    $validated = $request->validate([
        'vehicle_type_id' => 'required|exists:vehicle_types,id',
    ]);

    $vehicleTypeId = $validated['vehicle_type_id'];
    $vehicleType = VehicleType::findOrFail($vehicleTypeId);
    $price = $service->getPriceForVehicleType($vehicleTypeId);

    return view('booking.create', compact('service', 'vehicleType', 'price'));
}
```

**Blade Template** (`resources/views/booking/create.blade.php`):
```blade
<!-- Read-only vehicle type display -->
<div class="selected-vehicle-type">
    <p>Typ pojazdu: <strong>{{ $vehicleType->name }}</strong></p>
    <p>Cena: <strong>{{ number_format($price, 2) }} PLN</strong></p>
    <a href="{{ route('service.show', $service) }}">Zmień typ pojazdu</a>
</div>
```

---

## Part 3: Frontend UI/UX Design

### Service Page Redesign

**Current Layout** (v0.3.0):
```
[Hero Image]
[Service Name]
[Excerpt]
[Zarezerwuj Termin - 300 PLN] <-- Single price
[Duration: 2 godz | Price: 300 PLN]
---
[Body Content (RichEditor)]
[Builder Blocks]
[Related Services]
```

**New Layout** (with Vehicle Configurator):
```
[Hero Image]
[Service Name]
[Excerpt]
---
[KROK 1: Wybierz typ pojazdu] <-- NEW SECTION
[5 Vehicle Type Tiles in Grid]
  - Auto miejskie (300 PLN - Cena bazowa)
  - Auto małe (350 PLN - +50 PLN)
  - Auto średnie (400 PLN - +100 PLN) [NAJPOPULARNIEJSZE]
  - Auto duże (450 PLN - +150 PLN)
  - Auto dostawcze (550 PLN - +250 PLN)
---
[Sticky CTA Bar - Mobile Only]
  Mycie Podstawowe - 400 PLN
  [Zarezerwuj Termin] (full-width button)
---
[Duration: 2 godz | Selected Price: 400 PLN]
[Zarezerwuj Termin - 400 PLN] <-- Desktop CTA (price updates dynamically)
---
[Body Content (RichEditor)]
[Builder Blocks]
[Related Services]
```

### Vehicle Type Tile Component

**Figma-style Mockup** (text representation):

```
┌─────────────────────────────────────────────┐
│ KROK 1: Wybierz typ pojazdu                 │
└─────────────────────────────────────────────┘

Desktop Grid (2 rows, responsive):
┌──────────┬──────────┬──────────┬──────────┬──────────┐
│ 🚗        │ 🚗        │ 🚗        │ 🚙        │ 🚐        │
│ Auto      │ Auto      │ Auto      │ Auto      │ Auto      │
│ miejskie  │ małe      │ średnie   │ duże      │ dostawcze │
│           │           │ ⭐ POPULAR│           │           │
│ 300 PLN   │ 350 PLN   │ 400 PLN   │ 450 PLN   │ 550 PLN   │
│ Cena      │ +50 PLN   │ +100 PLN  │ +150 PLN  │ +250 PLN  │
│ bazowa    │           │           │           │           │
│           │           │ [✓]       │           │           │ <- Selected
└──────────┴──────────┴──────────┴──────────┴──────────┘

Mobile Stack (vertical):
┌─────────────────────────────────────┐
│ 🚗 Auto miejskie                     │
│ 300 PLN - Cena bazowa                │
│ [ ]                                  │
└─────────────────────────────────────┘
┌─────────────────────────────────────┐
│ 🚗 Auto małe                         │
│ 350 PLN (+50 PLN)                    │
│ [ ]                                  │
└─────────────────────────────────────┘
┌─────────────────────────────────────┐
│ 🚗 Auto średnie ⭐ NAJPOPULARNIEJSZE │
│ 400 PLN (+100 PLN)                   │
│ [✓]  <- Selected                     │
└─────────────────────────────────────┘
...
```

**Tailwind CSS Implementation**:

```blade
<!-- Vehicle Type Selector Section -->
<section class="vehicle-selector py-8 bg-gray-50 rounded-lg" x-data="vehicleSelector()">
    <div class="container mx-auto px-4">
        <h2 class="text-2xl font-bold mb-6">
            Krok 1: Wybierz typ pojazdu
        </h2>

        <!-- Desktop Grid (md:grid-cols-5) -->
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4">
            <template x-for="vehicleType in vehicleTypes" :key="vehicleType.id">
                <!-- Tile Component -->
                <button
                    @click="selectVehicleType(vehicleType)"
                    :class="selectedId === vehicleType.id ? 'ring-2 ring-blue-500 bg-blue-50' : 'border border-gray-300'"
                    class="relative flex flex-col items-center p-4 rounded-lg hover:shadow-md transition min-h-[44px]"
                    type="button"
                >
                    <!-- Popular Badge (conditionally) -->
                    <span
                        x-show="vehicleType.is_popular"
                        class="absolute top-2 right-2 bg-yellow-400 text-xs font-bold px-2 py-1 rounded"
                    >
                        ⭐ POPULAR
                    </span>

                    <!-- Vehicle Icon (emoji or SVG) -->
                    <div class="text-4xl mb-2" x-text="vehicleType.icon">🚗</div>

                    <!-- Vehicle Type Name -->
                    <div class="font-semibold text-center mb-2" x-text="vehicleType.name"></div>

                    <!-- Price -->
                    <div class="text-lg font-bold text-blue-600" x-text="vehicleType.price + ' PLN'"></div>

                    <!-- Price Difference or Base Price Label -->
                    <div
                        class="text-sm text-gray-600 mt-1"
                        x-text="vehicleType.is_base ? 'Cena bazowa' : vehicleType.price_difference"
                    ></div>

                    <!-- Selected Checkmark -->
                    <div
                        x-show="selectedId === vehicleType.id"
                        class="absolute bottom-2 right-2 bg-blue-500 rounded-full p-1"
                    >
                        <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/>
                        </svg>
                    </div>
                </button>
            </template>
        </div>

        <!-- Selected Price Display (below tiles) -->
        <div class="mt-6 text-center" x-show="selectedId">
            <p class="text-gray-700">
                Wybrano: <strong x-text="selectedName"></strong>
            </p>
            <p class="text-2xl font-bold text-blue-600 mt-2">
                Cena: <span x-text="selectedPrice"></span> PLN
            </p>
        </div>

        <!-- CTA Button (desktop) -->
        <div class="mt-6 text-center hidden md:block">
            <a
                :href="`/services/{{ $service->id }}/book?vehicle_type_id=${selectedId}`"
                :disabled="!selectedId"
                :class="selectedId ? 'bg-blue-600 hover:bg-blue-700' : 'bg-gray-300 cursor-not-allowed'"
                class="inline-block px-8 py-3 text-white font-semibold rounded-lg transition"
            >
                Zarezerwuj Termin - <span x-text="selectedPrice"></span> PLN
            </a>
        </div>
    </div>
</section>

<!-- Sticky CTA Footer (Mobile Only) -->
<div
    x-show="selectedId"
    class="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 p-4 md:hidden z-50"
>
    <div class="container mx-auto">
        <div class="flex items-center justify-between mb-2">
            <span class="font-semibold">{{ $service->name }}</span>
            <span class="text-lg font-bold text-blue-600" x-text="selectedPrice + ' PLN'"></span>
        </div>
        <a
            :href="`/services/{{ $service->id }}/book?vehicle_type_id=${selectedId}`"
            class="block w-full bg-blue-600 text-white text-center py-3 rounded-lg font-semibold"
        >
            Zarezerwuj Termin
        </a>
    </div>
</div>

<!-- Alpine.js Component -->
<script>
function vehicleSelector() {
    return {
        vehicleTypes: [],
        selectedId: null,
        selectedName: '',
        selectedPrice: 0,

        async init() {
            // Fetch pricing data from API
            const response = await fetch('/api/services/{{ $service->id }}/pricing');
            const data = await response.json();
            this.vehicleTypes = data.pricing;

            // Pre-select "most popular" (medium_car)
            const popular = this.vehicleTypes.find(vt => vt.is_popular);
            if (popular) {
                this.selectVehicleType(popular);
            }
        },

        selectVehicleType(vehicleType) {
            this.selectedId = vehicleType.vehicle_type_id;
            this.selectedName = vehicleType.vehicle_type_name;
            this.selectedPrice = vehicleType.price;
        }
    }
}
</script>
```

### Homepage Service Card Changes

**Current** (v0.3.0):
```blade
<div class="service-price">{{ number_format($service->price, 2) }} PLN</div>
```

**New** (with price range):
```blade
@php
    $minPrice = $service->vehicleTypes()->min('service_vehicle_type_pricing.price') ?? $service->price;
    $maxPrice = $service->vehicleTypes()->max('service_vehicle_type_pricing.price') ?? $service->price;
@endphp

<div class="service-price">
    @if ($minPrice == $maxPrice)
        {{ number_format($minPrice, 0) }} PLN
    @else
        Od {{ number_format($minPrice, 0) }} PLN
        <span class="text-sm text-gray-600">({{ number_format($minPrice, 0) }} - {{ number_format($maxPrice, 0) }} PLN)</span>
    @endif
</div>
```

---

## Part 4: Admin Panel Implementation

### Filament RelationManager for Service Pricing

**File**: `app/Filament/Resources/ServiceResource/RelationManagers/VehicleTypePricingRelationManager.php`

**Features**:
- Attach action: Add vehicle type with price
- Detach action: Remove vehicle type
- Edit action: Update price
- Table columns: Vehicle Type Name, Price, Price Difference, Created At

**Implementation**:
```php
<?php

namespace App\Filament\Resources\ServiceResource\RelationManagers;

use Filament\Forms;
use Filament\Tables;
use Filament\Resources\RelationManagers\RelationManager;

class VehicleTypePricingRelationManager extends RelationManager
{
    protected static string $relationship = 'vehicleTypes';
    protected static ?string $title = 'Cennik według typu pojazdu';

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('vehicle_type_id')
                    ->label('Typ pojazdu')
                    ->relationship('vehicleType', 'name')
                    ->required()
                    ->searchable(),

                Forms\Components\TextInput::make('pivot.price')
                    ->label('Cena (PLN)')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix('PLN')
                    ->helperText('Cena usługi dla tego typu pojazdu'),
            ]);
    }

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Typ pojazdu')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('pivot.price')
                    ->label('Cena')
                    ->money('PLN')
                    ->sortable(),

                Tables\Columns\TextColumn::make('price_difference')
                    ->label('Różnica cenowa')
                    ->state(function ($record) {
                        $basePrice = $this->ownerRecord->vehicleTypes()
                            ->orderBy('sort_order')
                            ->first()?->pivot?->price ?? 0;
                        $currentPrice = $record->pivot->price;
                        $diff = $currentPrice - $basePrice;

                        if ($diff == 0) return 'Cena bazowa';
                        return ($diff > 0 ? '+' : '') . number_format($diff, 2) . ' PLN';
                    })
                    ->color(fn ($state) => str_contains($state, '+') ? 'warning' : 'success'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Utworzono')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->label('Dodaj typ pojazdu')
                    ->form(fn (Tables\Actions\AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Forms\Components\TextInput::make('price')
                            ->label('Cena (PLN)')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->step(0.01)
                            ->prefix('PLN'),
                    ])
                    ->preloadRecordSelect(),

                Tables\Actions\Action::make('auto_populate')
                    ->label('Automatyczne wypełnienie')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->action(function () {
                        // Auto-populate all 5 vehicle types with suggested prices
                        $basePrice = $this->ownerRecord->price ?? 300;
                        $vehicleTypes = \App\Models\VehicleType::ordered()->get();

                        $priceMultipliers = [
                            'city_car' => 1.0,
                            'small_car' => 1.17, // +17%
                            'medium_car' => 1.33, // +33%
                            'large_car' => 1.50,  // +50%
                            'delivery_van' => 1.83, // +83%
                        ];

                        foreach ($vehicleTypes as $vehicleType) {
                            $multiplier = $priceMultipliers[$vehicleType->slug] ?? 1.0;
                            $price = round($basePrice * $multiplier, 2);

                            $this->ownerRecord->vehicleTypes()->syncWithoutDetaching([
                                $vehicleType->id => ['price' => $price]
                            ]);
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Cennik został automatycznie uzupełniony')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->form([
                        Forms\Components\TextInput::make('pivot.price')
                            ->label('Cena (PLN)')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->step(0.01)
                            ->prefix('PLN'),
                    ]),
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}
```

**Add to ServiceResource**:
```php
public static function getRelations(): array
{
    return [
        VehicleTypePricingRelationManager::class,
    ];
}
```

### Admin UX Flow

1. Admin navigates to `/admin/services`
2. Edits service (e.g., "Mycie Podstawowe")
3. Navigates to "Cennik według typu pojazdu" tab
4. Sees empty table with "Dodaj typ pojazdu" button
5. Clicks "Automatyczne wypełnienie" action:
   - System auto-populates 5 vehicle types with suggested prices based on `$service->price`
   - Base price (city_car): 300 PLN (1.0x)
   - Small car: 350 PLN (1.17x)
   - Medium car: 400 PLN (1.33x)
   - Large car: 450 PLN (1.50x)
   - Delivery van: 550 PLN (1.83x)
6. Admin can manually adjust prices via Edit action
7. Saves changes

---

## Part 5: Migration & Deployment Strategy

### Phase 1: Database Migration (Day 1)

**File**: `database/migrations/2025_12_30_create_service_vehicle_type_pricing_table.php`

```php
public function up()
{
    Schema::create('service_vehicle_type_pricing', function (Blueprint $table) {
        $table->id();
        $table->foreignId('service_id')->constrained()->cascadeOnDelete();
        $table->foreignId('vehicle_type_id')->constrained()->cascadeOnDelete();
        $table->decimal('price', 10, 2);
        $table->timestamps();

        $table->unique(['service_id', 'vehicle_type_id'], 'unique_service_vehicle');
        $table->index('service_id');
        $table->index('vehicle_type_id');
    });
}
```

**Seeder**: `database/seeders/ServiceVehicleTypePricingSeeder.php`

```php
public function run()
{
    $services = Service::all();
    $vehicleTypes = VehicleType::ordered()->get();

    $priceMultipliers = [
        'city_car' => 1.0,
        'small_car' => 1.17,
        'medium_car' => 1.33,
        'large_car' => 1.50,
        'delivery_van' => 1.83,
    ];

    foreach ($services as $service) {
        $basePrice = $service->price ?? 300;

        foreach ($vehicleTypes as $vehicleType) {
            $multiplier = $priceMultipliers[$vehicleType->slug] ?? 1.0;
            $price = round($basePrice * $multiplier, 2);

            ServiceVehicleTypePricing::create([
                'service_id' => $service->id,
                'vehicle_type_id' => $vehicleType->id,
                'price' => $price,
            ]);
        }
    }
}
```

### Phase 2: Backend Implementation (Days 2-3)

**Tasks**:
1. Create `ServiceVehicleTypePricing` model
2. Add relationships to `Service` and `VehicleType` models
3. Create `ServicePricingController` API endpoint
4. Create Filament RelationManager
5. Update Appointment creation logic to store selected vehicle type price

**Estimated Time**: 6-8 hours

### Phase 3: Frontend Implementation (Days 4-5)

**Tasks**:
1. Create vehicle selector Blade component
2. Add Alpine.js logic for real-time price updates
3. Update service page template (`resources/views/services/show.blade.php`)
4. Add sticky CTA footer for mobile
5. Update homepage service cards with price ranges
6. Test responsive design (mobile, tablet, desktop)

**Estimated Time**: 8-10 hours

### Phase 4: Booking Flow Integration (Day 6)

**Tasks**:
1. Update `BookingController::create()` to accept `vehicle_type_id` param
2. Modify booking wizard Step 1 to display selected vehicle type (read-only)
3. Update appointment creation to store correct price from `service_vehicle_type_pricing`
4. Test complete user journey: Service Page → Vehicle Selection → Booking Wizard → Confirmation

**Estimated Time**: 4-6 hours

### Phase 5: Testing & QA (Day 7)

**Test Cases**:
1. **Admin Panel**:
   - [ ] Create new service with pricing for 5 vehicle types
   - [ ] Use "Automatyczne wypełnienie" action
   - [ ] Manually edit prices
   - [ ] Detach vehicle type
   - [ ] Verify validation (price must be >= 0)

2. **Service Page**:
   - [ ] Vehicle selector displays 5 tiles
   - [ ] Clicking tile selects it (visual feedback)
   - [ ] Price updates in CTA button
   - [ ] "Zarezerwuj Termin" link includes `vehicle_type_id` param
   - [ ] Mobile: Sticky footer appears after selection
   - [ ] Desktop: CTA button below selector works

3. **Homepage**:
   - [ ] Service cards show "Od X PLN" (price range)
   - [ ] Clicking card navigates to service page
   - [ ] "Zobacz Szczegóły" button works

4. **Booking Wizard**:
   - [ ] URL param `vehicle_type_id` is validated
   - [ ] Selected vehicle type is displayed (read-only)
   - [ ] Correct price is shown throughout wizard
   - [ ] Appointment is created with correct price

5. **Edge Cases**:
   - [ ] Service has no pricing configured (fallback to base price)
   - [ ] Invalid `vehicle_type_id` in URL (404 or error message)
   - [ ] JavaScript disabled (graceful degradation)

**Estimated Time**: 6-8 hours

### Phase 6: Documentation & Deployment (Day 8)

**Tasks**:
1. Update `docs/features/service-pages/README.md` with vehicle selector section
2. Update `docs/features/booking-system/README.md` with new flow
3. Create ADR-XXX: Vehicle-Based Dynamic Pricing Architecture
4. Update `CHANGELOG.md` for v0.4.0 release
5. Create deployment notes with rollback plan

**Estimated Time**: 4-6 hours

---

## Part 6: Revised Estimation

### Total Implementation Time: 32-46 hours (4-6 business days)

**Breakdown**:
- Research & Planning: 4-6 hours (COMPLETED)
- Database & Models: 6-8 hours
- Backend API & Admin Panel: 6-8 hours
- Frontend UI/UX: 8-10 hours
- Booking Flow Integration: 4-6 hours
- Testing & QA: 6-8 hours
- Documentation: 4-6 hours

**Previous Estimate**: 8-10 hours (INCORRECT - too superficial)

**Why the increase?**:
1. **UX complexity underestimated**: This is a product configurator, not a simple dropdown
2. **Admin panel scope**: RelationManager + auto-populate action + validation
3. **Mobile responsiveness**: Sticky footer, touch targets, vertical stacking
4. **Booking flow changes**: Integration with existing 4-step wizard
5. **Testing thoroughness**: 5 test suites (admin, service page, homepage, wizard, edge cases)

### Risk Factors

**High Risk**:
- Mobile UX testing (multiple devices, browsers)
- Alpine.js state management conflicts with existing Livewire components
- Price calculation edge cases (missing pricing records)

**Medium Risk**:
- Admin UX confusion (relation manager usage)
- Homepage card query performance (N+1 queries for pricing)

**Low Risk**:
- Database migration (straightforward pivot table)
- API endpoint implementation (standard Laravel controller)

### Mitigation Strategies

1. **Mobile Testing**: Use BrowserStack or similar for cross-device testing
2. **Alpine.js Conflicts**: Isolate component with `x-data` scope, avoid global state
3. **Missing Pricing Records**: Fallback to `$service->price` if no vehicle pricing exists
4. **Admin Training**: Create video tutorial for Filament relation manager usage
5. **Performance**: Eager load pricing in homepage query: `$services->load('vehicleTypes')`

---

## Part 7: Alternative Approaches Considered

### Alternative 1: Modal/Popup Vehicle Selector

**Description**: Instead of inline tiles, show modal when user clicks "Zarezerwuj Termin".

**Pros**:
- Less page clutter
- Fewer changes to existing service page

**Cons**:
- Extra click (hurts conversion)
- Hides pricing transparency
- Against best practices (configurator should be inline)

**Decision**: REJECTED - Inline selector preferred for transparency and conversion.

### Alternative 2: Dropdown/Select Vehicle Type

**Description**: Use `<select>` dropdown instead of tiles.

**Pros**:
- Compact on mobile
- Faster to implement

**Cons**:
- Less visual
- Harder to scan options
- No price comparison at a glance
- Against UX research findings (tiles preferred for variant selection)

**Decision**: REJECTED - Tiles provide better UX and conversion.

### Alternative 3: Vehicle Selection in Booking Wizard Only

**Description**: Keep vehicle selection in Step 3 of wizard, add price calculation there.

**Pros**:
- Minimal changes to service page
- Existing flow preserved

**Cons**:
- User doesn't see price until Step 3 (late in funnel)
- Conversion drop-off risk (price shock)
- Against best practices (show price BEFORE commitment)

**Decision**: REJECTED - Transparent pricing upfront is critical for conversion.

---

## Part 8: Success Metrics

### KPIs to Track Post-Launch

1. **Conversion Rate**:
   - Baseline: Current conversion rate (homepage → booking completion)
   - Target: +10-15% increase within 30 days
   - Measurement: Google Analytics funnel tracking

2. **Average Order Value (AOV)**:
   - Baseline: Current average appointment price
   - Target: +20-30% increase (due to higher vehicle types being selected)
   - Measurement: Database query (`SELECT AVG(price) FROM appointments WHERE created_at >= '2025-01-01'`)

3. **Bounce Rate on Service Pages**:
   - Baseline: Current bounce rate
   - Target: -10% decrease (more engagement with configurator)
   - Measurement: Google Analytics

4. **Mobile Conversion Rate**:
   - Baseline: Current mobile conversion rate
   - Target: Match or exceed desktop conversion rate
   - Measurement: Google Analytics (device category breakdown)

5. **Time on Service Page**:
   - Baseline: Current avg time on page
   - Target: +20-30 seconds (users spend time configuring)
   - Measurement: Google Analytics

### A/B Testing Opportunities (Future)

1. **Tile Layout**:
   - Variant A: 5 tiles in single row (desktop)
   - Variant B: 2 rows of 2-3 tiles
   - Metric: Conversion rate

2. **Price Display Format**:
   - Variant A: "+150 PLN" (price difference)
   - Variant B: "450 PLN" (absolute price)
   - Metric: Selection of higher-priced vehicle types

3. **Default Selection**:
   - Variant A: Pre-select "Auto średnie" (most popular)
   - Variant B: No pre-selection (force user to choose)
   - Metric: Conversion rate + AOV

---

## Part 9: Future Enhancements (Post-MVP)

### Enhancement 1: Service Add-Ons System

**Description**: Allow customers to add optional extras (e.g., "Headlight Restoration +50 PLN").

**Implementation Complexity**: High (requires new table, admin UI, booking flow changes)

**Estimated Time**: 20-30 hours

### Enhancement 2: Package Bundles

**Description**: "Interior + Exterior Detail Bundle - Save 10%"

**Implementation Complexity**: Medium (discount logic, admin UI for bundles)

**Estimated Time**: 16-24 hours

### Enhancement 3: Dynamic Pricing Based on Demand

**Description**: Surge pricing during weekends or peak hours.

**Implementation Complexity**: Very High (requires pricing algorithm, admin controls, customer communication)

**Estimated Time**: 40-60 hours

### Enhancement 4: Customer Loyalty Pricing

**Description**: Returning customers get 10% discount.

**Implementation Complexity**: Medium (check customer history, apply discount)

**Estimated Time**: 12-16 hours

---

## Part 10: Rollback Plan

### If Deployment Fails or Conversion Drops

**Scenario 1: Critical Bug in Vehicle Selector**

**Symptoms**: JavaScript errors, tiles not clickable, price not updating

**Rollback Steps**:
1. Revert to previous version: `git revert <commit-hash>`
2. Deploy: `git push origin main`
3. Restart services: `docker compose restart app nginx`
4. Monitor: Check error logs, user feedback

**Fallback**: Temporarily disable vehicle selector, show single price (original behavior)

**Scenario 2: Conversion Rate Drops >10%**

**Symptoms**: Lower booking completion rate after 7 days

**Investigation Steps**:
1. Analyze Google Analytics funnel (where are users dropping off?)
2. Check mobile vs desktop conversion split
3. Review heatmaps (Hotjar/Crazy Egg) for interaction issues
4. Collect user feedback (exit surveys)

**Potential Fixes**:
- Simplify mobile layout (fewer tiles visible initially)
- Change default pre-selection (experiment with different vehicle types)
- Add tooltips/help text explaining price differences
- A/B test different tile layouts

**Rollback Threshold**: If conversion drops >15% after 14 days AND no fixable issues identified, consider rollback

---

## Part 11: Stakeholder Communication

### Weekly Status Updates (Project Duration)

**Week 1 Update** (after Phase 1-2):
```
Subject: Vehicle Pricing Feature - 40% Complete

Progress:
✅ Database migration completed
✅ Backend API endpoint functional
✅ Admin panel RelationManager implemented
🔄 Frontend UI in progress (50% done)

Next Week:
- Complete frontend vehicle selector
- Integrate with booking wizard
- Begin QA testing

Blockers: None
On Track: Yes
```

**Week 2 Update** (after Phase 3-4):
```
Subject: Vehicle Pricing Feature - 80% Complete

Progress:
✅ Frontend vehicle selector complete (desktop + mobile)
✅ Booking wizard integration complete
✅ Homepage price ranges implemented
🔄 QA testing in progress (60% done)

Next Week:
- Complete QA testing
- Deploy to staging
- Prepare production deployment

Blockers: Minor mobile Safari layout issue (fixing)
On Track: Yes
```

**Launch Announcement**:
```
Subject: Vehicle Pricing Feature LIVE ✅

The new vehicle-based pricing configurator is now live!

What Changed:
- Service pages now display pricing for all 5 vehicle types
- Customers select vehicle type BEFORE entering booking wizard
- Transparent pricing with clear price differences displayed
- Mobile-optimized with sticky CTA footer

Metrics to Watch:
- Conversion rate (baseline: X%, target: +10-15%)
- Average order value (baseline: Y PLN, target: +20-30%)
- Mobile conversion rate

Next Steps:
- Monitor metrics for 14 days
- Collect user feedback via exit surveys
- A/B test tile layouts if needed

Questions? Contact [Your Name]
```

---

## Conclusion

This comprehensive plan addresses the user's vision of a **product configurator pattern** for vehicle-based pricing. The research-backed UX approach, detailed architecture design, and realistic estimation (32-46 hours vs. initial 8-10h) reflect the true complexity of this feature.

**Key Takeaways**:
1. **UX Research Critical**: 8 sources analyzed to inform tile design, mobile optimization, and conversion psychology
2. **Architecture Solid**: Pivot table approach provides flexibility and admin-friendly UX
3. **Mobile-First**: 40-60% of traffic is mobile; sticky footer and vertical stacking are essential
4. **Realistic Estimation**: Previous 8-10h estimate was superficial; true scope is 32-46h (4-6 days)
5. **Rollback Plan**: Critical for mitigating risk if conversion drops post-launch

**Recommended Next Steps**:
1. User approval of plan and design mockups
2. Phase 1: Database migration (Day 1)
3. Phase 2-3: Backend + Frontend implementation (Days 2-5)
4. Phase 4-5: Integration + QA (Days 6-7)
5. Phase 6: Documentation + Deployment (Day 8)
6. Monitor metrics for 14 days post-launch

---

**Document Status**: Ready for Review
**Estimated Review Time**: 20-30 minutes
**Next Action**: User approval to proceed with implementation
