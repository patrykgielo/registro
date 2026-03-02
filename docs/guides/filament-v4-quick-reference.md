# Filament v4 Quick Reference Card

**Version:** Filament v4.2.3
**Last Updated:** 2025-12-17
**Purpose:** One-page cheat sheet for common Filament tasks

---

## Component Nesting Rules

### ✅ CORRECT Patterns

```php
// Widget with direct content
<x-filament-widgets::widget>
    <x-slot name="heading">Title</x-slot>
    <x-slot name="description">Subtitle</x-slot>
    <div>Content here</div>
</x-filament-widgets::widget>

// Page with sections
<x-filament-panels::page>
    <x-filament::section heading="Title" description="Subtitle">
        Content here
    </x-filament::section>
</x-filament-panels::page>

// Form with sections
use Filament\Schemas\Components\Section;

Section::make('User Details')
    ->schema([
        TextInput::make('name'),
        TextInput::make('email'),
    ])
```

### ❌ WRONG Patterns

```php
// DON'T nest Section in Widget (causes layout conflicts)
<x-filament-widgets::widget>
    <x-filament::section>  ← WRONG!
        Content
    </x-filament::section>
</x-filament-widgets::widget>

// DON'T use old v3 namespace
use Filament\Forms\Components\Section;  ← WRONG! v3 namespace
```

---

## v4 Critical Namespace Changes

| Component Type | ❌ v3 Namespace | ✅ v4 Namespace |
|----------------|----------------|----------------|
| **Section** (Layout) | `Forms\Components\Section` | `Schemas\Components\Section` |
| **Grid** (Layout) | `Forms\Components\Grid` | `Schemas\Components\Grid` |
| **Tabs** (Layout) | `Forms\Components\Tabs` | `Schemas\Components\Tabs` |
| **TextEntry** (Display) | `Infolists\Components\Entry` | `Infolists\Components\TextEntry` |
| **IconEntry** (Display) | `Infolists\Components\Entry` | `Infolists\Components\IconEntry` |

**Quick Fix:**

```php
// v3 (OLD)
use Filament\Forms\Components\Section;
use Filament\Infolists\Components\Entry;

// v4 (NEW)
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
```

---

## Common Tasks

### Create a Stats Widget

```php
php artisan make:filament-widget StatsOverview --stats
```

```php
protected function getStats(): array
{
    return [
        Stat::make('Total Users', User::count())
            ->description('Registered accounts')
            ->descriptionIcon('heroicon-o-users')
            ->color('success')
            ->chart([7, 2, 10, 3, 15, 4, 17]),
    ];
}
```

**See:** [Widgets Guide - Stats Widgets](filament-v4-widgets-guide.md#stats-widgets)

---

### Create a Chart Widget

```php
php artisan make:filament-widget RevenueChart --chart
```

```php
protected function getData(): array
{
    return [
        'datasets' => [
            [
                'label' => 'Revenue',
                'data' => [12000, 19000, 15000],
                'borderColor' => '#10b981',
            ],
        ],
        'labels' => ['Jan', 'Feb', 'Mar'],
    ];
}

protected function getType(): string
{
    return 'line'; // or 'bar', 'pie'
}
```

**See:** [Widgets Guide - Chart Widgets](filament-v4-widgets-guide.md#chart-widgets)

---

### Create a Table Widget

```php
php artisan make:filament-widget LatestOrders --table
```

```php
public function table(Table $table): Table
{
    return $table
        ->query(Order::query()->latest()->limit(10))
        ->columns([
            Tables\Columns\TextColumn::make('order_number'),
            Tables\Columns\BadgeColumn::make('status'),
        ]);
}
```

**See:** [Widgets Guide - Table Widgets](filament-v4-widgets-guide.md#table-widgets)

---

### Add Widget to Dashboard

```php
// app/Providers/Filament/AdminPanelProvider.php

public function panel(Panel $panel): Panel
{
    return $panel
        ->widgets([
            \App\Filament\Widgets\StatsOverview::class,
            \App\Filament\Widgets\RevenueChart::class,
        ]);
}
```

---

### Lazy Load Expensive Widget

```php
class ExpensiveWidget extends Widget
{
    protected static bool $isLazy = true;  // ← Load after page renders

    public function getData()
    {
        // Heavy query runs only when widget loads
        return ExpensiveModel::with('relations')->get();
    }
}
```

**See:** [Best Practices - Performance Optimization](filament-v4-best-practices.md#performance-optimization)

---

### Cache Widget Data

```php
protected function getStats(): array
{
    $count = Cache::remember('users.count', 300, fn () =>
        User::count()
    );

    return [Stat::make('Total Users', $count)];
}

// Clear cache on model changes
protected static function booted(): void
{
    static::saved(fn () => Cache::forget('users.count'));
}
```

**See:** [Best Practices - Performance Optimization](filament-v4-best-practices.md#performance-optimization)

---

### Form Debouncing

```php
TextInput::make('search')
    ->live(debounce: 500)  // ← Wait 500ms after typing stops
    ->afterStateUpdated(fn ($state) => $this->search($state))
```

**See:** [Best Practices - Component Composition](filament-v4-best-practices.md#component-composition-guidelines)

---

### Responsive Grid

```php
Grid::make([
    'default' => 1,  // Mobile: 1 column
    'sm' => 2,       // Small: 2 columns
    'md' => 3,       // Medium: 3 columns
    'lg' => 4,       // Large: 4 columns
    'xl' => 6,       // Extra large: 6 columns
])
->schema([
    TextInput::make('name'),
    TextInput::make('email'),
])
```

**See:** [Component Architecture - Responsive Grid](filament-v4-component-architecture.md#responsive-grid-with-breakpoints)

---

### Authorization

```php
// Resource-level
public static function canViewAny(): bool
{
    return auth()->user()->hasRole('admin');
}

// Record-level
public static function canEdit(Model $record): bool
{
    return auth()->user()->can('edit', $record);
}

// Query scoping
public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()
        ->where('user_id', auth()->id());
}
```

**See:** [Best Practices - Security](filament-v4-best-practices.md#security-best-practices)

---

### Widget Polling

```php
class LiveStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '10s';  // ← Auto-refresh every 10s

    protected function getStats(): array
    {
        return [
            Stat::make('Active Users', Cache::get('active_users', 0)),
        ];
    }
}
```

**See:** [Widgets Guide - Polling](filament-v4-widgets-guide.md#conditional-polling-)

---

### Collapsible Section

```php
Section::make('Advanced Settings')
    ->collapsible()
    ->collapsed()
    ->persistCollapsed('advanced-settings-section')  // Remember state
    ->schema([...])
```

**See:** [Component Architecture - Collapsible Sections](filament-v4-component-architecture.md#collapsible-sections-with-state-persistence)

---

### Tabs with Icons

```php
Tabs::make('Customer Data')
    ->tabs([
        Tabs\Tab::make('Profile')
            ->icon('heroicon-o-user')
            ->badge(fn ($record) => $record?->pendingTasks())
            ->badgeColor('warning')
            ->schema([...]),

        Tabs\Tab::make('Orders')
            ->icon('heroicon-o-shopping-bag')
            ->schema([...]),
    ])
    ->persistTabInQueryString()  // Save tab in URL
```

**See:** [Component Architecture - Tabs](filament-v4-component-architecture.md#tabs-with-icons-and-badges)

---

### Conditional Visibility

```php
// Show field based on role
TextInput::make('admin_notes')
    ->visible(fn (): bool => auth()->user()->hasRole('admin'))

// Show field based on other field value
Section::make('Credit Card')
    ->visible(fn (Get $get): bool => $get('payment_method') === 'card')
    ->schema([...])
```

**See:** [Component Architecture - Visibility Rules](filament-v4-component-architecture.md#component-visibility-rules)

---

## Performance Checklist

- [ ] Use `$isLazy = true` for heavy widgets
- [ ] Cache expensive queries (5-15 min TTL)
- [ ] Use `once()` for query memoization
- [ ] Add `debounce` to live form fields
- [ ] Eager load relationships (prevent N+1)
- [ ] Limit table widget rows (5-10 max)
- [ ] Use polling intervals ≥30s (not 5s)
- [ ] Clear cache on model changes

**See:** [Best Practices - Performance](filament-v4-best-practices.md#performance-optimization)

---

## Testing Patterns

```php
// Form validation
it('validates email format', function () {
    livewire(CreateUser::class)
        ->fillForm(['email' => 'invalid'])
        ->call('create')
        ->assertHasFormErrors(['email' => 'email']);
});

// Table actions
it('can cancel appointment', function () {
    livewire(ListAppointments::class)
        ->callTableAction('cancel', $appointment)
        ->assertSuccessful();
});

// Filters
it('filters by status', function () {
    livewire(ListAppointments::class)
        ->filterTable('status', 'pending')
        ->assertCanSeeTableRecords(
            Appointment::where('status', 'pending')->get()
        );
});
```

**See:** [Best Practices - Testing](filament-v4-best-practices.md#testing-strategies)

---

## Troubleshooting

### Widget Layout Issues

**Problem:** Section nested in widget causes layout conflicts

**Solution:** Use widget's named slots instead of Section

```php
// ❌ WRONG
<x-filament-widgets::widget>
    <x-filament::section heading="Title">

// ✅ CORRECT
<x-filament-widgets::widget>
    <x-slot name="heading">Title</x-slot>
```

---

### Namespace Errors

**Problem:** `Class not found` errors after v4 upgrade

**Solution:** Update namespaces from v3 to v4

```php
// ❌ v3
use Filament\Forms\Components\Section;

// ✅ v4
use Filament\Schemas\Components\Section;
```

**See:** [Migration Guide](filament-v4-migration-guide.md#critical-namespace-changes)

---

### Form Re-render Loops

**Problem:** Form re-renders constantly, state resets

**Solution:** Use debounce on live fields

```php
// ❌ Without debounce - re-renders on every keystroke
TextInput::make('search')->live()

// ✅ With debounce - waits 500ms after typing stops
TextInput::make('search')->live(debounce: 500)
```

---

## Essential Links

- **[Component Architecture](filament-v4-component-architecture.md)** - Complete hierarchy reference
- **[Best Practices](filament-v4-best-practices.md)** - Do's and don'ts
- **[Widgets Guide](filament-v4-widgets-guide.md)** - Widget patterns
- **[Migration Guide](filament-v4-migration-guide.md)** - v3 → v4 changes
- **[Official Docs](https://filamentphp.com/docs/4.x)** - Filament v4.x documentation

---

**Last Updated:** 2025-12-17
**Filament Version:** v4.2.3
**Maintained By:** Development Team
