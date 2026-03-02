# SMS System

Complete SMS notification system integrated with SMSAPI.pl for sending appointment-related SMS messages to customers.

## Overview

The SMS System provides automated SMS notifications throughout the appointment lifecycle:
- **Booking Confirmation**: Instant SMS when customer creates appointment
- **Admin Confirmation**: SMS when admin confirms pending appointment
- **24-Hour Reminder**: Automated reminder 24 hours before appointment
- **2-Hour Reminder**: Final reminder 2 hours before appointment
- **Follow-Up**: Post-appointment feedback request

**Provider**: SMSAPI.pl (https://www.smsapi.pl/)
**SDK**: `smsapi/php-client` v3.x (PSR-17/18 compliant)
**Authentication**: OAuth 2.0 Bearer Token

## Quick Start

### 1. Install Dependencies

```bash
cd app && composer require smsapi/php-client
```

### 2. Run Migrations

```bash
docker compose exec app php artisan migrate
```

This creates 5 tables:
- `sms_sends` - Sent SMS log
- `sms_templates` - Message templates (PL/EN)
- `sms_events` - Delivery tracking (webhook events)
- `sms_suppressions` - Opt-out/invalid number list
- `appointments` - Adds SMS reminder tracking fields

### 3. Seed Templates and Settings

```bash
docker compose exec app php artisan db:seed --class=SmsTemplateSeeder
docker compose exec app php artisan db:seed --class=SettingSeeder
```

### 4. Configure SMSAPI

1. Create SMSAPI.pl account and generate OAuth 2.0 Bearer Token
2. Navigate to Admin Panel → Settings → System Settings → SMS tab
3. Configure:
   - **SMS Enabled**: Yes
   - **API Token**: Your SMSAPI OAuth token
   - **Service**: `pl` (Poland) or `com` (International)
   - **Sender Name**: Max 11 alphanumeric characters
   - **Test Mode**: Enable for testing (no real SMS delivery)

4. Enable desired notifications:
   - Booking Confirmation
   - Admin Confirmation
   - 24-Hour Reminder
   - 2-Hour Reminder
   - Follow-up SMS

### 5. Configure Webhook (Optional)

For delivery tracking, configure SMSAPI webhook:

**Webhook URL**: `https://your-domain.com/api/webhooks/smsapi/delivery-status`
**Method**: POST
**Events**: All delivery events (sent, delivered, failed, invalid, expired)

See [webhook.md](webhook.md) for detailed configuration.

### 6. Schedule Jobs

Add to `app/Console/Kernel.php` schedule (if not already present):

```php
protected function schedule(Schedule $schedule): void
{
    // SMS reminder jobs (hourly)
    $schedule->job(new SendReminderSmsJob)->hourly();
    $schedule->job(new SendFollowUpSmsJob)->hourly();

    // Cleanup old SMS logs (daily at 2:30 AM)
    $schedule->job(new CleanupOldSmsLogsJob)->dailyAt('02:30');
}
```

Ensure Laravel scheduler is running:
```bash
# Crontab entry
* * * * * cd /var/www/projects/registro/app && php artisan schedule:run >> /dev/null 2>&1
```

Or use Docker container (already configured).

## Features

### Automated Notifications

- **Event-Driven**: SMS sent automatically on appointment lifecycle events
- **Configurable**: Enable/disable each notification type via admin panel
- **Bilingual**: Support for Polish (pl) and English (en) templates
- **Idempotent**: Duplicate prevention via message key hashing

### Template Management

- **Admin Editable**: All templates editable via Filament admin panel
- **Variable Interpolation**: Blade-style `{{variable}}` syntax
- **Character Counting**: Real-time character count with GSM-7/Unicode detection
- **Multi-Part**: Automatic handling of long messages (>160 chars)
- **Test Send**: Send test SMS directly from template edit screen

### Delivery Tracking

- **Webhook Integration**: Real-time delivery status from SMSAPI
- **Event Log**: Complete history of all SMS events (sent, delivered, failed)
- **Status Updates**: Automatic status updates based on delivery reports
- **Suppression List**: Auto-suppress invalid numbers and repeated failures

### Queue Integration

- **Asynchronous**: All SMS sent via Laravel Queue (Horizon)
- **Retry Logic**: Configurable retry attempts with exponential backoff
- **Failure Handling**: Failed jobs logged and tracked in Horizon
- **Scheduled Jobs**: Hourly reminder checks, daily cleanup

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    APPOINTMENT EVENTS                        │
│  (Created, Confirmed, Reminder24h, Reminder2h, FollowUp)    │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ↓
         ┌─────────────────────────────┐
         │   AppServiceProvider         │
         │  (Event Listeners)           │
         └─────────────┬────────────────┘
                       │
                       ↓
              ┌────────────────┐
              │   SmsService    │
              │  (sendFromTemplate)
              └────────┬────────┘
                       │
        ┌──────────────┴──────────────┐
        │                             │
        ↓                             ↓
┌──────────────┐             ┌───────────────┐
│ SmsTemplate  │             │ SmsSuppression│
│   (Blade)    │             │   (Check)     │
└──────┬───────┘             └───────────────┘
       │
       ↓
┌──────────────────────┐
│  SmsApiGateway       │
│  (SMSAPI.pl Client)  │
└──────┬───────────────┘
       │
       ↓
┌──────────────────────┐
│     SmsSend          │
│  (Database Log)      │
└──────────────────────┘
       ↑
       │ (Webhook)
       │
┌──────────────────────┐
│  SmsEvent            │
│ (Delivery Tracking)  │
└──────────────────────┘
```

See [architecture.md](architecture.md) for detailed design.

## Database Schema

### sms_sends
Tracks all sent SMS messages with delivery status.

**Key Fields**:
- `template_key`: Template identifier
- `phone_to`: Recipient phone (+48...)
- `message_body`: Final rendered message
- `status`: pending | sent | failed | invalid_number
- `sms_id`: SMSAPI message ID for tracking
- `message_length`: Character count
- `message_parts`: Number of SMS parts (multi-part messages)
- `message_key`: MD5 hash for idempotency

### sms_templates
Bilingual message templates with variable interpolation.

**Key Fields**:
- `key`: Template identifier (e.g., `booking_confirmation`)
- `language`: pl | en
- `message_body`: Template with `{{placeholders}}`
- `variables`: JSON array of available variables
- `max_length`: 160 (GSM-7) or 70 (Unicode)
- `active`: Enable/disable template

### sms_events
Webhook delivery events from SMSAPI.

**Event Types**:
- `sent`: Message sent from SMSAPI gateway
- `delivered`: Successfully delivered to recipient
- `failed`: Delivery failed (network, provider issue)
- `invalid_number`: Invalid phone number
- `expired`: Message expired before delivery

### sms_suppressions
Opt-out and invalid number management.

**Suppression Reasons**:
- `invalid_number`: Phone number format invalid
- `opted_out`: Customer opted out
- `failed_repeatedly`: 3+ consecutive failures
- `manual`: Manually suppressed by admin

See [database-schema.md](database-schema.md) for complete ERD.

## Admin Panel

### SMS Templates Resource
**Path**: `/admin/sms-templates`

- CRUD operations for templates
- Character count indicator (GSM-7/Unicode aware)
- Variable legend (dynamic based on template key)
- Test Send action with phone number input
- Filter by language, active status

### SMS Send Log Resource
**Path**: `/admin/sms-sends`

- Read-only SMS history
- Filter by status, template, date range
- Search by phone number
- View full message content and metadata

### SMS Events Resource
**Path**: `/admin/sms-events`

- Read-only delivery event log
- Filter by event type
- Linked to parent SMS send record

### SMS Suppression List Resource
**Path**: `/admin/sms-suppressions`

- CRUD for suppression list
- Manual add/remove phone numbers
- View suppression reason and date
- "Unsuppress" action to remove from list

See [filament-admin.md](filament-admin.md) for screenshots and usage.

## Troubleshooting

### SMS Not Sending

1. **Check Global Enable**: Admin → System Settings → SMS → SMS Enabled
2. **Check Notification Type**: Ensure specific notification is enabled (e.g., Booking Confirmation)
3. **Check Suppression**: Verify phone number not in suppression list
4. **Check Queue**: `docker compose logs -f queue` - ensure jobs processing
5. **Check Logs**: `storage/logs/laravel.log` for error messages

### Invalid API Token

**Error**: `401 Unauthorized` in logs

**Fix**: Verify OAuth token in System Settings → SMS → API Token

### Character Count Issues

**Problem**: Message split into multiple parts unexpectedly

**Cause**: Unicode characters detected (reduces limit from 160 to 70 chars)

**Fix**:
- Remove accents/special chars (use GSM-7 charset)
- Or accept multi-part message (costs more)

### Webhook Not Working

1. **Check Route**: `php artisan route:list | grep webhook`
2. **Check CSRF Exemption**: `bootstrap/app.php` should exclude `api/webhooks/*`
3. **Check SMSAPI Config**: Webhook URL must be publicly accessible HTTPS
4. **Check Logs**: `storage/logs/laravel.log` for webhook processing errors

See [troubleshooting.md](troubleshooting.md) for more issues.

## API Reference

### SmsService Methods

```php
// Send SMS from template
public function sendFromTemplate(
    string $templateKey,     // Template identifier
    string $language,         // 'pl' or 'en'
    string $recipient,        // +48501234567
    array $data,              // ['customer_name' => 'Jan', ...]
    array $metadata = []      // ['appointment_id' => 123]
): SmsSend

// Send raw SMS (bypass templates)
public function send(
    string $recipient,
    string $message,
    array $metadata = []
): SmsSend
```

### SmsGateway Methods

```php
// Send SMS via SMSAPI
public function send(
    string $to,
    string $message,
    array $metadata = []
): array

// Validate phone number format
public function validatePhoneNumber(string $phoneNumber): bool

// Calculate message length and parts
public function calculateMessageLength(string $message): array
```

See [api-reference.md](api-reference.md) for complete API docs.

## Configuration

### Environment Variables

```env
# Not used - all config via database settings
# (For reference only)
```

### Database Settings

All SMS configuration stored in `settings` table with `group = 'sms'`:

| Key | Type | Description | Default |
|-----|------|-------------|---------|
| `enabled` | boolean | Global SMS enable/disable | `true` |
| `api_token` | string | SMSAPI OAuth 2.0 Bearer Token | `null` |
| `service` | string | `pl` or `com` | `pl` |
| `sender_name` | string | Alphanumeric sender (max 11) | `Registro` |
| `test_mode` | boolean | Sandbox mode (no delivery) | `false` |
| `send_booking_confirmation` | boolean | Customer booking SMS | `true` |
| `send_admin_confirmation` | boolean | Admin confirm SMS | `true` |
| `send_reminder_24h` | boolean | 24h reminder | `true` |
| `send_reminder_2h` | boolean | 2h reminder | `true` |
| `send_follow_up` | boolean | Post-appointment SMS | `true` |

## Testing

### Unit Tests

```bash
cd app && php artisan test --filter=SmsTest
```

### Manual Testing

1. **Test Mode**: Enable in System Settings → SMS → Test Mode (Sandbox)
2. **Send Test SMS**: Admin → SMS Templates → Select template → Actions → Test Send
3. **Check Logs**: `storage/logs/laravel.log` for delivery confirmation
4. **Check History**: Admin → SMS Sends for send log

### Staging Testing

See [../../../environments/staging/](../../../environments/staging/) for staging server docs.

## Performance

### Message Costs

- **GSM-7** (160 chars): 1 SMS part
- **GSM-7 Multi-part** (306 chars): 2 SMS parts (153 chars each)
- **Unicode** (70 chars): 1 SMS part
- **Unicode Multi-part** (134 chars): 2 SMS parts (67 chars each)

### Rate Limits

SMSAPI.pl: **100 requests/second** (default plan)

### Queue Configuration

```php
// config/queue.php
'connections' => [
    'redis' => [
        'retry_after' => 90,
        'block_for' => null,
        'after_commit' => false,
    ],
],
```

## Related Documentation

- [Architecture Details](architecture.md)
- [Template Management](templates.md)
- [Notification Flow](notifications.md)
- [Scheduled Jobs](scheduled-jobs.md)
- [Filament Admin](filament-admin.md)
- [Webhook Configuration](webhook.md)
- [Troubleshooting](troubleshooting.md)
- [API Reference](api-reference.md)
- [Database Schema](database-schema.md)

## Support

For issues or questions:
1. Check [troubleshooting.md](troubleshooting.md)
2. Review logs: `storage/logs/laravel.log`
3. Check SMSAPI dashboard for delivery issues
4. Consult SMSAPI docs: https://docs.smsapi.pl/

---

**Last Updated**: 2025-11-12
**System Version**: Laravel 12 + SMSAPI PHP Client v3.x
