# Filament v4 Best Practices

**Version:** Filament v4.2.3
**Last Updated:** 2025-12-17
**Purpose:** Comprehensive guide for best practices, patterns, and anti-patterns in Filament v4

---

## Table of Contents

1. [Widget Implementation Patterns](#widget-implementation-patterns)
2. [Component Composition Guidelines](#component-composition-guidelines)
3. [Performance Optimization](#performance-optimization)
4. [Security Best Practices](#security-best-practices)
5. [Testing Strategies](#testing-strategies)
6. [Code Organization](#code-organization)
7. [Common Mistakes to Avoid](#common-mistakes-to-avoid)
8. [Accessibility Guidelines](#accessibility-guidelines)

---

## Widget Implementation Patterns

### ✅ CORRECT Widget Structure

#### Dashboard Widget

```php
<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class CacheClearWidget extends Widget
{
    // NON-static view property
    protected string $view = 'filament.widgets.cache-clear';

    // Column span for layout
    protected int | string | array $columnSpan = 'full';

    // Static sort order
    protected static ?int $sort = 999;

    // Authorization check
    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super-admin']) ?? false;
    }

    // Livewire actions
    public function clearCache(): void
    {
        Cache::flush();

        Notification::make()
            ->title('Cache cleared')
            ->success()
            ->send();
    }
}
```

#### Widget Blade Template

```blade
{{-- resources/views/filament/widgets/cache-clear.blade.php --}}

<x-filament-widgets::widget>
    {{-- Use widget slots for heading/description --}}
    <x-slot name="heading">
        Cache Management
    </x-slot>

    <x-slot name="description">
        Quick cache operations for development
    </x-slot>

    {{-- Direct content (NO Section wrapper) --}}
    <div class="space-y-4">
        <div class="flex gap-2">
            <x-filament::button
                size="sm"
                color="warning"
                wire:click="clearCache"
                wire:loading.attr="disabled"
            >
                <span wire:loading.remove wire:target="clearCache">
                    Clear Cache
                </span>
                <span wire:loading wire:target="clearCache">
                    Clearing...
                </span>
            </x-filament::button>
        </div>
    </div>
</x-filament-widgets::widget>
```

**Key Points:**
- ✅ Use `<x-filament-widgets::widget>` wrapper
- ✅ Heading/description in widget slots
- ✅ Direct content inside widget (no Section)
- ✅ Livewire directives (`wire:click`, `wire:loading`)
- ✅ Proper authorization check

---

### ❌ WRONG Widget Patterns

#### Mistake 1: Section Wrapper in Widget

```blade
{{-- ❌ WRONG: Causes layout issues --}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Title</x-slot>
        Content
    </x-filament::section>
</x-filament-widgets::widget>
```

**Problems:**
- Giant icons appear above heading
- Double borders
- Broken responsive layout
- Inconsistent spacing

**Solution:**
Remove Section wrapper, use widget slots directly.

---

#### Mistake 2: Static `$view` Property

```php
// ❌ WRONG: $view should NOT be static
protected static string $view = 'filament.widgets.cache-clear';

// ✅ CORRECT: Non-static property
protected string $view = 'filament.widgets.cache-clear';
```

**Error:**
```
Cannot redeclare non-static Filament\Widgets\Widget::$view as static
```

---

#### Mistake 3: Missing Authorization

```php
// ❌ WRONG: No authorization check
class SensitiveDataWidget extends Widget
{
    // Widget visible to all authenticated users
}

// ✅ CORRECT: Role-based authorization
class SensitiveDataWidget extends Widget
{
    public static function canView(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
}
```

---

## Component Composition Guidelines

### Form Schema Best Practices

#### ✅ CORRECT: Organized with Sections

```php
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;

public static function form(Form $form): Form
{
    return $form->schema([
        Section::make('Personal Information')
            ->description('User contact details')
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('first_name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('last_name')
                        ->required()
                        ->maxLength(255),
                ]),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),
            ]),

        Section::make('Security')
            ->description('Authentication settings')
            ->schema([
                TextInput::make('password')
                    ->password()
                    ->required(fn ($context) => $context === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->minLength(8),
                Select::make('role')
                    ->relationship('roles', 'name')
                    ->required(),
            ]),
    ]);
}
```

**Best Practices:**
- ✅ Group related fields in Sections
- ✅ Use Grid for responsive layouts
- ✅ Add descriptions for clarity
- ✅ Conditional validation (`required(fn...)`
- ✅ Dehydration control for passwords
- ✅ Meaningful field names and labels

---

#### ❌ WRONG: Flat, Unorganized Schema

```php
// ❌ WRONG: No organization, hard to maintain
public static function form(Form $form): Form
{
    return $form->schema([
        TextInput::make('first_name'),
        TextInput::make('last_name'),
        TextInput::make('email'),
        TextInput::make('password'),
        Select::make('role'),
        TextInput::make('phone'),
        TextInput::make('address'),
        // ... 20 more fields
    ]);
}
```

**Problems:**
- Hard to scan and understand
- Poor UX (long scrolling)
- Difficult to maintain
- No logical grouping

---

### Form Performance Patterns

#### Debounce with `live()` 🔴 CRITICAL

**Pattern:** Prevent excessive re-renders on text input.

❌ **WRONG: Immediate re-render on every keystroke**
```php
TextInput::make('search')
    ->reactive()  // OLD: Triggers on EVERY keystroke!
    ->afterStateUpdated(fn ($state) => $this->search($state))
```

✅ **CORRECT: Debounced updates**
```php
TextInput::make('search')
    ->live(debounce: 500)  // Wait 500ms after typing stops
    ->afterStateUpdated(fn ($state) => $this->search($state))
```

**Debounce Guidelines:**
- `debounce: 300` - Real-time search (fast)
- `debounce: 500` - Standard search (balanced)
- `debounce: 1000` - Heavy operations (conservative)
- `debounce: 2000` - API calls (rate-limit friendly)

**Advanced Debounce:**
```php
// Different debounce for different actions
TextInput::make('email')
    ->live(debounce: 300)  // Fast validation
    ->afterStateUpdated(fn ($state) => $this->validateEmail($state)),

TextInput::make('username')
    ->live(debounce: 800)  // Slower API check
    ->afterStateUpdated(fn ($state) => $this->checkAvailability($state)),
```

---

#### Dehydrated Fields for Computed Values

**Pattern:** Prevent computed fields from being saved to database.

```php
TextInput::make('full_name')
    ->label('Full Name')
    ->dehydrated(false)  // Don't save to database
    ->formatStateUsing(fn ($record) =>
        $record ? "{$record->first_name} {$record->last_name}" : ''
    )
    ->disabled(),

TextInput::make('age')
    ->dehydrated(false)  // Computed from birth_date
    ->formatStateUsing(fn ($record) =>
        $record?->birth_date?->age
    )
    ->disabled(),
```

**Conditional Dehydration:**
```php
TextInput::make('password')
    ->password()
    ->dehydrated(fn ($state) => filled($state))  // Only save if provided
    ->required(fn (string $context): bool => $context === 'create'),

Select::make('shipping_method')
    ->options([...])
    ->dehydrated(fn (Get $get): bool =>
        $get('requires_shipping') === true  // Only save if shipping enabled
    ),
```

---

#### Lazy Loading Select Options

**Pattern:** Load options only when needed for large datasets.

❌ **WRONG: Preload all options**
```php
Select::make('customer_id')
    ->options(Customer::pluck('name', 'id'))  // Loads ALL customers!
    ->searchable()
```

✅ **CORRECT: Search-driven loading**
```php
Select::make('customer_id')
    ->relationship('customer', 'name')
    ->searchable()
    ->getSearchResultsUsing(fn (string $search): array =>
        Customer::where('name', 'like', "%{$search}%")
            ->limit(50)
            ->pluck('name', 'id')
            ->toArray()
    )
    ->getOptionLabelUsing(fn ($value): ?string =>
        Customer::find($value)?->name
    )
```

**When to Use `preload()`:**
```php
// ✅ SAFE: Small datasets (<100 records)
Select::make('country_id')
    ->relationship('country', 'name')
    ->searchable()
    ->preload()  // OK for ~200 countries

// ❌ DANGEROUS: Large datasets
Select::make('customer_id')
    ->relationship('customer', 'name')
    ->preload()  // BAD: May load 10,000+ customers!
```

---

#### Reactive Form Dependencies

**Pattern:** Update fields based on other field values.

```php
use Filament\Forms\Get;
use Filament\Forms\Set;

Select::make('country')
    ->options(['US' => 'USA', 'CA' => 'Canada', 'MX' => 'Mexico'])
    ->live()  // Trigger updates
    ->afterStateUpdated(function (Set $set, ?string $state) {
        $set('state', null);  // Reset dependent field
        $set('city', null);
    }),

Select::make('state')
    ->options(fn (Get $get): array =>
        $get('country')
            ? State::where('country', $get('country'))->pluck('name', 'id')->toArray()
            : []
    )
    ->disabled(fn (Get $get): bool => ! $get('country'))
    ->live()
    ->afterStateUpdated(fn (Set $set) => $set('city', null)),

Select::make('city')
    ->options(fn (Get $get): array =>
        $get('state')
            ? City::where('state_id', $get('state'))->pluck('name', 'id')->toArray()
            : []
    )
    ->disabled(fn (Get $get): bool => ! $get('state')),
```

---

### Table Configuration Best Practices

#### ✅ CORRECT: Well-Organized Table

```php
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Actions\Action;

public static function table(Table $table): Table
{
    return $table
        ->columns([
            TextColumn::make('name')
                ->searchable()
                ->sortable()
                ->description(fn ($record) => $record->email),

            TextColumn::make('role.name')
                ->badge()
                ->color(fn ($state) => match ($state) {
                    'super-admin' => 'danger',
                    'admin' => 'warning',
                    default => 'gray',
                }),

            TextColumn::make('created_at')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ])
        ->filters([
            SelectFilter::make('role')
                ->relationship('roles', 'name')
                ->preload(),
        ])
        ->actions([
            Action::make('impersonate')
                ->icon('heroicon-o-user')
                ->visible(fn () => auth()->user()->can('impersonate'))
                ->action(fn ($record) => auth()->user()->impersonate($record)),
            Tables\Actions\EditAction::make(),
        ])
        ->bulkActions([
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make(),
            ]),
        ])
        ->defaultSort('created_at', 'desc');
}
```

**Best Practices:**
- ✅ Searchable key columns
- ✅ Sortable date/name columns
- ✅ Badge for status fields
- ✅ Descriptions for additional context
- ✅ Toggleable columns for less important data
- ✅ Role-based action visibility
- ✅ Sensible default sorting

---

### Infolist Best Practices

#### ✅ CORRECT: Organized Infolist

```php
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Grid;

public function infolist(Infolist $infolist): Infolist
{
    return $infolist->schema([
        Section::make('User Information')
            ->schema([
                Grid::make(2)->schema([
                    TextEntry::make('name')
                        ->icon('heroicon-o-user'),
                    TextEntry::make('email')
                        ->icon('heroicon-o-envelope')
                        ->copyable(),
                ]),
                TextEntry::make('role.name')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'admin' => 'warning',
                        default => 'gray',
                    }),
            ]),

        Section::make('Activity')
            ->schema([
                TextEntry::make('created_at')
                    ->dateTime()
                    ->since(),
                TextEntry::make('last_login_at')
                    ->dateTime()
                    ->placeholder('Never logged in'),
            ]),
    ]);
}
```

**Best Practices:**
- ✅ Use Sections for logical grouping
- ✅ Grid for responsive layouts
- ✅ Icons for visual clarity
- ✅ Copyable for important values (email, API keys)
- ✅ Badges for status fields
- ✅ Placeholders for nullable fields
- ✅ Human-readable dates (`since()`)

---

## Performance Optimization

### Database Query Optimization

#### ✅ CORRECT: Eager Loading

```php
use Filament\Tables\Table;

public static function table(Table $table): Table
{
    return $table
        ->modifyQueryUsing(fn ($query) => $query->with(['roles', 'teams']))
        ->columns([
            TextColumn::make('name'),
            TextColumn::make('role.name'),  // No N+1 query
            TextColumn::make('team.name'),  // No N+1 query
        ]);
}
```

**Benefits:**
- ✅ Prevents N+1 queries
- ✅ Faster page load
- ✅ Reduced database load

---

#### ❌ WRONG: N+1 Query Problem

```php
// ❌ WRONG: N+1 queries when displaying role/team
public static function table(Table $table): Table
{
    return $table
        ->columns([
            TextColumn::make('name'),
            TextColumn::make('role.name'),  // N+1 query!
            TextColumn::make('team.name'),  // N+1 query!
        ]);
}
```

---

### Caching Strategies

#### ✅ CORRECT: Cache Expensive Queries

```php
use Illuminate\Support\Facades\Cache;

class StatsWidget extends Widget
{
    public function getStats(): array
    {
        return Cache::remember('dashboard.stats', now()->addMinutes(5), function () {
            return [
                'total_users' => User::count(),
                'active_users' => User::where('last_login_at', '>', now()->subDays(30))->count(),
                'total_revenue' => Order::sum('total'),
            ];
        });
    }
}
```

**Benefits:**
- ✅ Reduced database queries
- ✅ Faster widget rendering
- ✅ Lower server load

**Important:** Clear cache when data changes:
```php
public function afterCreate(): void
{
    Cache::forget('dashboard.stats');
}
```

---

#### Widget Lazy Loading 🔴 CRITICAL

**Pattern:** Defer widget loading until needed to improve initial page load.

```php
use Filament\Widgets\Widget;

class ExpensiveStatsWidget extends Widget
{
    protected static bool $isLazy = true;  // Enable lazy loading

    protected string $view = 'filament.widgets.expensive-stats';

    public function getStats()
    {
        // This heavy query only runs when widget loads
        return Cache::remember('expensive.stats', 600, fn () =>
            HeavyModel::with(['deep', 'relations'])
                ->selectRaw('SUM(amount) as total, COUNT(*) as count')
                ->groupBy('category')
                ->get()
        );
    }
}
```

**When to Use Lazy Loading:**
- ✅ Widgets below the fold (not immediately visible)
- ✅ Widgets with expensive queries (>500ms)
- ✅ Dashboard with 5+ widgets
- ✅ Charts with complex calculations
- ❌ Critical above-the-fold metrics
- ❌ Real-time data displays

**Performance Impact:**
```
Without lazy loading:
┌─────────────────────────┐
│ Page Load: 3.2s         │
│ - Widget 1: 0.8s        │
│ - Widget 2: 1.2s        │ ← Expensive!
│ - Widget 3: 0.6s        │
│ - Widget 4: 0.6s        │
└─────────────────────────┘

With lazy loading (Widget 2):
┌─────────────────────────┐
│ Initial Load: 2.0s      │
│ - Widget 1: 0.8s        │
│ - Widget 3: 0.6s        │
│ - Widget 4: 0.6s        │
│ Widget 2 loads after: +1.2s (async)
└─────────────────────────┘
```

---

#### Query Memoization with `once()`

**Pattern:** Cache query results within single request to prevent duplicate queries.

```php
use function Laravel\Prompts\{once};

class OrderStatsWidget extends Widget
{
    public function getTodayOrders()
    {
        return once(function () {
            return Order::whereDate('created_at', today())
                ->with(['customer', 'items'])
                ->get();
        });
    }

    public function getTodayRevenue()
    {
        // Reuses getTodayOrders() result - no additional query!
        return $this->getTodayOrders()->sum('total');
    }

    public function getTodayCount()
    {
        // Reuses getTodayOrders() result - no additional query!
        return $this->getTodayOrders()->count();
    }
}
```

**Before `once()` (N queries):**
```php
// ❌ WRONG: Each method triggers separate query
public function getTodayRevenue() {
    return Order::whereDate('created_at', today())->sum('total'); // Query 1
}

public function getTodayCount() {
    return Order::whereDate('created_at', today())->count(); // Query 2 (duplicate!)
}
```

**After `once()` (1 query):**
```php
// ✅ CORRECT: Single query, reused result
protected function getTodayOrders() {
    return once(fn () => Order::whereDate('created_at', today())->get());
}

public function getTodayRevenue() {
    return $this->getTodayOrders()->sum('total'); // Reuses
}

public function getTodayCount() {
    return $this->getTodayOrders()->count(); // Reuses
}
```

**Benefits:**
- ✅ Eliminates duplicate queries in same request
- ✅ Cleaner code (DRY principle)
- ✅ Automatic memory cleanup after request
- ✅ No manual cache invalidation needed

---

#### Cache Tagging for Granular Invalidation

**Pattern:** Organize cache with tags for selective clearing.

```php
use Illuminate\Support\Facades\Cache;

class OrderStatsWidget extends Widget
{
    public function getStats(): array
    {
        return Cache::tags(['orders', 'stats', 'dashboard'])
            ->remember('orders.stats', 300, function () {
                return [
                    'today' => Order::today()->count(),
                    'week' => Order::thisWeek()->count(),
                    'month' => Order::thisMonth()->count(),
                ];
            });
    }
}

// Clear only order-related cache
Cache::tags(['orders'])->flush();

// Clear all dashboard cache
Cache::tags(['dashboard'])->flush();

// Clear specific cache key
Cache::tags(['orders', 'stats'])->forget('orders.stats');
```

**Model Event Integration:**
```php
// app/Models/Order.php
protected static function booted(): void
{
    parent::booted();

    static::saved(function () {
        // Clear all order-related cache
        Cache::tags(['orders'])->flush();
    });

    static::deleted(function () {
        Cache::tags(['orders', 'stats'])->flush();
    });
}
```

---

#### User-Specific Cache Keys

**Pattern:** Prevent cache collision between users.

```php
class UserDashboardWidget extends Widget
{
    protected function getStats(): array
    {
        $userId = auth()->id();

        return Cache::remember("dashboard.stats.user.{$userId}", 300, function () {
            return [
                'my_orders' => auth()->user()->orders()->count(),
                'my_revenue' => auth()->user()->orders()->sum('total'),
            ];
        });
    }
}
```

**Clear user-specific cache:**
```php
// On user data change
public function afterSave(): void
{
    $userId = $this->record->id;
    Cache::forget("dashboard.stats.user.{$userId}");
}

// Clear all user caches (admin action)
public function clearAllUserCaches(): void
{
    User::chunk(100, function ($users) {
        foreach ($users as $user) {
            Cache::forget("dashboard.stats.user.{$user->id}");
        }
    });
}
```

---

### Asset Optimization

#### ✅ CORRECT: Optimize Production Build

```bash
# Build for production
npm run build

# Verify output
ls -lh public/build/

# Clear Filament cache
php artisan filament:optimize
```

**vite.config.js:**
```javascript
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    build: {
        rollupOptions: {
            output: {
                manualChunks: {
                    'vendor': ['alpinejs', 'livewire'],
                },
            },
        },
    },
});
```

---

## Security Best Practices

### Authorization

#### ✅ CORRECT: Resource-Level Authorization

```php
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    // Policy-based authorization
    public static function canViewAny(): bool
    {
        return auth()->user()->can('viewAny', User::class);
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can('create', User::class);
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->can('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can('delete', $record);
    }
}
```

**Benefits:**
- ✅ Uses Laravel Policies
- ✅ Consistent authorization logic
- ✅ Prevents unauthorized access

---

#### ❌ WRONG: No Authorization Checks

```php
// ❌ WRONG: All authenticated users can access
class UserResource extends Resource
{
    // No authorization methods
}
```

**Risk:** Unauthorized users can view/edit sensitive data.

---

#### Authorization Granularity 🔴 CRITICAL

**Pattern:** Implement fine-grained authorization including soft deletes and record-specific rules.

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderResource extends Resource
{
    // Basic CRUD authorization
    public static function canViewAny(): bool
    {
        return auth()->user()->can('viewAny', Order::class);
    }

    public static function canCreate(): bool
    {
        return auth()->user()->can('create', Order::class);
    }

    public static function canEdit(Model $record): bool
    {
        // Record-specific authorization
        return auth()->user()->can('update', $record)
            && $record->status !== 'completed'  // Can't edit completed orders
            && $record->user_id === auth()->id();  // Only own orders
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can('delete', $record)
            && $record->status === 'pending'  // Only pending orders
            && $record->created_at->isToday();  // Only today's orders
    }

    // Soft delete authorization
    public static function canForceDelete(Model $record): bool
    {
        return auth()->user()->hasRole('super-admin')
            && $record->trashed();
    }

    public static function canRestore(Model $record): bool
    {
        return auth()->user()->can('restore', $record)
            && $record->trashed()
            && $record->deleted_at->greaterThan(now()->subDays(30));  // Within 30 days
    }

    // Replicate authorization
    public static function canReplicate(Model $record): bool
    {
        return auth()->user()->can('create', Order::class)
            && $record->status === 'completed';  // Only replicate completed
    }
}
```

---

#### Query Scoping for Multi-Tenancy

**Pattern:** Automatically scope queries by tenant or user context.

```php
class OrderResource extends Resource
{
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->when(
                ! auth()->user()->hasRole('admin'),
                fn ($query) => $query->where('user_id', auth()->id())
            )
            ->when(
                Filament::getTenant(),
                fn ($query) => $query->where('tenant_id', Filament::getTenant()->id)
            );
    }
}
```

**Advanced Scoping:**
```php
// Scope by department
public static function getEloquentQuery(): Builder
{
    $query = parent::getEloquentQuery();

    // Admins see all
    if (auth()->user()->hasRole('admin')) {
        return $query;
    }

    // Managers see their department
    if (auth()->user()->hasRole('manager')) {
        return $query->where('department_id', auth()->user()->department_id);
    }

    // Regular users see only their records
    return $query->where('user_id', auth()->id());
}
```

---

#### Action-Level Authorization

**Pattern:** Control individual table/form actions based on record state.

```php
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\DeleteAction;

public static function table(Table $table): Table
{
    return $table
        ->actions([
            EditAction::make()
                ->visible(fn (Model $record): bool =>
                    $record->status !== 'completed'
                    && auth()->user()->can('update', $record)
                ),

            Action::make('approve')
                ->icon('heroicon-o-check')
                ->visible(fn (Model $record): bool =>
                    $record->status === 'pending'
                    && auth()->user()->hasPermission('approve-orders')
                )
                ->requiresConfirmation()
                ->action(fn (Model $record) => $record->approve()),

            Action::make('cancel')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn (Model $record): bool =>
                    $record->status !== 'completed'
                    && ($record->user_id === auth()->id()
                        || auth()->user()->hasRole('admin'))
                )
                ->requiresConfirmation()
                ->action(fn (Model $record) => $record->cancel()),

            DeleteAction::make()
                ->visible(fn (Model $record): bool =>
                    $record->status === 'draft'
                    && auth()->user()->can('delete', $record)
                ),
        ]);
}
```

---

### Input Validation

#### ✅ CORRECT: Comprehensive Validation

```php
use Filament\Forms\Components\TextInput;

TextInput::make('email')
    ->email()
    ->required()
    ->unique(table: User::class, ignoreRecord: true)
    ->maxLength(255);

TextInput::make('phone')
    ->tel()
    ->regex('/^[0-9]{9}$/')
    ->required();

TextInput::make('website')
    ->url()
    ->nullable();
```

**Best Practices:**
- ✅ Use built-in validation rules
- ✅ Regex for custom patterns
- ✅ Unique validation with `ignoreRecord` for edits
- ✅ Proper nullable handling

---

### Mass Assignment Protection

#### ✅ CORRECT: Protected Attributes

```php
// Model
class User extends Authenticatable
{
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
    ];

    protected $guarded = [
        'is_admin',  // Prevent mass assignment
        'role_id',   // Use relationships
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];
}

// Resource
public static function form(Form $form): Form
{
    return $form->schema([
        // Only allow editing via specific method
        Select::make('role')
            ->relationship('roles', 'name')
            ->visible(fn () => auth()->user()->can('assignRoles')),
    ]);
}
```

---

## Testing Strategies

### Feature Tests for Resources

#### ✅ CORRECT: Comprehensive Resource Tests

```php
<?php

use App\Models\User;
use function Pest\Laravel\{actingAs, get, post};
use function Pest\Livewire\livewire;

it('allows admin to view user list', function () {
    $admin = User::factory()->create()->assignRole('admin');

    actingAs($admin)
        ->get('/admin/users')
        ->assertSuccessful();
});

it('prevents non-admin from accessing users', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get('/admin/users')
        ->assertForbidden();
});

it('validates required fields on user creation', function () {
    $admin = User::factory()->create()->assignRole('admin');

    livewire(\App\Filament\Resources\UserResource\Pages\CreateUser::class)
        ->actingAs($admin)
        ->fillForm([
            'email' => 'invalid-email',  // Invalid email
        ])
        ->call('create')
        ->assertHasFormErrors(['email', 'first_name', 'last_name']);
});

it('creates user with valid data', function () {
    $admin = User::factory()->create()->assignRole('admin');

    livewire(\App\Filament\Resources\UserResource\Pages\CreateUser::class)
        ->actingAs($admin)
        ->fillForm([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'john@example.com')->exists())->toBeTrue();
});
```

---

### Livewire Component Testing 🔴 CRITICAL

**Pattern:** Test Filament resources using Livewire testing helpers.

#### Testing Form Validation

```php
use function Pest\Livewire\livewire;

it('validates email format', function () {
    $admin = User::factory()->create()->assignRole('admin');

    livewire(CreateUser::class)
        ->actingAs($admin)
        ->fillForm([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'not-an-email',  // Invalid
        ])
        ->call('create')
        ->assertHasFormErrors(['email' => 'email']);
});

it('validates unique email', function () {
    $existing = User::factory()->create(['email' => 'john@example.com']);
    $admin = User::factory()->create()->assignRole('admin');

    livewire(CreateUser::class)
        ->actingAs($admin)
        ->fillForm([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'john@example.com',  // Duplicate
        ])
        ->call('create')
        ->assertHasFormErrors(['email' => 'unique']);
});
```

---

#### Testing Table Actions

```php
it('admin can cancel appointment', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $appointment = Appointment::factory()->create(['status' => 'pending']);

    livewire(ListAppointments::class)
        ->actingAs($admin)
        ->callTableAction('cancel', $appointment)
        ->assertSuccessful();

    expect($appointment->fresh()->status)->toBe('cancelled');
});

it('cannot cancel completed appointment', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $appointment = Appointment::factory()->create(['status' => 'completed']);

    livewire(ListAppointments::class)
        ->actingAs($admin)
        ->callTableAction('cancel', $appointment)
        ->assertActionHidden('cancel');
});
```

---

#### Testing Table Bulk Actions

```php
it('admin can bulk delete appointments', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $appointments = Appointment::factory()->count(5)->create();

    livewire(ListAppointments::class)
        ->actingAs($admin)
        ->callTableBulkAction('delete', $appointments)
        ->assertSuccessful();

    expect(Appointment::count())->toBe(0);
});
```

---

#### Testing Table Filters

```php
it('filters appointments by status', function () {
    $admin = User::factory()->create()->assignRole('admin');

    Appointment::factory()->create(['status' => 'pending']);
    Appointment::factory()->create(['status' => 'confirmed']);
    Appointment::factory()->create(['status' => 'completed']);

    livewire(ListAppointments::class)
        ->actingAs($admin)
        ->filterTable('status', 'pending')
        ->assertCanSeeTableRecords(
            Appointment::where('status', 'pending')->get()
        )
        ->assertCanNotSeeTableRecords(
            Appointment::whereNot('status', 'pending')->get()
        );
});
```

---

#### Testing Authorization

```php
it('regular user cannot access admin panel', function () {
    $user = User::factory()->create();

    livewire(ListAppointments::class)
        ->actingAs($user)
        ->assertForbidden();
});

it('admin can view all users', function () {
    $admin = User::factory()->create()->assignRole('admin');
    User::factory()->count(5)->create();

    livewire(ListUsers::class)
        ->actingAs($admin)
        ->assertSuccessful()
        ->assertCountTableRecords(6);  // 5 + admin
});

it('user can only view their own appointments', function () {
    $user = User::factory()->create();
    Appointment::factory()->count(3)->create(['user_id' => $user->id]);
    Appointment::factory()->count(2)->create();  // Other users

    livewire(ListAppointments::class)
        ->actingAs($user)
        ->assertSuccessful()
        ->assertCountTableRecords(3);
});
```

---

#### Testing Form State

```php
it('updates related fields when country changes', function () {
    $admin = User::factory()->create()->assignRole('admin');

    livewire(CreateAddress::class)
        ->actingAs($admin)
        ->fillForm([
            'country' => 'US',
            'state' => 'CA',
            'city' => 'Los Angeles',
        ])
        ->set('data.country', 'CA')  // Change country
        ->assertFormSet([
            'state' => null,  // Should reset
            'city' => null,   // Should reset
        ]);
});
```

---

#### Testing Widgets

```php
it('displays stats overview widget', function () {
    $admin = User::factory()->create()->assignRole('admin');

    User::factory()->count(10)->create();
    Order::factory()->count(20)->create();

    livewire(StatsOverview::class)
        ->actingAs($admin)
        ->assertSee('Total Users: 11')  // 10 + admin
        ->assertSee('Total Orders: 20');
});

it('widget polls for updates', function () {
    $admin = User::factory()->create()->assignRole('admin');

    $component = livewire(LiveOrdersWidget::class)
        ->actingAs($admin);

    // Create order after mount
    Order::factory()->create();

    // Dispatch polling event
    $component->dispatch('$refresh')
        ->assertSee('1 order');
});
```

---

#### Testing Notifications

```php
it('sends notification on successful save', function () {
    $admin = User::factory()->create()->assignRole('admin');

    livewire(CreateUser::class)
        ->actingAs($admin)
        ->fillForm([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
        ])
        ->call('create')
        ->assertNotified('User created successfully');
});

it('sends error notification on failure', function () {
    $admin = User::factory()->create()->assignRole('admin');

    // Mock service to throw exception
    $this->mock(UserService::class)
        ->shouldReceive('create')
        ->andThrow(new \Exception('Database error'));

    livewire(CreateUser::class)
        ->actingAs($admin)
        ->fillForm([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
        ])
        ->call('create')
        ->assertNotified('Failed to create user', type: 'danger');
});
```

---

### Test Organization Best Practices

```
tests/
├── Feature/
│   ├── Admin/
│   │   ├── Resources/
│   │   │   ├── UserResourceTest.php
│   │   │   ├── OrderResourceTest.php
│   │   │   └── AppointmentResourceTest.php
│   │   ├── Widgets/
│   │   │   ├── StatsOverviewTest.php
│   │   │   └── CacheClearWidgetTest.php
│   │   └── Pages/
│   │       ├── DashboardTest.php
│   │       └── SystemSettingsTest.php
│   │
│   └── Auth/
│       ├── LoginTest.php
│       └── RegistrationTest.php
│
└── Unit/
    ├── Models/
    ├── Services/
    └── Actions/
```

**Best Practices:**
- ✅ Group tests by feature area
- ✅ Separate unit and feature tests
- ✅ Use descriptive test names
- ✅ Test authorization thoroughly
- ✅ Test validation rules
- ✅ Test table actions and bulk actions

---

## Code Organization

### File Structure Best Practices

```
app/Filament/
├── Resources/
│   ├── UserResource.php
│   ├── UserResource/
│   │   ├── Pages/
│   │   │   ├── ListUsers.php
│   │   │   ├── CreateUser.php
│   │   │   └── EditUser.php
│   │   └── RelationManagers/
│   │       └── RolesRelationManager.php
│   │
│   └── AppointmentResource.php
│
├── Pages/
│   ├── Dashboard.php
│   └── SystemSettings.php
│
└── Widgets/
    ├── StatsOverview.php
    ├── CacheClearWidget.php
    └── RecentActivityWidget.php
```

**Best Practices:**
- ✅ One Resource per model
- ✅ Dedicated directories for complex resources
- ✅ Separate Pages for custom functionality
- ✅ Reusable Widgets for dashboard/pages

---

## Common Mistakes to Avoid

### 1. Widget/Section Nesting ⚠️ CRITICAL

**Issue:** Most common mistake in Filament v4

❌ **WRONG:**
```blade
<x-filament-widgets::widget>
    <x-filament::section>
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

---

### 2. Forgetting Eager Loading

**Issue:** N+1 query problems

❌ **WRONG:**
```php
public static function table(Table $table): Table
{
    return $table->columns([
        TextColumn::make('user.name'),  // N+1 query
    ]);
}
```

✅ **CORRECT:**
```php
public static function table(Table $table): Table
{
    return $table
        ->modifyQueryUsing(fn ($query) => $query->with('user'))
        ->columns([
            TextColumn::make('user.name'),  // Single query
        ]);
}
```

---

### 3. Missing Authorization Checks

**Issue:** Security vulnerability

❌ **WRONG:**
```php
class SensitiveDataResource extends Resource
{
    // No authorization
}
```

✅ **CORRECT:**
```php
class SensitiveDataResource extends Resource
{
    public static function canViewAny(): bool
    {
        return auth()->user()->can('viewSensitiveData');
    }
}
```

---

### 4. Hardcoded Values

**Issue:** Difficult to maintain

❌ **WRONG:**
```php
Select::make('status')->options([
    'active' => 'Active',
    'inactive' => 'Inactive',
]);
```

✅ **CORRECT:**
```php
// In Enum
enum UserStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => __('Active'),
            self::INACTIVE => __('Inactive'),
        };
    }
}

// In Resource
Select::make('status')
    ->options(UserStatus::class)
    ->required();
```

---

### 5. Not Clearing Cache

**Issue:** Old data persists

❌ **WRONG:**
```php
public function updateSettings(): void
{
    settings()->set('site_name', $this->form->getState()['site_name']);
    // Cache not cleared - old value persists
}
```

✅ **CORRECT:**
```php
public function updateSettings(): void
{
    settings()->set('site_name', $this->form->getState()['site_name']);
    Cache::tags('settings')->flush();

    Notification::make()
        ->title('Settings updated')
        ->success()
        ->send();
}
```

---

## Accessibility Guidelines

### WCAG 2.2 AA Compliance

#### ✅ CORRECT: Accessible Forms

```php
TextInput::make('email')
    ->label('Email Address')  // Explicit label
    ->helperText('We will never share your email')  // Helper text
    ->required()
    ->email();

Select::make('country')
    ->label('Country')
    ->options(Country::pluck('name', 'code'))
    ->searchable()  // Keyboard navigation
    ->required();
```

**Best Practices:**
- ✅ Always provide labels
- ✅ Use helper text for complex fields
- ✅ Enable searchable for long lists
- ✅ Proper ARIA attributes (handled by Filament)

---

## Quick Reference Checklist

### Before Deploying to Production

- [ ] All Resources have authorization checks
- [ ] Forms use proper validation
- [ ] Tables use eager loading for relationships
- [ ] Widgets have no Section wrappers
- [ ] Cache cleared after settings changes
- [ ] Production assets built (`npm run build`)
- [ ] Filament optimized (`php artisan filament:optimize`)
- [ ] Tests passing
- [ ] No console errors
- [ ] WCAG AA compliance verified

---

## Additional Resources

- **Component Architecture:** [filament-v4-component-architecture.md](filament-v4-component-architecture.md)
- **Migration Guide:** [filament-v4-migration-guide.md](filament-v4-migration-guide.md)
- **Widgets Guide:** [filament-v4-widgets-guide.md](filament-v4-widgets-guide.md)
- **Official Docs:** https://filamentphp.com/docs/4.x/introduction/overview

---

**Last Updated:** 2025-12-17
**Filament Version:** v4.2.3
**Maintained By:** Development Team
