# Filament v4 Component Architecture

**Version:** Filament v4.2.3
**Last Updated:** 2025-12-17
**Purpose:** Complete reference for Filament component hierarchy, nesting rules, and placement strategies

---

## Table of Contents

1. [Component Type Reference](#component-type-reference)
2. [Component Hierarchy](#component-hierarchy)
3. [Nesting Rules Matrix](#nesting-rules-matrix)
4. [Widget Architecture](#widget-architecture)
5. [Page Architecture](#page-architecture)
6. [Resource Architecture](#resource-architecture)
7. [Common Mistakes to Avoid](#common-mistakes-to-avoid)

---

## Component Type Reference

Filament v4 has 6 main component types, each with specific purposes and placement rules:

### 1. Panel Components

**Purpose:** Top-level application containers
**Namespace:** `Filament\Panel`
**Examples:** Admin panel configuration, authentication

```php
use Filament\Panel;

public function panel(Panel $panel): Panel
{
    return $panel
        ->default()
        ->id('admin')
        ->path('admin')
        ->login();
}
```

**Characteristics:**
- Defined in `AdminPanelProvider`
- Contains: Resources, Pages, Widgets
- Cannot be nested inside other components

---

### 2. Resource Components

**Purpose:** CRUD interfaces for Eloquent models
**Namespace:** `App\Filament\Resources`
**Examples:** UserResource, AppointmentResource

```php
use Filament\Resources\Resource;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    public static function form(Form $form): Form { }
    public static function table(Table $table): Table { }
}
```

**Characteristics:**
- Contains: Forms, Tables, Pages
- Registered in Panel
- Provides complete model management

---

### 3. Page Components

**Purpose:** Custom admin pages with full control
**Namespace:** `App\Filament\Pages`
**Examples:** Dashboard, Settings, Custom pages

```php
use Filament\Pages\Page;

class SystemSettings extends Page
{
    protected static string $view = 'filament.pages.system-settings';

    // Full control over page structure
}
```

**Characteristics:**
- Can contain: Widgets, Sections, Forms
- Registered in Panel or as Resource pages
- Full layout control via Blade templates

---

### 4. Widget Components

**Purpose:** Reusable dashboard and page components
**Namespace:** `App\Filament\Widgets`
**Types:** Stats, Charts, Tables, Custom

```php
use Filament\Widgets\Widget;

class CacheClearWidget extends Widget
{
    protected string $view = 'filament.widgets.cache-clear';

    // Widgets are self-contained with built-in layout
}
```

**Characteristics:**
- Self-contained with built-in layout
- Can be placed: Dashboard, Pages, Resource pages
- **CRITICAL:** Do NOT nest Section components inside widgets

---

### 5. Form Components

**Purpose:** Data entry and display in forms
**Namespace:** `Filament\Forms\Components`
**Examples:** TextInput, Select, FileUpload

```php
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;

TextInput::make('name')->required();
Select::make('status')->options([...]);
```

**Characteristics:**
- Used inside Forms (Resources, Pages)
- Handles validation and data binding
- Can be grouped with Fieldsets, Sections, Tabs

---

### 6. Table Components

**Purpose:** Data display in tables
**Namespace:** `Filament\Tables\Components`
**Examples:** TextColumn, BadgeColumn, Actions

```php
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;

TextColumn::make('name')->searchable();
BadgeColumn::make('status')->colors([...]);
```

**Characteristics:**
- Used inside Tables (Resources, Widgets)
- Handles sorting, searching, filtering
- Can include actions and bulk actions

---

## Component Hierarchy

### Visual Hierarchy Diagram

```
Panel (AdminPanelProvider)
├── Dashboard (Page)
│   ├── Widget (Stats)
│   ├── Widget (Chart)
│   └── Widget (Table)
│
├── Resource (e.g., UserResource)
│   ├── Form
│   │   ├── Section (Layout)
│   │   │   ├── TextInput
│   │   │   ├── Select
│   │   │   └── DatePicker
│   │   └── Tabs (Layout)
│   │       ├── Tab 1 → Fields
│   │       └── Tab 2 → Fields
│   │
│   ├── Table
│   │   ├── TextColumn
│   │   ├── BadgeColumn
│   │   └── Actions
│   │
│   └── Pages
│       ├── ListRecords
│       ├── CreateRecord
│       └── EditRecord
│           └── Widget (optional)
│
└── Custom Page
    ├── Section (Layout)
    │   └── Content
    ├── Widget (Custom)
    └── Form (optional)
```

---

## Nesting Rules Matrix

### What Can Contain What

| Parent Component | Can Contain | Cannot Contain |
|-----------------|-------------|----------------|
| **Panel** | Resources, Pages, Widgets | Forms, Tables directly |
| **Resource** | Forms, Tables, Pages | Widgets directly, Sections directly |
| **Page** | Sections, Widgets, Forms | Resource components |
| **Widget** | Direct HTML, Livewire components | ❌ Section (causes layout issues) |
| **Form** | Form Components, Layout Components (Section, Tabs, Grid) | Widgets, Pages |
| **Table** | Table Columns, Actions | Form Components, Widgets |
| **Section** | Form Components, Content | Widgets, Resources |

### Critical Rules

#### ✅ CORRECT Nesting

```php
// 1. Widget with direct content (NO Section wrapper)
<x-filament-widgets::widget>
    <x-slot name="heading">Cache Management</x-slot>
    <x-slot name="description">Operations</x-slot>

    <div class="space-y-4">
        <!-- Direct content -->
    </div>
</x-filament-widgets::widget>

// 2. Page with Sections
<x-filament::page>
    <x-filament::section heading="User Settings">
        <!-- Content -->
    </x-filament::section>

    <x-filament::section heading="Notifications">
        <!-- Content -->
    </x-filament::section>
</x-filament::page>

// 3. Form with Sections
use Filament\Schemas\Components\Section;

Section::make('Personal Information')
    ->schema([
        TextInput::make('first_name'),
        TextInput::make('last_name'),
    ]);
```

#### ❌ WRONG Nesting

```php
// 1. Section nested in Widget (CAUSES LAYOUT ISSUES!)
<x-filament-widgets::widget>
    <x-filament::section>  ← WRONG! Breaks layout
        <x-slot name="heading">Title</x-slot>
        Content
    </x-filament::section>
</x-filament-widgets::widget>

// 2. Widget directly in Resource
class UserResource extends Resource
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            CacheClearWidget::class,  ← WRONG! Use in pages only
        ]);
    }
}

// 3. Form Components in Widget Blade
<x-filament-widgets::widget>
    {{ Forms\Components\TextInput::make('name') }}  ← WRONG! Use Livewire properties
</x-filament-widgets::widget>
```

---

## Advanced Layout Patterns

### Responsive Grid with Breakpoints

**Pattern:** Control grid columns at different screen sizes using named breakpoints.

```php
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\TextInput;

// Responsive grid configuration
Grid::make([
    'default' => 1,  // Mobile: 1 column
    'sm' => 2,       // Small: 2 columns
    'md' => 3,       // Medium: 3 columns
    'lg' => 4,       // Large: 4 columns
    'xl' => 6,       // Extra large: 6 columns
    '2xl' => 8,      // 2X large: 8 columns
])
    ->schema([
        TextInput::make('first_name')
            ->columnSpan([
                'default' => 1,  // Full width on mobile
                'sm' => 2,       // Span 2 cols on small
                'lg' => 3,       // Span 3 cols on large
            ]),

        TextInput::make('last_name')
            ->columnSpan([
                'default' => 1,
                'sm' => 1,
            ]),

        TextInput::make('email')
            ->columnSpan('full'),  // Full width on all screens
    ]);
```

**Available Breakpoints:**
- `default` - Base (mobile-first)
- `sm` - ≥640px
- `md` - ≥768px
- `lg` - ≥1024px
- `xl` - ≥1280px
- `2xl` - ≥1536px

**Shorthand Patterns:**
```php
// Full width
->columnSpan('full')

// Half width on all screens
->columnSpan(2)

// Responsive shorthand
->columnSpan([
    'sm' => 2,
    'lg' => 4,
])
```

---

### Collapsible Sections with State Persistence

**Pattern:** Sections that remember their collapsed/expanded state.

```php
use Filament\Schemas\Components\Section;

Section::make('Shipping Information')
    ->description('Optional shipping details')
    ->schema([
        TextInput::make('shipping_address'),
        TextInput::make('shipping_city'),
        Select::make('shipping_country')->options([...]),
    ])
    ->collapsible()  // Enable collapse/expand
    ->collapsed()    // Start collapsed
    ->persistCollapsed()  // Remember state in session
    ->persistCollapsed('user-shipping-section')  // Custom persistence key
    ->icon('heroicon-o-truck')  // Section icon
    ->iconColor('primary');     // Icon color
```

**Advanced Collapsible Patterns:**
```php
// Conditional collapsible state
Section::make('Advanced Options')
    ->collapsible()
    ->collapsed(fn (Get $get): bool => ! $get('show_advanced'))
    ->schema([...]);

// Multiple sections with different persistence
Section::make('Personal Info')
    ->persistCollapsed('personal')
    ->collapsible()
    ->collapsed(false)  // Start expanded
    ->schema([...]),

Section::make('Privacy Settings')
    ->persistCollapsed('privacy')
    ->collapsible()
    ->collapsed(true)   // Start collapsed
    ->schema([...]),
```

---

### Tabs with Icons and Badges

**Pattern:** Enhanced tabs with visual indicators.

```php
use Filament\Schemas\Components\Tabs;

Tabs::make('Customer Management')
    ->tabs([
        Tabs\Tab::make('Profile')
            ->icon('heroicon-o-user')
            ->iconColor('primary')
            ->schema([
                TextInput::make('first_name'),
                TextInput::make('last_name'),
            ]),

        Tabs\Tab::make('Orders')
            ->icon('heroicon-o-shopping-bag')
            ->badge(fn ($record) => $record?->orders()->count())
            ->badgeColor('success')
            ->schema([
                // Order-related fields
            ]),

        Tabs\Tab::make('Invoices')
            ->icon('heroicon-o-document-text')
            ->badge(fn ($record) => $record?->unpaidInvoices()->count())
            ->badgeColor(fn ($record) =>
                $record?->unpaidInvoices()->count() > 0 ? 'danger' : 'success'
            )
            ->schema([
                // Invoice-related fields
            ]),

        Tabs\Tab::make('Activity')
            ->icon('heroicon-o-clock')
            ->badge('New')
            ->badgeColor('warning')
            ->schema([
                // Activity log
            ]),
    ])
    ->activeTab(2)  // Start on Invoices tab (1-indexed)
    ->contained(false)  // Remove container styling
    ->persistTabInQueryString();  // Persist tab in URL
```

**Badge Color Options:**
- `primary`, `success`, `warning`, `danger`, `gray`, `info`

**Conditional Tabs:**
```php
Tabs::make('Content')
    ->tabs([
        Tabs\Tab::make('General')->schema([...]),

        Tabs\Tab::make('SEO')
            ->visible(fn () => auth()->user()->hasPermission('manage-seo'))
            ->schema([...]),

        Tabs\Tab::make('Advanced')
            ->hidden(fn (Get $get): bool => $get('content_type') === 'simple')
            ->schema([...]),
    ]);
```

---

### Component Visibility Rules

**Pattern:** Show/hide components based on conditions.

#### Visibility Based on User Roles

```php
Section::make('Admin Tools')
    ->visible(fn (): bool => auth()->user()->hasRole('admin'))
    ->schema([
        TextInput::make('internal_notes'),
        Toggle::make('is_featured'),
    ]),
```

#### Visibility Based on Form State

```php
use Filament\Forms\Get;

Select::make('payment_method')
    ->options([
        'credit_card' => 'Credit Card',
        'bank_transfer' => 'Bank Transfer',
        'cash' => 'Cash',
    ]),

Section::make('Credit Card Information')
    ->visible(fn (Get $get): bool => $get('payment_method') === 'credit_card')
    ->schema([
        TextInput::make('card_number'),
        TextInput::make('card_cvv'),
    ]),

Section::make('Bank Details')
    ->visible(fn (Get $get): bool => $get('payment_method') === 'bank_transfer')
    ->schema([
        TextInput::make('bank_account'),
        TextInput::make('bank_routing'),
    ]),
```

#### Page-Specific Visibility

```php
TextInput::make('password')
    ->password()
    ->required(fn (string $context): bool => $context === 'create')
    ->dehydrated(fn ($state) => filled($state))
    ->hiddenOn('view')          // Hide on view page
    ->visibleOn(['create', 'edit'])  // Show only on create/edit
    ->hidden(fn (Page $livewire): bool =>
        $livewire instanceof ViewUser
    ),
```

#### Conditional Required Fields

```php
Toggle::make('has_shipping_address')
    ->label('Ship to different address')
    ->live(),  // Update form in real-time

TextInput::make('shipping_address')
    ->required(fn (Get $get): bool => $get('has_shipping_address'))
    ->visible(fn (Get $get): bool => $get('has_shipping_address'))
    ->dehydrated(fn (Get $get): bool => $get('has_shipping_address')),
```

#### Multi-Condition Visibility

```php
Section::make('Discount Settings')
    ->visible(fn (Get $get): bool =>
        $get('is_promotional')
        && auth()->user()->can('manage-discounts')
        && now()->isBefore($get('promotion_end_date'))
    )
    ->schema([
        TextInput::make('discount_percentage'),
    ]),
```

---

### Infolist Integration

**Pattern:** Display-only components in View pages.

```php
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;

// In ViewUser page
public function infolist(Infolist $infolist): Infolist
{
    return $infolist
        ->schema([
            Section::make('User Information')
                ->description('Basic user account details')
                ->icon('heroicon-o-user')
                ->collapsible()
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('name')
                            ->icon('heroicon-o-user')
                            ->copyable()
                            ->tooltip('Click to copy'),

                        TextEntry::make('email')
                            ->icon('heroicon-o-envelope')
                            ->copyable(),
                    ]),

                    TextEntry::make('role.name')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'super-admin' => 'danger',
                            'admin' => 'warning',
                            default => 'gray',
                        }),

                    IconEntry::make('email_verified_at')
                        ->label('Email Verified')
                        ->boolean()
                        ->trueIcon('heroicon-o-check-circle')
                        ->falseIcon('heroicon-o-x-circle')
                        ->trueColor('success')
                        ->falseColor('danger'),
                ]),

            Section::make('Activity')
                ->schema([
                    TextEntry::make('created_at')
                        ->dateTime()
                        ->since()
                        ->icon('heroicon-o-calendar'),

                    TextEntry::make('last_login_at')
                        ->dateTime()
                        ->placeholder('Never logged in')
                        ->icon('heroicon-o-clock'),
                ]),

            Section::make('Profile Picture')
                ->schema([
                    ImageEntry::make('avatar_url')
                        ->circular()
                        ->size(100),
                ]),
        ]);
}
```

**Key Infolist Components:**

| Component | Purpose | Example |
|-----------|---------|---------|
| `TextEntry` | Display text | Name, email, description |
| `IconEntry` | Boolean with icons | Verified status, feature flags |
| `ImageEntry` | Display images | Avatar, photo gallery |
| `BadgeEntry` | Status badges | Order status, role |
| `ColorEntry` | Color swatches | Brand colors, themes |
| `KeyValueEntry` | Key-value pairs | Settings, metadata |

**Advanced Infolist Patterns:**
```php
// Formatted currency
TextEntry::make('total')
    ->money('USD')
    ->icon('heroicon-o-currency-dollar'),

// List of items
TextEntry::make('tags')
    ->listWithLineBreaks()
    ->bulleted(),

// HTML content
TextEntry::make('bio')
    ->html()
    ->columnSpanFull(),

// Custom formatting
TextEntry::make('phone')
    ->formatStateUsing(fn (string $state): string =>
        preg_replace('/(\d{3})(\d{3})(\d{3})/', '($1) $2-$3', $state)
    ),
```

---

## Widget Architecture

### Widget Placement Strategies

#### 1. Dashboard Widgets

**Purpose:** Global metrics and actions
**Registration:** `AdminPanelProvider::widgets()`

```php
// app/Providers/Filament/AdminPanelProvider.php
public function panel(Panel $panel): Panel
{
    return $panel
        ->widgets([
            Widgets\AccountWidget::class,
            Widgets\FilamentInfoWidget::class,
            CacheClearWidget::class,  // Custom widget
        ]);
}
```

**Characteristics:**
- Visible on main dashboard
- Available to all admin users (with permissions)
- Best for: Stats, charts, quick actions

#### 2. Page-Level Widgets

**Purpose:** Page-specific functionality
**Registration:** Inside custom Page classes

```php
// app/Filament/Pages/SystemSettings.php
class SystemSettings extends Page
{
    protected function getHeaderWidgets(): array
    {
        return [
            CacheStatsWidget::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            SystemInfoWidget::class,
        ];
    }
}
```

**Characteristics:**
- Contextual to specific page
- Can access page data via Livewire
- Best for: Page-specific actions, related metrics

#### 3. Resource Page Widgets

**Purpose:** Model-specific functionality
**Registration:** Inside Resource page classes

```php
// app/Filament/Resources/UserResource/Pages/EditUser.php
class EditUser extends EditRecord
{
    protected function getHeaderWidgets(): array
    {
        return [
            UserActivityWidget::class,
        ];
    }
}
```

**Characteristics:**
- Access to model record via `$this->record`
- Best for: Model-specific metrics, related actions

---

### Widget Structure

#### Widget Class Structure

```php
<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class CacheClearWidget extends Widget
{
    // View path (required)
    protected string $view = 'filament.widgets.cache-clear';

    // Column span (optional, default: 1)
    protected int | string | array $columnSpan = 'full';

    // Sort order (optional)
    protected static ?int $sort = 999;

    // Authorization (optional)
    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['admin']) ?? false;
    }

    // Livewire methods
    public function clearCache(): void
    {
        // Widget logic
    }
}
```

#### Widget Blade Template Structure

```blade
{{-- resources/views/filament/widgets/cache-clear.blade.php --}}

<x-filament-widgets::widget>
    {{-- Heading (optional) --}}
    <x-slot name="heading">
        Widget Title
    </x-slot>

    {{-- Description (optional) --}}
    <x-slot name="description">
        Widget description text
    </x-slot>

    {{-- Direct content (NO Section wrapper!) --}}
    <div class="space-y-4">
        {{-- Your widget content --}}

        {{-- Filament buttons work with wire:click --}}
        <x-filament::button
            wire:click="clearCache"
            wire:loading.attr="disabled"
        >
            <span wire:loading.remove wire:target="clearCache">Clear Cache</span>
            <span wire:loading wire:target="clearCache">Clearing...</span>
        </x-filament::button>
    </div>
</x-filament-widgets::widget>
```

---

### Widget Scopes & Contexts

**CRITICAL:** Widgets can access different contexts depending on where they're placed.

#### Dashboard Widgets (Global Scope)

**Access:** Tenant/user-level data

```php
<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    // Dashboard widgets can access global tenant context
    protected static ?string $tenantScope = true;

    protected function getStats(): array
    {
        // Access current tenant
        $tenant = Filament::getTenant();

        // Access authenticated user
        $user = auth()->user();

        return [
            Stat::make('Total Users', $tenant->users()->count()),
            Stat::make('My Appointments', $user->appointments()->count()),
        ];
    }
}
```

**Characteristics:**
- Access to `Filament::getTenant()` for multi-tenancy
- Access to `auth()->user()` for user-specific data
- No access to specific model records
- Best for: Global metrics, tenant-wide statistics

---

#### Resource Widgets (Resource Scope)

**Access:** Current resource record

```php
<?php

namespace App\Filament\Resources\OrderResource\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;

class OrderStatsChart extends ChartWidget
{
    // Access to current record
    public ?Model $record = null;

    protected function getData(): array
    {
        // Access the current Order record
        if (!$this->record) {
            return ['datasets' => [], 'labels' => []];
        }

        $items = $this->record->items()
            ->selectRaw('DATE(created_at) as date, SUM(total) as total')
            ->groupBy('date')
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Daily Sales',
                    'data' => $items->pluck('total')->toArray(),
                ],
            ],
            'labels' => $items->pluck('date')->toArray(),
        ];
    }
}
```

**Usage in Resource Page:**
```php
// app/Filament/Resources/OrderResource/Pages/EditOrder.php
class EditOrder extends EditRecord
{
    protected function getHeaderWidgets(): array
    {
        return [
            OrderResource\Widgets\OrderStatsChart::class,
        ];
    }

    // Pass record to widget
    protected function getHeaderWidgetData(): array
    {
        return [
            'record' => $this->record,
        ];
    }
}
```

**Characteristics:**
- Access to `$this->record` (current model instance)
- Best for: Model-specific metrics, related data
- Updated automatically when record changes

---

#### Page Widgets (Page Scope)

**Access:** Page-specific state and data

```php
<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Filament\Widgets\FilteredStatsWidget;

class Reports extends Page
{
    protected static string $view = 'filament.pages.reports';

    public $startDate;
    public $endDate;

    public function mount(): void
    {
        $this->startDate = now()->startOfMonth();
        $this->endDate = now();
    }

    protected function getHeaderWidgets(): array
    {
        return [
            FilteredStatsWidget::class,
        ];
    }

    // Pass page data to widgets
    protected function getHeaderWidgetData(): array
    {
        return [
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
        ];
    }
}
```

**Widget accessing page data:**
```php
class FilteredStatsWidget extends BaseWidget
{
    public $startDate;
    public $endDate;

    protected function getStats(): array
    {
        return [
            Stat::make('Revenue', Order::whereBetween('created_at', [
                $this->startDate,
                $this->endDate,
            ])->sum('total')),
        ];
    }
}
```

**Characteristics:**
- Access to page properties via widget public properties
- Reactive to page state changes
- Best for: Filtered data, page-specific calculations

---

### Widget Discovery & Registration

**IMPORTANT:** Different registration patterns for different widget placements.

#### Resource-Level Widget Registration

```php
<?php

namespace App\Filament\Resources;

class CustomerResource extends Resource
{
    // Register widgets for ALL resource pages
    public static function getWidgets(): array
    {
        return [
            Widgets\CustomerStatsWidget::class,
            Widgets\CustomerActivityChart::class,
        ];
    }

    // Available in any resource page via:
    protected function getHeaderWidgets(): array
    {
        return CustomerResource::getWidgets();
    }
}
```

**Difference: `getWidgets()` vs `getHeaderWidgets()`**

| Method | Scope | Purpose |
|--------|-------|---------|
| `getWidgets()` | Resource-wide | Define available widgets for resource |
| `getHeaderWidgets()` | Page-specific | Choose which widgets to display on THIS page |
| `getFooterWidgets()` | Page-specific | Widgets below page content |

**Example:**
```php
// Resource defines available widgets
public static function getWidgets(): array
{
    return [
        Widgets\Stats::class,
        Widgets\Chart::class,
        Widgets\Table::class,
    ];
}

// List page shows only stats
class ListCustomers extends ListRecords
{
    protected function getHeaderWidgets(): array
    {
        return [
            CustomerResource\Widgets\Stats::class,
        ];
    }
}

// Edit page shows stats + chart
class EditCustomer extends EditRecord
{
    protected function getHeaderWidgets(): array
    {
        return [
            CustomerResource\Widgets\Stats::class,
            CustomerResource\Widgets\Chart::class,
        ];
    }
}
```

---

#### Panel-Level Widget Registration

```php
// app/Providers/Filament/AdminPanelProvider.php
public function panel(Panel $panel): Panel
{
    return $panel
        ->widgets([
            Widgets\AccountWidget::class,
            Widgets\FilamentInfoWidget::class,
            \App\Filament\Widgets\StatsOverview::class,
        ])
        ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets');
}
```

**Characteristics:**
- Registered widgets appear on dashboard
- Auto-discovery scans directory for widgets
- Manual registration for explicit control

---

### Widget Polling & Real-time Updates

**Pattern:** Auto-refresh widgets at intervals

#### Basic Polling

```php
class LiveOrdersWidget extends Widget
{
    protected static ?string $pollingInterval = '10s';  // Refresh every 10 seconds

    protected string $view = 'filament.widgets.live-orders';

    public function getOrders()
    {
        return Order::where('status', 'processing')
            ->latest()
            ->limit(10)
            ->get();
    }
}
```

**Supported Intervals:**
- `'1s'` - Every second (use sparingly!)
- `'5s'` - Every 5 seconds
- `'10s'` - Every 10 seconds
- `'30s'` - Every 30 seconds
- `'1m'` - Every minute
- `null` - No auto-refresh (default)

---

#### Conditional Polling

```php
class OrdersChart extends ChartWidget
{
    protected static ?string $pollingInterval = '30s';

    // Disable polling based on conditions
    public function isPollingEnabled(): bool
    {
        return auth()->user()->hasPermission('view-live-stats')
            && $this->isWidgetVisible();
    }

    // Pause polling when widget not visible
    public function isWidgetVisible(): bool
    {
        return ! $this->isHidden;
    }
}
```

**Best Practices:**
- ⚠️ Use intervals ≥30s for production (prevents server overload)
- ✅ Implement `isPollingEnabled()` for conditional polling
- ✅ Cache expensive queries (see Performance section)
- ❌ Avoid polling <5s without caching

---

#### Manual Refresh

```php
class ManualRefreshWidget extends Widget
{
    protected string $view = 'filament.widgets.manual-refresh';

    public $data = [];

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        $this->data = Cache::remember('widget.data', 300, fn () =>
            ExpensiveModel::with('relations')->get()
        );
    }

    public function refresh(): void
    {
        Cache::forget('widget.data');
        $this->loadData();

        $this->dispatch('data-refreshed');
    }
}
```

**Blade template:**
```blade
<x-filament-widgets::widget>
    <x-slot name="heading">
        Data Overview
        <x-filament::button
            size="xs"
            wire:click="refresh"
            wire:loading.attr="disabled"
            class="ml-auto"
        >
            <x-heroicon-o-arrow-path class="w-4 h-4" />
        </x-filament::button>
    </x-slot>

    <div wire:loading.class="opacity-50">
        @foreach($data as $item)
            {{ $item->name }}
        @endforeach
    </div>
</x-filament-widgets::widget>
```

---

### Widget Actions Integration

**Pattern:** Add action buttons to widget headers/footers

#### Header Actions

```php
<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Filament\Actions\Action;

class OrdersWidget extends Widget
{
    protected string $view = 'filament.widgets.orders';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->action(fn () => $this->dispatch('refresh')),

            Action::make('export')
                ->label('Export')
                ->icon('heroicon-o-document-arrow-down')
                ->action(fn () => $this->export()),
        ];
    }

    protected function getFooterActions(): array
    {
        return [
            Action::make('viewAll')
                ->label('View All Orders')
                ->url(fn (): string => OrderResource::getUrl('index'))
                ->icon('heroicon-o-arrow-right'),
        ];
    }

    public function export(): void
    {
        // Export logic
    }
}
```

**Blade template:**
```blade
<x-filament-widgets::widget>
    <x-slot name="heading">
        Recent Orders
    </x-slot>

    {{-- Actions automatically rendered in header --}}

    <div>
        {{-- Widget content --}}
    </div>

    {{-- Footer actions automatically rendered at bottom --}}
</x-filament-widgets::widget>
```

---

#### Action Modals

```php
protected function getHeaderActions(): array
{
    return [
        Action::make('create')
            ->label('Create Order')
            ->icon('heroicon-o-plus')
            ->form([
                TextInput::make('customer_name')->required(),
                Select::make('product_id')->options(Product::pluck('name', 'id')),
            ])
            ->action(function (array $data): void {
                Order::create($data);

                Notification::make()
                    ->title('Order created')
                    ->success()
                    ->send();

                $this->dispatch('refresh');
            }),
    ];
}
```

---

## Page Architecture

### Page Types

#### 1. Simple Pages

**Purpose:** Static content pages
**Example:** Dashboard, About, Help

```php
use Filament\Pages\Page;

class Dashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-home';
    protected static string $view = 'filament.pages.dashboard';
}
```

#### 2. Form Pages

**Purpose:** Settings, configuration
**Example:** System Settings, Profile

```php
use Filament\Pages\Page;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;

class SystemSettings extends Page implements HasForms
{
    use InteractsWithForms;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'site_name' => settings('site_name'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('General Settings')
                    ->schema([
                        TextInput::make('site_name'),
                    ]),
            ])
            ->statePath('data');
    }
}
```

#### 3. Resource Pages

**Purpose:** CRUD operations
**Types:** List, Create, Edit, View

```php
// Generated by: php artisan make:filament-resource User
class ListUsers extends ListRecords { }
class CreateUser extends CreateRecord { }
class EditUser extends EditRecord { }
class ViewUser extends ViewRecord { }
```

---

## Resource Architecture

### Resource Structure

```php
<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use Filament\Resources\Resource;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'User Management';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Personal Information')
                ->schema([
                    Forms\Components\TextInput::make('first_name')
                        ->required(),
                    Forms\Components\TextInput::make('last_name')
                        ->required(),
                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->required(),
                ]),

            Forms\Components\Section::make('Security')
                ->schema([
                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->required(fn ($context) => $context === 'create'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'active',
                        'danger' => 'inactive',
                    ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
```

---

### Table Empty States & Header Actions

**Pattern:** Customize table appearance when no records exist and add header-level actions.

#### Empty State Customization

```php
public static function table(Table $table): Table
{
    return $table
        ->columns([...])
        ->emptyStateHeading('No customers yet')
        ->emptyStateDescription('Get started by creating your first customer.')
        ->emptyStateIcon('heroicon-o-users')
        ->emptyStateActions([
            Action::make('create')
                ->label('Create Customer')
                ->url(fn (): string => CustomerResource::getUrl('create'))
                ->icon('heroicon-o-plus')
                ->button(),
        ]);
}
```

**Advanced Empty State:**
```php
->emptyStateHeading(fn (): string =>
    auth()->user()->can('create_customer')
        ? 'No customers yet'
        : 'No customers found'
)
->emptyStateDescription(fn (): string =>
    auth()->user()->can('create_customer')
        ? 'Create your first customer to get started.'
        : 'Contact your administrator to add customers.'
)
->emptyStateActions(
    fn (): array => auth()->user()->can('create_customer')
        ? [Action::make('create')->url(CustomerResource::getUrl('create'))]
        : []
);
```

---

#### Header Actions

**Pattern:** Add actions above the table (import, export, bulk operations).

```php
public static function table(Table $table): Table
{
    return $table
        ->columns([...])
        ->headerActions([
            Action::make('import')
                ->label('Import Customers')
                ->icon('heroicon-o-arrow-up-tray')
                ->form([
                    FileUpload::make('file')
                        ->acceptedFileTypes(['text/csv', 'application/vnd.ms-excel'])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    // Import logic
                    $imported = Excel::import(new CustomersImport, $data['file']);

                    Notification::make()
                        ->title($imported . ' customers imported')
                        ->success()
                        ->send();
                }),

            Action::make('export')
                ->label('Export to CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn (): Response =>
                    Excel::download(new CustomersExport, 'customers.csv')
                ),

            Action::make('bulk_email')
                ->label('Send Email to All')
                ->icon('heroicon-o-envelope')
                ->requiresConfirmation()
                ->form([
                    TextInput::make('subject')->required(),
                    Textarea::make('message')->required(),
                ])
                ->action(function (array $data): void {
                    // Send to all customers
                }),
        ])
        ->filters([...])
        ->actions([...]);
}
```

---

#### Combined Pattern: Empty State + Header Actions

```php
public static function table(Table $table): Table
{
    return $table
        ->columns([
            Tables\Columns\TextColumn::make('name'),
            Tables\Columns\TextColumn::make('email'),
        ])
        ->headerActions([
            Action::make('import')
                ->icon('heroicon-o-arrow-up-tray')
                ->url(fn (): string => route('customers.import')),
        ])
        ->emptyStateHeading('No customers imported yet')
        ->emptyStateDescription('Import your customer list to get started.')
        ->emptyStateIcon('heroicon-o-document-arrow-up')
        ->emptyStateActions([
            Action::make('import')
                ->label('Import Customers')
                ->url(fn (): string => route('customers.import'))
                ->icon('heroicon-o-arrow-up-tray')
                ->button(),

            Action::make('createManually')
                ->label('Create Manually')
                ->url(fn (): string => CustomerResource::getUrl('create'))
                ->icon('heroicon-o-plus'),
        ]);
}
```

---

## Common Mistakes to Avoid

### 1. Widget/Section Nesting ⚠️ CRITICAL

**Problem:** Nesting `<x-filament::section>` inside `<x-filament-widgets::widget>`

❌ **WRONG:**
```blade
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Title</x-slot>
        Content
    </x-filament::section>
</x-filament-widgets::widget>
```

✅ **CORRECT:**
```blade
<x-filament-widgets::widget>
    <x-slot name="heading">Title</x-slot>
    <div>Content</div>
</x-filament-widgets::widget>
```

**Why:** Widgets have built-in layout and styling. Section adds conflicting wrapper that breaks spacing, icons, and responsive behavior.

**Symptoms:**
- Giant icons appearing above heading
- Double borders
- Broken responsive layout
- Inconsistent spacing

---

### 2. Using Old Namespaces (v3 → v4)

**Problem:** Using deprecated v3 namespaces

❌ **WRONG (v3):**
```php
use Filament\Forms\Components\Section;  // Old namespace
use Filament\Forms\Components\Grid;     // Old namespace
```

✅ **CORRECT (v4):**
```php
use Filament\Schemas\Components\Section;  // Layout components
use Filament\Schemas\Components\Grid;     // Layout components
use Filament\Forms\Components\TextInput;  // Data entry components
```

**Rule:** Layout components (Section, Grid, Tabs) → `Filament\Schemas\Components`

---

### 3. Mixing Form and Infolist Components

**Problem:** Using wrong component type for display

❌ **WRONG:**
```php
// In ViewUser page (should use Infolist)
Forms\Components\TextInput::make('name')->disabled();
```

✅ **CORRECT:**
```php
// Use Infolist for read-only display
use Filament\Infolists\Components\TextEntry;

TextEntry::make('name');
```

**Rule:** Forms = input/edit, Infolists = display/view

---

### 4. Incorrect Widget Registration

**Problem:** Trying to use widgets in wrong contexts

❌ **WRONG:**
```php
// In Resource form schema
Section::make()->schema([
    CacheClearWidget::class,  // Widgets can't be in forms
]);
```

✅ **CORRECT:**
```php
// In Page or Resource Page
protected function getHeaderWidgets(): array
{
    return [CacheClearWidget::class];
}
```

---

### 5. Missing Authorization Checks

**Problem:** Widgets visible to unauthorized users

❌ **WRONG:**
```php
class SensitiveWidget extends Widget
{
    // No canView() method - visible to all
}
```

✅ **CORRECT:**
```php
class SensitiveWidget extends Widget
{
    public static function canView(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
}
```

---

### 6. Static Property Errors

**Problem:** Incorrectly making properties static

❌ **WRONG:**
```php
protected static string $view = 'filament.widgets.my-widget';
// Error: Cannot redeclare non-static property
```

✅ **CORRECT:**
```php
protected string $view = 'filament.widgets.my-widget';
// $view should NOT be static
```

**Rule:** `$view`, `$columnSpan` are NOT static. Only `$sort`, `$navigationIcon`, `$navigationLabel` are static.

---

## Quick Reference

### Component Decision Tree

```
Need to display data?
├─ Editable? → Use Form with Forms\Components
└─ Read-only? → Use Infolist with Infolists\Components

Need a reusable dashboard element?
└─ Use Widget (Stats, Chart, Table, Custom)

Need layout structure?
├─ In Form/Page? → Use Schemas\Components\Section
└─ In Widget? → Use plain HTML/Tailwind (NO Section)

Need to manage a model?
└─ Use Resource with Form + Table

Need a custom page?
└─ Use Page (can contain Widgets, Sections, Forms)
```

### Namespace Quick Reference

| Component Type | Namespace |
|----------------|-----------|
| Layout (Section, Grid, Tabs) | `Filament\Schemas\Components` |
| Form Inputs (TextInput, Select) | `Filament\Forms\Components` |
| Table Columns (TextColumn) | `Filament\Tables\Columns` |
| Infolist Entries (TextEntry) | `Filament\Infolists\Components` |
| Widgets | `Filament\Widgets` |
| Pages | `Filament\Pages` |
| Resources | `Filament\Resources` |

---

## Additional Resources

- **Official Docs:** https://filamentphp.com/docs/4.x/introduction/overview
- **Migration Guide:** [filament-v4-migration-guide.md](filament-v4-migration-guide.md)
- **Best Practices:** [filament-v4-best-practices.md](filament-v4-best-practices.md)
- **Widgets Guide:** [filament-v4-widgets-guide.md](filament-v4-widgets-guide.md)
- **Quick Reference:** [filament-v4-quick-reference.md](filament-v4-quick-reference.md)

---

**Last Updated:** 2025-12-17
**Filament Version:** v4.2.3
**Maintained By:** Development Team
