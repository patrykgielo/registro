# Email System & Notifications

**Status:** ✅ Production Ready (November 2025)

Complete transactional email system with queue-based delivery, multi-language support (PL/EN), and Filament admin panel for management.

## Quick Overview

- **18 Email Templates** (9 types × 2 languages: Polish + English)
- **Queue-Based Delivery** (Redis + Laravel Horizon)
- **Gmail SMTP** with App Password support
- **Event-Driven Architecture** (9 domain events, 8 queued notifications)
- **4 Scheduled Jobs** (reminders, follow-ups, digest, cleanup)
- **4 Filament Resources** (templates, sends, events, suppressions)
- **Idempotency** via unique message_key (prevents duplicates)
- **Email Suppression List** (bounce/complaint/unsubscribe handling)

## Quick Links

- **[Architecture](./architecture.md)** - Services, Models, Events, Notifications
- **[Templates](./templates.md)** - Template management, variables, seeding
- **[Notifications](./notifications.md)** - Events and notification classes
- **[Scheduled Jobs](./scheduled-jobs.md)** - Reminders, follow-ups, digests
- **[Filament Admin](./filament-admin.md)** - Admin panel resources and permissions
- **[Troubleshooting](./troubleshooting.md)** - Common issues and fixes

## Features

### Multi-Language Support
All templates available in Polish (PL) and English (EN). User's `preferred_language` field determines which template is sent.

### Template Types

| Template Key              | Purpose                          |
|---------------------------|----------------------------------|
| user-registered           | Welcome email after registration |
| password-reset            | Password reset link              |
| appointment-created       | Booking confirmation             |
| appointment-rescheduled   | Date/time change notification    |
| appointment-cancelled     | Cancellation confirmation        |
| appointment-reminder-24h  | 24-hour reminder                 |
| appointment-reminder-2h   | 2-hour reminder                  |
| appointment-followup      | Post-service feedback request    |
| admin-daily-digest        | Daily statistics for admins      |

### Queue Architecture
- **Backend:** Redis
- **Monitor:** Laravel Horizon (https://registro.local:8444/horizon)
- **Queues:** `emails` (priority), `reminders`, `default`
- **Workers:** 3 max processes, auto-scaling
- **Retries:** 3 attempts with exponential backoff
- **Timeout:** 90 seconds per job

## Quick Start

### Send Email from Code

```php
use App\Services\Email\EmailService;

$emailService = app(EmailService::class);

$emailSend = $emailService->sendFromTemplate(
    templateKey: 'appointment-created',
    language: 'pl',
    recipient: 'customer@example.com',
    data: [
        'customer_name' => 'Jan Kowalski',
        'appointment_date' => '2025-11-15',
        'appointment_time' => '14:00',
        'service_name' => 'Detailing zewnętrzny',
        'location_address' => 'ul. Marszałkowska 1, Warszawa',
    ],
    metadata: ['appointment_id' => 123]
);

if ($emailSend->status === 'sent') {
    echo "Email sent successfully!";
}
```

### Test Email System

```bash
# Test complete flow
php artisan email:test-flow --all

# Test from Filament admin panel
# 1. Open: https://registro.local:8444/admin/email-templates
# 2. Click "Test Send" on any template
# 3. Enter email address
# 4. Check inbox (and spam folder)
```

### Monitor Queues

```bash
# Check Horizon dashboard
https://registro.local:8444/horizon

# Process queue manually (development)
php artisan queue:work redis --queue=emails,reminders,default --tries=3

# Retry failed jobs
php artisan queue:retry all
```

## Configuration

### Environment Variables (.env)

```bash
# Gmail SMTP with App Password (REQUIRED)
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-16-char-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="your-email@gmail.com"
MAIL_FROM_NAME="${APP_NAME}"

# Queue Configuration
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=redis
```

### Gmail App Password Setup

**IMPORTANT:** Google deprecated "Less Secure Apps" on May 1, 2025. App Passwords are now mandatory.

1. Enable 2-Step Verification: https://myaccount.google.com/security
2. Generate App Password: https://myaccount.google.com/apppasswords
3. Copy 16-character password (e.g., `abcd efgh ijkl mnop`)
4. Remove spaces → `abcdefghijklmnop`
5. Update `.env`: `MAIL_PASSWORD=abcdefghijklmnop`

## Database Schema

**4 Tables:**

```sql
email_templates     → Template storage (key, language, subject, body, variables)
email_sends         → Delivery logs (recipient, status, sent_at, message_key)
email_events        → Event tracking (sent, delivered, bounced, complained, opened, clicked)
email_suppressions  → Bounce/complaint/unsubscribe list
```

## File Structure

```
app/
├── Services/Email/
│   ├── EmailGatewayInterface.php    # Email delivery abstraction
│   ├── SmtpMailer.php               # SMTP implementation
│   └── EmailService.php             # Core email service
├── Models/
│   ├── EmailTemplate.php
│   ├── EmailSend.php
│   ├── EmailEvent.php
│   └── EmailSuppression.php
├── Events/
│   ├── UserRegistered.php
│   ├── Appointment*.php             # 8 appointment events
│   └── EmailDeliveryFailed.php
├── Notifications/
│   ├── UserRegisteredNotification.php
│   └── Appointment*Notification.php # 8 appointment notifications
├── Jobs/Email/
│   ├── SendReminderEmailsJob.php
│   ├── SendFollowUpEmailsJob.php
│   ├── SendAdminDigestJob.php
│   └── CleanupOldEmailLogsJob.php
├── Filament/Resources/
│   ├── EmailTemplateResource.php
│   ├── EmailSendResource.php
│   ├── EmailEventResource.php
│   └── EmailSuppressionResource.php
└── Console/Commands/
    └── TestEmailFlowCommand.php
```

## Next Steps

- **New to Email System?** Start with [Architecture](./architecture.md)
- **Need to manage templates?** See [Templates](./templates.md)
- **Setting up scheduled emails?** Read [Scheduled Jobs](./scheduled-jobs.md)
- **Having issues?** Check [Troubleshooting](./troubleshooting.md)

## See Also

- [Settings System](../settings-system/README.md) - Email SMTP configuration
- [Queue System](../../architecture/queue-system.md) - Redis + Horizon architecture
- [Testing Guide](../../guides/testing.md) - How to test email sending
