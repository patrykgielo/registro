# Email System - Quick Reference Guide

**Last Updated:** 2025-11-09
**Status:** FAZA 3 Complete (Database Schema Ready)

## Quick Start

### Get a Template
```php
use App\Models\EmailTemplate;

// Get template by key and language
$template = EmailTemplate::forKey('user-registered')
    ->forLanguage('pl')
    ->active()
    ->first();

// Get all active templates
$templates = EmailTemplate::active()->get();
```

### Render a Template
```php
$data = [
    'user_name' => 'Jan Kowalski',
    'app_name' => config('app.name'),
    'user_email' => 'jan@example.com',
];

$subject = $template->renderSubject($data);
$htmlBody = $template->render($data);
$textBody = $template->renderText($data);
```

### Record an Email Send
```php
use App\Models\EmailSend;

$send = EmailSend::create([
    'template_key' => 'user-registered',
    'language' => 'pl',
    'recipient_email' => 'jan@example.com',
    'subject' => $subject,
    'body_html' => $htmlBody,
    'body_text' => $textBody,
    'status' => 'pending',
    'metadata' => ['user_id' => 123],
    'message_key' => 'user-registered:123:' . md5(json_encode($data)),
]);

// After sending
$send->markAsSent();

// If failed
$send->markAsFailed('SMTP error: Connection timeout');

// If bounced
$send->markAsBounced();
```

### Track Email Events
```php
use App\Models\EmailEvent;

$send->emailEvents()->create([
    'event_type' => 'delivered',
    'event_data' => ['provider' => 'mailgun', 'message_id' => 'xyz123'],
    'occurred_at' => now(),
]);

// Query events
$opens = EmailEvent::type('opened')->recent()->get();
$clicks = EmailEvent::type('clicked')->recent()->get();
```

### Check Suppression List
```php
use App\Models\EmailSuppression;

// Check if email is suppressed
if (EmailSuppression::isSuppressed('spam@example.com')) {
    return; // Don't send email
}

// Add to suppression list
EmailSuppression::suppress('spam@example.com', 'bounced');

// Remove from suppression list
EmailSuppression::unsuppress('spam@example.com');

// Get all bounced emails
$bounced = EmailSuppression::bounced();
```

## Available Email Templates

| Key | Languages | Variables | Use Case |
|-----|-----------|-----------|----------|
| `user-registered` | pl, en | user_name, app_name, user_email | Welcome email after registration |
| `password-reset` | pl, en | user_name, app_name, reset_url, expires_in | Password reset link |
| `appointment-created` | pl, en | customer_name, service_name, appointment_date, appointment_time, location_address, app_name | Appointment confirmation |
| `appointment-rescheduled` | pl, en | customer_name, service_name, appointment_date, appointment_time, location_address, app_name | Date/time change notice |
| `appointment-cancelled` | pl, en | customer_name, service_name, appointment_date, appointment_time, app_name | Cancellation confirmation |
| `appointment-reminder-24h` | pl, en | customer_name, service_name, appointment_date, appointment_time, location_address, app_name | 24-hour reminder |
| `appointment-reminder-2h` | pl, en | customer_name, service_name, appointment_time, location_address, app_name | 2-hour reminder |
| `appointment-followup` | pl, en | customer_name, service_name, review_url, app_name | Thank you + review request |
| `admin-daily-digest` | pl, en | admin_name, date, appointment_count, appointment_list, app_name | Daily summary for admins |

## Common Queries

### Get recent sent emails
```php
$recentSends = EmailSend::status('sent')->recent()->get();
```

### Get failed emails for retry
```php
$failed = EmailSend::status('failed')
    ->where('created_at', '>', now()->subHours(24))
    ->get();
```

### Get emails sent to specific recipient
```php
$userEmails = EmailSend::recipient('jan@example.com')
    ->orderBy('sent_at', 'desc')
    ->get();
```

### Get all emails for an appointment
```php
$appointmentEmails = EmailSend::where('metadata->appointment_id', 123)->get();
```

### Track open rate for a template
```php
$template = 'appointment-reminder-24h';
$sends = EmailSend::where('template_key', $template)
    ->where('status', 'sent')
    ->count();

$opens = EmailEvent::whereHas('emailSend', function($q) use ($template) {
    $q->where('template_key', $template);
})->type('opened')->count();

$openRate = $sends > 0 ? ($opens / $sends) * 100 : 0;
```

## Database Schema Quick Reference

### email_templates
- Stores template definitions with multilingual support
- Unique constraint: (key, language)
- Use `active` flag to enable/disable templates

### email_sends
- Records every email send attempt
- `status`: pending → sent/failed/bounced
- `message_key`: Unique idempotency key to prevent duplicates
- `metadata`: JSON with user_id, appointment_id, etc.

### email_events
- Tracks delivery events (sent, delivered, bounced, complained, opened, clicked)
- Foreign key to email_sends with CASCADE delete
- `event_data`: Provider-specific data

### email_suppressions
- Maintains global suppression list
- Check before sending any email
- Reasons: bounced, complained, unsubscribed, manual

## Scopes Reference

### EmailTemplate
- `active()` - Only active templates
- `forKey($key)` - Filter by template key
- `forLanguage($lang)` - Filter by language

### EmailSend
- `status($status)` - Filter by status
- `recipient($email)` - Filter by recipient
- `recent()` - Last 7 days

### EmailEvent
- `type($type)` - Filter by event type
- `recent()` - Last 30 days

### EmailSuppression
- `reason($reason)` - Filter by suppression reason
- `active()` - Active suppressions only

## Artisan Commands

```bash
# Run migrations
php artisan migrate

# Seed templates
php artisan db:seed --class=EmailTemplateSeeder

# Check database
php artisan tinker
>>> EmailTemplate::count(); // Should be 18
>>> EmailSend::count();
>>> EmailEvent::count();
>>> EmailSuppression::count();
```

## Idempotency Pattern

Always use message_key to prevent duplicate sends:

```php
$messageKey = "{$templateKey}:{$userId}:{$appointmentId}:" . md5(json_encode($data));

$existingSend = EmailSend::where('message_key', $messageKey)->first();

if ($existingSend) {
    return $existingSend; // Already sent, don't send again
}

// Create new send with unique message_key
$send = EmailSend::create([
    'message_key' => $messageKey,
    // ... other fields
]);
```

## Language Detection Pattern

```php
// Get user's preferred language
$user = auth()->user();
$language = $user->preferred_language ?? 'pl'; // Default to Polish

// Get template in user's language
$template = EmailTemplate::forKey('user-registered')
    ->forLanguage($language)
    ->active()
    ->firstOrFail();
```

## Error Handling Pattern

```php
try {
    // Render template
    $htmlBody = $template->render($data);

    // Send email (FAZA 4)
    Mail::send(...);

    // Mark as sent
    $send->markAsSent();

} catch (\Exception $e) {
    // Mark as failed with error
    $send->markAsFailed($e->getMessage());

    // Log error
    Log::error('Email send failed', [
        'email_send_id' => $send->id,
        'error' => $e->getMessage(),
    ]);
}
```

## Testing in Tinker

```bash
docker compose exec app php artisan tinker
```

```php
// Test template rendering
$template = App\Models\EmailTemplate::forKey('user-registered')->forLanguage('pl')->first();
$data = ['user_name' => 'Jan', 'app_name' => 'Paradocks', 'user_email' => 'jan@test.pl'];
echo $template->renderSubject($data);
echo $template->render($data);

// Test suppression
App\Models\EmailSuppression::suppress('test@example.com', 'manual');
App\Models\EmailSuppression::isSuppressed('test@example.com'); // true
App\Models\EmailSuppression::unsuppress('test@example.com');
App\Models\EmailSuppression::isSuppressed('test@example.com'); // false

// Create test email send
$send = App\Models\EmailSend::create([
    'template_key' => 'user-registered',
    'language' => 'pl',
    'recipient_email' => 'test@example.com',
    'subject' => 'Test Subject',
    'body_html' => '<p>Test</p>',
    'status' => 'pending',
    'message_key' => 'test-' . time(),
]);
$send->markAsSent();

// Create test event
$send->emailEvents()->create([
    'event_type' => 'delivered',
    'occurred_at' => now(),
]);
```

## Next Steps (FAZA 4)

1. Add `preferred_language` column to users table
2. Create `EmailService` class for sending emails
3. Create Laravel Mailable classes
4. Integrate with queue system
5. Create Filament admin resources
6. Add event listeners for automatic emails

## Support

For issues or questions, see:
- Full documentation: `docs/email-system-phase-3-summary.md`
- Model files: `app/Models/Email*.php`
- Migrations: `database/migrations/*_create_email_*_table.php`
- Seeder: `database/seeders/EmailTemplateSeeder.php`
