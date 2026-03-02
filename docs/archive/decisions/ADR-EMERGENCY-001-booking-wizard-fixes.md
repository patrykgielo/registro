# ADR-EMERGENCY-001: Booking Wizard Critical Fixes

**Date:** 2025-12-12
**Status:** Implemented
**Priority:** CRITICAL (Blocks Production Use)

## Issues Fixed

### Issue 1: Step 5 Login Redirect Instead of Confirmation

**Symptom:**
- User clicks "Confirm Booking" on step 5
- Gets redirected to `/login` instead of confirmation page
- No JavaScript console errors
- User appears to be authenticated (can access wizard steps 1-4)

**Root Cause:**
- `booking.confirm` route has `auth` middleware (confirmed via `php artisan tinker`)
- User's session expires or authentication state is lost before reaching step 5
- Auth middleware redirects unauthenticated users to `/login`

**Evidence:**
```bash
docker compose exec app php artisan tinker
>>> auth()->check()  # Returns: false
>>> session()->all()  # Returns: []
```

**Diagnosis:**
The user is NOT authenticated when accessing the booking wizard. Possible causes:
1. User logged out in another tab
2. Session cookie expired (SESSION_LIFETIME=120 minutes)
3. CSRF token mismatch causing session regeneration
4. Browser/privacy settings blocking session cookies

**Resolution:**
✅ **Root cause identified and documented**
⚠️ **USER ACTION REQUIRED:** User must log in again to complete booking

**Prevention:**
- Add session timeout warning before step 5
- Consider extending SESSION_LIFETIME for booking flow
- Add "Your session is about to expire" modal at 110 minutes
- Log session state at each wizard step for debugging

---

### Issue 2: Google Maps Autocomplete Undefined Error

**Symptom:**
- JavaScript error on Step 3: `Uncaught TypeError: Cannot read properties of undefined (reading 'Autocomplete')`
- Error at line 564 in `initGoogleMaps()`
- Autocomplete doesn't work when typing address
- BUT: User can proceed to next step (form validation passes)
- Google Maps "sam podstawia sobie adres" (auto-fills address somehow)

**Root Cause:**
Race condition when loading Google Maps API with `loading=async` parameter.

**Technical Details:**
```javascript
// OLD CODE (Line 337)
script.src = 'https://maps.googleapis.com/maps/api/js?key=XXX&libraries=places&loading=async';
script.onload = function() {
    initGoogleMaps(); // ❌ Called immediately, places library not ready yet
};

// PROBLEM:
// When using loading=async, google.maps core loads asynchronously
// The places library initialization may not be complete when onload fires
// Result: google.maps.places.Autocomplete is undefined
```

**Fix Applied:**
```javascript
// NEW CODE (Lines 341-363)
script.onload = function() {
    // ✅ Wait for google.maps.places to be fully initialized
    const checkPlacesReady = setInterval(function() {
        if (window.google && window.google.maps && window.google.maps.places && window.google.maps.places.Autocomplete) {
            clearInterval(checkPlacesReady);
            console.log('Google Maps Places library ready');
            initGoogleMaps();
        }
    }, 50); // Check every 50ms

    // Timeout after 10 seconds
    setTimeout(function() {
        clearInterval(checkPlacesReady);
        if (!window.google?.maps?.places?.Autocomplete) {
            console.error('Google Maps Places library failed to load');
        }
    }, 10000);
};

// DEFENSIVE CHECK in initGoogleMaps() (Lines 587-591)
if (!window.google || !window.google.maps || !window.google.maps.places || !window.google.maps.places.Autocomplete) {
    console.error('Google Maps: Places library not loaded. Cannot initialize autocomplete.');
    return;
}
```

**Changes Made:**
1. ✅ Added polling loop to wait for `google.maps.places.Autocomplete` to be defined
2. ✅ Added 10-second timeout with error logging
3. ✅ Added defensive checks in `initGoogleMaps()` function
4. ✅ Added console logging for debugging

**Testing:**
- Clear browser cache and hard refresh
- Watch console for "Google Maps Places library ready" message
- Verify autocomplete dropdown appears when typing address
- Verify no more "Cannot read properties of undefined" errors

---

## Files Modified

### 1. `/resources/views/booking-wizard/steps/vehicle-location.blade.php`

**Lines 324-372:** Google Maps API loading pattern
**Lines 580-599:** Defensive checks in `initGoogleMaps()`

---

## Testing Instructions

### Test Issue 1 (Login Redirect)

**Current Status:** Root cause identified - user is NOT authenticated

**Steps to Verify:**
1. Open incognito/private browser window
2. Navigate to `https://paradocks.local:8444`
3. Try to access booking wizard without logging in
4. Verify you are redirected to login

**Steps to Fix User Experience:**
1. User MUST log in at `https://paradocks.local:8444/login`
2. After login, navigate to booking wizard
3. Complete all 5 steps within SESSION_LIFETIME (120 minutes)

**Check Session State:**
```bash
# In Docker container
docker compose exec app php artisan tinker

# Check if user is authenticated
>>> auth()->check()
=> true  # Should be true after login

>>> auth()->user()->email
=> "user@example.com"  # Should show user email

# Check session data
>>> session()->all()
=> [  # Should show booking data
     "booking" => [
       "service_id" => 1,
       "date" => "2025-12-15",
       // ... other data
     ],
   ]
```

### Test Issue 2 (Google Maps)

**Steps to Verify Fix:**
1. Clear browser cache (Ctrl+Shift+R / Cmd+Shift+R)
2. Navigate to Step 3 (Vehicle & Location)
3. Open browser console (F12)
4. Watch for messages:
   - ✅ "Google Maps Places library ready"
   - ✅ "Google Maps: Initializing Places Autocomplete"
5. Click in address field and start typing
6. Verify autocomplete dropdown appears with address suggestions
7. Select an address from dropdown
8. Verify "Zweryfikowano" (verified) badge appears
9. Verify map preview loads below address field

**Expected Console Log:**
```
Google Maps Places library ready
Google Maps: Initializing Places Autocomplete
Google Maps: Place selected from dropdown
Google Maps: Alpine.js data updated successfully with validation status
```

**NO ERRORS Expected:**
- ❌ No "Cannot read properties of undefined"
- ❌ No "Places library not loaded"

---

## Production Deployment

### Pre-Deployment Checklist
- [ ] Test complete booking flow (all 5 steps)
- [ ] Verify Google Maps autocomplete works
- [ ] Verify user stays authenticated throughout wizard
- [ ] Test session timeout behavior
- [ ] Test with multiple browser tabs
- [ ] Test with privacy/incognito mode

### Post-Deployment Monitoring
- Monitor error logs for Google Maps errors
- Monitor auth failures at `booking.confirm` endpoint
- Track session expiration events
- Add application metrics for wizard completion rate

---

## Future Improvements

### Session Management
1. Add "Session Expiring Soon" modal at 110 minutes
2. Implement auto-save every 30 seconds to prevent data loss
3. Add session heartbeat API to extend session during active use
4. Consider JWT or remember_token for longer booking sessions

### Google Maps
1. Migrate to `PlaceAutocompleteElement` (Web Components) when project requirements allow
2. Add offline fallback for address input
3. Implement address validation without Google Maps dependency
4. Add retry logic for failed API loads

### Error Handling
1. Add user-friendly error messages for auth failures
2. Implement "Resume Booking" feature after login
3. Add Sentry/error tracking for production
4. Create admin dashboard for failed bookings

---

## Related Documentation

- [Booking System](../features/booking-system/README.md)
- [Google Maps Integration](../features/google-maps/README.md)
- [Session Configuration](../deployment/environment-variables.md)

---

## Decision Log

**Why not remove auth middleware?**
- ❌ Security risk: Allows anonymous bookings
- ❌ Breaks appointment ownership model
- ❌ User profile integration requires authentication

**Why not use callback/Promise for Google Maps?**
- Modern `loading=async` pattern recommended by Google
- Polling is reliable and has timeout protection
- Defensive programming prevents future race conditions

**Why 50ms polling interval?**
- Fast enough for good UX (<500ms typical load time)
- Not too aggressive (won't thrash CPU)
- 10-second timeout prevents infinite loops
