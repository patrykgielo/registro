# Email System Troubleshooting

Najczęstsze problemy i rozwiązania.

## Test Send Button Errors

### Error: Property [name] does not exist

**Solved:** ✅ Fixed in November 9, 2025

**Problem:** Notifications and email templates use `$user->name` but User model only had `first_name` and `last_name` fields.

**Cause:** User model refactored to separate fields (October 2025) but no accessor added for backward compatibility.

**Fix:** Added `getNameAttribute()` accessor to User model:
```php
// app/Models/User.php (line 97)
public function getNameAttribute(): string
{
    return $this->getFullNameAttribute();
}
```

**See Also:** ADR-006 for full decision rationale

### Error: Undefined constant 'customer_name' or 'app_name'

**Solved:** ✅ Fixed in November 9, 2025

**Problem:** Template rendering failed with "Undefined constant" errors for variables.

**Cause:** EmailService called `Blade::render()` directly, bypassing `EmailTemplate::render()` which converts `{{variable}}` → `{{ $variable }}`.

**Fix:** Changed EmailService to use EmailTemplate methods:
```php
// app/Services/Email/EmailService.php (lines 191-193)
$subject = $template->renderSubject($data);
$html = $template->render($data);
$text = $template->renderText($data);
```

### Error: Field 'occurred_at' doesn't have a default value

**Solved:** ✅ Fixed in November 9, 2025

**Problem:** EmailEvent creation failed with database error.

**Cause:** EmailEvent::create() calls didn't provide required `occurred_at` field.

**Fix:** Added `occurred_at` to all EmailEvent::create() calls:
```php
EmailEvent::create([
    'email_send_id' => $emailSend->id,
    'event_type' => 'sent',
    'occurred_at' => now(),
    'event_data' => [...],
]);
```

**Note:** Also fixed column names: `type` → `event_type`, `data` → `event_data`

### Error: Call to undefined method sendFromTemplate()

**Solved:** ✅ Fixed in November 2025

Parameter order was incorrect. Now using named parameters:

```php
$emailService->sendFromTemplate(
    templateKey: $record->key,
    language: $record->language,
    recipient: $data['email'],
    data: self::getExampleData($record),
    metadata: []
);
```

### Error: Route [verification.notice] not defined

**Solved:** ✅ Fixed in November 2025

**Problem:** Test Send fails for "user-registered" template with route error.

**Cause:** `getExampleData()` tried to generate route that doesn't exist (email verification not implemented yet).

**Fix:**
```php
// In EmailTemplateResource.php (line 291)
// FROM:
'verification_url' => route('verification.notice'),

// TO:
'verification_url' => url('/email/verify'),
```

**File:** `app/Filament/Resources/EmailTemplateResource.php` (line 291)

## Gmail SMTP Errors

### Error 535-5.7.8: Username and Password not accepted

**Cause:** Using regular Gmail password instead of App Password

**Fix:**
1. Enable 2-Step Verification: https://myaccount.google.com/security
2. Generate App Password: https://myaccount.google.com/apppasswords
3. Update `.env`: `MAIL_PASSWORD=your-16-char-app-password`
4. Restart queue workers: `docker compose restart horizon`

### Error: Connection timeout

**Possible causes:**
- Gmail SMTP blocked by firewall
- Wrong port (use 587 for TLS, 465 for SSL)
- Redis not running

**Fix:**
```bash
# Check Redis
docker compose ps redis

# Test SMTP connection
php artisan tinker
>>> Mail::raw('Test', fn($msg) => $msg->to('test@example.com')->subject('Test'));
```

## Queue Issues

### Emails Not Sending (Queue Not Processing)

```bash
# Check if queue worker running
docker compose ps horizon

# Check Horizon dashboard
https://registro.local:8444/horizon/failed

# Check logs
docker compose logs -f horizon
tail -f storage/logs/laravel.log | grep Email

# Retry failed jobs
php artisan queue:retry all
```

### Duplicate Emails Being Sent

**Cause:** `message_key` idempotency not working

**Check:**
```sql
SELECT message_key, COUNT(*) as count
FROM email_sends
GROUP BY message_key
HAVING count > 1;
```

**Fix:** Ensure metadata includes unique identifier:

```php
$emailService->sendFromTemplate(
    // ...
    metadata: ['appointment_id' => $appointment->id] // ✅ Unique per appointment
);
```

## Template Rendering Errors

### Undefined variable: variable_name

**Fix:** Pass all variables in `data` array:

```php
data: [
    'customer_name' => $user->full_name,
    'appointment_date' => $appointment->scheduled_at->format('Y-m-d'),
    // Add all variables used in template
]
```

### Template Not Found

```bash
# Run seeder
php artisan db:seed --class=EmailTemplateSeeder

# Check database
SELECT key, language FROM email_templates;
```

## Scheduler Not Running

### Jobs Not Executing (Reminders, Digests)

```bash
# Check scheduler container
docker compose ps scheduler

# Check cron logs
docker compose logs -f scheduler

# Manually trigger
php artisan schedule:run --verbose

# List scheduled jobs
php artisan schedule:list
```

## Database Issues

### Duplicate Settings Migration Error

**Solved:** ✅ Deleted duplicate migration `2025_11_09_004042_create_settings_table.php`

Settings table already exists from `2025_11_01_000000` migration.

## Performance Issues

### Slow Email Sending

**Possible causes:**
- SMTP timeout too high
- No queue workers (synchronous sending)
- Too many emails in queue

**Fix:**
```bash
# Use Horizon for auto-scaling
php artisan horizon

# Increase workers in config/horizon.php
'production' => [
    'supervisor-1' => [
        'maxProcesses' => 10, // Increase from 3
    ],
],
```

## Debugging Tips

### Enable Email Logging

In `.env`:
```bash
LOG_CHANNEL=stack
LOG_LEVEL=debug
```

Check `storage/logs/laravel.log` for email-related errors.

### Use Mailpit (Development)

Already configured in Docker:
- Mailpit UI: http://localhost:8025
- All emails caught (no real sending)

### Check Email Logs in Database

```sql
-- Recent emails
SELECT id, template_key, recipient_email, status, sent_at, error_message
FROM email_sends
ORDER BY created_at DESC
LIMIT 10;

-- Failed emails
SELECT * FROM email_sends WHERE status = 'failed';

-- Email events timeline
SELECT es.id, es.template_key, ee.event_type, ee.occurred_at
FROM email_sends es
JOIN email_events ee ON es.id = ee.email_send_id
WHERE es.id = 123
ORDER BY ee.occurred_at;
```

## Common Mistakes

### 1. Forgetting to Start Queue Workers

Emails queued but never sent → workers not running.

**Fix:** `docker compose up -d horizon`

### 2. Wrong Parameter Order in sendFromTemplate()

**Wrong:**
```php
sendFromTemplate($key, $email, $data, $language) // ❌ Old bug
```

**Correct:**
```php
sendFromTemplate(
    templateKey: $key,
    language: $language,
    recipient: $email,
    data: $data
) // ✅ Named parameters
```

### 3. Not Clearing Cache After Config Changes

After updating SMTP settings:
```bash
php artisan optimize:clear
docker compose restart horizon
```

## Getting Help

1. Check [Architecture](./architecture.md) for system overview
2. Review [Templates](./templates.md) for template syntax
3. Check Laravel logs: `storage/logs/laravel.log`
4. Check Horizon dashboard: https://registro.local:8444/horizon
5. Run test command: `php artisan email:test-flow --all`
