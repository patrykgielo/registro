# Production Build Guide

**Last Updated:** November 2025
**Tailwind CSS Version:** 4.0
**Vite Version:** 7+

## Overview

This project uses **Tailwind CSS 4.0** with the `@tailwindcss/vite` plugin. The configuration is optimized for production builds with proper tree-shaking and CSS purging.

**CRITICAL:** Plugin order matters! `tailwindcss()` MUST be loaded BEFORE `laravel()` in vite.config.js.

## Build Process

### Step 1: Build Production Assets

```bash
cd app && npm run build
```

**This command:**
1. Compiles Tailwind CSS 4.0 styles
2. Bundles JavaScript modules
3. Minifies CSS and JS
4. Generates hashed filenames for cache busting
5. Creates `public/build/.vite/manifest.json`

### Step 2: Verify Build Output

```bash
# Check build directory
ls -la app/public/build/

# Verify manifest exists
cat app/public/build/.vite/manifest.json
```

**Expected Output** (Vite 7+):
```
public/build/
├── assets/
│   ├── app-[hash].css    (minified CSS with Tailwind)
│   └── app-[hash].js     (minified JavaScript)
└── .vite/
    └── manifest.json     (asset manifest for Laravel)
```

**Note:** Vite 7+ moved manifest from `public/build/manifest.json` to `public/build/.vite/manifest.json`

## Configuration Files

### vite.config.js (Optimized for Tailwind v4.0)

```javascript
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(), // ⚠️ MUST be BEFORE laravel() for v4.0
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    build: {
        manifest: true,
        outDir: 'public/build',
    },
});
```

**Key Points:**
- `tailwindcss()` plugin MUST come first
- Order matters for v4.0 compatibility
- `manifest: true` ensures Laravel can find assets

### resources/css/app.css (Tailwind v4.0 Syntax)

```css
@import 'tailwindcss';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
@source '../**/*.blade.php';
@source '../**/*.js';

@theme {
    --font-sans: 'Inter', ui-sans-serif, system-ui, sans-serif;
    /* Custom theme tokens... */
}
```

**IMPORTANT:**
- Use `@import 'tailwindcss'` (NOT old v3 `@tailwind` directives)
- Use `@source` for content detection (NOT `tailwind.config.js` content array)
- `@theme` for custom design tokens

### package.json Scripts

```json
{
  "scripts": {
    "dev": "vite",
    "build": "vite build"
  },
  "devDependencies": {
    "@tailwindcss/vite": "^4.0.0",
    "laravel-vite-plugin": "^1.0.0",
    "vite": "^7.0.0"
  }
}
```

## Production Mode in Docker

### Test Locally

```bash
# 1. Build assets on host
cd app && npm run build

# 2. Set production environment in .env
APP_ENV=production
APP_DEBUG=false

# 3. Restart containers
docker compose down && docker compose up -d

# 4. Clear Laravel cache
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
```

### Verify in Browser

1. Visit https://registro.local:8444
2. Open DevTools → Network tab
3. Check CSS/JS files are loaded from `/build/assets/`
4. Verify filenames have hashes (e.g., `app-abc123.css`)
5. Check Response Headers for `Cache-Control` (should be long-lived)

## Troubleshooting

### Problem: npm run build Fails

**Error:** `Plugin order issue` or `Cannot find module '@tailwindcss/vite'`

**Solutions:**
1. **Check plugin order** in `vite.config.js` - `tailwindcss()` MUST be BEFORE `laravel()`
2. **Verify Node.js version** >= 20.19:
   ```bash
   node --version
   ```
3. **Clear Vite cache:**
   ```bash
   rm -rf app/node_modules/.vite
   cd app && npm ci
   ```
4. **Check for syntax errors** in `resources/css/app.css`

### Problem: manifest.json Not Generated

**Error:** Laravel shows "Vite manifest not found" error

**Solutions:**
1. **Check vite.config.js** has `build: { manifest: true }`
2. **Run build again:**
   ```bash
   cd app && npm run build
   ```
3. **Check file permissions:**
   ```bash
   ls -la app/public/build/.vite/manifest.json
   chmod 644 app/public/build/.vite/manifest.json
   ```

### Problem: Tailwind Styles Not Applied

**Error:** Page loads but no Tailwind classes work (everything unstyled)

**Solutions:**
1. **Verify @import syntax** - use `@import 'tailwindcss'` NOT `@tailwind base/components/utilities`
2. **Check @source directives** point to correct template paths:
   ```css
   @source '../**/*.blade.php';  /* Covers all Blade templates */
   @source '../**/*.js';          /* JavaScript files */
   ```
3. **Check browser DevTools:**
   - Network tab: Is CSS file loaded? (200 status)
   - Elements tab: Do classes exist but have no styles?
4. **Verify @vite directive** in layout Blade file:
   ```blade
   @vite(['resources/css/app.css', 'resources/js/app.js'])
   ```

### Problem: CSS File 404 in Production

**Error:** Browser shows 404 for `/build/assets/app-[hash].css`

**Solutions:**
1. **Check manifest path** - Vite 7+ uses `public/build/.vite/manifest.json`
2. **Run build locally first:**
   ```bash
   cd app && npm run build
   ls app/public/build/assets/
   ```
3. **Check Laravel vite config** in `vite.php`:
   ```php
   'build_path' => 'build',
   ```
4. **Clear Laravel cache:**
   ```bash
   php artisan optimize:clear
   ```

### Problem: Docker Build Fails at Asset Stage

**Error:** Docker build fails with "Cannot find module" during npm ci

**Solutions:**
1. **Check Docker logs:**
   ```bash
   docker compose -f docker-compose.prod.yml logs nginx
   ```
2. **Verify package.json** has all dependencies (not just devDependencies)
3. **Ensure Docker uses `npm ci`** (NOT `npm ci --only=production`) - build tools are in devDependencies
4. **Test build locally first:**
   ```bash
   cd app && npm ci && npm run build
   ```

### Problem: Old CSS Cached in Browser

**Error:** CSS changes not visible after build

**Solutions:**
1. **Hard refresh browser:** Ctrl+Shift+R (Windows/Linux) or Cmd+Shift+R (Mac)
2. **Clear browser cache completely**
3. **Verify hash changed** in manifest.json:
   ```bash
   cat app/public/build/.vite/manifest.json
   ```
4. **Check Laravel cache:**
   ```bash
   php artisan view:clear
   php artisan optimize:clear
   ```

## Best Practices

### 1. Always Build Before Deployment

```bash
cd app && npm run build
```

**Never deploy without building!** Development mode uses Vite dev server, production requires compiled assets.

### 2. Verify manifest.json Exists

```bash
test -f app/public/build/.vite/manifest.json && echo "OK" || echo "MISSING"
```

Laravel requires manifest to load assets. Missing manifest = broken site.

### 3. Use Docker Multi-Stage Builds

```dockerfile
# Stage 1: Build assets
FROM node:20 AS builder
WORKDIR /app
COPY app/package*.json ./
RUN npm ci
COPY app/ .
RUN npm run build

# Stage 2: Production
FROM php:8.2-fpm
COPY --from=builder /app/public/build /var/www/html/public/build
```

### 4. Version Control package-lock.json

```bash
git add app/package-lock.json
git commit -m "Update dependencies"
```

Ensures reproducible builds across environments.

### 5. Cache Busting Automatic

Vite automatically hashes filenames (e.g., `app-abc123.css`). No manual versioning needed!

### 6. Set Production Environment

```bash
# In .env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com
```

### 7. Optimize for Production

```bash
php artisan optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Performance Checklist

- [ ] `npm run build` completed successfully
- [ ] `manifest.json` exists in `public/build/.vite/`
- [ ] CSS file size < 50 KB (gzipped)
- [ ] JS file size < 100 KB (gzipped)
- [ ] Browser DevTools shows 200 for all assets
- [ ] No console errors in browser
- [ ] Tailwind classes work correctly
- [ ] Page loads in < 2 seconds
- [ ] Lighthouse score > 90

## See Also

- [Commands Reference](./commands.md) - npm and build commands
- [Docker Guide](./docker.md) - Production Docker setup
- [Troubleshooting](./troubleshooting.md) - General troubleshooting
