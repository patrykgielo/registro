# SMS System Architecture

Complete architectural overview of the SMS notification system.

## Design Patterns

### Service Layer Pattern

**Purpose**: Decouple business logic from framework dependencies.

```
SmsService (Business Logic)
    ↓
SmsGatewayInterface (Contract)
    ↓
SmsApiGateway (Implementation)
```

**Benefits**:
- Testable: Easy to mock gateway for unit tests
- Swappable: Can switch SMS providers by implementing interface
- Single Responsibility: Each class has one job

### Repository Pattern

**Models**: SmsSend, SmsTemplate, SmsEvent, SmsSuppression

Each model encapsulates database logic with scopes, relationships, and helpers.

### Event-Driven Architecture

**Events**: AppointmentCreated, AppointmentConfirmed, AppointmentReminder24h, etc.

**Listeners**: AppServiceProvider registers event listeners that call SmsService

**Benefits**:
- Decoupled: Appointment logic doesn't know about SMS
- Extensible: Easy to add new notification types
- Asynchronous: Events can be queued

## Components

### 1. Service Layer

#### SmsService
**File**: `app/Services/Sms/SmsService.php`
**Purpose**: Core SMS sending logic with template rendering

**Key Methods**:
```php
// Send from template with idempotency check
sendFromTemplate(string $templateKey, string $language, string $recipient, array $data, array $metadata = []): SmsSend

// Send raw SMS (bypass templates)
send(string $recipient, string $message, array $metadata = []): SmsSend

// Render template with Blade
private function renderTemplate(SmsTemplate $template, array $data): string

// Generate idempotency key
private function generateMessageKey(string $templateKey, string $recipient, array $metadata): string
```

**Idempotency**: Uses MD5 hash of (template_key + recipient + metadata) to prevent duplicate sends.

#### SmsGatewayInterface
**File**: `app/Services/Sms/SmsGatewayInterface.php`
**Purpose**: Contract for SMS gateway implementations

**Methods**:
```php
send(string $to, string $message, array $metadata = []): array
validatePhoneNumber(string $phoneNumber): bool
calculateMessageLength(string $message): array
```

#### SmsApiGateway
**File**: `app/Services/Sms/SmsApiGateway.php`
**Purpose**: SMSAPI.pl implementation using official PHP client

**Features**:
- OAuth 2.0 Bearer Token authentication
- Phone number normalization (strip spaces, ensure +prefix)
- GSM-7 vs Unicode detection for character counting
- Test mode support (sandbox)
- Service endpoint selection (pl | com)

**Dependencies**:
```php
use Smsapi\Client\Curl\SmsapiHttpClient;
use Smsapi\Client\Feature\Sms\Bag\SendSmsBag;
use Smsapi\Client\SmsapiClient;
```

### 2. Models

#### SmsSend
**File**: `app/Models/SmsSend.php`
**Table**: `sms_sends`

**Relationships**:
- `belongsTo(SmsTemplate)` - Parent template
- `hasMany(SmsEvent)` - Delivery events

**Scopes**:
- `sent()`, `pending()`, `failed()` - Filter by status
- `template($key)` - Filter by template key
- `phone($phone)` - Filter by recipient

**Helpers**:
- `markAsSent()`, `markAsFailed()` - Status updates
- `isSent()`, `isPending()` - Status checks

#### SmsTemplate
**File**: `app/Models/SmsTemplate.php`
**Table**: `sms_templates`

**Key Methods**:
```php
// Render template with Blade
render(array $data): string

// Check if message exceeds max length
exceedsMaxLength(string $message): bool

// Truncate message to max length
truncateMessage(string $message): string
```

**Template Keys**:
- `booking_confirmation` - Customer creates appointment
- `admin_confirmation` - Admin confirms appointment
- `reminder_24h` - 24 hours before
- `reminder_2h` - 2 hours before
- `follow_up` - After appointment completed

#### SmsEvent
**File**: `app/Models/SmsEvent.php`
**Table**: `sms_events`

**Event Types**: sent | delivered | failed | invalid_number | expired

**Purpose**: Tracks webhook events from SMSAPI for delivery monitoring.

#### SmsSuppression
**File**: `app/Models/SmsSuppression.php`
**Table**: `sms_suppressions`

**Static Helpers**:
```php
isSuppressed(string $phone): bool
suppress(string $phone, string $reason): self
unsuppress(string $phone): bool
```

**Suppression Reasons**:
- `invalid_number` - Invalid phone format
- `opted_out` - Customer opted out
- `failed_repeatedly` - 3+ consecutive failures
- `manual` - Admin manually suppressed

### 3. Queue Jobs

#### SendReminderSmsJob
**File**: `app/Jobs/Sms/SendReminderSmsJob.php`
**Schedule**: Hourly via Laravel Scheduler

**Logic**:
```php
1. Check if SMS globally enabled
2. Check if 24h reminder enabled
   → Find appointments 24h away (±1h window)
   → Filter out already sent (sent_24h_reminder_sms = true)
   → Send SMS for each
   → Mark sent_24h_reminder_sms = true
3. Check if 2h reminder enabled
   → Find appointments 2h away (±1h window)
   → Send and mark sent_2h_reminder_sms = true
```

#### SendFollowUpSmsJob
**File**: `app/Jobs/Sms/SendFollowUpSmsJob.php`
**Schedule**: Hourly via Laravel Scheduler

**Logic**:
```php
1. Check if SMS globally enabled
2. Check if follow-up enabled
3. Find completed appointments 24h ago (±1h window)
4. Filter out already sent (sent_followup_sms = true)
5. Send SMS for each
6. Mark sent_followup_sms = true
```

#### CleanupOldSmsLogsJob
**File**: `app/Jobs/Sms/CleanupOldSmsLogsJob.php`
**Schedule**: Daily at 2:30 AM

**Logic**:
```php
1. Delete sms_sends older than 90 days
2. Delete sms_events older than 90 days
3. Log deletion count
```

**GDPR Compliance**: 90-day retention policy.

### 4. Event Listeners

#### AppServiceProvider
**File**: `app/Providers/AppServiceProvider.php`

**Registered Listeners**:
```php
Event::listen(AppointmentCreated::class, function (AppointmentCreated $event) {
    $this->sendSmsNotification('booking_confirmation', $event->appointment, 'send_booking_confirmation');
});

Event::listen(AppointmentConfirmed::class, function (AppointmentConfirmed $event) {
    $this->sendSmsNotification('admin_confirmation', $event->appointment, 'send_admin_confirmation');
});
```

**Helper Method**:
```php
private function sendSmsNotification(string $templateKey, $appointment, string $settingKey): void
{
    // 1. Check SMS globally enabled
    // 2. Check specific notification enabled
    // 3. Get customer phone
    // 4. Prepare template data
    // 5. Send via SmsService
    // 6. Log success/failure
}
```

**Error Handling**: Catches all exceptions, logs error, but doesn't throw (SMS failure shouldn't block appointment flow).

### 5. Webhook Handler

#### SmsApiWebhookController
**File**: `app/Http/Controllers/Api/SmsApiWebhookController.php`
**Route**: `POST /api/webhooks/smsapi/delivery-status`

**Flow**:
```php
1. Validate incoming payload (id, status, to, date_sent, error_code)
2. Find SmsSend by sms_id
3. Map SMSAPI status to event type:
   - SENT/QUEUE → sent
   - DELIVERED/ACCEPTED → delivered
   - FAILED/REJECTED/ERROR → failed
   - INVALID/INVALID_NUMBER → invalid_number
   - EXPIRED/NOT_DELIVERED → expired
4. Create SmsEvent record
5. Update SmsSend status (if newer)
6. Handle suppression list:
   - invalid_number → suppress immediately
   - failed → suppress after 3 consecutive failures
7. Return 200 OK
```

**Security**: CSRF exemption in `bootstrap/app.php` for `api/webhooks/*` routes.

### 6. Filament Resources

#### SmsTemplateResource
**Features**:
- CRUD operations
- Character count display (GSM-7/Unicode aware)
- Variable legend (dynamic based on template key)
- Test Send action

#### SmsSendResource
**Features**:
- Read-only history
- Filter by status, template, date
- Search by phone

#### SmsEventResource
**Features**:
- Read-only event log
- Filter by event type
- Linked to parent SmsSend

#### SmsSuppressionResource
**Features**:
- CRUD for suppression list
- Manual add/remove
- "Unsuppress" delete action

## Data Flow

### Immediate Notifications (Booking, Admin Confirmation)

```
1. User Action (Create/Confirm Appointment)
   ↓
2. Appointment Model Dispatches Event (AppointmentCreated/AppointmentConfirmed)
   ↓
3. AppServiceProvider Event Listener Triggered
   ↓
4. sendSmsNotification() Helper Method
   ↓ (checks enabled, phone exists)
5. SmsService::sendFromTemplate()
   ↓ (checks suppression, idempotency)
6. SmsTemplate::render() (Blade interpolation)
   ↓
7. SmsApiGateway::send() (SMSAPI HTTP request)
   ↓
8. SmsSend::create() (Database log)
   ↓
9. Queue Job Dispatched (async)
   ↓
10. SMSAPI Sends SMS
```

### Scheduled Reminders

```
1. Laravel Scheduler (Hourly)
   ↓
2. SendReminderSmsJob::handle()
   ↓ (checks enabled, finds appointments)
3. For each appointment:
   ↓
4. SmsService::sendFromTemplate()
   ↓
5. ... (same as above)
   ↓
6. Update appointment.sent_24h_reminder_sms = true
```

### Webhook Delivery Tracking

```
1. SMSAPI HTTP POST to /api/webhooks/smsapi/delivery-status
   ↓
2. SmsApiWebhookController::handleDeliveryStatus()
   ↓ (validate payload)
3. Find SmsSend by sms_id
   ↓
4. Create SmsEvent (event_type, occurred_at, metadata)
   ↓
5. Update SmsSend status
   ↓
6. Handle suppression (if invalid/failed)
   ↓
7. Return 200 OK
```

## Configuration Management

### Settings Flow

```
1. Admin edits System Settings → SMS tab (Filament)
   ↓
2. Form submission to SystemSettings::submit()
   ↓
3. SettingsManager::updateGroups(['sms' => [...]])
   ↓
4. Updates `settings` table rows (group='sms')
   ↓
5. Next SMS send reads updated settings via SettingsManager::group('sms')
```

### Template Updates

```
1. Admin edits SMS Template (Filament)
   ↓
2. SmsTemplate::update(['message_body' => '...'])
   ↓
3. Next SMS send uses updated template
```

**Note**: No cache invalidation needed (settings read from DB on each request).

## Error Handling

### Service Layer
```php
try {
    $smsService->sendFromTemplate(...);
} catch (\Exception $e) {
    Log::error('SMS send failed', ['error' => $e->getMessage()]);
    // Don't throw - SMS failure shouldn't block app flow
}
```

### Queue Jobs
```php
public $tries = 3; // Retry 3 times
public $backoff = [60, 120, 300]; // Exponential backoff (1min, 2min, 5min)
```

### Webhook Handler
```php
// Always return 200 to prevent SMSAPI retries
// Log errors but accept webhook
return response()->json(['success' => true], 200);
```

## Security

### API Token Storage
- Stored in database (encrypted at rest via Laravel encryption)
- Never exposed in logs (masked: `+48501***`)

### CSRF Protection
- Webhook routes excluded from CSRF (`bootstrap/app.php`)
- All other routes protected

### Phone Number Privacy
- Logs mask phone numbers: `substr($phone, 0, 3) . '***'`
- Full number only in database

### Suppression List
- Prevents spam to invalid numbers
- Respects opt-outs
- Auto-suppresses after 3 failures

## Performance Optimization

### Idempotency
- Prevents duplicate sends via `message_key` (MD5 hash)
- Database unique index on `message_key`

### Queue Processing
- All SMS sent asynchronously via queue
- Prevents blocking web requests
- Horizon for monitoring and failed job management

### Database Indexes
```sql
-- sms_sends
INDEX (status)
INDEX (phone_to)
INDEX (created_at)
UNIQUE INDEX (message_key)

-- sms_templates
INDEX (key, language)
INDEX (active)

-- sms_events
INDEX (sms_send_id, event_type)

-- sms_suppressions
UNIQUE INDEX (phone)
```

### Cleanup Job
- Daily deletion of logs >90 days
- Prevents table bloat
- GDPR compliance

## Testing Strategy

### Unit Tests
```php
// Test SmsService with mocked gateway
$gateway = Mockery::mock(SmsGatewayInterface::class);
$gateway->shouldReceive('send')->once()->andReturn(['sms_id' => '123']);

$service = new SmsService($gateway, ...);
$result = $service->send('+48501234567', 'Test');

$this->assertEquals('sent', $result->status);
```

### Integration Tests
```php
// Test webhook flow
$this->post('/api/webhooks/smsapi/delivery-status', [
    'id' => $smsSend->sms_id,
    'status' => 'DELIVERED',
])
->assertOk();

$this->assertDatabaseHas('sms_events', [
    'sms_send_id' => $smsSend->id,
    'event_type' => 'delivered',
]);
```

### Feature Tests
```php
// Test appointment creation triggers SMS
Event::fake();

$appointment = Appointment::create([...]);

Event::assertDispatched(AppointmentCreated::class);

// Check SMS sent
$this->assertDatabaseHas('sms_sends', [
    'template_key' => 'booking_confirmation',
    'phone_to' => $appointment->customer->phone,
]);
```

## Monitoring

### Logs
- **Location**: `storage/logs/laravel.log`
- **Events Logged**:
  - SMS send success/failure
  - Webhook received/processed
  - Suppression list updates
  - Template rendering errors

### Horizon Dashboard
- **URL**: `/horizon`
- **Metrics**:
  - Queue throughput
  - Failed jobs
  - Job wait time
  - Memory usage

### Database Metrics
- Count pending SMS: `SELECT COUNT(*) FROM sms_sends WHERE status = 'pending'`
- Daily send volume: `SELECT DATE(created_at), COUNT(*) FROM sms_sends GROUP BY DATE(created_at)`
- Failure rate: `SELECT COUNT(*) FROM sms_sends WHERE status = 'failed'`

## Deployment Checklist

- [ ] Run migrations (`php artisan migrate`)
- [ ] Seed templates (`db:seed --class=SmsTemplateSeeder`)
- [ ] Seed settings (`db:seed --class=SettingSeeder`)
- [ ] Configure SMSAPI token in admin panel
- [ ] Set sender name (max 11 chars)
- [ ] Enable desired notifications
- [ ] Configure webhook URL in SMSAPI dashboard
- [ ] Test with sandbox mode enabled
- [ ] Verify queue processing (`docker compose logs queue`)
- [ ] Check cron/scheduler running (`artisan schedule:list`)
- [ ] Monitor first sends in production

---

**See Also**:
- [README.md](README.md) - Quick start guide
- [webhook.md](webhook.md) - Webhook configuration
- [database-schema.md](database-schema.md) - Database structure
