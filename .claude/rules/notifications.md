---
paths:
  - "app/Notifications/**"
---

# Notification Rules

## Required Interfaces for Email Notifications

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class AppointmentConfirmation extends Notification implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
}
```

## Uniqueness (CRITICAL - prevent duplicates)

```php
/**
 * Unique identifier for deduplication
 */
public function uniqueId(): string
{
    return $this->appointment->id . '_confirmation';
}

/**
 * How long the uniqueness lock should last
 */
public function uniqueFor(): int
{
    return 3600; // 1 hour
}
```

## Queue Configuration

```php
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AppointmentConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Appointment $appointment
    ) {
        // Route to specific queue
        $this->onQueue('emails');
    }
}
```

## Constructor Pattern

```php
public function __construct(
    public Appointment $appointment,
    protected ?string $recipientType = 'customer'
) {
    $this->onQueue('emails');
}
```

## EmailService Integration (EmailServiceChannel)

Notifications that use `EmailService::sendFromTemplate()` MUST use the custom
`EmailServiceChannel` instead of Laravel's built-in `'mail'` channel.
This prevents double-sending (EmailService sends + MailChannel sends).

```php
use App\Channels\EmailServiceChannel;
use App\Services\Email\EmailService;

public function via(object $notifiable): array
{
    return [EmailServiceChannel::class];
}

public function toEmailService(object $notifiable, EmailService $emailService): void
{
    $emailService->sendFromTemplate(
        TemplateKey::APPOINTMENT_CREATED->value,
        $notifiable->preferred_language ?? 'pl',
        $notifiable->email,
        ['customer_name' => $notifiable->name],
        ['notification' => 'AppointmentCreatedNotification']
    );
}
```

**When to use which channel:**
- `EmailServiceChannel::class` — notifications with DB templates, tracking, idempotency
- `'mail'` — simple one-off notifications (e.g., DataExportCompletedNotification)

## Multi-channel Support

```php
public function via(object $notifiable): array
{
    return [EmailServiceChannel::class];
}
```

## Naming Convention

- `{Entity}{Action}` - AppointmentConfirmation, BookingCancelled
- Customer variant: `AppointmentConfirmationCustomer`
- Admin variant: `AppointmentConfirmationAdmin`

## DocBlock Documentation

```php
/**
 * Notification sent to customer after successful booking
 *
 * Channels: email, sms (if consent)
 * Queue: emails
 * Unique for: 1 hour per appointment
 */
class AppointmentConfirmation extends Notification
```

## Strict Types

```php
<?php

declare(strict_types=1);

namespace App\Notifications;
```

## Testing Notifications

```php
// W testach
Notification::fake();

// Wykonaj akcję
$this->service->createAppointment($data);

// Asercje
Notification::assertSentTo(
    $user,
    AppointmentConfirmation::class
);
```

## Istniejące Notifications (reference)

**EmailServiceChannel (DB templates + tracking):**
- `AppointmentCreatedNotification` - potwierdzenie rezerwacji
- `AppointmentCancelledNotification` - anulowanie
- `AppointmentRescheduledNotification` - zmiana terminu
- `UserRegisteredNotification` - rejestracja
- `PasswordResetNotification` - reset hasła
- `AdminCreatedUserNotification` - setup hasła dla admin-created users

**Standard MailChannel (MailMessage):**
- `DataExportCompletedNotification` - eksport danych RODO
