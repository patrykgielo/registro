# Filament v4 Migration Guide

**Version:** Filament v4.2.3
**Last Updated:** 2025-12-17
**Purpose:** Complete guide for migrating from Filament v3 to v4

---

## Table of Contents

1. [Overview](#overview)
2. [Critical Namespace Changes](#critical-namespace-changes)
3. [Component Renaming](#component-renaming)
4. [Deprecated Features](#deprecated-features)
5. [Breaking Changes Checklist](#breaking-changes-checklist)
6. [Step-by-Step Migration Process](#step-by-step-migration-process)
7. [Code Examples](#code-examples)
8. [Troubleshooting](#troubleshooting)

---

## Overview

### What Changed in v4

Filament v4 introduced significant architectural improvements:

1. **Namespace Reorganization:** Layout components moved from `Forms` to `Schemas`
2. **Component Renaming:** Clearer, more explicit component names
3. **Improved Type Safety:** Better IDE support and static analysis
4. **Enhanced Performance:** Optimized rendering and caching
5. **Deprecated Patterns:** Removal of legacy v2 compatibility

### Migration Impact

**Low Risk:**
- Most changes are namespace updates
- Backward compatibility maintained for most features
- Clear error messages guide fixes

**Medium Risk:**
- Layout components require namespace updates
- Infolist components renamed
- Widget patterns changed (Section nesting no longer supported)

**High Risk:**
- Custom components using old namespaces
- Heavy use of deprecated features
- Complex form/infolist schemas

---

## Critical Namespace Changes

### 1. Layout Components → `Filament\Schemas\Components`

Layout components (Section, Grid, Tabs, Fieldset, etc.) moved from `Forms\Components` to `Schemas\Components`.

#### ❌ v3 (Old Namespace)

```php
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Group;
```

#### ✅ v4 (New Namespace)

```php
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Group;
```

### 2. Data Entry Components → `Filament\Forms\Components`

Form input components remain in `Forms\Components` (no change).

#### ✅ v3 and v4 (No Change)

```php
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Checkbox;
```

### 3. Display Components → `Filament\Infolists\Components`

Infolist components renamed for clarity (see Component Renaming section).

#### ❌ v3 (Old Names)

```php
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\TextEntry as Entry\Text;
```

#### ✅ v4 (New Names)

```php
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
```

### 4. Table Components → `Filament\Tables\Columns`

Table columns remain mostly unchanged.

#### ✅ v3 and v4 (Mostly No Change)

```php
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
```

---

## Component Renaming

### Infolist Components

Infolist components renamed for consistency and clarity.

| v3 Name | v4 Name | Namespace |
|---------|---------|-----------|
| `Entry` (abstract) | `TextEntry` | `Filament\Infolists\Components` |
| `Entry\Text` | `TextEntry` | `Filament\Infolists\Components` |
| `Entry\Icon` | `IconEntry` | `Filament\Infolists\Components` |
| `Entry\Image` | `ImageEntry` | `Filament\Infolists\Components` |
| `Entry\Badge` | `BadgeEntry` (deprecated, use TextEntry with badge) | `Filament\Infolists\Components` |

#### Migration Example

❌ **v3:**
```php
use Filament\Infolists\Components\Entry;

Entry::make('name');
```

✅ **v4:**
```php
use Filament\Infolists\Components\TextEntry;

TextEntry::make('name');
```

---

## Deprecated Features

### 1. Badge Entry (Deprecated)

`BadgeEntry` deprecated in favor of `TextEntry` with badge modifier.

❌ **v3:**
```php
use Filament\Infolists\Components\BadgeEntry;

BadgeEntry::make('status')
    ->colors([
        'success' => 'active',
        'danger' => 'inactive',
    ]);
```

✅ **v4:**
```php
use Filament\Infolists\Components\TextEntry;

TextEntry::make('status')
    ->badge()
    ->color(fn (string $state): string => match ($state) {
        'active' => 'success',
        'inactive' => 'danger',
    });
```

### 2. Old Section Namespace in Widgets

Widgets should NOT nest `<x-filament::section>` (never supported, but commonly misused).

❌ **Wrong Pattern:**
```blade
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Title</x-slot>
        Content
    </x-filament::section>
</x-filament-widgets::widget>
```

✅ **Correct Pattern:**
```blade
<x-filament-widgets::widget>
    <x-slot name="heading">Title</x-slot>
    <div>Content</div>
</x-filament-widgets::widget>
```

### 3. Form Builder Column Span (Simplified)

Column span syntax simplified in v4.

❌ **v3 (verbose):**
```php
TextInput::make('name')->columnSpan([
    'sm' => 1,
    'md' => 2,
    'lg' => 3,
]);
```

✅ **v4 (simplified):**
```php
TextInput::make('name')->columnSpan([
    'sm' => 1,
    'md' => 2,
    'lg' => 3,
]);
// Or use shorthand:
TextInput::make('name')->columnSpan(2); // Applies to all breakpoints
```

---

## Breaking Changes Checklist

Use this checklist to audit your codebase for v3 → v4 compatibility issues.

### Namespace Audit

- [ ] Search for `use Filament\Forms\Components\Section;` → Replace with `Filament\Schemas\Components\Section`
- [ ] Search for `use Filament\Forms\Components\Grid;` → Replace with `Filament\Schemas\Components\Grid`
- [ ] Search for `use Filament\Forms\Components\Tabs;` → Replace with `Filament\Schemas\Components\Tabs`
- [ ] Search for `use Filament\Forms\Components\Fieldset;` → Replace with `Filament\Schemas\Components\Fieldset`
- [ ] Search for `use Filament\Forms\Components\Group;` → Replace with `Filament\Schemas\Components\Group`

### Component Renaming Audit

- [ ] Search for `Filament\Infolists\Components\Entry;` → Replace with `TextEntry`
- [ ] Search for `Entry::make(` → Replace with `TextEntry::make(`
- [ ] Search for `BadgeEntry::make(` → Refactor to `TextEntry::make()->badge()`

### Widget Structure Audit

- [ ] Search for `<x-filament::section>` in widget Blade files → Remove wrapper
- [ ] Verify widget heading/description use widget slots, not section slots

### Resource/Page Audit

- [ ] Check all Resource `form()` methods for old namespaces
- [ ] Check all Resource `table()` methods for deprecated columns
- [ ] Check all Page classes for old component references
- [ ] Check all Infolist schemas for old Entry classes

---

## Step-by-Step Migration Process

### Phase 1: Preparation (1-2 hours)

1. **Backup Your Code**
   ```bash
   git checkout -b migration/filament-v4-upgrade develop
   git push -u origin migration/filament-v4-upgrade
   ```

2. **Document Current State**
   ```bash
   # List all Filament files
   find app/Filament -name "*.php" > /tmp/filament-files.txt

   # Count old namespace usage
   grep -r "use Filament\\Forms\\Components\\Section" app/Filament | wc -l
   grep -r "use Filament\\Infolists\\Components\\Entry" app/Filament | wc -l
   ```

3. **Update Composer Dependencies**
   ```bash
   composer require filament/filament:"^4.0" --with-all-dependencies
   composer update
   ```

4. **Clear All Caches**
   ```bash
   php artisan optimize:clear
   php artisan filament:optimize-clear
   ```

### Phase 2: Automated Fixes (2-3 hours)

5. **Find and Replace Namespaces**

   Use global search/replace or sed commands:

   ```bash
   # Section namespace
   find app/Filament -name "*.php" -exec sed -i 's/use Filament\\Forms\\Components\\Section;/use Filament\\Schemas\\Components\\Section;/g' {} +

   # Grid namespace
   find app/Filament -name "*.php" -exec sed -i 's/use Filament\\Forms\\Components\\Grid;/use Filament\\Schemas\\Components\\Grid;/g' {} +

   # Tabs namespace
   find app/Filament -name "*.php" -exec sed -i 's/use Filament\\Forms\\Components\\Tabs;/use Filament\\Schemas\\Components\\Tabs;/g' {} +

   # Fieldset namespace
   find app/Filament -name "*.php" -exec sed -i 's/use Filament\\Forms\\Components\\Fieldset;/use Filament\\Schemas\\Components\\Fieldset;/g' {} +

   # Group namespace
   find app/Filament -name "*.php" -exec sed -i 's/use Filament\\Forms\\Components\\Group;/use Filament\\Schemas\\Components\\Group;/g' {} +
   ```

6. **Fix Infolist Entries**

   ```bash
   # Entry → TextEntry
   find app/Filament -name "*.php" -exec sed -i 's/use Filament\\Infolists\\Components\\Entry;/use Filament\\Infolists\\Components\\TextEntry;/g' {} +

   # Entry::make( → TextEntry::make(
   find app/Filament -name "*.php" -exec sed -i 's/Entry::make(/TextEntry::make(/g' {} +
   ```

7. **Verify Changes**
   ```bash
   # Check for remaining old namespaces
   grep -r "use Filament\\Forms\\Components\\Section" app/Filament
   grep -r "use Filament\\Infolists\\Components\\Entry;" app/Filament

   # Should return empty results
   ```

### Phase 3: Manual Fixes (3-5 hours)

8. **Fix Widget Blade Templates**

   Find all widget Blade files:
   ```bash
   find resources/views/filament/widgets -name "*.blade.php"
   ```

   For each widget:
   - Remove `<x-filament::section>` wrapper
   - Move heading/description to widget slots
   - Place content directly in widget

9. **Refactor BadgeEntry Usage**

   Find all BadgeEntry usage:
   ```bash
   grep -r "BadgeEntry::make" app/Filament
   ```

   Convert to TextEntry with badge():
   ```php
   // Before
   BadgeEntry::make('status')->colors([...]);

   // After
   TextEntry::make('status')->badge()->color(fn ($state) => ...);
   ```

10. **Update Custom Components**

    If you have custom components extending Filament classes:
    - Update parent class namespaces
    - Check for deprecated method calls
    - Test thoroughly

### Phase 4: Testing (2-4 hours)

11. **Run Tests**
    ```bash
    php artisan test
    ./vendor/bin/pint  # Code style check
    ```

12. **Manual Testing Checklist**
    - [ ] Admin panel loads without errors
    - [ ] All Resources display correctly
    - [ ] Forms render and submit successfully
    - [ ] Tables display and actions work
    - [ ] Widgets appear correctly (no giant icons!)
    - [ ] Infolists display properly
    - [ ] Custom pages load
    - [ ] Navigation works

13. **Browser Console Check**
    - [ ] No JavaScript errors
    - [ ] Livewire components initialize
    - [ ] Alpine.js directives work

### Phase 5: Deployment (1-2 hours)

14. **Commit Changes**
    ```bash
    git add .
    git commit -m "refactor(admin): migrate Filament v3 to v4

    - Update layout component namespaces (Section, Grid, Tabs → Schemas)
    - Refactor Infolist Entry components to explicit TextEntry
    - Fix widget Blade templates (remove Section wrappers)
    - Update all Resources and Pages with new namespaces
    - Clear deprecated BadgeEntry usage

    BREAKING CHANGE: Requires Filament v4.0+"
    ```

15. **Deploy to Staging**
    ```bash
    git push origin migration/filament-v4-upgrade
    # Create PR to develop
    # Merge to develop (triggers staging deployment)
    ```

16. **Production Deployment**
    ```bash
    # After staging approval
    git checkout -b release/v1.0.0 develop
    ./scripts/release.sh minor
    # Merge to main (triggers production deployment)
    ```

---

## Code Examples

### Example 1: Resource Form Migration

#### Before (v3)

```php
<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Forms\Components\Section;  // Old namespace
use Filament\Forms\Components\Grid;      // Old namespace
use Filament\Forms\Components\Tabs;      // Old namespace
use Filament\Resources\Resource;

class UserResource extends Resource
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Personal Information')
                ->schema([
                    Grid::make(2)->schema([
                        Forms\Components\TextInput::make('first_name'),
                        Forms\Components\TextInput::make('last_name'),
                    ]),
                ]),

            Tabs::make('Additional')
                ->tabs([
                    Tabs\Tab::make('Profile')
                        ->schema([...]),
                ]),
        ]);
    }
}
```

#### After (v4)

```php
<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Schemas\Components\Section;  // New namespace
use Filament\Schemas\Components\Grid;      // New namespace
use Filament\Schemas\Components\Tabs;      // New namespace
use Filament\Resources\Resource;

class UserResource extends Resource
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Personal Information')
                ->schema([
                    Grid::make(2)->schema([
                        Forms\Components\TextInput::make('first_name'),
                        Forms\Components\TextInput::make('last_name'),
                    ]),
                ]),

            Tabs::make('Additional')
                ->tabs([
                    Tabs\Tab::make('Profile')
                        ->schema([...]),
                ]),
        ]);
    }
}
```

**Changes:**
- ✅ Updated `Section`, `Grid`, `Tabs` namespaces to `Filament\Schemas\Components`
- ✅ Form input components remain unchanged

---

### Example 2: Infolist Migration

#### Before (v3)

```php
<?php

use Filament\Infolists\Components\Entry;

public function infolist(Infolist $infolist): Infolist
{
    return $infolist->schema([
        Entry::make('name'),
        Entry::make('email'),
        Entry::make('status')
            ->badge()
            ->color(fn ($state) => match ($state) {
                'active' => 'success',
                'inactive' => 'danger',
            }),
    ]);
}
```

#### After (v4)

```php
<?php

use Filament\Infolists\Components\TextEntry;

public function infolist(Infolist $infolist): Infolist
{
    return $infolist->schema([
        TextEntry::make('name'),
        TextEntry::make('email'),
        TextEntry::make('status')
            ->badge()
            ->color(fn ($state) => match ($state) {
                'active' => 'success',
                'inactive' => 'danger',
            }),
    ]);
}
```

**Changes:**
- ✅ Replaced `Entry` with `TextEntry`
- ✅ Badge modifier remains the same

---

### Example 3: Widget Blade Template Migration

#### Before (v3 - Incorrect Pattern)

```blade
{{-- resources/views/filament/widgets/cache-clear.blade.php --}}

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Cache Management
        </x-slot>

        <div class="space-y-4">
            <!-- Content -->
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
```

#### After (v4 - Correct Pattern)

```blade
{{-- resources/views/filament/widgets/cache-clear.blade.php --}}

<x-filament-widgets::widget>
    <x-slot name="heading">
        Cache Management
    </x-slot>

    <x-slot name="description">
        Quick cache operations for development and troubleshooting
    </x-slot>

    <div class="space-y-4">
        <!-- Content -->
    </div>
</x-filament-widgets::widget>
```

**Changes:**
- ✅ Removed `<x-filament::section>` wrapper
- ✅ Moved heading to widget slot
- ✅ Added description slot (optional)
- ✅ Content directly in widget

---

## Troubleshooting

### Issue 1: Class Not Found Errors

**Error:**
```
Class 'Filament\Forms\Components\Section' not found
```

**Solution:**
Update namespace to `Filament\Schemas\Components\Section`

**Quick Fix:**
```bash
# Find all occurrences
grep -r "use Filament\\Forms\\Components\\Section" app/

# Replace globally
find app/ -name "*.php" -exec sed -i 's/use Filament\\Forms\\Components\\Section;/use Filament\\Schemas\\Components\\Section;/g' {} +
```

---

### Issue 2: Widget Layout Broken / Giant Icons

**Symptoms:**
- Large icons appearing above widget heading
- Double borders
- Broken spacing

**Root Cause:**
`<x-filament::section>` nested inside `<x-filament-widgets::widget>`

**Solution:**
Remove Section wrapper from widget Blade template:

```blade
{{-- Before --}}
<x-filament-widgets::widget>
    <x-filament::section>...</x-filament::section>
</x-filament-widgets::widget>

{{-- After --}}
<x-filament-widgets::widget>
    <x-slot name="heading">Title</x-slot>
    <div>Content</div>
</x-filament-widgets::widget>
```

---

### Issue 3: BadgeEntry Deprecated Warning

**Error:**
```
BadgeEntry is deprecated, use TextEntry with badge() modifier
```

**Solution:**
Refactor to TextEntry:

```php
// Before
use Filament\Infolists\Components\BadgeEntry;

BadgeEntry::make('status')->colors([...]);

// After
use Filament\Infolists\Components\TextEntry;

TextEntry::make('status')
    ->badge()
    ->color(fn ($state) => ...);
```

---

### Issue 4: Form Rendering Issues

**Symptoms:**
- Form fields not displaying
- Layout broken
- Sections not rendering

**Checklist:**
1. Check all namespace imports
2. Clear cache: `php artisan optimize:clear`
3. Clear Filament cache: `php artisan filament:optimize-clear`
4. Rebuild assets: `npm run build`
5. Restart containers: `docker compose restart`

---

### Issue 5: IDE Autocomplete Not Working

**Problem:**
IDE not recognizing new namespaces

**Solution:**
1. Clear IDE cache (PHPStorm: File → Invalidate Caches)
2. Regenerate IDE helper:
   ```bash
   composer require --dev barryvdh/laravel-ide-helper
   php artisan ide-helper:generate
   php artisan ide-helper:models
   ```

---

## Migration Time Estimates

| Project Size | Estimated Time | Notes |
|-------------|----------------|-------|
| **Small** (1-5 Resources) | 2-4 hours | Quick namespace updates, minimal manual fixes |
| **Medium** (6-20 Resources) | 4-8 hours | Automated replacements + widget audits |
| **Large** (20+ Resources) | 8-16 hours | Extensive testing, custom component refactoring |

**Factors Affecting Time:**
- Number of custom widgets
- Complexity of form schemas
- Custom component usage
- Test coverage (more tests = faster verification)

---

## Post-Migration Checklist

After completing migration:

- [ ] All tests pass
- [ ] Admin panel loads without errors
- [ ] All Resources functional
- [ ] Widgets display correctly (no layout issues)
- [ ] Forms validate and submit
- [ ] Tables display and filter
- [ ] Infolists render properly
- [ ] Custom pages work
- [ ] Navigation intact
- [ ] No console errors
- [ ] Production deployment successful
- [ ] Documentation updated

---

## Additional Resources

- **Component Architecture:** [filament-v4-component-architecture.md](filament-v4-component-architecture.md)
- **Best Practices:** [filament-v4-best-practices.md](filament-v4-best-practices.md)
- **Widgets Guide:** [filament-v4-widgets-guide.md](filament-v4-widgets-guide.md)
- **Official Docs:** https://filamentphp.com/docs/4.x/introduction/overview
- **Upgrade Guide:** https://filamentphp.com/docs/4.x/support/upgrade-guide

---

**Last Updated:** 2025-12-17
**Filament Version:** v4.2.3
**Maintained By:** Development Team
