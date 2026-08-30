# SMSAPI Webhook Configuration

Complete guide to setting up delivery status webhooks from SMSAPI.pl.

## Overview

Webhooks enable real-time delivery tracking by receiving HTTP POST callbacks from SMSAPI whenever an SMS status changes (sent → delivered/failed/expired).

**Benefits**:
- Real-time delivery status updates
- Failed delivery detection
- Invalid number identification
- Automatic suppression list updates

## Webhook Endpoint

**URL**: `https://your-domain.com/api/webhooks/smsapi/delivery-status`
**Method**: POST
**Authentication**: None (public endpoint)
**CSRF**: Exempt (configured in `bootstrap/app.php`)

### Local Development

```
http://localhost:8444/api/webhooks/smsapi/delivery-status
```

**Note**: SMSAPI cannot reach localhost. Use ngrok or similar for local testing:

```bash
ngrok http 8444
# Use ngrok URL: https://abc123.ngrok.io/api/webhooks/smsapi/delivery-status
```

### Staging/Production

```
https://registro.staging.example.com/api/webhooks/smsapi/delivery-status
https://registro.com/api/webhooks/smsapi/delivery-status
```

**Requirements**:
- Publicly accessible HTTPS URL
- Valid SSL certificate (no self-signed)
- Responds within 5 seconds (SMSAPI timeout)

## SMSAPI Dashboard Configuration

### 1. Login to SMSAPI Dashboard

Navigate to: https://ssl.smsapi.pl/login

### 2. Navigate to Webhooks Settings

**Path**: Account → Integrations → Webhooks → SMS Delivery Reports

### 3. Configure Webhook

**Webhook URL**: `https://your-domain.com/api/webhooks/smsapi/delivery-status`

**Events to Enable**:
- [x] SMS Sent
- [x] SMS Delivered
- [x] SMS Failed
- [x] SMS Invalid Number
- [x] SMS Expired

**HTTP Method**: POST

**Content-Type**: application/json

**Authentication**: None

### 4. Test Webhook

Use SMSAPI's "Test Webhook" button to send a test payload.

**Expected Response**: `200 OK`

```json
{
  "success": true,
  "message": "Webhook processed successfully"
}
```

### 5. Save Configuration

Click "Save" to enable webhook for all future SMS sends.

## Webhook Payload Format

SMSAPI sends POST requests with the following JSON payload:

```json
{
  "id": "12345ABC67890DEF",
  "status": "DELIVERED",
  "to": "+48501234567",
  "date_sent": "2025-11-12 10:30:00",
  "error_code": null
}
```

### Payload Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | string | Yes | SMSAPI message ID (matches `sms_sends.sms_id`) |
| `status` | string | Yes | Delivery status (see Status Codes below) |
| `to` | string | No | Recipient phone number (+48...) |
| `date_sent` | string | No | ISO 8601 timestamp |
| `error_code` | string | No | Error code if failed (e.g., "ERROR_001") |

### Status Codes

SMSAPI uses various status codes. Our webhook maps them to our event types:

| SMSAPI Status | Our Event Type | Description |
|---------------|----------------|-------------|
| `SENT`, `QUEUE` | `sent` | Message sent from SMSAPI gateway |
| `DELIVERED`, `ACCEPTED` | `delivered` | Successfully delivered to recipient |
| `FAILED`, `REJECTED`, `ERROR` | `failed` | Delivery failed (network, provider issue) |
| `INVALID`, `INVALID_NUMBER`, `INVALID_SENDER` | `invalid_number` | Invalid phone number format |
| `EXPIRED`, `NOT_DELIVERED` | `expired` | Message expired before delivery |

## Webhook Processing Flow

```
1. SMSAPI HTTP POST → /api/webhooks/smsapi/delivery-status
   ↓
2. SmsApiWebhookController::handleDeliveryStatus()
   ↓
3. Validate payload (required fields present)
   ↓
4. Find SmsSend by sms_id
   ↓ (if not found, return 200 anyway to prevent retries)
5. Map SMSAPI status to event type
   ↓
6. Create SmsEvent record:
   - sms_send_id
   - event_type
   - occurred_at (now)
   - metadata (full webhook payload)
   ↓
7. Update SmsSend status (if newer)
   ↓
8. Handle Suppression List:
   - If invalid_number → suppress immediately
   - If failed → count failures, suppress after 3rd
   ↓
9. Log success
   ↓
10. Return 200 OK
```

## Controller Implementation

### handleDeliveryStatus Method

**File**: `app/Http/Controllers/Api/SmsApiWebhookController.php`

```php
public function handleDeliveryStatus(Request $request): JsonResponse
{
    try {
        // Log incoming webhook
        Log::info('SMSAPI webhook received', ['payload' => $request->all()]);

        // Validate payload
        $validated = $request->validate([
            'id' => 'required|string',
            'status' => 'required|string',
            'to' => 'nullable|string',
            'date_sent' => 'nullable|string',
            'error_code' => 'nullable|string',
        ]);

        // Find SMS send
        $smsSend = SmsSend::where('sms_id', $validated['id'])->first();

        if (!$smsSend) {
            // Return 200 to prevent retries for unknown SMS IDs
            return response()->json(['success' => true], 200);
        }

        // Map status to event type
        $eventType = $this->mapStatusToEventType($validated['status']);

        // Create event
        SmsEvent::create([
            'sms_send_id' => $smsSend->id,
            'event_type' => $eventType,
            'occurred_at' => now(),
            'metadata' => [
                'smsapi_status' => $validated['status'],
                'error_code' => $validated['error_code'] ?? null,
                'phone' => $validated['to'] ?? null,
                'webhook_data' => $validated,
            ],
        ]);

        // Update SMS send status
        $this->updateSmsSendStatus($smsSend, $eventType);

        // Handle suppression
        $this->handleSuppressionList($smsSend, $eventType, $validated['to'] ?? null);

        return response()->json(['success' => true], 200);
    } catch (\Exception $e) {
        Log::error('Webhook processing failed', ['error' => $e->getMessage()]);
        // Return 200 anyway to prevent infinite retries
        return response()->json(['success' => false, 'error' => $e->getMessage()], 200);
    }
}
```

## Security Considerations

### CSRF Exemption

Webhook routes are excluded from CSRF protection in `bootstrap/app.php`:

```php
$middleware->validateCsrfTokens(except: [
    'api/webhooks/*',
]);
```

**Why**: SMSAPI cannot send CSRF tokens. We accept the risk for this public endpoint.

**Mitigation**:
- Validate payload structure
- Verify `sms_id` exists in database
- Log all webhook attempts
- Rate limiting (future enhancement)

### IP Whitelisting (Optional)

For additional security, you can whitelist SMSAPI's IP addresses:

**SMSAPI IP Ranges**:
- `91.210.26.0/24`
- `91.242.84.0/24`
- `185.165.168.0/24`

**Implementation** (in `app/Http/Middleware/WhitelistIps.php`):

```php
public function handle($request, Closure $next)
{
    $allowedIps = [
        '91.210.26.0/24',
        '91.242.84.0/24',
        '185.165.168.0/24',
    ];

    if (!$this->isIpAllowed($request->ip(), $allowedIps)) {
        Log::warning('Webhook IP not whitelisted', ['ip' => $request->ip()]);
        return response()->json(['error' => 'Forbidden'], 403);
    }

    return $next($request);
}
```

**Apply Middleware** (in `routes/web.php`):

```php
Route::post('/smsapi/delivery-status', [SmsApiWebhookController::class, 'handleDeliveryStatus'])
    ->middleware('whitelist-ips');
```

### Signature Verification (Future Enhancement)

SMSAPI supports webhook signatures for verifying authenticity:

1. Configure secret in SMSAPI dashboard
2. SMSAPI sends `X-SMSAPI-Signature` header with HMAC
3. Verify signature in controller:

```php
$signature = $request->header('X-SMSAPI-Signature');
$payload = $request->getContent();
$secret = config('services.smsapi.webhook_secret');

$expectedSignature = hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expectedSignature, $signature)) {
    Log::warning('Invalid webhook signature');
    return response()->json(['error' => 'Invalid signature'], 403);
}
```

## Troubleshooting

### Webhook Not Receiving Calls

**1. Check URL Accessibility**

```bash
curl -X POST https://your-domain.com/api/webhooks/smsapi/delivery-status \
  -H "Content-Type: application/json" \
  -d '{"id":"test123","status":"DELIVERED"}'
```

**Expected**: `{"success":true,"message":"Webhook processed successfully"}`

**2. Check SSL Certificate**

```bash
curl -v https://your-domain.com
```

Ensure certificate is valid (not self-signed).

**3. Check Firewall Rules**

Ensure port 443 (HTTPS) is open to SMSAPI IP ranges.

**4. Check SMSAPI Dashboard**

Verify webhook URL is saved correctly in SMSAPI dashboard.

### Webhook Returns Error

**1. Check Laravel Logs**

```bash
tail -f storage/logs/laravel.log | grep webhook
```

**2. Check Validation Errors**

If payload validation fails, webhook returns 400. Check log for:
```
SMSAPI webhook validation failed: ["id" => "The id field is required"]
```

**3. Check Database Connection**

If `SmsSend::where('sms_id', ...)` fails, check database connectivity.

### Events Not Creating

**1. Check sms_id Exists**

```sql
SELECT * FROM sms_sends WHERE sms_id = '12345ABC67890DEF';
```

If not found, webhook returns 200 but logs warning.

**2. Check Event Table**

```sql
SELECT * FROM sms_events WHERE sms_send_id = 123;
```

**3. Check Logs**

```bash
grep "SmsEvent::create" storage/logs/laravel.log
```

### Suppression List Not Updating

**1. Check Event Type**

Only `invalid_number` and `failed` (3+ times) trigger suppression.

**2. Check Suppression Table**

```sql
SELECT * FROM sms_suppressions WHERE phone = '+48501234567';
```

**3. Check Logs**

```bash
grep "suppression" storage/logs/laravel.log
```

## Testing

### Manual Webhook Test

Send test POST request using cURL:

```bash
curl -X POST https://your-domain.com/api/webhooks/smsapi/delivery-status \
  -H "Content-Type: application/json" \
  -d '{
    "id": "TEST_SMS_ID_123",
    "status": "DELIVERED",
    "to": "+48501234567",
    "date_sent": "2025-11-12 10:30:00"
  }'
```

**Expected Response**:
```json
{
  "success": true,
  "message": "Webhook processed successfully"
}
```

### Check Database

```sql
-- Check if event created
SELECT * FROM sms_events
WHERE sms_send_id = (SELECT id FROM sms_sends WHERE sms_id = 'TEST_SMS_ID_123')
ORDER BY created_at DESC
LIMIT 1;
```

### Integration Test

```php
/** @test */
public function it_processes_smsapi_webhook()
{
    // Create a test SMS send
    $smsSend = SmsSend::factory()->create([
        'sms_id' => 'TEST123',
        'status' => 'pending',
    ]);

    // Send webhook payload
    $response = $this->postJson('/api/webhooks/smsapi/delivery-status', [
        'id' => 'TEST123',
        'status' => 'DELIVERED',
        'to' => '+48501234567',
    ]);

    $response->assertOk();

    // Assert event created
    $this->assertDatabaseHas('sms_events', [
        'sms_send_id' => $smsSend->id,
        'event_type' => 'delivered',
    ]);

    // Assert status updated
    $this->assertEquals('delivered', $smsSend->fresh()->status);
}
```

## Monitoring

### Webhook Success Rate

```sql
SELECT
  DATE(created_at) as date,
  COUNT(*) as total_webhooks,
  SUM(CASE WHEN event_type = 'delivered' THEN 1 ELSE 0 END) as delivered,
  SUM(CASE WHEN event_type = 'failed' THEN 1 ELSE 0 END) as failed
FROM sms_events
GROUP BY DATE(created_at)
ORDER BY date DESC
LIMIT 30;
```

### Failed Deliveries

```sql
SELECT
  se.event_type,
  se.metadata->>'$.error_code' as error_code,
  COUNT(*) as count
FROM sms_events se
WHERE se.event_type IN ('failed', 'invalid_number', 'expired')
GROUP BY se.event_type, error_code
ORDER BY count DESC;
```

### Suppression List Growth

```sql
SELECT
  DATE(suppressed_at) as date,
  reason,
  COUNT(*) as count
FROM sms_suppressions
GROUP BY DATE(suppressed_at), reason
ORDER BY date DESC;
```

## Best Practices

1. **Always Return 200**: Even on errors, return 200 to prevent SMSAPI retries
2. **Log Everything**: Log all webhook receives, successes, and failures
3. **Validate Payload**: Always validate required fields
4. **Handle Duplicates**: Webhooks may be sent multiple times for same event
5. **Monitor Regularly**: Set up alerts for high failure rates
6. **Test Thoroughly**: Test with SMSAPI's test button before going live
7. **Use HTTPS**: Always use HTTPS with valid SSL certificate

---

**See Also**:
- [README.md](README.md) - Quick start guide
- [architecture.md](architecture.md) - System architecture
- [troubleshooting.md](troubleshooting.md) - Common issues

---

## Zależność, na której opiera się bezpieczeństwo tych tras (2026-08-30)

Od Layer 7 (`VULN-003`) trasy `api/webhooks/smsapi/*` przechodzą przez `ResolveTenant`,
czego wcześniej nie robiły — bo `ResolveTenant` jest teraz w bazowej grupie `web`.

**Dziś jest to nieszkodliwe i opiera się na dwóch założeniach:**

1. **URL webhooka jest zarejestrowany na domenie głównej** (`registrolabs.com`), a nie na
   subdomenie tenanta. `ResolveTenant` na domenie głównej przepuszcza żądanie natychmiast,
   bez rozwiązywania tenanta i bez żadnej z gałęzi redirect / 503 (zawieszony) /
   410 (zamknięty).
2. **`SMSAPI_TOKEN` jest jedną globalną zmienną** (`config/services.php:43`), a nie
   poświadczeniem per organizacja — więc webhook nie potrzebuje kontekstu tenanta.

**Jeśli którekolwiek przestanie być prawdą, callbacki o statusie doręczenia SMS zaczną
po cichu padać.** Zmiana URL-a na subdomenę tenanta wprowadzi te żądania w gałęzie
zawieszenia/zamknięcia konta dokładnie tak, jak dzieje się to dziś dla Przelewy24.

Żadne z tych założeń nie jest weryfikowalne z repozytorium — URL webhooka mieszka w panelu
SMSAPI. **Sprawdź go tam przed wdrożeniem** i przy każdej zmianie modelu wdrożenia.
