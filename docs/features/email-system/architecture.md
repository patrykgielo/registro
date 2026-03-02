# Email System Architecture

Szczegółowy opis architektury systemu emailowego - serwisy, modele, wzorce projektowe.

## Core Components

### 1. Email Gateway (Interface + Implementation)

**EmailGatewayInterface** - Abstrakcja dla wysyłania emaili

```php
namespace App\Services\Email;

interface EmailGatewayInterface
{
    public function send(string $to, string $subject, string $html, ?string $text = null): bool;
    public function testConnection(): bool;
}
```

**SmtpMailer** - Implementacja SMTP z dynamiczną konfiguracją

```php
namespace App\Services\Email;

use App\Support\Settings\SettingsManager;
use Illuminate\Support\Facades\Mail;

class SmtpMailer implements EmailGatewayInterface
{
    public function __construct(private SettingsManager $settings) {}

    public function send(string $to, string $subject, string $html, ?string $text = null): bool
    {
        // Konfiguracja SMTP z bazy danych (settings table)
        config([
            'mail.mailers.smtp.host' => $this->settings->get('email.smtp_host'),
            'mail.mailers.smtp.port' => $this->settings->get('email.smtp_port'),
            // ...
        ]);

        // Wysyłka przez Laravel Mail facade
        Mail::raw($html, function ($message) use ($to, $subject) {
            $message->to($to)->subject($subject)->html($html);
        });

        return true;
    }
}
```

**Zalety tego podejścia:**
- ✅ Łatwa podmiana providera (SMTP → SendGrid → SES)
- ✅ Konfiguracja z bazy danych (nie wymaga redeploy)
- ✅ Testowalne (można mockować interface)

### 2. EmailService - Core Business Logic

**Odpowiedzialności:**
- Renderowanie szablonów Blade z danymi
- Idempotencja (zapobiega duplikatom)
- Sprawdzanie suppression list
- Logowanie do `email_sends`
- Obsługa błędów SMTP

**Metoda główna:**

```php
public function sendFromTemplate(
    string $templateKey,    // np. 'appointment-created'
    string $language,       // 'pl' lub 'en'
    string $recipient,      // email odbiorcy
    array $data,            // zmienne do szablonu
    array $metadata = []    // tracking data (appointment_id, user_id)
): EmailSend
```

**Przepływ działania:**

```
1. Sprawdź suppression list
   ↓
2. Wczytaj szablon z bazy (email_templates)
   ↓
3. Oblicz message_key (MD5 z metadata + recipient)
   ↓
4. Sprawdź duplikację (czy już wysłano z tym kluczem)
   ↓
5. Renderuj Blade template z danymi
   ↓
6. Wyślij przez EmailGateway
   ↓
7. Zapisz EmailSend (status: sent/failed)
   ↓
8. Zapisz EmailEvent (event_type: sent)
```

**Idempotencja - message_key:**

```php
protected function generateMessageKey(array $metadata, string $recipient): string
{
    return md5(json_encode(array_merge($metadata, ['recipient' => $recipient])));
}
```

Przykład:
- Metadata: `['appointment_id' => 123]`
- Recipient: `customer@example.com`
- message_key: `5f4dcc3b5aa765d61d8327deb882cf99`

Jeśli `message_key` już istnieje w `email_sends` → zwróć istniejący rekord, **nie wysyłaj ponownie**.

### 3. Models

#### EmailTemplate

**Pola:**
```php
id, key, language, subject, html_body, text_body, variables, blade_path, active
```

**Relacje:**
```php
hasMany(EmailSend)
```

**Metody:**
```php
render(array $data): string         // Renderuje html_body z Blade
renderText(array $data): ?string    // Renderuje text_body
scopeActive()                       // Tylko aktywne szablony
scopeForKey(string $key)            // Filtruj po key
scopeForLanguage(string $lang)      // Filtruj po języku
```

**Przykład użycia:**

```php
$template = EmailTemplate::forKey('appointment-created')
    ->forLanguage('pl')
    ->active()
    ->firstOrFail();

$html = $template->render([
    'customer_name' => 'Jan Kowalski',
    'appointment_date' => '2025-11-15',
]);
```

#### EmailSend

**Pola:**
```php
id, template_key, language, recipient_email, subject, html_body, text_body,
status, sent_at, message_key, metadata, error_message
```

**Statusy:**
- `sent` - Wysłano pomyślnie
- `failed` - Błąd SMTP
- `bounced` - Odrzucono przez serwer
- `pending` - W kolejce (nieużywane obecnie)

**Relacje:**
```php
belongsTo(EmailTemplate)
hasMany(EmailEvent)
```

**Scope'y:**
```php
scopeSent()                         // status = 'sent'
scopeFailed()                       // status = 'failed'
scopeForTemplate(string $key)       // template_key = $key
scopeBetweenDates($start, $end)     // sent_at BETWEEN ...
```

#### EmailEvent

**Pola:**
```php
id, email_send_id, event_type, occurred_at, metadata
```

**Event Types:**
- `sent` - Email wysłany
- `delivered` - Dostarczono do skrzynki
- `bounced` - Odbił się (hard/soft bounce)
- `complained` - Oznaczono jako spam
- `opened` - Otwarty (wymaga tracking pixel)
- `clicked` - Kliknięto link (wymaga tracking URLs)

**Relacje:**
```php
belongsTo(EmailSend)
```

**Notatka:** Obecnie system nie trackuje `opened`/`clicked` (brak pixel/URL tracking). Funkcjonalność może być dodana w przyszłości.

#### EmailSuppression

**Pola:**
```php
id, email, reason, notes, suppressed_at
```

**Reasons:**
- `bounced` - Hard bounce
- `complained` - Spam complaint
- `unsubscribed` - Użytkownik wypisał się
- `manual` - Ręcznie dodany przez admina

**Metody:**
```php
static isSuppressed(string $email): bool
static suppress(string $email, string $reason, ?string $notes = null): void
static unsuppress(string $email): void
```

**Przykład:**

```php
// Sprawdź przed wysłaniem
if (EmailSuppression::isSuppressed('spam@example.com')) {
    Log::warning('Email blocked by suppression list');
    return;
}

// Dodaj do suppression list
EmailSuppression::suppress(
    'bounced@example.com',
    'bounced',
    'Hard bounce: mailbox does not exist'
);
```

## Service Layer Architecture

```
Controller/Job/Event
    ↓
EmailService
    ↓
+-- EmailGatewayInterface (SmtpMailer)
+-- EmailTemplate (Model)
+-- EmailSend (Model)
+-- EmailEvent (Model)
+-- EmailSuppression (Model)
```

**Dependency Injection:**

```php
// W kontrolerze
public function __construct(
    private EmailService $emailService
) {}

// Lub via app() helper
$emailService = app(EmailService::class);
```

**Service Provider Registration** (`AppServiceProvider.php`):

```php
$this->app->singleton(EmailGatewayInterface::class, SmtpMailer::class);
$this->app->singleton(EmailService::class);
```

## Event-Driven Architecture

### Domain Events (9 total)

1. `UserRegistered` - Po utworzeniu konta
2. `PasswordResetRequested` - Po żądaniu resetu hasła
3. `AppointmentCreated` - Po zarezerwowaniu wizyty
4. `AppointmentRescheduled` - Po zmianie terminu
5. `AppointmentCancelled` - Po anulowaniu
6. `AppointmentReminder24h` - 24h przed wizytą (triggered by job)
7. `AppointmentReminder2h` - 2h przed wizytą (triggered by job)
8. `AppointmentFollowUp` - 24h po wizycie (triggered by job)
9. `EmailDeliveryFailed` - Po błędzie wysyłki

### Event Listeners → Notifications

**Event** wywoływane przez modele lub jobs → automatycznie triggerują **Notification**.

Przykład:

```php
// W App\Models\User::created()
event(new UserRegistered($user));

// EventServiceProvider.php
UserRegistered::class => [
    SendUserRegisteredNotification::class,
],

// SendUserRegisteredNotification (listener)
public function handle(UserRegistered $event): void
{
    $event->user->notify(new UserRegisteredNotification($event->user));
}
```

### Notification Classes (8 total)

Wszystkie implementują `ShouldQueue` i `ShouldBeUnique`:

```php
class AppointmentCreatedNotification extends Notification implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function via($notifiable): array
    {
        return ['mail']; // Tylko email channel
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
                // ...
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

**Zalety:**
- ✅ Automatyczna obsługa kolejki (Redis)
- ✅ Retry z exponential backoff (3 próby)
- ✅ Unique job ID zapobiega duplikatom
- ✅ Łatwe testowanie (`Notification::fake()`)

## Design Patterns

### 1. Strategy Pattern - EmailGateway

```php
interface EmailGatewayInterface { ... }

class SmtpMailer implements EmailGatewayInterface { ... }
class SendGridMailer implements EmailGatewayInterface { ... }
class SesMailer implements EmailGatewayInterface { ... }
```

Zamiana providera = zmiana bindingu w AppServiceProvider.

### 2. Repository Pattern (via Eloquent)

Modele Eloquent działają jako repozytoria:

```php
// Zamiast tworzyć EmailTemplateRepository
EmailTemplate::forKey('user-registered')->forLanguage('pl')->first();

// Query builder = flexibilny repository
EmailSend::sent()->betweenDates($start, $end)->get();
```

### 3. Event Sourcing (partial)

`EmailEvent` table loguje wszystkie zdarzenia dla auditu:

```php
SELECT * FROM email_events WHERE email_send_id = 123 ORDER BY occurred_at;
// Timeline: sent → delivered → opened → clicked
```

### 4. Singleton Pattern - Services

```php
$this->app->singleton(EmailService::class);
```

Gwarantuje jedną instancję EmailService w całym request lifecycle.

## Security Considerations

### 1. SMTP Credentials
- Przechowywane w `settings` table (encrypted w bazie - opcjonalnie)
- Nigdy nie commitowane do Git
- App Password zamiast zwykłego hasła Gmail

### 2. Template Injection Prevention

```blade
<!-- Blade auto-escapes HTML -->
{{ $customer_name }}  <!-- Safe -->

<!-- Raw HTML only for trusted content -->
{!! $html_body !!}    <!-- Use with caution -->
```

### 3. Rate Limiting (Gmail)

- Free tier: 500 emails/day
- Google Workspace: 2,000 emails/day
- Implementacja: Laravel RateLimiter (opcjonalnie)

### 4. Suppression List Compliance

GDPR/CAN-SPAM wymaga:
- ✅ Unsubscribe link w każdym emailu (TODO)
- ✅ Suppression list (zaimplementowany)
- ✅ Bounce handling (partial - manual)

## Testing Strategy

### Unit Tests

```php
// Test EmailService::sendFromTemplate()
$emailService = new EmailService(
    gateway: Mockery::mock(EmailGatewayInterface::class),
    settings: Mockery::mock(SettingsManager::class)
);
```

### Feature Tests

```php
Notification::fake();

// Create appointment
$appointment = Appointment::factory()->create();

// Assert notification sent
Notification::assertSentTo(
    $appointment->customer,
    AppointmentCreatedNotification::class
);
```

### Integration Tests

```bash
# Test full flow
php artisan email:test-flow --all
```

## Performance Considerations

### 1. Caching Templates

EmailTemplate nie jest cachowany (zmienia się rzadko, ale może być edytowany przez adminów).

Opcjonalne:
```php
Cache::remember("email-template-{$key}-{$language}", 3600, fn() =>
    EmailTemplate::forKey($key)->forLanguage($language)->first()
);
```

### 2. Queue Workers

```bash
# Development: pojedynczy worker
php artisan queue:work redis --queue=emails

# Production: Horizon z auto-scalingiem (3-10 workers)
php artisan horizon
```

### 3. Database Indexes

```sql
-- email_templates
INDEX (key, language, active)

-- email_sends
INDEX (message_key) -- Unique constraint dla idempotencji
INDEX (sent_at)     -- Dla queries z datami
INDEX (status)      -- Filtrowanie po statusie

-- email_events
INDEX (email_send_id, occurred_at)

-- email_suppressions
UNIQUE (email)
```

## Next Steps

- **[Templates Management](./templates.md)** - Jak zarządzać szablonami
- **[Notifications](./notifications.md)** - Szczegóły eventów i notyfikacji
- **[Scheduled Jobs](./scheduled-jobs.md)** - Automated emails (reminders, digests)
- **[Troubleshooting](./troubleshooting.md)** - Rozwiązywanie problemów
