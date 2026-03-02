---
paths:
  - "routes/api.php"
  - "app/Http/Controllers/Api/**"
---

# API Endpoint Rules

## Security Requirements

### Authentication
- All API endpoints MUST use authentication middleware
- Exception: Public endpoints (health check, status)

```php
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::apiResource('appointments', AppointmentController::class);
});
```

### Rate Limiting

**Default limits:**
- Default: `throttle:60,1` (60 requests/minute)
- Sensitive: `throttle:10,1` (10 requests/minute)
- Auth: `throttle:5,1` (5 requests/minute)

**Environment-aware rate limiting (RECOMMENDED):**

Problem: Strict limits (10/min) block development testing.
Solution: Use environment-aware throttling.

```php
// routes/api.php
$isProduction = app()->environment('production');

Route::middleware($isProduction ? 'throttle:10,1' : 'throttle:100,1')->group(function () {
    Route::post('/service-area/validate', ...);
});
```

| Environment | Limit | Purpose |
|-------------|-------|---------|
| Local | 100/min | Development testing |
| Staging | 100/min | QA testing |
| Production | 10/min | Security protection |

**Incident 2026-02-05:** 429 errors during booking flow testing due to strict 10/min limit on `/api/service-area/validate` and `/booking/confirm`. Fixed by environment-aware limits.

### Authorization
- Use Form Requests for validation
- Use Policies for authorization
- Never trust client input blindly

## Response Format

### Success Response
```php
return response()->json([
    'data' => $resource,
    'message' => 'Resource created successfully',
], 201);
```

### Error Response
```php
return response()->json([
    'error' => 'Validation failed',
    'errors' => $validator->errors(),
], 422);
```

## CORS Configuration

Check `config/cors.php`:
- Never use `'*'` for `allowed_origins` in production
- Specify exact domains

## Documentation

- Document all endpoints in `docs/api/`
- Include request/response examples
- Specify authentication requirements
