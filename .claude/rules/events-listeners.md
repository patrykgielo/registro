---
paths:
  - "app/Events/**"
  - "app/Listeners/**"
---

# Events & Listeners Rules

## Event Naming Convention

```
{Entity}{Action}
```

- `AppointmentCreated`
- `AppointmentCancelled`
- `UserRegistered`
- `BookingConfirmed`

## Listener Naming Convention

```
{Action}{Handler} lub {What}Listener
```

- `AssignCustomerRole`
- `SendConfirmationEmail`
- `UpdateBookingStats`

## Event Structure

```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppointmentCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Appointment $appointment
    ) {}
}
```

## Required Traits

```php
use Dispatchable;      // Pozwala na AppointmentCreated::dispatch($appointment)
use SerializesModels;  // Serializuje modele dla queue
```

## Listener Structure

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AppointmentCreated;
use App\Notifications\AppointmentConfirmation;

class SendAppointmentConfirmation
{
    public function handle(AppointmentCreated $event): void
    {
        $event->appointment->customer->notify(
            new AppointmentConfirmation($event->appointment)
        );
    }
}
```

## Async Listeners (Queue)

```php
use Illuminate\Contracts\Queue\ShouldQueue;

class SendAppointmentConfirmation implements ShouldQueue
{
    public $queue = 'emails';

    public function handle(AppointmentCreated $event): void
    {
        // Long-running operation
    }
}
```

## Event Registration

**Opcja 1: Automatyczne discovery (EventServiceProvider):**
```php
// Laravel automatycznie wykrywa Events i Listeners
// jeśli są w app/Events/ i app/Listeners/
```

**Opcja 2: Manualna rejestracja:**
```php
// EventServiceProvider
protected $listen = [
    AppointmentCreated::class => [
        SendAppointmentConfirmation::class,
        UpdateBookingStats::class,
    ],
];
```

## Dispatching Events

```php
// Z modelu (via $dispatchesEvents)
protected $dispatchesEvents = [
    'created' => AppointmentCreated::class,
];

// Manualnie
AppointmentCreated::dispatch($appointment);

// Lub
event(new AppointmentCreated($appointment));
```

## DocBlock Documentation

```php
/**
 * Event fired when a new appointment is created
 *
 * Listeners:
 * - SendAppointmentConfirmation (queued)
 * - UpdateBookingStats
 * - NotifyAdminOfNewBooking
 */
class AppointmentCreated
```

## Return Values in Listeners

```php
// Zwróć false aby zatrzymać propagację do innych listeners
public function handle(AppointmentCreated $event): bool
{
    if ($this->shouldStop($event)) {
        return false; // Zatrzymaj kolejne listeners
    }

    // Process...
    return true;
}
```

## Testing Events

```php
Event::fake();

// Wykonaj akcję
$this->service->createAppointment($data);

// Asercje
Event::assertDispatched(AppointmentCreated::class);
Event::assertDispatched(function (AppointmentCreated $event) use ($appointment) {
    return $event->appointment->id === $appointment->id;
});
```

## Istniejące Events (reference)

- `AppointmentCreated` - nowa rezerwacja
- `AppointmentCancelled` - anulowanie
- `UserRegistered` - rejestracja użytkownika
