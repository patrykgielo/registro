# Tailwind CSS 4.0 + Vite Setup Documentation

**Created:** 2024-11-14
**Status:** Production Ready ✅
**Version:** Tailwind 4.0.0 + Vite 7.0.7

---

## Overview

This project uses **Tailwind CSS 4.0** with **Vite 7** and an **automated design token system** based on `design-system.json` as the single source of truth for iOS-styled design tokens.

### Architecture

```
design-system.json (Single Source of Truth)
        ↓
scripts/generate-theme.js (Build Script)
        ↓
resources/css/design-tokens.css (Generated @theme {})
        ↓
resources/css/app.css (@import)
        ↓
Tailwind CSS 4.0 Classes
        ↓
public/build/assets/*.css (Production)
```

---

## Key Features

✅ **No tailwind.config.js** - Tailwind 4.0 uses `@theme {}` in CSS
✅ **No PostCSS config** - `@tailwindcss/vite` plugin handles everything
✅ **Automated token generation** - 135 CSS variables from design-system.json
✅ **iOS Design System** - SF Pro font, iOS colors, iOS-specific measurements
✅ **Single Source of Truth** - All tokens defined in design-system.json
✅ **Build-time generation** - Theme auto-regenerates on every build

---

## File Structure

```
app/
├── design-system.json              # iOS design tokens (source of truth)
├── component-registry.json         # iOS component documentation
├── vite.config.js                  # Vite config with @tailwindcss/vite
├── package.json                    # NPM scripts
├── scripts/
│   └── generate-theme.js           # Theme generator (JSON → CSS)
├── resources/
│   └── css/
│       ├── app.css                 # Main CSS entry point
│       └── design-tokens.css       # Generated @theme {} (auto-generated)
└── public/
    └── build/
        └── assets/                 # Built CSS/JS (production)
```

---

## Configuration Files

### 1. vite.config.js

```javascript
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(), // ⚠️ MUST be before laravel() for v4.0
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
    ],
    build: {
        manifest: 'manifest.json', // Vite 7: force manifest in root build dir (was .vite/manifest.json)
        outDir: 'public/build',
    },
});
```

**Critical:** `tailwindcss()` MUST be before `laravel()` plugin!

### 2. resources/css/app.css

```css
@import 'tailwindcss';

/* Design Tokens - Auto-generated from design-system.json */
@import './design-tokens.css';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
@source '../**/*.blade.php';
@source '../**/*.js';

/* Custom styles using plain CSS - Tailwind CSS 4.0 compatible */
```

**Notes:**
- Uses `@import 'tailwindcss'` (NOT `@tailwind` directives)
- Uses `@source` to define content scanning paths
- Imports auto-generated `design-tokens.css`

### 3. package.json Scripts

```json
{
  "scripts": {
    "dev": "vite",
    "build": "npm run generate:theme && vite build",
    "generate:theme": "node scripts/generate-theme.js",
    "watch:theme": "node scripts/generate-theme.js --watch"
  }
}
```

---

## Design System (design-system.json)

**Single Source of Truth** for all design tokens.

### Structure

```json
{
  "version": "1.0.0",
  "last_updated": "2024-11-14",

  "colors": {
    "primary": { ... },      // iOS Blue (#007AFF)
    "secondary": { ... },    // Slate grays
    "semantic": { ... },     // success, warning, error, info
    "background": { ... },   // iOS backgrounds
    "text": { ... },         // iOS text hierarchy
    "gray": { ... }          // iOS gray scale
  },

  "typography": {
    "fontFamilies": {
      "system": "-apple-system, BlinkMacSystemFont, 'SF Pro Text', ..."
    },
    "fontSizes": { ... },
    "fontWeights": { ... },
    "lineHeights": { ... },
    "letterSpacing": { ... }
  },

  "spacing": { ... },
  "borderRadius": { ... },
  "shadows": { ... },
  "animations": { ... },
  "breakpoints": { ... },
  "zIndex": { ... },

  "ios": {
    "touchTarget": {
      "minimum": "44px",     // iOS minimum touch target
      "comfortable": "48px",
      "large": "56px"
    },
    "separators": { ... },
    "statusBar": { ... },
    "navigationBar": { ... },
    "tabBar": { ... },
    "safeArea": { ... }
  }
}
```

### Token Categories (135 variables)

| Category | Variables | Example |
|----------|-----------|---------|
| Colors | 48 | `--color-primary-500`, `--color-success` |
| Typography | 31 | `--font-system`, `--font-size-lg` |
| Spacing | 14 | `--spacing-4`, `--spacing-12` |
| Border Radius | 10 | `--radius-lg`, `--radius-full` |
| Shadows | 7 | `--shadow-md`, `--shadow-xl` |
| Animations | 9 | `--duration-base`, `--ease-ios` |
| iOS-specific | 11 | `--touch-target-minimum`, `--safe-area-bottom` |
| Breakpoints | 5 | `--breakpoint-md`, `--breakpoint-xl` |

---

## Usage

### Development

```bash
# Start dev server (Vite HMR)
npm run dev

# Generate theme manually
npm run generate:theme
```

**Dev Server:** http://registro.local:5173

### Production Build

```bash
# Build for production (auto-generates theme)
npm run build
```

**Output:**
- `public/build/.vite/manifest.json` - Asset manifest
- `public/build/assets/app-[hash].css` - Minified CSS (~93KB)
- `public/build/assets/app-[hash].js` - Minified JS (~147KB)

**Build Time:** ~750ms

### Using Design Tokens in CSS

```css
/* Tailwind utility classes (preferred) */
<div class="bg-primary-500 text-white rounded-lg shadow-md">...</div>

/* Custom CSS with CSS variables */
.custom-button {
  background: var(--color-primary-500);
  border-radius: var(--radius-lg);
  padding: var(--spacing-4);
  font-family: var(--font-system);
  box-shadow: var(--shadow-md);
}
```

### Using in Blade Components

```blade
{{-- iOS Button Component --}}
<x-ios-button
  variant="primary"
  size="lg"
  :loading="$isSubmitting"
  wire:click="submit"
>
  Submit
</x-ios-button>

{{-- Uses design-system.json tokens automatically --}}
```

**See:** `component-registry.json` for full component library

---

## Updating Design Tokens

### 1. Edit design-system.json

```json
{
  "colors": {
    "primary": {
      "500": "#007AFF"  // Change color here
    }
  }
}
```

### 2. Regenerate Theme

```bash
npm run generate:theme
```

### 3. Build

```bash
npm run build
```

**Done!** All components using `primary-500` will update automatically.

---

## Build Script (scripts/generate-theme.js)

**Features:**
- ✅ Reads `design-system.json`
- ✅ Generates 135 CSS custom properties
- ✅ Outputs to `resources/css/design-tokens.css`
- ✅ Uses Tailwind 4.0 `@theme {}` syntax
- ✅ Auto-runs before production build
- ✅ Warning header: "AUTO-GENERATED - Do not edit"

**Output Format:**

```css
/**
 * Design Tokens - Generated from design-system.json
 *
 * WARNING: This file is AUTO-GENERATED. Do not edit manually!
 * Run 'npm run generate:theme' to regenerate.
 */

@theme {
  /* Colors */
  --color-primary-500: #007AFF;
  --color-success: #34C759;

  /* Typography */
  --font-system: -apple-system, BlinkMacSystemFont, ...;
  --font-size-lg: 1.125rem;

  /* iOS-specific */
  --touch-target-minimum: 44px;
  --safe-area-bottom: 34px;

  /* ... 135 total variables */
}
```

---

## Component Integration

### Component Registry

All iOS components defined in `component-registry.json` use tokens from `design-system.json`:

```json
{
  "design_principles": {
    "touch_targets": "Minimum 44x44px for all interactive elements",
    "colors": "iOS semantic colors from design-system.json",
    "typography": "System font stack mimicking SF Pro",
    "mobile_first": "Always design for mobile first"
  }
}
```

### Available Components

| Component | Category | Design Tokens Used |
|-----------|----------|-------------------|
| ios-button | forms | primary, touch-target, radius, shadow |
| ios-input | forms | text, bg, border, radius |
| ios-card | layout | bg, shadow, radius |
| ios-list-row | lists | text, bg, separator |
| ios-bottom-sheet | overlays | bg, shadow, safe-area |
| ios-toggle | forms | primary, success |

**See:** `component-registry.json:385` - References design-system.json

---

## Troubleshooting

### "Vite manifest not found" Error

**Error:** `Vite manifest not found at: /var/www/public/build/manifest.json`

**Cause:** Vite 7 changed manifest location from `public/build/manifest.json` to `public/build/.vite/manifest.json`

**Fix:**
```bash
# 1. Ensure vite.config.js has correct manifest path
# build: { manifest: 'manifest.json' }

# 2. Rebuild assets
rm -rf public/build/*
npm run build

# 3. Clear Laravel cache
docker compose exec app php artisan optimize:clear

# 4. Verify manifest location
ls -la public/build/manifest.json  # Should exist ✓
```

### Build fails with "Cannot find design-system.json"

```bash
# Make sure file exists
ls design-system.json

# Regenerate manually
npm run generate:theme
```

### CSS not loading in production

```bash
# Clear Laravel cache
docker compose exec app php artisan optimize:clear

# Rebuild assets
npm run build
```

### Old tokens still showing

```bash
# Clear Vite cache
rm -rf node_modules/.vite

# Reinstall and rebuild
npm ci
npm run build
```

### Design tokens not applying

**Check:** `resources/css/app.css` imports `design-tokens.css`:

```css
@import './design-tokens.css';
```

### Assets not updating in Docker

```bash
# Restart containers
docker compose restart app nginx

# Force rebuild in container
docker compose exec app npm run build

# Check file permissions
docker compose exec app ls -la /var/www/public/build/
```

---

## Key Differences from Tailwind 3.x

| Feature | Tailwind 3.x | Tailwind 4.0 |
|---------|-------------|--------------|
| Config File | `tailwind.config.js` | `@theme {}` in CSS |
| Directives | `@tailwind base` | `@import 'tailwindcss'` |
| Content | `content: [...]` | `@source '...'` |
| Plugins | `plugins: [...]` | Vite plugin only |
| Custom Tokens | `theme.extend` | CSS variables in `@theme {}` |
| PostCSS | Required | NOT required with `@tailwindcss/vite` |

---

## Production Checklist

- [x] design-system.json exists and is valid JSON
- [x] scripts/generate-theme.js exists and is executable
- [x] resources/css/design-tokens.css is auto-generated (don't commit if in .gitignore)
- [x] resources/css/app.css imports design-tokens.css
- [x] vite.config.js has tailwindcss() BEFORE laravel()
- [x] package.json has "build" script with theme generation
- [x] No tailwind.config.js (removed legacy v3.x config)
- [x] No postcss.config.js (not needed)
- [x] Build completes successfully: `npm run build`
- [x] Assets exist in public/build/assets/

---

## Support & Documentation

### Official Docs
- [Tailwind CSS 4.0 Docs](https://tailwindcss.com/docs)
- [Vite Plugin Docs](https://tailwindcss.com/docs/installation/vite)
- [Laravel Vite Plugin](https://laravel.com/docs/vite)

### Project Docs
- `design-system.json` - iOS design tokens
- `component-registry.json` - iOS component library
- `CLAUDE.md` - Project overview
- `docs/` - Complete project documentation

---

## FAQ

### Q: Do I need tailwind.config.js?

**A:** No. Tailwind 4.0 uses `@theme {}` in CSS. Only create tailwind.config.js if you need to configure plugins (e.g., @tailwindcss/forms), which we don't use since we have custom iOS components.

### Q: Do I need PostCSS?

**A:** No. The `@tailwindcss/vite` plugin handles everything.

### Q: Can I still use Tailwind utility classes?

**A:** Yes! All standard Tailwind classes work. Plus, you get custom classes from design-system.json tokens.

### Q: What if I want to add new design tokens?

**A:** Edit `design-system.json`, run `npm run generate:theme`, then `npm run build`. That's it!

### Q: Is DaisyUI compatible?

**A:** We don't use DaisyUI. We have a custom iOS component library (component-registry.json) specifically designed for mobile-first, iOS-styled interfaces.

### Q: How do I update design tokens in production?

1. Edit `design-system.json`
2. Commit changes
3. Deploy to production
4. Run `npm run build` on server
5. Clear Laravel cache: `php artisan optimize:clear`

---

**Last Updated:** 2024-11-14
**Maintained By:** Claude Code + Project Team
**Status:** Production Ready ✅
