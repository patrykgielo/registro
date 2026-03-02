# Scheduled Jobs - Automated Emails

Cron jobs wysyłające automated emails (reminders, follow-ups, digests).

## Jobs Overview

| Job | Schedule | Purpose |
|-----|----------|---------|
| `SendReminderEmailsJob` | Hourly | Wysyła przypomnienia 24h i 2h przed wizytą |
| `SendFollowUpEmailsJob` | Hourly | Wysyła follow-up 24h po ukończonej wizycie |
| `SendAdminDigestJob` | Daily 8:00 AM | Wysyła raport dnia do adminów |
| `CleanupOldEmailLogsJob` | Daily 2:00 AM | Usuwa logi starsze niż 90 dni (GDPR) |

## Configuration (routes/console.php)

```php
use App\Jobs\Email\{SendReminderEmailsJob, SendFollowUpEmailsJob, SendAdminDigestJob, CleanupOldEmailLogsJob};

Schedule::job(new SendReminderEmailsJob)->hourly()->withoutOverlapping();
Schedule::job(new SendFollowUpEmailsJob)->hourly()->withoutOverlapping();
Schedule::job(new SendAdminDigestJob)->dailyAt('08:00')->withoutOverlapping();
Schedule::job(new CleanupOldEmailLogsJob)->dailyAt('02:00')->withoutOverlapping();
```

## SendReminderEmailsJob

**Logika:**
1. Znajdź wszystkie wizyty jutro (24h)
2. Wysyłaj email jeśli `reminder_24h_sent_at` IS NULL
3. Ustaw `reminder_24h_sent_at = now()`
4. Repeat dla 2h przed wizytą

**Code:**

```php
$appointments24h = Appointment::whereDate('scheduled_at', now()->addDay())
    ->whereNull('reminder_24h_sent_at')
    ->get();

foreach ($appointments24h as $appointment) {
    event(new AppointmentReminder24h($appointment));
    $appointment->update(['reminder_24h_sent_at' => now()]);
}
```

## SendFollowUpEmailsJob

**Logika:**
1. Znajdź ukończone wizyty sprzed 24h
2. Wysyłaj feedback request jeśli `followup_sent_at` IS NULL

```php
$appointments = Appointment::where('status', 'completed')
    ->whereDate('scheduled_at', now()->subDay())
    ->whereNull('followup_sent_at')
    ->get();

foreach ($appointments as $appointment) {
    event(new AppointmentFollowUp($appointment));
    $appointment->update(['followup_sent_at' => now()]);
}
```

## SendAdminDigestJob

**Logika:**
1. Zlicz statystyki z wczoraj
2. Wyślij email do wszystkich adminów

```php
$stats = [
    'date' => now()->subDay()->format('Y-m-d'),
    'total_appointments' => Appointment::whereDate('created_at', now()->subDay())->count(),
    'pending' => Appointment::where('status', 'pending')->count(),
    'completed' => Appointment::where('status', 'completed')->count(),
];

$admins = User::role('admin')->get();

foreach ($admins as $admin) {
    $emailService->sendFromTemplate(
        templateKey: 'admin-daily-digest',
        language: $admin->preferred_language ?? 'pl',
        recipient: $admin->email,
        data: $stats
    );
}
```

## CleanupOldEmailLogsJob

**GDPR Compliance:** Usuwa logi starsze niż 90 dni.

```php
EmailSend::where('sent_at', '<', now()->subDays(90))->delete();
EmailEvent::where('occurred_at', '<', now()->subDays(90))->delete();
```

**Note:** `email_suppressions` NIE są usuwane (unsubscribe compliance).

## Testing Jobs

```bash
# Manually trigger job
php artisan tinker
>>> SendReminderEmailsJob::dispatch();

# Check scheduled jobs
php artisan schedule:list

# Run scheduler once
php artisan schedule:run --verbose

# Check Horizon
https://registro.local:8444/horizon
```

## Monitoring

```bash
# Check job status in Horizon
https://registro.local:8444/horizon/jobs

# Check logs
tail -f storage/logs/laravel.log | grep Job
```

## Next Steps

- [Troubleshooting](./troubleshooting.md) - Common scheduler issues
- [Architecture](./architecture.md) - Queue system design
