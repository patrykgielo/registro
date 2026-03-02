---
paths:
  - "resources/views/components/**"
  - "resources/views/livewire/**"
---

# Blade Components Rules

## Component Structure

### Anonymous Components
```blade
{{-- resources/views/components/button.blade.php --}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'disabled' => false,
])

@php
$classes = match($variant) {
    'primary' => 'bg-blue-500 text-white',
    'secondary' => 'bg-gray-100 text-gray-900',
    default => 'bg-blue-500 text-white',
};
@endphp

<button {{ $attributes->merge(['class' => $classes]) }} @disabled($disabled)>
    {{ $slot }}
</button>
```

## Livewire Compatibility

Always support Livewire directives:
- `wire:model` - Two-way data binding
- `wire:click` - Action triggers
- `wire:loading` - Loading state management

```blade
<input
    {{ $attributes->whereStartsWith('wire:model') }}
    {{ $attributes->merge(['class' => 'input-base']) }}
    type="text" />
```

## Touch Targets (Mobile-First)

- Minimum touch target: 44x44px (iOS standard)
- Use `min-h-[44px] min-w-[44px]` for interactive elements

## Design Tokens

Reference design tokens from `design-system.json` or Tailwind config:
- Colors: Use semantic names (`primary`, `secondary`, `error`)
- Spacing: Use Tailwind spacing scale
- Typography: Use configured font families

## CMS Page Architecture (CRITICAL)

**The homepage (`/`) uses CMS system - there is NO `home.blade.php` file!**

### Routing Flow

```
/ (homepage) → routes/web.php → CMS check:
  ├─ If homepage_page_id set → pages.show (CMS page)
  │   └─ Uses cms/layouts/home.blade.php
  │       └─ Renders content blocks (hero, content_grid, etc.)
  └─ If not set → home-fallback.blade.php (placeholder)
```

### Content Blocks Location

CMS content blocks are in `resources/views/components/content-blocks/`:
- `hero.blade.php` - Hero sections
- `content-grid.blade.php` - Service/post/portfolio grids
- `feature-list.blade.php` - Feature lists
- `cta-banner.blade.php` - CTA sections

### Modifying Homepage Appearance

**CORRECT approaches:**
1. Edit `content-blocks/*.blade.php` components (code changes)
2. Update CMS block settings in admin panel (content changes)
3. Edit `cms/layouts/home.blade.php` (layout structure)

### content-grid Component

The `content-grid` component's dark variant depends on CMS block settings:

```php
$backgroundColor = $data['background_color'] ?? $defaultBg;
$isDark = $backgroundColor === 'dark';
```

For services content type, default is now `'dark'`. For other types (posts, portfolio), default is `'white'`.
