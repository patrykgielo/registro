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

## ⚠️ ShouldBeUnique + wielu odbiorców = fan-out bug (Incident 2026-06-30)

**NIE używaj `ShouldBeUnique` gdy ta sama notyfikacja idzie do WIELU odbiorców jednym `Notification::send($collection, …)`.**

Laravel dispatchuje **jeden `SendQueuedNotifications` job per notifiable**, a wszystkie współdzielą **jeden** klucz locka (`uniqueId()` jest notifiable-agnostyczny). Tylko pierwszy job zdobywa lock — reszta jest **cicho odrzucana**. Efekt: z N super-adminów mail dostaje **tylko 1**.

```php
// ❌ ŹLE — z 5 super-adminami tylko 1 dostanie mail
class OrganizationClosureRequestedNotification extends Notification implements ShouldQueue, ShouldBeUnique
{
    public function uniqueId(): string { return 'closure:'.$this->org->id; } // wspólny dla wszystkich odbiorców!
}
Notification::send(User::role('super-admin')->get(), new OrganizationClosureRequestedNotification($org));

// ✅ DOBRZE — bez ShouldBeUnique; deduplikację rób na poziomie AKCJI (atomowy guard)
class OrganizationClosureRequestedNotification extends Notification implements ShouldQueue { /* … */ }
```

**Zasada:** `ShouldBeUnique` jest dla notyfikacji **1-odbiorczych** powiązanych z encją (np. potwierdzenie do jednego klienta). Dla broadcastu do roli/zespołu — **wyłącz** i zapobiegaj duplikatom u źródła (atomowy `whereNull(...)->update(...)`, flaga stanu). Ref: `app/Notifications/OrganizationClosureRequestedNotification.php`.

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

## `notify()` wewnątrz `DB::transaction()` → `ShouldQueueAfterCommit` (Incident 2026-08)

**Problem:** `config/queue.php` ma `'after_commit' => false` na WSZYSTKICH połączeniach (projektowy default). Notyfikacja bez `ShouldQueueAfterCommit` wywołana wewnątrz `DB::transaction()` jest wysyłana **natychmiast** — zanim transakcja się zatwierdzi. Jeśli coś PO `notify()` w tej samej transakcji padnie (np. masowy `update()`, kolejny zapis), odbiorca dostaje maila o czymś, czego nie ma w bazie.

**Fix:** notyfikacja implementuje `Illuminate\Contracts\Queue\ShouldQueueAfterCommit`:

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

class SomeNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;
}
```

To odkłada dispatch joba `SendQueuedNotifications` do faktycznego commitu najbardziej zewnętrznej transakcji — a przy rollbacku job jest po cichu odrzucany. Zero zmian w miejscu wywołania `notify()`.

**Dlaczego to, a nie wyniesienie `notify()` poza transakcję:** jeśli transakcja jest szeroka (wiele locków, sprawdzeń dostępności, korekt finansowych) i treść powiadomienia zależy od danych mutowanych w jej środku, wyniesienie `notify()` wymaga odtwarzania stanu po commicie w każdym miejscu z osobna i nie chroni przyszłego `notify()` dodanego w tej samej transakcji później. `ShouldQueueAfterCommit` jest deklaratywne na klasie notyfikacji — chroni każde miejsce wywołania, obecne i przyszłe. Global `'after_commit' => true` w `config/queue.php` byłby zbyt szeroki — cichy zasięg na wszystko, co ktoś kiedykolwiek zakolejkuje, łącznie z rzeczami nigdy nie testowanymi pod tym kątem.

**Wzorzec odwrotny (nadal poprawny):** gdy transakcja jest wąska i nie zależy od niej treść powiadomienia — trzymaj `notify()` jawnie POZA `DB::transaction()`, tak jak `StartOrganizationOffboarding` (transakcja obejmuje wyłącznie zapis `lifecycle_state`, `notify()` jest już poza nią). Nie trzeba dodawać `ShouldQueueAfterCommit` tam, gdzie kod i tak już nie wysyła nic z wnętrza transakcji.

### Testowanie — `Notification::fake()` i `Queue::fake()` NIE dowodzą niczego tutaj

Oba fake'i **całkowicie omijają** ścieżkę `SendQueuedNotifications` → `Queue::push()` → `shouldDispatchAfterCommit()`:
- `NotificationFake::send()` woła `sendNow()` bezpośrednio, synchronicznie, niezależnie od `ShouldQueue`/`ShouldQueueAfterCommit` i stanu transakcji.
- `QueueFake::push()` zapisuje joba do wewnętrznej tablicy bez sprawdzania `shouldDispatchAfterCommit()`.

Test z `Notification::fake()` + `assertNothingSent()` po wymuszonym rollbacku przejdzie (lub padnie) **identycznie z fixem i bez niego** — nie ma mocy dowodowej.

**Właściwy sposób:** nie fake'uj Notification/Queue. Zamockuj prawdziwą granicę systemową (np. `EmailGatewayInterface` albo, gdy w teście jest realnie rozwiązany tenant i seed'owane szablony są global/NULL-org — patrz `email_templates` w `MEMORY.md` — jeden poziom wyżej: `EmailService`), i:
1. **Negatywny test:** owiń wywołanie w zewnętrzną `DB::transaction()`, rzuć wyjątek PO wywołaniu serwisu → assert, że mock NIGDY nie został wywołany (`shouldNotReceive`) + `assertDatabaseMissing`.
2. **Pozytywny test (kontrola):** to samo bez wymuszonego rollbacku → assert, że mock BYŁ wywołany dokładnie raz.
3. **Zweryfikuj mutacją:** cofnij `ShouldQueueAfterCommit`, potwierdź że negatywny test PADA (mock wywołany 1 raz zamiast 0) — inaczej test nie ma mocy dowodowej.

**RefreshDatabase + `DB::transaction()` w teście — działa poprawnie, ale nieoczywiście:** `RefreshDatabase` podmienia `db.transactions` na `Illuminate\Foundation\Testing\DatabaseTransactionsManager` (nie bazową klasę), która traktuje poziom **1** (własną owijającą transakcję testu) jako efektywny root — `afterCommitCallbacksShouldBeExecuted($level) { return $level === 1; }`. Dzięki temu `DB::transaction()` wywołane wewnątrz testu poprawnie odpala `ShouldQueueAfterCommit` callbacki od razu po swoim (pozornie tylko nested/SAVEPOINT) commicie — nie trzeba żadnego specjalnego triku. Bez tego callbacki nigdy by nie odpaliły podczas testu (znane, udokumentowane ograniczenie Laravela poza kontekstem testowym — zob. laravel/framework#35857, #48451, #48472). Ref: `RentalExtensionServiceTest::test_request_extension_notification_does_not_leave_the_app_when_the_wrapping_transaction_rolls_back()` i sąsiednie testy.

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
