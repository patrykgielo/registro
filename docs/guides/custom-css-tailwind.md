# Custom CSS with Tailwind CSS 4.0 - Best Practices

**Status:** Active
**Last Updated:** 2025-12-09
**Related:** [Production Build Guide](production-build.md), [Tailwind CSS 4.0 Migration](../decisions/ADR-TW4-migration.md)

## Problem Statement

Custom CSS classes defined in Blade component `<style>` tags were not being included in Vite production builds. Classes like `.ios-spring`, `.animate-blob`, `.animation-delay-*`, `.ios-toggle`, etc., were present in components but missing from the compiled CSS file (`public/build/assets/app-*.css`).

This caused the login page (https://registro.local:8444/login) to display incorrectly in production because custom animations and iOS-specific styling were not applied.

## Root Cause

**Tailwind CSS 4.0 + Vite does NOT extract CSS from `<style>` tags inside Blade components.**

Vite's build process:
1. Processes `resources/css/app.css` (entry point)
2. Resolves `@import` directives
3. Scans files specified in `@source` directives
4. Extracts Tailwind utility classes from HTML/Blade
5. **Ignores** `<style>` tags completely

This is by design – Vite treats component-level `<style>` as scoped styles (common in Vue/Svelte), but in Blade components, they are NOT scoped and were intended as global styles.

## Solution: Consolidate Custom CSS in app.css

### The Correct Pattern

All custom CSS (animations, keyframes, component classes) MUST be defined in `resources/css/app.css` or imported files. Never use `<style>` tags in Blade components for global styles.

#### resources/css/app.css Structure

```css
@import 'tailwindcss';

/* Design Tokens - Auto-generated from design-system.json */
@import './design-tokens.css';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.php';
@source '../**/*.blade.php';
@source '../**/*.js';

/* Custom styles using plain CSS - Tailwind CSS 4.0 compatible */

/* =============================================================================
   iOS-Style Components & Animations
   ============================================================================= */

/* Smooth Scroll (iOS-style) */
html {
    scroll-behavior: smooth;
    scroll-padding-top: 100px;
}

/* =============================================================================
   Animations & Keyframes
   ============================================================================= */

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

.animate-fade-in-up {
    animation: fadeInUp 0.8s cubic-bezier(0.36, 0.66, 0.04, 1) both;
}

/* =============================================================================
   iOS Components
   ============================================================================= */

.ios-spring {
    transition-timing-function: cubic-bezier(0.36, 0.66, 0.04, 1);
}

/* ... more custom CSS ... */
```

### When to Use `<style>` Tags (Rarely)

Only use `<style>` tags in Blade components when:

1. **Scoped inline styles** - Very component-specific styles that won't be reused
2. **Dynamic styles** - Styles that depend on PHP variables/logic
3. **Third-party widget styles** - Embedded widgets with their own CSS

**Example of acceptable `<style>` usage:**

```blade
{{-- Dynamic gradient based on component prop --}}
<style>
    .hero-{{ $id }} {
        background: linear-gradient({{ $angle }}deg, {{ $color1 }}, {{ $color2 }});
    }
</style>
```

## Implementation Details

### Files Modified

1. **resources/css/app.css** - Added 180+ lines of consolidated custom CSS
2. **resources/views/components/ios/*.blade.php** - Removed all `<style>` tags from 6 components

### Custom CSS Classes Added to app.css

#### Animations & Keyframes

- `@keyframes fadeInUp` - Fade-in-up animation with iOS spring easing
- `@keyframes blob` - Blob animation (iOS 17 style gradient orbs)
- `@keyframes gradient` - Animated gradient background
- `.animate-fade-in-up` - Apply fade-in-up animation
- `.animate-blob` - Apply blob animation
- `.animate-gradient` - Apply gradient animation
- `.animation-delay-200`, `.animation-delay-400`, `.animation-delay-600`, `.animation-delay-2000`, `.animation-delay-4000` - Animation delays

#### iOS Component Styles

- `.ios-spring` - Core iOS spring easing transition
- `.ios-input` - Input field styles (remove native appearance, prevent iOS zoom)
- `.ios-toggle` - Toggle switch container
- `.ios-toggle-thumb` - Toggle switch thumb element
- `.ios-checkbox` - Checkbox styles
- `.ios-card` - Card container styles with touch feedback
- `.ios-button`, `.ios-button-primary`, `.ios-button-secondary-outline` - Button variants
- `.ios-checkbox-group label` - Touch target sizing (44px minimum)

#### Utility Classes

- `.line-clamp-2` - Multi-line text truncation
- `.bg-noise` - SVG noise texture (iOS App Store style)

#### Accessibility

- `@media (prefers-reduced-motion: reduce)` - Disable all animations/transitions for users who prefer reduced motion

### Build Verification

```bash
# Build production assets
npm run build

# Verify custom classes exist in compiled CSS
grep -o "\.animate-blob\|\.ios-spring\|\.ios-toggle" public/build/assets/app-*.css

# Expected output:
# .animate-blob
# .ios-spring
# .ios-toggle
# ... etc
```

### Build Results

- **Before:** `app-Cjcf43yi.css` - 75KB (missing custom CSS)
- **After:** `app-CVsEsLK2.css` - 84KB (includes all custom CSS)
- **Increase:** +9KB (+12%) - All custom iOS styling included

## Best Practices for Future Development

### 1. Always Define Global CSS in app.css

```css
/* ✅ CORRECT - Define in app.css */
.custom-animation {
    animation: fadeIn 0.3s ease-in-out;
}
```

```blade
<!-- ❌ INCORRECT - Don't use <style> for global classes -->
<style>
    .custom-animation {
        animation: fadeIn 0.3s ease-in-out;
    }
</style>
```

### 2. Use Tailwind Utilities First

Before adding custom CSS, check if Tailwind utilities can achieve the same effect:

```blade
<!-- ✅ GOOD - Use Tailwind utilities -->
<div class="transition-all duration-300 ease-in-out hover:scale-105">

<!-- ⚠️ LESS GOOD - Custom CSS when Tailwind exists -->
<style>.my-hover { transition: all 0.3s ease-in-out; }</style>
<div class="my-hover">
```

### 3. Organize Custom CSS with Comments

Use clear section comments in app.css:

```css
/* =============================================================================
   Feature Name / Component Group
   ============================================================================= */

/* Subfeature or specific component */
.class-name { }
```

### 4. Test Production Builds Locally

Always test production builds locally before deploying:

```bash
# Build production assets
npm run build

# Start production-like server
php artisan serve --env=production

# Verify styling works
```

### 5. Use @theme for Tailwind-Integrated Styles

For styles that should generate Tailwind utilities, use `@theme` in app.css:

```css
@theme {
    --animate-fade-in: fadeIn 0.3s ease-in-out;

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
}
```

Then use in Blade:

```blade
<div class="animate-fade-in">Content</div>
```

## Debugging Checklist

If custom CSS is missing in production:

1. ✅ Check if CSS is defined in `resources/css/app.css` (not in `<style>` tags)
2. ✅ Run `npm run build` to regenerate production assets
3. ✅ Check `public/build/assets/app-*.css` contains your custom classes
4. ✅ Verify `public/build/manifest.json` references the new CSS file
5. ✅ Clear browser cache and hard refresh (Ctrl+Shift+R)
6. ✅ Inspect element in DevTools to confirm CSS is loaded
7. ✅ Check for syntax errors in app.css that might break the build

## Related Documentation

- [Tailwind CSS 4.0 - Adding Custom Styles](https://tailwindcss.com/docs/adding-custom-styles)
- [Tailwind CSS 4.0 Blog Post](https://tailwindcss.com/blog/tailwindcss-v4)
- [Vite - CSS Pre-processors](https://vite.dev/guide/features#css-pre-processors)
- [Laravel Vite Plugin](https://laravel.com/docs/12.x/vite)

## Conclusion

Custom CSS MUST be defined in `resources/css/app.css` for Tailwind CSS 4.0 + Vite projects. Component-level `<style>` tags are NOT processed during Vite builds and should only be used for scoped, dynamic styles.

This pattern ensures:
- ✅ All custom CSS is included in production builds
- ✅ Consistent styling across development and production
- ✅ Better performance (single CSS file, no duplicate styles)
- ✅ Easier maintenance (centralized custom CSS location)
