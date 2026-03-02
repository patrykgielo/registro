# Bug Fixes & Solutions

This directory contains detailed documentation for critical bug fixes implemented in the Paradocks application. Each document provides root cause analysis, solutions, testing procedures, and lessons learned.

## Purpose

These documents serve as:
- **Historical Record:** Track critical bugs and their resolutions
- **Knowledge Base:** Help future developers understand complex issues
- **Prevention Guide:** Learn patterns to avoid similar bugs
- **Troubleshooting Aid:** Quick reference for recurring issues

## Active Fixes

### Google Maps Picker Livewire Re-render Fix
**File:** [google-maps-picker-livewire-fix.md](google-maps-picker-livewire-fix.md)
**Date:** December 14, 2025
**Severity:** Critical
**Component:** `resources/views/filament/components/google-maps-picker.blade.php`

**Issue:** Map resets to Warsaw coordinates after autocomplete selection or marker dragging in Filament admin panel.

**Root Cause:** Livewire/Alpine.js state conflict - `$wire.set()` calls without third parameter triggered full component re-render, resetting Alpine.js state to default values.

**Solution:** Added `, false` parameter to `$wire.set()` calls for deferred updates without re-rendering.

**Key Learning:** Use `$wire.set(key, value, false)` for real-time UI interactions in Livewire + Alpine.js components.

**Impact:** All service area edits in admin panel - completely broke map functionality.

**Related:**
- [Google Maps Integration](../features/google-maps/README.md#admin-panel-integration)
- [Livewire Documentation](https://livewire.laravel.com/docs/javascript)
- [Alpine.js Magic Properties](https://alpinejs.dev/magics/wire)

---

### Alpine.js Button Click Fix
**File:** [ALPINE-BUTTON-CLICK-FIX.md](ALPINE-BUTTON-CLICK-FIX.md)
**Date:** December 14, 2025
**Component:** Filament buttons with Alpine.js `@click` handlers

**Issue:** Button clicks not registering due to Alpine.js event handling conflicts.

**Solution:** Adjusted event binding and pointer-events CSS to ensure proper click registration.

**Key Learning:** Alpine.js event handlers can conflict with Filament's internal button event system.

---

## Fix Categories

### Framework Integration Issues
- [Google Maps Picker Livewire Fix](google-maps-picker-livewire-fix.md) - Livewire + Alpine.js state conflicts
- [Alpine.js Button Click Fix](ALPINE-BUTTON-CLICK-FIX.md) - Alpine.js + Filament integration

### State Management
- [Google Maps Picker Livewire Fix](google-maps-picker-livewire-fix.md) - Component state re-initialization

## Common Patterns

### Pattern 1: Livewire + Alpine.js Deferred Updates

**Problem:** Component state resets after user interaction.

**Solution:**
```javascript
// ❌ BAD: Immediate update triggers re-render
this.$wire.set('data.field', value);

// ✅ GOOD: Deferred update, no re-render
this.$wire.set('data.field', value, false);
```

**When to Use:**
- Real-time map interactions
- Drag-and-drop events
- Autocomplete selections
- Any rapid user interaction

**When NOT to Use:**
- Form submissions
- "Save" button clicks
- Explicit user-triggered updates

**See:** [Livewire Re-render Loop Fix](google-maps-picker-livewire-fix.md#technical-deep-dive)

### Pattern 2: Defensive Programming in Alpine.js

**Problem:** Null reference errors when accessing DOM/API objects.

**Solution:**
```javascript
// ❌ BAD: Crashes if marker not initialized
this.marker.setPosition({ lat, lng });

// ✅ GOOD: Null check prevents crashes
if (this.marker) {
    this.marker.setPosition({ lat, lng });
}
```

**See:** [Google Maps Picker Fix - Input Validation](google-maps-picker-livewire-fix.md#4-add-input-validation)

### Pattern 3: Type Validation

**Problem:** Unexpected data types cause bugs.

**Solution:**
```javascript
// ✅ GOOD: Validate input types
updatePosition(lat, lng) {
    if (typeof lat !== 'number' || typeof lng !== 'number') {
        console.error('Invalid coordinates:', lat, lng);
        return;
    }
    // ... proceed with update
}
```

**See:** [Google Maps Picker Fix - Input Validation](google-maps-picker-livewire-fix.md#4-add-input-validation)

## Prevention Checklist

When creating new Livewire + Alpine.js components:

- [ ] Use `$wire.set(key, value, false)` for real-time UI updates
- [ ] Reserve immediate sync for user-triggered actions (e.g., "Save" button)
- [ ] Add null checks before manipulating DOM/map objects
- [ ] Validate input data types before processing
- [ ] Test rapid interactions (drag, click, type) to catch re-render loops
- [ ] Monitor browser console for errors during testing
- [ ] Document state management strategy in component comments

## Troubleshooting Resources

### Browser Console Errors
1. Open DevTools → Console tab
2. Reproduce the issue
3. Look for error messages or stack traces
4. Check if errors reference Livewire, Alpine.js, or specific components

### Livewire Debugging
```bash
# Enable Livewire debug mode in .env
LIVEWIRE_WIRE_DEBUG=true

# Check Livewire network requests in DevTools → Network tab
# Filter by "livewire/update" to see component updates
```

### Alpine.js Debugging
```javascript
// Add to component for state inspection
init() {
    // Log state changes
    this.$watch('currentLat', value => console.log('Lat changed:', value));
    this.$watch('currentLng', value => console.log('Lng changed:', value));

    // Inspect component state
    window.debugComponent = this;  // Access via window.debugComponent in console
}
```

### Cache Clearing
```bash
# Clear all Laravel caches
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan filament:optimize-clear

# Clear browser cache
Ctrl+Shift+R (Windows/Linux)
Cmd+Shift+R (Mac)

# Rebuild frontend assets
cd /var/www/projects/paradocks/app
npm run build
```

## Contributing New Fixes

When documenting a new bug fix, include:

1. **Overview**
   - Brief description of the bug
   - Severity and user impact
   - Date fixed and component affected

2. **Root Cause Analysis**
   - Detailed explanation of why the bug occurred
   - Include code examples showing the problem
   - Explain framework/library interactions

3. **The Fix**
   - Show before/after code
   - Explain why the fix works
   - Document all changes made (not just the main one)

4. **Testing Procedures**
   - Step-by-step test scenarios
   - Expected behavior for each test
   - Edge cases to verify

5. **Troubleshooting**
   - Common issues after applying the fix
   - How to verify the fix is working
   - Commands to diagnose problems

6. **Technical Deep Dive**
   - Explain underlying framework mechanisms
   - Best practices learned
   - Prevention strategies

7. **References**
   - Links to official documentation
   - Related internal documentation
   - Stack Overflow or GitHub issues (if relevant)

**Template:** Use [google-maps-picker-livewire-fix.md](google-maps-picker-livewire-fix.md) as a template for comprehensive documentation.

## Related Documentation

- [Troubleshooting Guide](../guides/troubleshooting.md) - General troubleshooting
- [Google Maps Integration](../features/google-maps/README.md) - Google Maps setup and usage
- [Filament Admin](../features/filament/README.md) - Filament best practices
- [Alpine.js Components](../guides/alpine-js.md) - Alpine.js patterns (if exists)

## Changelog

**2025-12-14**
- Created fixes directory README
- Documented Google Maps Picker Livewire fix
- Documented Alpine.js button click fix
- Added common patterns and prevention checklist
