# SMSAPI.pl Integration

## Overview

Registro uses **SMSAPI.pl** as the SMS gateway provider. SMSAPI.pl is a Polish SMS gateway with:
- Competitive pricing (~0.10 PLN per SMS)
- Reliable delivery in Poland and internationally
- RESTful HTTP API
- Webhook callbacks for delivery status
- Test mode (sandbox) for development

**Website:** https://www.smsapi.pl
**API Docs:** https://www.smsapi.pl/docs
**Dashboard:** https://ssl.smsapi.pl/

---

## Getting Started

### 1. Create SMSAPI.pl Account

1. Go to https://www.smsapi.pl
2. Click "Załóż konto" (Create Account)
3. Fill in registration form
4. Verify email address
5. Add credits to account (minimum 10 PLN)

### 2. Get API Token

1. Log in to SMSAPI.pl dashboard
2. Go to **"Ustawienia"** (Settings) → **"API"**
3. Click **"Wygeneruj token"** (Generate Token)
4. Copy the token (starts with `Bearer ...`)
5. Save token in Registro:
   - Go to **System Settings** → **SMS Tab**
   - Paste token in **"API Token"** field
   - Click **"Save Settings"**

### 3. Configure Sender Name

**Sender Name** appears as the SMS sender (max 11 characters, alphanumeric only).

**Options:**
- **Alphanumeric:** "Registro", "YourBrand" (cannot reply)
- **Phone Number:** "+48123456789" (can reply, requires registration)

**Configuration:**
1. Go to **System Settings** → **SMS Tab**
2. Set **"Sender Name"** to "Registro" (or your brand)
3. Click **"Save Settings"**

---

## API Configuration

### Environment Variables

```bash
# .env
SMSAPI_TOKEN=Bearer_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
SMSAPI_SERVICE=pl  # or 'com' for international
SMSAPI_SENDER_NAME=Registro
SMSAPI_TEST_MODE=false  # Set to true for sandbox
```

### System Settings (Filament)

Go to **System Settings** → **SMS Tab**:

**SMSAPI Configuration:**
- **SMS Enabled:** Toggle to enable/disable SMS system
- **API Token:** Your SMSAPI.pl Bearer token (secure, revealable)
- **Service:** `pl` (Poland) or `com` (International)
- **Sender Name:** Max 11 characters, alphanumeric (e.g., "Registro")
- **Test Mode:** Enable sandbox mode (no real SMS sent, for development)

**SMS Notification Settings:**
- **Booking Confirmation SMS:** Send SMS when appointment created
- **Admin Confirmation SMS:** Send SMS when admin confirms appointment
- **24-Hour Reminder SMS:** Send reminder 24h before appointment
- **2-Hour Reminder SMS:** Send reminder 2h before appointment
- **Follow-up SMS:** Send follow-up after service completed

---

## Sending SMS

### API Endpoint

**URL:** `https://api.smsapi.pl/sms.do`

**Method:** `POST`

**Authentication:** Bearer Token (in `Authorization` header)

### Request Format

```http
POST https://api.smsapi.pl/sms.do
Authorization: Bearer xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Content-Type: application/json

{
  "to": "+48501234567",        // Recipient phone (E.164 format)
  "message": "Your SMS text",   // Message content (max 160 chars)
  "from": "Registro",          // Sender name (max 11 chars)
  "test": false,                // true = sandbox mode
  "format": "json"              // Response format
}
```

### Response (Success)

```json
{
  "count": 1,
  "list": [
    {
      "id": "sms-123456",                // Message ID (for status checks)
      "points": 0.1,                     // Cost in PLN
      "number": "+48501234567",          // Recipient
      "date_sent": 1635789012,           // Unix timestamp
      "submitted_number": "48501234567", // Normalized number
      "status": "SENT",                  // Status: SENT, QUEUE, DELIVERED, etc.
      "error": null                      // Error message (if failed)
    }
  ]
}
```

### Response (Error)

```json
{
  "error": "Invalid phone number",
  "code": 13
}
```

**Common Error Codes:**
- `13` - Invalid phone number format
- `101` - Invalid authorization (check token)
- `102` - Invalid parameters
- `201` - Insufficient credits

---

## PHP Implementation

### SmsApiGateway Service

**Location:** `app/Services/Sms/SmsApiGateway.php`

**Methods:**

#### `send()`
```php
use App\Services\Sms\SmsApiGateway;

$gateway = app(SmsApiGateway::class);

$response = $gateway->send(
    phoneNumber: '+48501234567',
    messageBody: 'Witaj Jan! Wizyta jutro o 14:00.',
    from: 'Registro' // optional, uses config if null
);

// Response:
[
    'id' => 'sms-123456',
    'status' => 'SENT',
    'points' => 0.1,
    'error' => null
]
```

#### `checkCredits()`
```php
$balance = $gateway->checkCredits();

echo "Account balance: {$balance} PLN";
```

#### `getMessageStatus()`
```php
$status = $gateway->getMessageStatus('sms-123456');

// Status codes:
// 401 - DELIVERED
// 402 - NOT_DELIVERED
// 403 - EXPIRED
// 404 - SENT
// 405 - ACCEPTED
// 406 - UNKNOWN
```

### SmsService (Higher Level)

**Location:** `app/Services/Sms/SmsService.php`

```php
use App\Services\Sms\SmsService;

$smsService = app(SmsService::class);

// Send from template
$smsSend = $smsService->sendFromTemplate(
    templateKey: 'appointment-reminder-24h',
    language: 'pl',
    phoneNumber: '+48501234567',
    data: [
        'service_name' => 'Detailing zewnętrzny',
        'appointment_date' => '2025-11-15',
        'appointment_time' => '14:00',
        'location_address' => 'ul. Marszałkowska 1, Warszawa',
        'app_name' => 'Registro',
    ]
);

// Check status
if ($smsSend->status === 'sent') {
    echo "SMS sent! Message ID: {$smsSend->smsapi_message_id}";
}
```

---

## Webhook Configuration

### Why Webhooks?

Webhooks allow SMSAPI.pl to notify your application when:
- SMS is **delivered** to recipient's phone
- SMS is **undelivered** (e.g., invalid number, phone off)
- SMS **expires** (not delivered within 48 hours)

Without webhooks, you'd have to **poll** SMSAPI.pl API to check delivery status (inefficient).

### Webhook URL

**Route:** `POST /api/webhooks/smsapi`

**Full URL:** `https://your-domain.com/api/webhooks/smsapi`

**Example:** `https://registro.local:8444/api/webhooks/smsapi`

### Configure in SMSAPI.pl Dashboard

1. Log in to https://ssl.smsapi.pl/
2. Go to **"Ustawienia"** (Settings) → **"Webhook"**
3. Click **"Dodaj webhook"** (Add Webhook)
4. Fill in form:
   - **URL:** `https://your-domain.com/api/webhooks/smsapi`
   - **Events:** Select `DELIVERED` and `UNDELIVERED`
   - **Format:** JSON
   - **Active:** Yes
5. Click **"Zapisz"** (Save)
6. Test webhook by clicking **"Test"** button

### Webhook Payload

**DELIVERED Event:**
```json
{
  "id": "sms-123456",
  "status": "DELIVERED",
  "error": null,
  "date_sent": 1635789012,
  "date_delivered": 1635789120,
  "number": "+48501234567"
}
```

**UNDELIVERED Event:**
```json
{
  "id": "sms-123456",
  "status": "UNDELIVERED",
  "error": "Phone number not reachable",
  "date_sent": 1635789012,
  "number": "+48501234567"
}
```

### Webhook Handler

**Location:** `app/Http/Controllers/Api/SmsApiWebhookController.php`

**Flow:**
1. Webhook receives POST request from SMSAPI.pl
2. Extract `id` (message ID) from payload
3. Find `SmsSend` record by `smsapi_message_id`
4. Update `status` to `delivered` or `failed`
5. Set `delivered_at` or `failed_at` timestamp
6. Create `SmsEvent` for audit trail
7. Return `200 OK` to SMSAPI.pl

**Code:**
```php
public function handle(Request $request): JsonResponse
{
    $payload = $request->all();

    // Find SMS send by SMSAPI message ID
    $smsSend = SmsSend::where('smsapi_message_id', $payload['id'])->first();

    if (!$smsSend) {
        return response()->json(['error' => 'SMS not found'], 404);
    }

    // Update status based on webhook event
    if ($payload['status'] === 'DELIVERED') {
        $smsSend->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);
        $eventType = 'delivered';
    } else {
        $smsSend->update([
            'status' => 'failed',
            'failed_at' => now(),
        ]);
        $eventType = 'undelivered';
    }

    // Create audit event
    SmsEvent::create([
        'sms_send_id' => $smsSend->id,
        'event_type' => $eventType,
        'smsapi_status' => $payload['status'],
        'error_message' => $payload['error'] ?? null,
        'raw_response' => json_encode($payload),
    ]);

    return response()->json(['success' => true]);
}
```

---

## Test Mode (Sandbox)

### Enable Test Mode

**System Settings → SMS Tab → Test Mode:** ON

**OR** in `.env`:
```bash
SMSAPI_TEST_MODE=true
```

### What Happens in Test Mode?

- SMS are **not sent** to real phones
- SMSAPI.pl simulates sending (returns fake message ID)
- Useful for **development** and **testing** without costs
- **No credits** deducted from account

### Test Mode Response

```json
{
  "count": 1,
  "list": [
    {
      "id": "test-123456",    // Fake message ID
      "points": 0.0,          // No cost
      "number": "+48501234567",
      "status": "SENT",
      "test": true            // Indicates test mode
    }
  ]
}
```

### Disable Test Mode for Production

⚠️ **Important:** Disable test mode before going live!

```bash
# .env
SMSAPI_TEST_MODE=false
```

---

## Pricing & Costs

### SMSAPI.pl Pricing (2025)

**Poland (pl service):**
- **1 SMS part (160 chars):** ~0.10 PLN
- **2 SMS parts (161-306 chars):** ~0.20 PLN (2×)
- **3 SMS parts (307-459 chars):** ~0.30 PLN (3×)

**International (com service):**
- Varies by country (check SMSAPI.pl dashboard)
- Typically 0.20-0.50 PLN per SMS

**Bulk discounts:**
- 10,000 SMS: ~5% discount
- 50,000 SMS: ~10% discount
- 100,000+ SMS: Custom pricing

### Cost Tracking

**SmsSend Model:**
- `cost` field stores SMS cost in PLN
- `message_parts` field stores number of SMS parts (1-3)

**Example:**
```php
$smsSend = SmsSend::find(123);

echo "Cost: {$smsSend->cost} PLN";
echo "Parts: {$smsSend->message_parts}";
```

**Filament Reports (future enhancement):**
- Total SMS sent this month
- Total cost this month
- Cost breakdown by template type
- Average cost per SMS

---

## Troubleshooting

### "Invalid authorization" Error

**Cause:** API token is incorrect or expired

**Solution:**
1. Check token in **System Settings → SMS Tab**
2. Regenerate token in SMSAPI.pl dashboard
3. Update token in Registro
4. Click **"Test SMS Connection"** to verify

### "Insufficient credits" Error

**Cause:** SMSAPI.pl account balance is 0

**Solution:**
1. Log in to SMSAPI.pl dashboard
2. Go to **"Doładowanie"** (Top-up)
3. Add credits (minimum 10 PLN)
4. Wait 2-5 minutes for balance to update
5. Retry sending SMS

### SMS Sent But Not Delivered

**Check:**
1. **Phone number format:** Must be E.164 (`+48501234567`)
2. **Phone is on:** Recipient's phone must be powered on
3. **Network coverage:** Recipient must have cellular signal
4. **Number is valid:** Check if number exists (e.g., not canceled)
5. **Webhook configured:** Check webhook events in Filament (SMS Events)

**SMSAPI.pl Status Codes:**
- `401` - DELIVERED (success)
- `402` - NOT_DELIVERED (failed, permanent)
- `403` - EXPIRED (not delivered within 48h)
- `404` - SENT (in transit, waiting for delivery)
- `405` - ACCEPTED (queued for sending)
- `406` - UNKNOWN (status unknown)

### Webhook Not Receiving Events

**Check:**
1. **Webhook URL:** Must be publicly accessible (not localhost)
2. **HTTPS required:** Use HTTPS, not HTTP
3. **Firewall:** Allow SMSAPI.pl IP ranges
4. **Route registered:** Check `routes/api.php`
5. **Test webhook:** Use SMSAPI.pl dashboard "Test" button

**SMSAPI.pl IP Ranges (whitelist):**
- `195.201.229.0/24`
- `195.201.230.0/24`
- `195.201.231.0/24`

**Nginx Configuration:**
```nginx
# Allow SMSAPI.pl IPs
location /api/webhooks/smsapi {
    allow 195.201.229.0/24;
    allow 195.201.230.0/24;
    allow 195.201.231.0/24;
    deny all;

    try_files $uri $uri/ /index.php?$query_string;
}
```

### Test Mode Not Working

**Symptom:** Test SMS sent to real phone (costs credits)

**Cause:** Test mode not enabled properly

**Solution:**
1. Check **System Settings → SMS Tab → Test Mode:** Should be ON
2. Check `.env`: `SMSAPI_TEST_MODE=true`
3. Clear config cache: `php artisan config:clear`
4. Restart queue workers: `php artisan horizon:terminate`

---

## Security Best Practices

### API Token Security

1. **Store securely:** Token encrypted in database (`settings` table)
2. **Never commit:** Add `.env` to `.gitignore`
3. **Rotate regularly:** Regenerate token every 3-6 months
4. **Limit access:** Only Super Admins can view token in Filament
5. **Monitor usage:** Check SMSAPI.pl dashboard for unusual activity

### Webhook Security

**Problem:** SMSAPI.pl doesn't support webhook signatures (no HMAC verification)

**Mitigations:**
1. **IP Whitelist:** Limit webhook route to SMSAPI.pl IPs (see above)
2. **HTTPS Only:** Use HTTPS to prevent MITM attacks
3. **Idempotency:** Webhook handler is idempotent (safe to call multiple times)
4. **Rate Limiting:** Add rate limiter to webhook route (optional)

### Phone Number Privacy

1. **GDPR Compliance:** Store phone numbers securely
2. **Access Control:** Only admins with `view sms sends` permission can see numbers
3. **Suppression List:** Users can opt-out (add to suppression list)
4. **Data Retention:** Delete old SMS sends after 90 days (optional)

---

## Monitoring & Debugging

### SMSAPI.pl Dashboard

**URL:** https://ssl.smsapi.pl/

**Features:**
- **Balance:** Current account credits
- **SMS History:** All sent SMS with status
- **Statistics:** Delivery rates, costs, trends
- **Webhook Logs:** See all webhook callbacks
- **API Logs:** See all API requests (debug errors)

### Filament Resources

**SMS Sends:**
- Go to **SMS Sends** resource
- Filter by status: `sent`, `delivered`, `failed`
- Search by phone number or message content
- View details: Message, metadata, events

**SMS Events:**
- Go to **SMS Events** resource
- See audit trail of all SMS lifecycle events
- Check `error_message` for failures
- Expand `raw_response` to see full SMSAPI.pl response

### Laravel Logs

**Location:** `storage/logs/laravel.log`

```
[2025-11-12 10:30:45] local.INFO: SMS sent {"sms_send_id": 123, "phone": "+48501234567", "template": "appointment-reminder-24h"}
[2025-11-12 10:30:46] local.ERROR: SMS failed {"error": "Invalid phone number", "phone": "+48999999999"}
```

---

## API Reference

### Send SMS

**Endpoint:** `POST https://api.smsapi.pl/sms.do`

**Headers:**
```
Authorization: Bearer xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Content-Type: application/json
```

**Body:**
```json
{
  "to": "+48501234567",
  "message": "Your SMS text",
  "from": "Registro",
  "test": false
}
```

**Response:**
```json
{
  "count": 1,
  "list": [
    {
      "id": "sms-123456",
      "points": 0.1,
      "number": "+48501234567",
      "status": "SENT",
      "error": null
    }
  ]
}
```

### Check Credits

**Endpoint:** `GET https://api.smsapi.pl/user.do`

**Headers:**
```
Authorization: Bearer xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

**Response:**
```json
{
  "points": 123.45,
  "pro_points": 0.0
}
```

### Get Message Status

**Endpoint:** `GET https://api.smsapi.pl/sms.do?id={message_id}`

**Headers:**
```
Authorization: Bearer xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

**Response:**
```json
{
  "status": "401",  // 401=DELIVERED, 402=NOT_DELIVERED, etc.
  "date_delivered": 1635789120
}
```

---

## Related Documentation

- **[Architecture](./architecture.md)** - Services, Models, Flow
- **[Templates](./templates.md)** - Template management, variables
- **[README](./README.md)** - Overview and quick start

---

**Last Updated:** November 2025
