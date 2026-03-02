# Email Notifications & Events

Event-driven architecture - jak domain events triggerują wysyłkę emaili.

## Domain Events (9 total)

| Event | Triggered By | Sends Email |
|-------|--------------|-------------|
| `UserRegistered` | User::created() | user-registered template |
| `PasswordResetRequested` | Password reset request | password-reset template |
| `AppointmentCreated` | Appointment::created() | appointment-created template |
| `AppointmentRescheduled` | Appointment updated | appointment-rescheduled template |
| `AppointmentCancelled` | Appointment::deleted() | appointment-cancelled template |
| `AppointmentReminder24h` | SendReminderEmailsJob | appointment-reminder-24h template |
| `AppointmentReminder2h` | SendReminderEmailsJob | appointment-reminder-2h template |
| `AppointmentFollowUp` | SendFollowUpEmailsJob | appointment-followup template |
| `EmailDeliveryFailed` | SMTP error | Logs error, doesn't send email |

## Notification Classes (8 total)

All implement `ShouldQueue` + `ShouldBeUnique`.

**Example:**

```php
class AppointmentCreatedNotification extends Notification implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function __construct(public Appointment $appointment) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $emailService = app(EmailService::class);

        return $emailService->sendFromTemplate(
            templateKey: 'appointment-created',
            language: $notifiable->preferred_language ?? 'pl',
            recipient: $notifiable->email,
            data: [
                'customer_name' => $notifiable->full_name,
                'appointment_date' => $this->appointment->scheduled_at->format('Y-m-d'),
                'appointment_time' => $this->appointment->scheduled_at->format('H:i'),
                'service_name' => $this->appointment->service->name,
                'location_address' => $this->appointment->formatted_location,
            ],
            metadata: ['appointment_id' => $this->appointment->id]
        );
    }

    public function uniqueId(): string
    {
        return "appointment-created-{$this->appointment->id}";
    }
}
```

## Event → Notification Flow

```
Model/Controller action
    ↓
event(new AppointmentCreated($appointment))
    ↓
EventServiceProvider listener
    ↓
$user->notify(new AppointmentCreatedNotification($appointment))
    ↓
Queue system (Redis)
    ↓
NotificationClass::toMail()
    ↓
EmailService::sendFromTemplate()
    ↓
SmtpMailer::send()
    ↓
EmailSend created (status: sent/failed)
```

## Testing Notifications

```php
use Illuminate\Support\Facades\Notification;

Notification::fake();

// Trigger action
$appointment = Appointment::factory()->create();

// Assert notification sent
Notification::assertSentTo(
    $appointment->customer,
    AppointmentCreatedNotification::class,
    function ($notification) use ($appointment) {
        return $notification->appointment->id === $appointment->id;
    }
);
```

## Next Steps

- [Scheduled Jobs](./scheduled-jobs.md) - Automated reminders and digests
- [Architecture](./architecture.md) - Detailed system design
