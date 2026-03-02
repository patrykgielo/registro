# Booking Wizard Troubleshooting Guide

**Last Updated:** 2026-01-22

## Quick Diagnostics

### Issue 1: Redirected to Login on "Confirm Booking"

**What you're experiencing:**
- You complete all 5 wizard steps
- Click "Confirm Booking" on step 5
- Instead of confirmation page, you see login page

**Root Cause:**
You are not logged in or your session expired.

**Solution:**
```bash
1. Go to https://paradocks.local:8444/login
2. Log in with your credentials
3. Start booking wizard again
4. Complete all steps within 120 minutes (session timeout)
```

**Check if you're logged in:**
```bash
# Open browser console (F12)
# Navigate to Application > Cookies
# Look for "laravel_session" cookie
# If missing or expired, you need to log in again
```

**Verify authentication in backend:**
```bash
docker compose exec app php artisan tinker
>>> auth()->check()
=> true  # You ARE logged in
=> false # You are NOT logged in (must log in)

>>> auth()->user()->email
=> "your-email@example.com"  # Shows your email if logged in
=> null                       # Not logged in
```

---

### Issue 2: Google Maps Autocomplete Not Working

**What you're experiencing:**
- Step 3 (Vehicle & Location)
- Type in address field
- No autocomplete dropdown appears
- Console error: "Cannot read properties of undefined (reading 'Autocomplete')"

**Root Cause:**
Google Maps Places library not fully loaded before initialization.

**Solution (Fixed in latest code):**
The code now waits for Places library to be ready before initializing autocomplete.

**Verify the fix:**
```bash
1. Hard refresh page (Ctrl+Shift+R or Cmd+Shift+R)
2. Open browser console (F12)
3. Navigate to Step 3
4. Watch for console messages:
   - "Google Maps Places library ready" ✅
   - "Google Maps: Initializing Places Autocomplete" ✅
5. Start typing in address field
6. Autocomplete dropdown should appear
```

**If still not working:**
```bash
# Check if Google Maps API key is configured
docker compose exec app php artisan tinker
>>> config('services.google_maps.api_key')
=> "AIzaSy..."  # Should show API key
=> null         # NOT configured (check .env)

# Check browser console for errors
# F12 > Console tab
# Look for:
# - "Failed to load Google Maps API script"
# - "Google Maps Places library failed to load"
# - Network errors (red in Network tab)
```

---

## Common Issues & Solutions

### Session Expired During Booking

**Symptom:**
- Start booking wizard
- Take longer than 120 minutes to complete
- Get redirected to login on step 5

**Solution:**
```bash
# Option 1: Complete wizard faster (within 2 hours)

# Option 2: Extend session lifetime (development only)
# Edit .env file:
SESSION_LIFETIME=240  # 4 hours instead of 2

# Restart containers
docker compose restart app

# Option 3: Re-login and start over
# Your progress is NOT saved across logins
```

---

### Google Maps Shows "For development purposes only" Watermark

**Symptom:**
- Map preview appears on Step 3
- Has gray watermark "For development purposes only"
- Map tiles are grayed out

**Cause:**
Google Maps API key restrictions or billing not enabled.

**Solution:**
```bash
1. Go to Google Cloud Console
2. Enable billing for your project
3. Enable Maps JavaScript API
4. Enable Places API
5. Add API key to .env file
6. Restart: docker compose restart app
```

---

### Address Input Shows "Zweryfikowano" but No Map Preview

**Symptom:**
- Select address from autocomplete
- Green "Zweryfikowano" badge appears
- No map preview shows below address field

**Cause:**
- `location_latitude` or `location_longitude` not set
- Map initialization function not called

**Debug:**
```javascript
// Open browser console (F12)
// Inspect Alpine.js data
document.querySelector('form').locationLat
=> 52.2297  // Should show latitude
=> ""       // EMPTY - address not geocoded

document.querySelector('form').locationLng
=> 21.0122  // Should show longitude
=> ""       // EMPTY - address not geocoded

// Check hidden fields
document.getElementById('location-latitude').value
=> "52.2297"  // Should be populated
=> ""         // EMPTY - form won't validate
```

**Solution:**
- Select address from autocomplete dropdown (don't just type)
- If you typed manually, click out of field to trigger blur event
- The code will geocode address automatically

---

### Booking Wizard Stuck on Same Step

**Symptom:**
- Fill out form on step N
- Click "Continue"
- Page reloads but stays on same step
- No error message

**Cause:**
- Form validation failed
- AJAX request error
- Session save failed

**Debug:**
```javascript
// Open browser console (F12)
// Click "Continue" and watch for:

// Network tab:
// - POST /booking/save-progress (should return 200 OK)
// - If 422: Validation error (check response body)
// - If 500: Server error (check Laravel logs)

// Console tab:
// - Look for "Failed to save progress"
// - Look for validation error alerts
```

**Solution:**
```bash
# Check Laravel logs
docker compose logs -f app | grep -E "(error|fail)"

# Clear caches
docker compose exec app php artisan optimize:clear

# Restart containers
docker compose restart app nginx
```

---

## Diagnostic Commands

### Check Current User Session
```bash
docker compose exec app php artisan tinker

# Is user logged in?
>>> auth()->check()
=> true/false

# Who is logged in?
>>> auth()->user()
=> User {#1234 ...}

# What's in booking session?
>>> session('booking')
=> [
     "service_id" => 1,
     "date" => "2025-12-15",
     "time_slot" => "10:00",
     ...
   ]

# Session ID
>>> session()->getId()
=> "abc123..."
```

---

### Check Google Maps Configuration
```bash
docker compose exec app php artisan tinker

# API Key configured?
>>> config('services.google_maps.api_key')
=> "AIzaSy..."

# Map ID configured?
>>> config('services.google_maps.map_id')
=> "your_map_id"
```

---

### Clear All Caches
```bash
# Laravel application caches
docker compose exec app php artisan optimize:clear

# Browser caches
# Chrome: Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac)
# Firefox: Ctrl+F5 (Windows) or Cmd+Shift+R (Mac)

# Restart containers (nuclear option)
docker compose restart app nginx redis
```

---

## Testing Checklist

### Before Reporting Bug

- [ ] Am I logged in? (Check `auth()->check()`)
- [ ] Is my session valid? (Check `session()->all()`)
- [ ] Is Google Maps API key configured?
- [ ] Did I hard-refresh browser? (Ctrl+Shift+R)
- [ ] Are there errors in browser console? (F12)
- [ ] Are there errors in Laravel logs? (`docker compose logs app`)
- [ ] Did I try in incognito/private mode?
- [ ] Did I clear Laravel caches? (`optimize:clear`)

---

## Getting Help

### Include in Bug Report

1. **Browser console output** (F12 > Console tab, screenshot)
2. **Network tab** (F12 > Network tab, show failed requests)
3. **Current step number** (1-5)
4. **Authentication status** (`auth()->check()` output)
5. **Session data** (`session('booking')` output)
6. **Laravel logs** (last 50 lines from `storage/logs/laravel.log`)

### Example Bug Report
```
ISSUE: Redirected to login on step 5

STEPS TO REPRODUCE:
1. Logged in as user@example.com
2. Completed steps 1-4 successfully
3. Clicked "Confirm Booking" on step 5
4. Redirected to /login

DIAGNOSTICS:
- auth()->check() => false (NOT LOGGED IN!)
- session()->all() => []
- Browser: Chrome 120
- OS: Windows 11
- Time elapsed: ~45 minutes

LOGS:
[See attached Laravel log excerpt]
```

---

### Step 2 Back Button Creates Infinite Loop

**Symptom:**
- On Step 2 (Date & Time), clicking "Back" button
- Briefly shows Step 1, immediately redirects back to Step 2
- Cannot change selected service

**Cause:**
Step 1 auto-redirects to Step 2 if a service is already in session (BookingController line 131-134).

**Solution:**
Use the "Zmień usługę" (Change Service) button instead.
This button explicitly clears the service selection from session before navigating.

**Fixed in:** v4.15.0

---

### Can Submit Step 3 Without Vehicle Details

**Symptom:**
- On Step 3 (Vehicle & Location)
- Fill in address but don't select vehicle type / enter brand / enter model
- Click "Continue"
- Form submits but returns 422 validation error

**Cause:**
Frontend `canSubmit()` validation was missing checks for:
- vehicle_type_id (required)
- vehicle_brand (required)
- vehicle_model (required)

**Solution:**
Frontend now validates all required vehicle fields before allowing submission:
1. Vehicle type must be selected
2. Brand must be filled
3. Model must be filled
Red highlight appears on empty required fields.

**Fixed in:** v4.15.0

---

## Known Limitations

1. **Session expires after 120 minutes**
   - Complete booking within 2 hours
   - No auto-save between sessions
   - Progress lost if logged out

2. **Google Maps requires internet connection**
   - No offline mode
   - API calls may be rate-limited
   - Billing must be enabled in Google Cloud

3. **CSRF token expires with session**
   - If session expires, CSRF token invalid
   - Must refresh page to get new token

---

## Related Documentation

- [ADR-EMERGENCY-001: Booking Wizard Critical Fixes](decisions/ADR-EMERGENCY-001-booking-wizard-fixes.md)
- [Booking System](features/booking-system/README.md)
- [Google Maps Integration](features/google-maps/README.md)
