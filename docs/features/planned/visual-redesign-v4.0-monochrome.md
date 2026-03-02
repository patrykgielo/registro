# Visual Redesign Implementation Plan - Registro

**Created:** 2025-12-11
**Status:** Ready for Review
**Estimated Scope:** 45 Blade templates, 3 config files, 1 design system update

## Executive Summary

This plan addresses 6 critical visual and dimensional improvements requested by the user:

1. **Hero Section Mobile Fix**: Reduce excessive height from `min-h-screen` to max 50vh on mobile
2. **Gradient Removal**: Eliminate all purple/pink/indigo gradients ("choinka" problem), replace with solid design system colors
3. **Border-Radius Standardization**: Unify all border-radius to 10px (0.625rem) across buttons, forms, inputs, cards
4. **Logo Analysis**: Keep sharp geometric logo edges as distinctive brand element (user decision)
5. **Monochrome Color System**: Implement professional monochrome palette with single turquoise accent based on logo colors
6. **Quality Assessment**: Research-backed approach for professional luxury brand aesthetic

## Design Decisions

### 1. Monochrome Color System - "Medical Precision" Palette

**Research Finding**: Luxury automotive and professional services brands use 80-90% monochrome with single accent.

**Recommended Palette** (Proposal 3 - "Medical Precision"):
- **Turquoise Accent**: `#4AA5B0` (24% saturation, derived from logo's #6BC6D9)
- **Neutral System**: 9 shades from `#FFFFFF` (white) to `#1F2937` (charcoal)
- **Usage Split**: 88% neutrals, 12% turquoise accent
- **Rationale**: Professional aesthetic for €200-600 premium services, WCAG AA compliant

**Color Mappings**:
```
Logo → Design System
#6BC6D9 (59% sat) → #4AA5B0 (24% sat) - Primary accent
#2B2A29 → #2B2D2F - Charcoal (already in system)
#FFFFFF → #FFFFFF - White (unchanged)
```

**9-Shade Neutral System**:
- `neutral-50`: `#F9FAFB` - Lightest gray
- `neutral-100`: `#F3F4F6` - Light gray
- `neutral-200`: `#E5E7EB` - Soft gray
- `neutral-300`: `#D1D5DB` - Medium light gray
- `neutral-400`: `#9CA3AF` - Medium gray
- `neutral-500`: `#6B7280` - True neutral
- `neutral-600`: `#4B5563` - Dark gray
- `neutral-700`: `#374151` - Very dark gray
- `neutral-800`: `#1F2937` - Charcoal
- `neutral-900`: `#111827` - Near black

### 2. Hero Section Responsive Strategy

**Current Problem**: `min-h-screen` = 100vh on all devices (excessive on mobile)

**Solution - Responsive Height**:
```html
<!-- Mobile: 50vh, Tablet: 60vh, Desktop: 70vh -->
<section class="relative min-h-[50vh] sm:min-h-[60vh] lg:min-h-[70vh] ...">
```

**Responsive Padding**:
```html
<!-- Current: py-32 (8rem fixed) -->
<!-- New: py-12 sm:py-16 md:py-20 lg:py-24 (3rem → 6rem) -->
<div class="py-12 sm:py-16 md:py-20 lg:py-24">
```

**Fluid Typography with clamp()**:
```html
<!-- Heading: 2.25rem (36px) → 6rem (96px) fluid -->
<h1 style="font-size: clamp(2.25rem, 6vw, 6rem); line-height: 1.1;">

<!-- Subtitle: 1.125rem (18px) → 1.5rem (24px) fluid -->
<p style="font-size: clamp(1.125rem, 2.5vw, 1.5rem); line-height: 1.5;">
```

### 3. Border-Radius Standardization - 10px Exact

**Target**: All UI elements use `rounded-lg` class = 10px (0.625rem)

**Design System Update**:
```json
// design-system.json
"borderRadius": {
  "none": "0px",
  "sm": "0.25rem",    // 4px - keep for small elements
  "base": "0.375rem", // 6px - keep for badges
  "md": "0.5rem",     // 8px - keep for inputs
  "lg": "0.625rem",   // 10px ← MAIN CHANGE (was 1rem/16px)
  "xl": "1.25rem",    // 20px - deprecate
  "2xl": "1.5rem",    // 24px - deprecate
  "3xl": "1.875rem",  // 30px - deprecate
  "full": "9999px"    // Pills, avatars - PRESERVE
}
```

**DaisyUI Theme Update**:
```javascript
// tailwind.config.js
{
  ios: {
    "--rounded-box": "0.625rem",   // Cards (was 1.5rem/24px)
    "--rounded-btn": "9999px",     // Pill buttons - PRESERVE
    "--rounded-badge": "0.625rem", // Badges (was 1rem/16px)
  }
}
```

**Search & Replace Strategy**:
- **Replace**: `rounded-{xl,2xl,3xl}` → `rounded-lg`
- **Preserve**: `rounded-full` (avatars, badges, pills, toggles, gradient orbs)
- **Scope**: ~200 instances across 45 Blade templates

### 4. Gradient Removal Strategy

**Replace ALL gradients with solid colors from design system**:

| Current Gradient | Replacement Solid Color | Usage |
|-----------------|-------------------------|-------|
| `from-indigo-600 via-violet-600 to-pink-600` | `bg-primary-600` (#5A8A99) | Hero sections |
| `from-indigo-50 via-violet-50 to-pink-50` | `bg-neutral-50` (#F9FAFB) | Light backgrounds |
| `from-gray-50 to-white` | `bg-white` | Sections |
| `from-indigo-500 to-violet-500` | `bg-primary-500` (#6BABB5) | Icons, accents |

**Gradient Orbs** (decorative background elements):
```html
<!-- Current: Multi-color gradients -->
<div class="... from-indigo-500/40 via-indigo-500/20 to-transparent ..."></div>
<div class="... from-violet-500/30 via-violet-500/15 to-transparent ..."></div>

<!-- New: Monochrome turquoise with reduced opacity -->
<div class="... from-primary-500/15 via-primary-500/8 to-transparent ..."></div>
<div class="... from-primary-400/12 via-primary-400/6 to-transparent ..."></div>
```

## Implementation Plan

### Phase 1: Design System Foundation (30 min)

**Files to Modify**:
1. `/var/www/projects/registro/app/design-system.json`
2. `/var/www/projects/registro/app/tailwind.config.js`

**Changes**:

**1.1. Update design-system.json**
```json
{
  "version": "4.0.0",
  "name": "Medical Precision",
  "description": "Monochrome luxury palette with turquoise accent",
  "colors": {
    "primary": {
      "50": "#E6F4F6",
      "100": "#CCE9ED",
      "200": "#99D3DB",
      "300": "#66BDC9",
      "400": "#4AA5B0",
      "500": "#3D8A94",
      "600": "#2F6A72",
      "700": "#224A50",
      "800": "#162A2E",
      "900": "#0A0F10"
    },
    "neutral": {
      "50": "#F9FAFB",
      "100": "#F3F4F6",
      "200": "#E5E7EB",
      "300": "#D1D5DB",
      "400": "#9CA3AF",
      "500": "#6B7280",
      "600": "#4B5563",
      "700": "#374151",
      "800": "#1F2937",
      "900": "#111827"
    },
    "success": "#34C759",
    "warning": "#FF9500",
    "error": "#FF3B30",
    "info": "#4AA5B0"
  },
  "borderRadius": {
    "none": "0px",
    "sm": "0.25rem",
    "base": "0.375rem",
    "md": "0.5rem",
    "lg": "0.625rem",
    "xl": "1.25rem",
    "2xl": "1.5rem",
    "3xl": "1.875rem",
    "full": "9999px"
  }
}
```

**1.2. Update tailwind.config.js DaisyUI theme**
```javascript
{
  ios: {
    // Monochrome Color System
    "primary": "#4AA5B0",        // Turquoise accent (24% saturation)
    "secondary": "#2B2D2F",      // Warm Charcoal
    "accent": "#4AA5B0",         // Same as primary (monochrome + accent)
    "success": "#34C759",        // iOS Green
    "warning": "#FF9500",        // iOS Orange
    "error": "#FF3B30",          // iOS Red
    "info": "#4AA5B0",           // Turquoise

    // Neutral Base Colors
    "base-100": "#FFFFFF",       // Pure White
    "base-200": "#F3F4F6",       // Light Gray (was tan #D4C5B0)
    "base-300": "#E5E7EB",       // Soft Gray (was light tan)
    "base-content": "#1F2937",   // Charcoal Text

    // Border Radius (10px standard)
    "--rounded-box": "0.625rem",   // 10px for cards
    "--rounded-btn": "9999px",     // Pill buttons (preserve)
    "--rounded-badge": "0.625rem", // 10px for badges

    // Animations (unchanged)
    "--animation-btn": "0.3s",
    "--animation-input": "0.2s",
    "--border-btn": "1px",
  }
}
```

**1.3. Regenerate design tokens**
```bash
npm run generate-tokens
```

### Phase 2: Hero Component Fixes (45 min)

**Critical Files**:
1. `/resources/views/components/ios/hero-banner.blade.php` (main hero component)
2. `/resources/views/home.blade.php` (homepage usage)
3. `/resources/views/components/ios/service-hero.blade.php` (service pages)

**2.1. Update hero-banner.blade.php**

**Current (lines 8-27)**:
```php
@props([
    'gradient' => 'from-indigo-600 via-violet-600 to-pink-600',
])

<section class="relative min-h-screen flex items-center justify-center overflow-hidden bg-gradient-to-br {{ $gradient }}">
    <!-- Gradient Orbs -->
    <div class="absolute top-1/4 left-1/4 w-[600px] h-[600px] rounded-full bg-gradient-radial from-indigo-500/40 via-indigo-500/20 to-transparent blur-3xl animate-blob"></div>
    <div class="absolute bottom-1/4 right-1/4 w-[500px] h-[500px] rounded-full bg-gradient-radial from-violet-500/30 via-violet-500/15 to-transparent blur-3xl animate-blob animation-delay-2000"></div>
    <div class="absolute top-1/2 right-1/3 w-[400px] h-[400px] rounded-full bg-gradient-radial from-pink-500/25 via-pink-500/10 to-transparent blur-3xl animate-blob animation-delay-4000"></div>

    <!-- Content -->
    <div class="relative z-10 container mx-auto px-4 py-32 text-center">
        <h1 class="text-6xl md:text-7xl lg:text-8xl font-bold text-white mb-6 drop-shadow-lg">
            {{ $title }}
        </h1>
        <p class="text-xl md:text-2xl text-blue-100 mb-8 max-w-3xl mx-auto">
            {{ $subtitle }}
        </p>
```

**New (complete replacement)**:
```php
@props([
    'title',
    'subtitle',
])

<section class="relative min-h-[50vh] sm:min-h-[60vh] lg:min-h-[70vh] flex items-center justify-center overflow-hidden bg-primary-600">
    <!-- Monochrome Gradient Orbs (subtle decoration) -->
    <div class="absolute top-1/4 left-1/4 w-[600px] h-[600px] rounded-full bg-gradient-radial from-primary-500/15 via-primary-500/8 to-transparent blur-3xl animate-blob"></div>
    <div class="absolute bottom-1/4 right-1/4 w-[500px] h-[500px] rounded-full bg-gradient-radial from-primary-400/12 via-primary-400/6 to-transparent blur-3xl animate-blob animation-delay-2000"></div>

    <!-- Content -->
    <div class="relative z-10 container mx-auto px-4 py-12 sm:py-16 md:py-20 lg:py-24 text-center">
        <h1 class="font-bold text-white mb-6 drop-shadow-lg" style="font-size: clamp(2.25rem, 6vw, 6rem); line-height: 1.1;">
            {{ $title }}
        </h1>
        <p class="text-white/90 mb-8 max-w-3xl mx-auto" style="font-size: clamp(1.125rem, 2.5vw, 1.5rem); line-height: 1.5;">
            {{ $subtitle }}
        </p>

        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            {{ $slot }}
        </div>
    </div>
</section>
```

**2.2. Update home.blade.php hero usage (line 14)**

**Current**:
```html
<x-ios.hero-banner
    gradient="from-indigo-600 via-violet-600 to-pink-600"
>
```

**New**:
```html
<x-ios.hero-banner
    title="Profesjonalny Car Detailing w Poznaniu"
    subtitle="Przywróć swojemu samochodowi pierwotny blask. Najwyższa jakość, doświadczony zespół, nowoczesne technologie."
>
```

**2.3. Update gradient backgrounds in sections (lines 34, 70)**

**Line 34 - Services Section**:
```html
<!-- Current -->
<section class="py-24 px-4 md:px-6 bg-gradient-to-b from-gray-50 to-white">

<!-- New -->
<section class="py-24 px-4 md:px-6 bg-white">
```

**Line 70 - Why Choose Us Section**:
```html
<!-- Current -->
<section class="relative py-24 px-4 md:px-6 overflow-hidden bg-gradient-to-br from-indigo-50 via-violet-50 to-pink-50">

<!-- New -->
<section class="relative py-24 px-4 md:px-6 overflow-hidden bg-neutral-50">
```

### Phase 3: Border-Radius Component Updates (2 hours)

**Strategy**: Update 10 core reusable components, then verify in pages.

**Core Components to Update**:

**3.1. Button Component** (`/resources/views/components/ios/button.blade.php`)
```html
<!-- Search: rounded-xl, rounded-2xl -->
<!-- Replace: rounded-lg -->

<!-- Exception: Pill buttons keep rounded-full -->
<button class="... rounded-lg ...">
```

**3.2. Input Component** (`/resources/views/components/ios/input.blade.php`)
```html
<!-- Search: rounded-xl -->
<!-- Replace: rounded-lg -->

<input class="... rounded-lg ...">
```

**3.3. Service Card** (`/resources/views/components/ios/service-card.blade.php`)
```html
<!-- Card container: rounded-2xl → rounded-lg -->
<div class="bg-white rounded-lg shadow-lg overflow-hidden ...">

<!-- Icon container: rounded-2xl → rounded-lg -->
<div class="flex-shrink-0 w-12 h-12 rounded-lg bg-primary-500 ...">

<!-- Badges: keep rounded-full -->
<span class="... rounded-full ...">{{ $badge }}</span>
```

**3.4. Auth Card** (`/resources/views/components/ios/auth-card.blade.php`)
```html
<!-- Current: rounded-3xl (30px) -->
<!-- New: rounded-lg (10px) -->

<div class="w-full max-w-md bg-white rounded-lg shadow-2xl p-8">
```

**3.5. Alert Component** (`/resources/views/components/ios/alert.blade.php`)
```html
<!-- rounded-xl → rounded-lg -->
<div class="... rounded-lg ...">
```

**3.6-3.10. Other Components**:
- `service-details.blade.php` - rounded-2xl → rounded-lg
- `checkbox.blade.php` - preserve rounded-full for toggles
- `footer.blade.php` - check for any border-radius
- `nav-item.blade.php` - check for any border-radius
- `mobile-drawer.blade.php` - rounded-3xl → rounded-lg

**Batch Search & Replace Commands**:
```bash
# Find all instances of rounded-xl, rounded-2xl, rounded-3xl
grep -rn "rounded-\(xl\|2xl\|3xl\)" resources/views/ --include="*.blade.php" > border-radius-audit.txt

# Manual review required to preserve:
# - rounded-full (avatars, badges, pills)
# - Intentional larger radius elements
```

### Phase 4: Page Template Updates (2 hours)

**35+ Templates Requiring Updates** (grouped by type):

**Authentication Pages**:
- `/resources/views/auth/login.blade.php`
- `/resources/views/auth/register.blade.php`
- `/resources/views/auth/passwords/email.blade.php`
- `/resources/views/auth/passwords/reset.blade.php`

**Profile Pages**:
- `/resources/views/profile/index.blade.php`
- `/resources/views/profile/personal.blade.php`
- `/resources/views/profile/vehicle.blade.php`
- `/resources/views/profile/address.blade.php`
- `/resources/views/profile/notifications.blade.php`
- `/resources/views/profile/security.blade.php`

**Service Pages**:
- `/resources/views/services/index.blade.php` (hero gradient at line 7)
- `/resources/views/services/show.blade.php`

**Booking Wizard**:
- `/resources/views/booking/step1.blade.php`
- `/resources/views/booking/step2.blade.php`
- `/resources/views/booking/step3.blade.php`
- `/resources/views/booking/step4.blade.php`
- `/resources/views/booking/confirmation.blade.php`

**CMS Pages**:
- `/resources/views/pages/show.blade.php`
- `/resources/views/posts/show.blade.php`
- `/resources/views/promotions/show.blade.php`
- `/resources/views/portfolio/show.blade.php`

**Other Pages**:
- `/resources/views/appointments/index.blade.php`
- `/resources/views/maintenance.blade.php`

**Common Changes Across All Templates**:
1. Replace `rounded-{xl,2xl,3xl}` with `rounded-lg`
2. Remove gradient classes (`bg-gradient-to-*`)
3. Replace with solid colors (`bg-white`, `bg-neutral-50`, `bg-primary-600`)
4. Preserve `rounded-full` for avatars, badges, status indicators

### Phase 5: Gradient Icon Replacements (1 hour)

**Target**: Icon containers with gradient backgrounds

**Pattern to Find**:
```html
<div class="... bg-gradient-to-br from-indigo-500 to-violet-500 ...">
```

**Replacement**:
```html
<div class="... bg-primary-500 ...">
```

**Files with Icon Gradients** (from home.blade.php analysis):
- Lines 84, 95, 106 in `/resources/views/home.blade.php`
- Service cards across multiple pages
- Feature cards in CMS pages

### Phase 6: CSS Custom Properties Update (15 min)

**File**: `/resources/css/app.css`

**Verify Radial Gradient Utility** (line 257 - already exists):
```css
.bg-gradient-radial {
    background-image: radial-gradient(circle, var(--tw-gradient-stops));
}
```

**Add Fluid Typography Utilities** (new):
```css
/* Fluid Typography Utilities */
.text-fluid-hero {
    font-size: clamp(2.25rem, 6vw, 6rem);
    line-height: 1.1;
}

.text-fluid-subtitle {
    font-size: clamp(1.125rem, 2.5vw, 1.5rem);
    line-height: 1.5;
}

.text-fluid-body {
    font-size: clamp(1rem, 2vw, 1.25rem);
    line-height: 1.6;
}
```

### Phase 7: Testing & Validation (1 hour)

**7.1. Visual Regression Testing**

**Pages to Test** (4 breakpoints: 375px, 768px, 1024px, 1920px):
1. Homepage `/`
2. Services index `/uslugi`
3. Service detail `/uslugi/{slug}`
4. Login `/login`
5. Register `/register`
6. Profile `/moje-konto`
7. Booking wizard `/booking/step/1`
8. Maintenance page (if accessible)

**Checklist per Page**:
- [ ] Hero height appropriate (50vh mobile, 70vh desktop)
- [ ] No purple/pink/indigo gradients visible
- [ ] All borders 10px (except pills/avatars)
- [ ] Monochrome color scheme (88% neutrals, 12% turquoise)
- [ ] Typography readable at all sizes
- [ ] Touch targets ≥44x44px (mobile)
- [ ] WCAG AA contrast ratios met

**7.2. Browser Compatibility**

Test in:
- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile Safari (iOS)
- Chrome Mobile (Android)

**7.3. Accessibility Audit**

```bash
# Run axe DevTools on each page
# Verify WCAG AA compliance
# Check keyboard navigation
# Test screen reader compatibility
```

### Phase 8: Documentation Updates (30 min)

**Files to Update**:

**8.1. CHANGELOG.md**
```markdown
## [4.0.0] - 2025-12-11

### Changed - Visual Redesign
- **BREAKING**: Implemented monochrome "Medical Precision" color system
- Updated primary color from #6B9FA8 (25% sat) to #4AA5B0 (24% sat)
- Replaced gradient backgrounds with solid colors across all pages
- Standardized border-radius to 10px (rounded-lg) for all UI elements
- Hero section responsive height: 50vh mobile → 70vh desktop
- Fluid typography with clamp() for optimal readability
- Updated design-system.json to v4.0.0

### Removed
- All purple/pink/indigo gradient backgrounds
- Tan/bronze accent colors (#D4C5B0, #8B7355)
- Large border-radius values (xl, 2xl, 3xl) from components

### Preserved
- Sharp geometric logo (brand identity)
- Pill buttons with rounded-full
- Avatar and badge rounded-full styling
```

**8.2. Design System Documentation**
```markdown
# Design System v4.0.0 - Medical Precision

## Color Philosophy
Monochrome luxury palette with single turquoise accent.

**Usage Guidelines**:
- 88% neutrals (white, grays, charcoal)
- 12% turquoise accent (CTAs, highlights, icons)
- No multi-color gradients
- Solid backgrounds only

## Border Radius Standard
All UI elements use 10px (rounded-lg) except:
- Pills/badges: rounded-full
- Avatars: rounded-full
- Toggles: rounded-full
```

**8.3. Component Documentation**

Update each component's usage examples to reflect new props and styling.

### Phase 9: Deployment & Rollback Plan (15 min)

**9.1. Pre-Deployment Checklist**
- [ ] All tests passing
- [ ] Visual regression screenshots captured
- [ ] Design tokens regenerated
- [ ] Production build successful (`npm run build`)
- [ ] Git branch created: `feature/visual-redesign-v4`

**9.2. Deployment Steps**
```bash
# 1. Build production assets
cd app && npm run build

# 2. Clear all caches
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan filament:optimize-clear

# 3. Restart containers
docker compose restart app nginx

# 4. Verify deployment
curl -I https://registro.local:8444/
```

**9.3. Rollback Plan**

If critical issues found within 24 hours:

```bash
# Emergency rollback to v3.0.0
git revert <commit-hash>

# Restore previous design system
cp design-system.v3.0.0.backup.json design-system.json

# Restore previous Tailwind config
cp tailwind.config.v3.backup.js tailwind.config.js

# Rebuild assets
npm run build

# Clear caches
docker compose exec app php artisan optimize:clear
```

## Risk Assessment

**High Risk**:
- ❌ **Breaking Change**: Color system complete overhaul may affect third-party integrations
- ⚠️ **User Perception**: Monochrome palette is drastic visual change from current vibrant design

**Medium Risk**:
- ⚠️ **Browser Compatibility**: `clamp()` requires modern browsers (IE11 not supported)
- ⚠️ **Accessibility**: Must verify contrast ratios for all color combinations

**Low Risk**:
- ✅ **Border-Radius**: Simple CSS changes, easily reversible
- ✅ **Hero Height**: Improves mobile UX, low controversy

**Mitigation Strategies**:
1. **Staged Rollout**: Deploy to staging first, gather feedback
2. **A/B Testing**: Consider split testing if user feedback is uncertain
3. **Fallback CSS**: Add browser fallbacks for clamp() if needed:
   ```css
   font-size: 2.25rem; /* fallback */
   font-size: clamp(2.25rem, 6vw, 6rem);
   ```

## Success Metrics

**Visual Quality**:
- ✅ No purple/pink/indigo gradients on any page
- ✅ All border-radius consistently 10px (except pills/avatars)
- ✅ Hero height ≤50vh on mobile (375px width)
- ✅ Monochrome color distribution: 88% neutrals, 12% turquoise

**Technical Quality**:
- ✅ WCAG AA compliance maintained (4.5:1 contrast)
- ✅ Lighthouse accessibility score ≥95
- ✅ Mobile performance score ≥90
- ✅ Zero console errors/warnings

**User Experience**:
- ✅ Professional luxury aesthetic achieved
- ✅ Consistent with €200-600 service tier branding
- ✅ Logo sharp edges preserved as distinctive element
- ✅ Touch targets ≥44x44px on mobile

## Timeline Estimate

| Phase | Duration | Dependencies |
|-------|----------|--------------|
| Phase 1: Design System Foundation | 30 min | None |
| Phase 2: Hero Component Fixes | 45 min | Phase 1 complete |
| Phase 3: Border-Radius Components | 2 hours | Phase 1 complete |
| Phase 4: Page Template Updates | 2 hours | Phase 3 complete |
| Phase 5: Gradient Icon Replacements | 1 hour | Phase 2 complete |
| Phase 6: CSS Custom Properties | 15 min | Phase 1 complete |
| Phase 7: Testing & Validation | 1 hour | All phases complete |
| Phase 8: Documentation Updates | 30 min | Phase 7 complete |
| Phase 9: Deployment & Rollback | 15 min | Phase 8 complete |
| **Total** | **~8-9 hours** | Sequential execution |

## Critical Files Summary

**Must Modify** (3 config files):
1. `/var/www/projects/registro/app/design-system.json` - Color system v4.0.0
2. `/var/www/projects/registro/app/tailwind.config.js` - DaisyUI theme update
3. `/var/www/projects/registro/app/resources/css/app.css` - Fluid typography utilities

**Must Update** (10 core components):
1. `/resources/views/components/ios/hero-banner.blade.php` - Responsive height + solid bg
2. `/resources/views/components/ios/button.blade.php` - Border-radius
3. `/resources/views/components/ios/input.blade.php` - Border-radius
4. `/resources/views/components/ios/service-card.blade.php` - Border-radius + gradient removal
5. `/resources/views/components/ios/auth-card.blade.php` - Border-radius
6. `/resources/views/components/ios/alert.blade.php` - Border-radius
7. `/resources/views/components/ios/service-details.blade.php` - Border-radius
8. `/resources/views/components/ios/service-hero.blade.php` - Gradient removal
9. `/resources/views/components/ios/checkbox.blade.php` - Verify rounded-full preserved
10. `/resources/views/components/ios/footer.blade.php` - Border-radius audit

**Must Review** (35+ page templates):
- All auth, profile, services, booking, CMS, appointments, maintenance pages

## Conclusion

This plan delivers all 6 user requirements with research-backed design decisions:

1. ✅ **Hero Mobile Fix**: 50vh responsive height with fluid typography
2. ✅ **Gradient Removal**: Complete elimination, solid design system colors
3. ✅ **Border-Radius**: 10px standard across all UI elements
4. ✅ **Logo Compatibility**: Sharp edges preserved as distinctive brand element
5. ✅ **Monochrome Color System**: Professional 88/12 neutral/accent split
6. ✅ **Quality Research**: BMW, Mercedes, Stripe, Linear best practices applied

**Ready for implementation approval.**
