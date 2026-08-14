---
paths:
  - "app/Notifications/**"
---

# Notification Rules

## Required Interfaces for Email Notifications

```php
use Illuminate\Contracts\Queue\ShouldQueue;

class AppointmentConfirmation extends Notification implements ShouldQueue
{
    use Queueable;
}
```

## ⚠️ `ShouldBeUnique` na notyfikacji NIC NIE ROBI (Laravel 12.60.2)

**Zweryfikowane 2026-08-12 w źródle frameworka i empirycznie. Deklaracja jest martwa — nie szkodzi, ale nie chroni.**

`NotificationSender::queueNotification()` robi `$this->bus->dispatch(SendQueuedNotifications…)` — bezpośredni `Bus::dispatch` na opakowaniu, które implementuje wyłącznie `ShouldQueue`. Lock zakłada **tylko** `PendingDispatch::__destruct()`, czyli ścieżka `Job::dispatch()`, której notyfikacje nigdy nie używają. `Bus\Dispatcher` w ogóle nie zna tego interfejsu, a `InteractsWithUniqueJobs` służy do ZWALNIANIA locka i sprawdza opakowanie, nie notyfikację.

**Dowód:** 5 odbiorców przez jedno `Notification::send($collection, …)` z `uniqueId()` niezależnym od odbiorcy → **5 dostarczeń**, na prawdziwej kolejce `sync` i prawdziwym cache.

**Co z tego wynika:** 23 klasy w `app/Notifications/` deklarują ten interfejs i żadna nic z niego nie ma. Te wysyłane mailem chroni deduplikacja po `message_key` w `EmailService` (patrz niżej) — **to jest jedyny działający mechanizm**. Notyfikacje niemailowe nie mają żadnego.

**Nie dodawaj `ShouldBeUnique` do nowych notyfikacji** — sugeruje ochronę, której nie ma. Deduplikację rób u źródła: atomowy guard w akcji (`whereNull(...)->update(...)`, flaga stanu) albo `message_key` w `EmailService`.

**Sprostowanie:** wcześniejsza wersja tej reguły opisywała „Incident 2026-06-30 — fan-out bug", w którym `ShouldBeUnique` miał sprawić, że z pięciu super-adminów maila dostaje jeden. **Ten incydent nigdy się nie wydarzył.** `OrganizationClosureRequestedNotification` powstał w commicie `b2d79f9` od razu bez tego interfejsu — nikt nigdy go z niczego nie zdejmował (`git log -S` po całej historii `app/Notifications/`: zero usunięć). Był to przewidywany, nie zaobserwowany tryb awarii, zapisany jako kronika incydentu — i przewidywanie było błędne, bo Laravel 12.60.2 wszedł miesiąc WCZEŚNIEJ (2026-05-23). Jeśli kiedyś realnie zaobserwujesz „tylko 1 z N dostał maila", przyczyna jest gdzie indziej — zacznij od tego, czy kolekcja odbiorców nie jest pusta albo jednoelementowa (`User::role('super-admin')->get()` na stacku tenanta zwraca **pustą** kolekcję).

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

## Idempotencja EmailService — nieudana wysyłka NIE jest stanem końcowym

`EmailService::sendFromTemplate()` deduplikuje po `message_key = md5(template:recipient:metadata)`.
Do 2026-08-03 zwracał istniejący rekord **niezależnie od statusu** — więc nieudana wysyłka blokowała
własne ponowienie na zawsze.

Najgorsze było sprzężenie z kolejką: serwis **rzuca** wyjątek po nieudanej wysyłce (celowo, żeby job
padł i Laravel go ponowił), ale **ponowienie trafiało w dedupe i kończyło się „sukcesem"** bez
wysłania czegokolwiek. Mechanizm odzyskiwania kolejki po cichu połykał maila — dlatego
`failed_jobs` było 0 przy nieudanych wysyłkach na produkcji.

Zasada, którą teraz egzekwuje `isRetryable()`:

| status | ponawiać? | dlaczego |
|---|---|---|
| `sent` | nie | stan końcowy, po to jest idempotencja |
| `bounced` | nie | werdykt odbiorcy; ponowienie psuje reputację nadawcy |
| `failed` | **tak** | zawiódł transport, odbiorca nie miał nic do powiedzenia |
| `pending` > 15 min | **tak** | wysyłka jest synchroniczna, więc to proces, który padł między utworzeniem wiersza a zapisem wyniku |

`email_sends.message_key` ma UNIQUE, więc ponowienie **aktualizuje istniejący wiersz**, nigdy nie
wstawia drugiego. Przy okazji czyści `error_message` i `sent_at`, żeby stary błąd nie przeżył
udanej próby.

**Pisząc nową ścieżkę wysyłki: nie kopiuj `if ($existing) return $existing;`.** Użyj
`isRetryable()`.

## `event()` w afterTransitionHooks() nie jest transakcyjny (pre-existing, 2026-08-12)

`OrderStatusStateMachine::transitionTo()` (kod vendora) zapisuje `status` + `state_histories`,
DOPIERO POTEM woła `afterTransitionHooks()` jako **osobny, późniejszy zapis** — nie w tej samej
transakcji DB. `event(new OrderConfirmed/OrderHandedOver/OrderReturned/OrderCancelled(...))`
w tych hookach kolejkuje job (`SendQueuedNotifications`) **synchronicznie** — push do brokera
(Redis) dzieje się inline, w tym samym stack call co hook. Gdy broker jest nieosiągalny, push
rzuca wyjątek, który wypada z `afterTransitionHooks()` prosto do `try/catch` w
`OrderResource`/`EditOrder` — admin widzi "Nie można zmienić statusu", mimo że status + kolumna
znacznika czasu **już się zapisały** chwilę wcześniej. Pre-existing dla `confirm`/`cancel`,
`feature/handover-return-emails` tylko podwaja powierzchnię (dwie kolejne dyspozycje eventów w
tym samym niezatransakcjonowanym miejscu), nie wprowadza problemu. Pełny opis:
`app/docs/features/cart-order-system.md` → "Known limitation — status write and timestamp write
are not atomic".

## Wstawianie markupu do html_body — `TrustedHtml` (2026-08-14)

`EmailTemplate::render()` HTML-escapuje KAŻDĄ podstawianą wartość domyślnie — `html_body` edytuje
tenant-admin, więc żadna zmienna nie może przemycić własnego znacznika. Jeśli Twoja notyfikacja
buduje fragment markupu do wstawienia (np. tabelę pozycji zamówienia), owiń go w
`App\Support\Email\TrustedHtml` w miejscu, gdzie string jest gotowy:

```php
use App\Support\Email\TrustedHtml;

return ['items_list_html' => new TrustedHtml($itemsListHtml)];
```

**Zaufanie jest per-WARTOŚĆ, nie per-nazwa zmiennej** — nie ma listy dozwolonych kluczy w
`EmailTemplate` do zsynchronizowania. Ten sam klucz przekazany jako zwykły string (np. przez inną
notyfikację) nadal jest escapowany. `renderSubject()`/`renderText()` zawsze `strip_tags()`ują
`TrustedHtml` — temat i wersja tekstowa nie mają legalnego zastosowania dla znaczników.

**Warunek bezpieczeństwa leży PRZED konstruktorem, nie w nim:** każde pole interpolowane w
środku owijanego stringa (np. nazwa usługi ustawiana przez tenant-admina) musi być
`htmlspecialchars()`-owane w Twoim kodzie PRZED sklejeniem z resztą markupu — `TrustedHtml` samo
w sobie niczego nie sanityzuje, tylko wyłącza escaping wynikowego stringa jako całości. Wzorzec:
`OrderPaidNotification::buildRentalVariables()`.

## Istniejące Notifications (reference)

**EmailServiceChannel (DB templates + tracking):**
- `AppointmentCreatedNotification` - potwierdzenie rezerwacji
- `AppointmentCancelledNotification` - anulowanie
- `AppointmentRescheduledNotification` - zmiana terminu
- `UserRegisteredNotification` - rejestracja
- `PasswordResetNotification` - reset hasła
- `AdminCreatedUserNotification` - setup hasła dla admin-created users
- `OrderPaidNotification`, `OrderConfirmedNotification`, `OrderHandedOverNotification`,
  `OrderReturnedNotification`, `OrderCancelledNotification` - cykl życia zamówienia (wynajem);
  patrz `app/docs/features/order-notifications.md`

**Standard MailChannel (MailMessage):**
- `DataExportCompletedNotification` - eksport danych RODO
