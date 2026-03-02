# CMS Page Menu Management

## Overview

Pages can be dynamically added to the frontend navigation menu through the Filament admin panel. Each page can be configured to appear in the header, footer, or both menus with a customizable order and label.

## Database Fields

| Field | Type | Description |
|-------|------|-------------|
| `show_in_menu` | boolean | Whether the page appears in navigation |
| `menu_order` | smallint | Sort order (lower = higher in menu) |
| `menu_label` | string (nullable) | Custom menu label (defaults to page title) |
| `menu_location` | enum | Where to display: `header`, `footer`, or `both` |

## Filament Admin Usage

### Adding a Page to Menu

1. Go to **Admin Panel → Treść → Strony**
2. Edit a page
3. Expand the **Menu** section (bottom of form)
4. Enable **Pokaż w menu** toggle
5. Configure:
   - **Kolejność** - Lower numbers appear first (0, 10, 20...)
   - **Etykieta menu** - Optional shorter name for menu
   - **Lokalizacja** - Header, Footer, or Both
6. Save the page

### Viewing Menu Status

In the pages list:
- **Menu** column shows checkmark for pages in menu
- **Kolejność** column shows sort order (toggle visibility)
- Filter by "W menu" to see only menu pages

## Technical Implementation

### Key Files

| File | Purpose |
|------|---------|
| `app/Models/Page.php` | Model with `scopeInMenu()` and cache invalidation |
| `app/Enums/MenuLocation.php` | Enum for header/footer/both |
| `app/Services/NavigationService.php` | Cached menu retrieval |
| `resources/views/components/navigation/menu-items.blade.php` | Blade component |
| `app/Filament/Resources/Pages/PageResource.php` | Admin form and table |

### Using the Menu Component

```blade
{{-- Header navigation (dark variant) --}}
<x-navigation.menu-items location="header" :dark="true" />

{{-- Footer navigation --}}
<x-navigation.menu-items location="footer" />
```

### Programmatic Access

```php
use App\Services\NavigationService;

$navigation = app(NavigationService::class);

// Get header menu items
$headerItems = $navigation->getMenuItems('header');
// Returns: Collection of ['label' => string, 'url' => string, 'active' => bool]

// Get footer menu items
$footerItems = $navigation->getMenuItems('footer');
```

### Using the Scope

```php
use App\Models\Page;

// All pages in menu
Page::inMenu()->get();

// Only header pages
Page::inMenu('header')->get();

// Published pages in footer
Page::published()->inMenu('footer')->get();
```

## Caching

Menu items are cached for **30 minutes** per location (`navigation.pages.header`, `navigation.pages.footer`).

Cache is automatically invalidated when:
- A page is saved (created or updated)
- A page is deleted

### Manual Cache Clear

```bash
php artisan cache:clear
```

Or programmatically:

```php
app(NavigationService::class)->clearCache();
```

## Best Practices

1. **Order spacing**: Use 10, 20, 30 instead of 1, 2, 3 - easier to insert pages between existing ones

2. **Short labels**: Use `menu_label` for shorter names (e.g., "O Nas" instead of "O Naszej Firmie")

3. **Publish first**: Only published pages appear in the menu (draft pages are excluded)

4. **Test locally**: Changes are immediate - verify menu appearance before pushing to production

## Migration

```bash
# Run migration
php artisan migrate

# Rollback if needed
php artisan migrate:rollback
```

Migration adds:
- `show_in_menu` (bool, default: false)
- `menu_order` (smallint, default: 0)
- `menu_label` (string, nullable)
- `menu_location` (string, default: 'header')
- Index on `show_in_menu` + `menu_order`
