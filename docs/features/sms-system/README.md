# SMS System Documentation

Comprehensive documentation for the Registro SMS notification system.

## Quick Links

- **[Architecture](./architecture.md)** - Complete system architecture with Mermaid diagrams

## Overview

The SMS system provides automated customer communication through SMS messages using SMSAPI.pl gateway.

### Key Components

1. **Event-Driven SMS** - Instant notifications triggered by events (booking created, confirmed, cancelled, rescheduled)
2. **Scheduled SMS** - Time-based notifications via Laravel Scheduler (24h/2h reminders, follow-ups)
3. **Webhook Integration** - Delivery status tracking via SMSAPI webhooks
4. **Template System** - Blade-based SMS templates with multi-language support

### Architecture Highlights

```
Event → AppServiceProvider → SmsService → SmsApiGateway → SMSAPI.pl
                                  ↓
                          SmsSend + SmsEvent records
                                  ↓
                          Webhook updates status
```

### Scheduler Jobs

| Job | Schedule | Purpose |
|-----|----------|---------|
| `SendReminderSmsJob` | Hourly | Send 24h and 2h appointment reminders |
| `SendFollowUpSmsJob` | Hourly | Send follow-up SMS 24h after completed appointments |
| `CleanupOldSmsLogsJob` | Daily 2:30 AM | GDPR compliance - cleanup 90-day old logs |

### Key Features

- **GDPR Compliant:** Consent tracking, suppression list, 90-day retention
- **Idempotent:** Duplicate prevention via message keys
- **Secure:** HMAC signature verification + IP whitelist for webhooks
- **Cost Control:** Daily/monthly spending limits with alert emails
- **Multi-language:** Template system supports pl/en (extensible)
- **Queue-based:** Jobs processed via Laravel queues with retries

### Files Overview

| File | Description |
|------|-------------|
| `architecture.md` | Complete system documentation with diagrams |
| `README.md` | This file - quick navigation |

### Database Tables

- `sms_templates` - SMS template storage
- `sms_sends` - Sent SMS log
- `sms_events` - Delivery status events
- `sms_suppressions` - Opt-out/blocked numbers

### Quick Commands

```bash
# Monitor SMS jobs
php artisan queue:work --queue=reminders,sms

# Check scheduler
php artisan schedule:list

# Test SMS sending
php artisan tinker
>>> app(\App\Services\Sms\SmsService::class)->sendTestSms('+48501234567');

# View logs
tail -f storage/logs/laravel.log | grep SMS
```

### Admin Panel Access

- **SMS Templates:** `/admin/sms-templates`
- **Sent SMS:** `/admin/sms-sends`
- **Events:** `/admin/sms-events`
- **Suppressions:** `/admin/sms-suppressions`
- **Settings:** `/admin/settings` (SMS tab)

---

## Getting Started

1. Read [architecture.md](./architecture.md) for complete system understanding
2. Check environment variables in `.env` (SMSAPI_* keys)
3. Verify scheduler is running: `php artisan schedule:work`
4. Ensure queue worker is active for `reminders` queue
5. Configure webhook URL in SMSAPI.pl dashboard

---

**Last Updated:** 2026-01-25
