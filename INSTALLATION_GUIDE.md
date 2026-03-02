# Registro Frontend - Installation & Testing Guide

**Quick Start Guide for Development Team**

---

## Prerequisites

- Node.js 20+ installed
- npm or yarn package manager
- Laravel 12 application running
- Docker environment setup (optional)

---

## Installation Steps

### 1. Install Node Dependencies

```bash
cd /var/www/projects/registro/app
npm install
```

This will install:
- Alpine.js 3.14.1
- Tailwind CSS 4.0
- Vite 7.0.7
- All dev dependencies

### 2. Start Development Server

```bash
# Option A: Start Vite dev server only
npm run dev

# Option B: Start all services (Laravel + Vite + Queue + Logs)
composer run dev
```

Vite dev server will run on: `http://localhost:5173`

### 3. Build for Production

```bash
npm run build
```

Assets will be compiled to: `public/build/`

---

## Verify Installation

### Check Files Created/Modified

```bash
# Alpine.js configuration
cat resources/js/app.js | grep -A 5 "Alpine"

# Tailwind theme
cat resources/css/app.css | grep -A 5 "@theme"

# Package.json
cat package.json | grep "alpinejs"
```

### Test in Browser

1. **Open Homepage:**
   ```
   https://registro.local:8444/
   ```

2. **Check Console:**
   - Open browser DevTools (F12)
   - Console should show: "Booking wizard initialized" (when on booking page)
   - No errors should appear

3. **Test Alpine.js:**
   - Open browser console
   - Type: `window.Alpine`
   - Should return Alpine.js object

---

## Testing Checklist

### Homepage Testing

#### Visual Test
- [ ] Hero section displays with gradient background
- [ ] Trust badges animate when scrolling into view
- [ ] Service cards display in grid (1/2/3 columns based on screen size)
- [ ] Service cards scale on hover (desktop)
- [ ] Service details expand/collapse when clicking info button
- [ ] Prices display correctly formatted

#### Interaction Test
```javascript
// Open browser console and test Alpine.js components
Alpine.store('test', { value: 'working' })
console.log(Alpine.store('test').value) // Should log: "working"
```

#### Mobile Test
- [ ] Mobile sticky CTA appears when scrolling down (guests only)
- [ ] Mobile sticky CTA disappears when hero section is visible
- [ ] Touch targets are at least 44x44px
- [ ] No horizontal scroll on any screen size

### Booking Form Testing

#### Step 1: Service Confirmation
```
URL: /services/1/book (or any service ID)
```

- [ ] Service details display correctly
- [ ] "Next" button advances to step 2
- [ ] Progress bar shows 25%

#### Step 2: Date & Time Selection
- [ ] Staff dropdown populates from backend
- [ ] Date picker allows future dates only
- [ ] Selecting staff + date triggers API call
- [ ] Loading spinner appears during fetch
- [ ] Time slots display in grid
- [ ] Selected time slot highlights
- [ ] "Next" button disabled until slot selected
- [ ] Previous button returns to step 1

**Test API Call:**
```javascript
// Open browser console
fetch('/api/available-slots', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
  },
  body: JSON.stringify({
    service_id: 1,
    staff_id: 2,
    date: '2025-10-15'
  })
})
.then(r => r.json())
.then(console.log)
// Should return: { slots: [...], date: "2025-10-15" }
```

#### Step 3: Customer Details
- [ ] Notes textarea accepts input
- [ ] Character limit (1000) enforced
- [ ] Help text displays
- [ ] Important info alert shows policies

#### Step 4: Summary & Confirmation
- [ ] All selected data displays correctly:
  - Service name
  - Staff name
  - Date
  - Time range
  - Duration
  - Notes (if entered)
  - Total price
- [ ] "Confirm" button submits form
- [ ] Hidden inputs contain correct values

#### Sidebar Testing
- [ ] Sidebar is sticky on desktop (scrolls with page)
- [ ] Summary updates as user selects options
- [ ] Price displays correctly

### Responsive Testing

Use browser DevTools (F12) → Device Toolbar (Ctrl+Shift+M)

**Mobile (375px):**
```javascript
// Set viewport
document.documentElement.style.width = '375px'
```
- [ ] Single column layout
- [ ] Service cards stack vertically
- [ ] Time slots: 2 columns
- [ ] Buttons full width
- [ ] No horizontal scroll

**Tablet (768px):**
- [ ] Service cards: 2 columns
- [ ] Time slots: 3 columns
- [ ] Step labels visible

**Desktop (1280px):**
- [ ] Service cards: 3 columns
- [ ] Time slots: 4 columns
- [ ] Sidebar visible and sticky
- [ ] Trust badges inline

### Accessibility Testing

#### Keyboard Navigation Test
1. Tab through homepage
   - [ ] All interactive elements focusable
   - [ ] Focus indicators visible
   - [ ] Tab order logical

2. Tab through booking form
   - [ ] Can navigate all steps
   - [ ] Enter/Space activates buttons
   - [ ] Can select time slots with keyboard

#### Screen Reader Test

**VoiceOver (Mac):**
```bash
# Enable VoiceOver
Cmd + F5
```

**NVDA (Windows):**
```
Download from: https://www.nvaccess.org/
```

Test:
- [ ] Page title announced
- [ ] Step indicators announce current step
- [ ] Form labels read correctly
- [ ] Error messages announced
- [ ] Button purposes clear

#### Automated Accessibility Test

**Lighthouse:**
```bash
# Open Chrome DevTools
# Go to Lighthouse tab
# Select "Accessibility" only
# Click "Generate report"
```
Target Score: 100

**axe DevTools:**
```bash
# Install extension: https://www.deque.com/axe/devtools/
# Click axe icon in DevTools
# Click "Scan ALL of my page"
```
Target: 0 violations

### Performance Testing

#### Lighthouse Performance

```bash
# Open Chrome DevTools (F12)
# Go to Lighthouse tab
# Select "Performance" only
# Click "Generate report"
```

**Target Metrics:**
- Performance Score: ≥90
- LCP (Largest Contentful Paint): <2.5s
- TBT (Total Blocking Time): <200ms
- CLS (Cumulative Layout Shift): <0.1

#### Network Analysis

```javascript
// Open DevTools → Network tab
// Reload page
// Check loaded resources
```

Expected loads:
- [ ] app.css (Tailwind compiled)
- [ ] app.js (Alpine.js + custom code)
- [ ] No external CDN requests
- [ ] Total page weight: <500KB

#### Bundle Size Check

```bash
# After build
ls -lh public/build/assets/

# app.css should be ~50-100KB
# app.js should be ~50-100KB
```

---

## Common Issues & Solutions

### Issue: Alpine.js Not Loading

**Symptoms:**
- Components don't respond to clicks
- `x-data` attributes don't work
- Console error: "Alpine is not defined"

**Solution:**
```bash
# Clear cache
npm run build
php artisan view:clear

# Check import
cat resources/js/app.js | grep "import Alpine"

# Verify Alpine is started
cat resources/js/app.js | grep "Alpine.start()"
```

### Issue: Styles Not Applying

**Symptoms:**
- Components look unstyled
- Custom classes don't work
- Colors wrong

**Solution:**
```bash
# Rebuild Tailwind
npm run build

# Check Tailwind config
cat resources/css/app.css | head -n 20

# Clear Laravel cache
php artisan view:clear
```

### Issue: API Calls Failing

**Symptoms:**
- Time slots don't load
- Console error: 401 or 419
- CSRF token error

**Solution:**
```bash
# Check CSRF token present
curl -I https://registro.local:8444/ | grep -i cookie

# Verify API endpoint
php artisan route:list | grep available-slots

# Check authentication
php artisan tinker
>>> Auth::check()
```

### Issue: Vite Dev Server Not Hot Reloading

**Symptoms:**
- Changes not reflecting
- Need to manually refresh
- HMR not working

**Solution:**
```bash
# Restart Vite
npm run dev

# Check Vite config
cat vite.config.js

# Verify @vite directive in layout
grep "@vite" resources/views/layouts/app.blade.php
```

---

## Development Workflow

### Making Changes

1. **Edit Components:**
   ```bash
   # Homepage
   nano resources/views/home.blade.php

   # Booking form
   nano resources/views/booking/create.blade.php

   # Alpine.js components
   nano resources/js/app.js

   # Styles
   nano resources/css/app.css
   ```

2. **Save and Test:**
   - Vite hot-reloads automatically
   - Refresh browser if needed
   - Check browser console for errors

3. **Build for Production:**
   ```bash
   npm run build
   php artisan view:clear
   ```

### Adding New Alpine.js Component

```javascript
// In resources/js/app.js

Alpine.data('myComponent', () => ({
    // State
    property: 'initial value',

    // Lifecycle
    init() {
        console.log('Component initialized');
    },

    // Methods
    myMethod() {
        this.property = 'new value';
    },

    // Computed
    get computedValue() {
        return this.property.toUpperCase();
    }
}));
```

Usage in Blade:
```html
<div x-data="myComponent()">
    <p x-text="property"></p>
    <button @click="myMethod()">Click me</button>
</div>
```

### Adding New Tailwind Component

```css
/* In resources/css/app.css */

@layer components {
    .my-component {
        @apply px-4 py-2 bg-primary-600 text-white rounded-lg;
        @apply hover:bg-primary-700 transition-colors;
    }
}
```

Usage in Blade:
```html
<button class="my-component">
    My Button
</button>
```

---

## Browser DevTools Tips

### Inspect Alpine.js State

```javascript
// In browser console

// Get component data
$el = document.querySelector('[x-data]')
Alpine.$data($el)

// Watch state changes
Alpine.$data($el).step // Current step
Alpine.$data($el).errors // Current errors
```

### Debug API Calls

```javascript
// In browser console

// Monitor fetch requests
const originalFetch = window.fetch;
window.fetch = function(...args) {
    console.log('Fetch:', args);
    return originalFetch.apply(this, args);
};
```

### Inspect Tailwind Classes

```javascript
// In browser console

// Get all classes on element
$el = document.querySelector('.btn-primary')
console.log($el.className)

// Check computed styles
getComputedStyle($el).backgroundColor
```

---

## Testing Scripts

### Automated Test Suite

Create this file: `tests/frontend-check.sh`

```bash
#!/bin/bash

echo "🔍 Checking Registro Frontend Implementation..."

# Check Alpine.js
echo "✓ Checking Alpine.js..."
grep -q "import Alpine from 'alpinejs'" app/resources/js/app.js && echo "  ✅ Alpine.js imported" || echo "  ❌ Alpine.js missing"

# Check Tailwind theme
echo "✓ Checking Tailwind theme..."
grep -q "@theme" app/resources/css/app.css && echo "  ✅ Custom theme found" || echo "  ❌ Theme missing"

# Check package.json
echo "✓ Checking dependencies..."
grep -q "alpinejs" app/package.json && echo "  ✅ Alpine.js in package.json" || echo "  ❌ Alpine.js not in package.json"

# Check views
echo "✓ Checking Blade templates..."
[ -f app/resources/views/home.blade.php ] && echo "  ✅ home.blade.php exists" || echo "  ❌ home.blade.php missing"
[ -f app/resources/views/booking/create.blade.php ] && echo "  ✅ booking/create.blade.php exists" || echo "  ❌ booking/create.blade.php missing"

# Check build files
echo "✓ Checking compiled assets..."
[ -d app/public/build ] && echo "  ✅ Build directory exists" || echo "  ⚠️  Run 'npm run build'"

echo "✅ Frontend check complete!"
```

Run with:
```bash
chmod +x tests/frontend-check.sh
./tests/frontend-check.sh
```

---

## Production Deployment Checklist

Before deploying to production:

### Build
- [ ] `npm run build` completes without errors
- [ ] Assets minified and optimized
- [ ] Source maps generated

### Caching
- [ ] `php artisan config:cache`
- [ ] `php artisan route:cache`
- [ ] `php artisan view:cache`

### Testing
- [ ] All functional tests pass
- [ ] Lighthouse performance ≥90
- [ ] Lighthouse accessibility = 100
- [ ] Tested on mobile devices
- [ ] Cross-browser tested

### Security
- [ ] CSRF tokens working
- [ ] Input validation working
- [ ] XSS protection verified
- [ ] HTTPS enforced

### Monitoring
- [ ] Error logging configured
- [ ] Analytics tracking setup
- [ ] Performance monitoring active

---

## Next Steps

1. **Install & Test:**
   ```bash
   cd /var/www/projects/registro/app
   npm install
   npm run dev
   ```

2. **Open in Browser:**
   ```
   https://registro.local:8444/
   ```

3. **Test Booking Flow:**
   - Click on a service
   - Go through all 4 steps
   - Submit booking

4. **Report Issues:**
   - Check browser console for errors
   - Review IMPLEMENTATION_SUMMARY.md
   - Contact dev team with specifics

---

## Support Resources

- **Implementation Details:** `IMPLEMENTATION_SUMMARY.md`
- **API Documentation:** `/docs/api-contract-frontend.md`
- **Project Architecture:** `/docs/project_map.md`
- **Alpine.js Docs:** https://alpinejs.dev
- **Tailwind CSS Docs:** https://tailwindcss.com/docs

---

**Happy Coding!** 🚀
