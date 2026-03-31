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

## SettingsManager w Blade — KRYTYCZNE

**ZAWSZE używaj pełnej FQCN. Nigdy stringa `'settings'`!**

```blade
{{-- ✅ PRAWIDŁOWO --}}
app(\App\Support\Settings\SettingsManager::class)->vatRate()
app(\App\Support\Settings\SettingsManager::class)->nettoPrice($price)

{{-- ❌ BŁĄD — BindingResolutionException: Target class [settings] does not exist --}}
app('settings')->vatRate()
```

`SettingsManager` jest zarejestrowany jako `app(SettingsManager::class)`, NIE pod stringiem `'settings'`.

Incydent 2026-03-31: `frontend-ui-architect` użył `app('settings')` w show.blade.php, cart/show.blade.php, checkout/show.blade.php — 6 wywołań crashowało z BindingResolutionException.

---

## price_on_request — WSZYSTKIE miejsca wyświetlania cen

**Flaga `price_on_request=true` musi być obsługiwana w KAŻDYM szablonie wyświetlającym cenę.**

Gdy `$service->price_on_request === true`:
- ❌ NIE wyświetlaj ceny
- ✅ Pokaż "Cena do potwierdzenia" (lub odpowiednik)

Szablony do zaktualizowania przy każdej nowej lokalizacji cen:

| Plik | Co sprawdzić |
|------|-------------|
| `services/show.blade.php` | Kafelki cenowe + widget koszyka |
| `services/index.blade.php` | Cena w karcie produktu |
| `components/ios/service-card.blade.php` | Badge cenowy w tilesach |
| `cart/show.blade.php` | (koszyk nie wyświetla produktów price_on_request) |

**Wzorzec:**
```blade
@if($service->service_type === \App\Enums\ServiceType::ItemRental && $service->price_on_request)
    <span class="...italic">Cena do potwierdzenia</span>
@elseif($service->price_per_day)
    <span ...>{{ number_format($service->price_per_day, 0, ',', ' ') }} zł/dzień</span>
@endif
```

Incydent 2026-03-31: Po naprawieniu `show.blade.php`, kafelki na stronie głównej i liście nadal pokazywały cenę (naprawione w `service-card.blade.php` + `index.blade.php`).

---

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
