# Email Templates Management

Zarządzanie szablonami email - tworzenie, edycja, zmienne, seeding.

## Overview

Szablony przechowywane w bazie danych (`email_templates` table) z możliwością edycji przez admina w panelu Filament.

**18 szablonów:**
- 9 typów × 2 języki (PL + EN)
- Renderowane przez Blade engine
- Zmienne w formacie `{{ $variable_name }}`

## Template Structure

### Database Schema

```sql
CREATE TABLE email_templates (
    id BIGINT PRIMARY KEY,
    key VARCHAR(255),              -- np. 'appointment-created'
    language VARCHAR(10),          -- 'pl' lub 'en'
    subject VARCHAR(255),          -- Tytuł emaila
    html_body TEXT,                -- Treść HTML (Blade syntax)
    text_body TEXT NULL,           -- Wersja plain text (opcjonalna)
    variables JSON NULL,           -- Lista dostępnych zmiennych (reference)
    blade_path VARCHAR(255) NULL,  -- Fallback Blade view (opcjonalnie)
    active BOOLEAN DEFAULT TRUE,
    created_at, updated_at,
    UNIQUE(key, language)
);
```

### Template Keys

| Key                        | Trigger Event                        |
|----------------------------|--------------------------------------|
| `user-registered`          | User::created()                      |
| `password-reset`           | PasswordResetRequested               |
| `appointment-created`      | Appointment::created()               |
| `appointment-rescheduled`  | Appointment updated (date/time)      |
| `appointment-cancelled`    | Appointment::deleted()               |
| `appointment-reminder-24h` | SendReminderEmailsJob (24h before)   |
| `appointment-reminder-2h`  | SendReminderEmailsJob (2h before)    |
| `appointment-followup`     | SendFollowUpEmailsJob (24h after)    |
| `admin-daily-digest`       | SendAdminDigestJob (daily 8:00 AM)   |

## Template Variables

### Common Variables (wszystkie szablony)

```php
$app_name         // Nazwa aplikacji (z config)
$app_url          // URL aplikacji
$contact_email    // Email kontaktowy
$contact_phone    // Telefon kontaktowy
$current_year     // Rok (footer copyright)
```

### User-Specific

```php
$user_name        // Imię i nazwisko użytkownika
$user_email       // Email użytkownika
```

### Appointment-Specific

```php
$customer_name          // Imię klienta
$appointment_date       // Data (Y-m-d)
$appointment_time       // Godzina (H:i)
$service_name           // Nazwa usługi
$location_address       // Adres lokalizacji
$cancellation_reason    // Powód anulowania (tylko cancelled)
$feedback_url           // Link do feedback (tylko followup)
```

### Password Reset

```php
$reset_url        // Link do resetu hasła
$expires_in       // Ważność linku (np. "60 minutes")
```

### Admin Digest

```php
$date                       // Data raportu
$total_appointments         // Wszystkie wizyty
$pending_appointments       // Oczekujące
$completed_appointments     // Ukończone
```

## Blade Syntax in Templates

### Basic Variables

```blade
<h1>Hello {{ $customer_name }}</h1>
<p>Your appointment is on {{ $appointment_date }} at {{ $appointment_time }}.</p>
```

**Auto-escaping:** Blade automatycznie escapuje HTML w `{{ }}` (bezpieczne).

### Conditional Content

```blade
@if($cancellation_reason)
    <p><strong>Reason:</strong> {{ $cancellation_reason }}</p>
@endif

@isset($feedback_url)
    <a href="{{ $feedback_url }}">Leave feedback</a>
@endisset
```

### Loops (avoid in email templates)

```blade
@foreach($services as $service)
    <li>{{ $service }}</li>
@endforeach
```

**Uwaga:** Loops w email templates mogą powodować problemy z renderingiem. Lepiej przekazać sformatowany HTML jako zmienną.

### Formatting Dates

```blade
{{ \Carbon\Carbon::parse($appointment_date)->format('d.m.Y') }}
{{ \Carbon\Carbon::parse($appointment_date)->translatedFormat('l, j F Y') }}
```

## Creating Templates

### Via Seeder (Recommended)

**File:** `database/seeders/EmailTemplateSeeder.php`

```php
EmailTemplate::create([
    'key' => 'appointment-created',
    'language' => 'pl',
    'subject' => 'Potwierdzenie rezerwacji - {{ $service_name }}',
    'html_body' => '<h1>Dziękujemy, {{ $customer_name }}!</h1>
        <p>Twoja wizyta została zarezerwowana na {{ $appointment_date }} o {{ $appointment_time }}.</p>
        <p><strong>Usługa:</strong> {{ $service_name }}</p>
        <p><strong>Lokalizacja:</strong> {{ $location_address }}</p>',
    'text_body' => 'Dziękujemy, {{ $customer_name }}!
        Twoja wizyta została zarezerwowana na {{ $appointment_date }} o {{ $appointment_time }}.
        Usługa: {{ $service_name }}
        Lokalizacja: {{ $location_address }}',
    'variables' => [
        'customer_name',
        'appointment_date',
        'appointment_time',
        'service_name',
        'location_address',
        'app_name',
        'contact_email',
    ],
    'active' => true,
]);
```

**Run seeder:**

```bash
docker compose exec app php artisan db:seed --class=EmailTemplateSeeder
```

### Via Filament Admin Panel

1. Otwórz: `https://registro.local:8444/admin/email-templates`
2. Kliknij **"Create"**
3. Wypełnij formularz:
   - **Template Key:** wybierz z dropdown (9 opcji)
   - **Language:** PL lub EN
   - **Subject Line:** tytuł emaila (może zawierać `{{ }}`)
   - **HTML Body:** treść HTML z Blade syntax
   - **Plain Text Body:** wersja tekstowa (opcjonalna)
   - **Available Variables:** lista zmiennych (tylko reference)
   - **Blade Path:** fallback view (opcjonalnie)
   - **Active:** czy template aktywny
4. Kliknij **"Create"**

### Bulk Import (CSV/JSON)

Nie zaimplementowane. Użyj seedera dla bulk operations.

## Editing Templates

### In Filament Admin Panel

1. Otwórz: `https://registro.local:8444/admin/email-templates`
2. Kliknij **"Edit"** przy wybranym template
3. Modyfikuj pola
4. Kliknij **"Save changes"**
5. **Test Send** - wyślij testowego maila przed aktywacją

### Via Tinker (Development)

```bash
php artisan tinker

>>> $template = EmailTemplate::forKey('appointment-created')->forLanguage('pl')->first();
>>> $template->subject = 'Nowy tytuł';
>>> $template->save();
```

### Version Control

Szablony w bazie NIE są w Git. Aby zachować w repo:

```bash
# Export to SQL
docker compose exec mysql mysqldump -u registro -ppassword registro email_templates > email_templates_backup.sql

# Import from SQL
docker compose exec -T mysql mysql -u registro -ppassword registro < email_templates_backup.sql
```

**Rekomendacja:** Używaj seeder dla production templates. Admini mogą edytować kopie w bazie, ale seeder zawsze ma "source of truth".

## Testing Templates

### Preview in Filament (Currently Disabled)

**Status:** ❌ Temporarily disabled due to Livewire bug

Preview button został wyłączony (linie 178-192 w `EmailTemplateResource.php`).

**Workaround:** Użyj **Test Send** action.

### Test Send

1. Otwórz: `https://registro.local:8444/admin/email-templates`
2. Kliknij **"Test Send"** przy wybranym template
3. Wpisz email odbiorcy (np. `patryk3580@gmail.com`)
4. System wysyła email z przykładowymi danymi (zdefiniowanymi w `getExampleData()`)
5. Sprawdź skrzynkę (także spam folder)

**Example Data** (z `EmailTemplateResource.php`):

```php
protected static function getExampleData(EmailTemplate $template): array
{
    $data = [
        'app_name' => config('app.name', 'Registro'),
        'app_url' => config('app.url'),
        'user_name' => 'Jan Kowalski',
        'user_email' => 'jan.kowalski@example.com',
        'current_year' => date('Y'),
    ];

    $specificData = match ($template->key) {
        'appointment-created' => [
            'appointment_date' => now()->addDays(2)->format('Y-m-d'),
            'appointment_time' => '14:00',
            'service_name' => 'Full Car Detailing',
            'location_address' => 'ul. Przykładowa 123, Warszawa',
        ],
        // ...
        default => [],
    };

    return array_merge($data, $specificData);
}
```

### Manual Test via Tinker

```bash
php artisan tinker

>>> $template = EmailTemplate::forKey('appointment-created')->forLanguage('pl')->first();
>>> $rendered = $template->render([
...     'customer_name' => 'Jan Kowalski',
...     'appointment_date' => '2025-11-15',
...     'appointment_time' => '14:00',
...     'service_name' => 'Detailing',
...     'location_address' => 'Warszawa',
... ]);
>>> echo $rendered;
```

## Fallback Blade Views

### Purpose

Jeśli template w bazie nie istnieje lub jest nieaktywny, system może użyć Blade view z `resources/views/emails/`.

### Configuration

W `EmailTemplate`:
- **blade_path:** `emails.appointment-created-pl`

System sprawdza:
1. Email template w bazie (primary)
2. Blade view w `resources/views/` (fallback)

### Example Blade View

**File:** `resources/views/emails/appointment-created-pl.blade.php`

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $subject ?? 'Potwierdzenie rezerwacji' }}</title>
</head>
<body>
    <h1>Dziękujemy, {{ $customer_name }}!</h1>
    <p>Twoja wizyta została zarezerwowana:</p>
    <ul>
        <li><strong>Data:</strong> {{ $appointment_date }}</li>
        <li><strong>Godzina:</strong> {{ $appointment_time }}</li>
        <li><strong>Usługa:</strong> {{ $service_name }}</li>
        <li><strong>Lokalizacja:</strong> {{ $location_address }}</li>
    </ul>
    <p>Pozdrawiamy,<br>{{ $app_name }}</p>
</body>
</html>
```

**Current Status:** Only 2/18 Blade views exist. Not critical - database templates work fine.

## Multi-Language Support

### Language Selection Logic

```php
// W NotificationClass
public function toMail($notifiable): MailMessage
{
    $language = $notifiable->preferred_language ?? 'pl';

    $emailService->sendFromTemplate(
        templateKey: 'appointment-created',
        language: $language,  // 'pl' lub 'en'
        recipient: $notifiable->email,
        data: [...],
    );
}
```

**User Model** ma pole:
- `preferred_language` VARCHAR(10) DEFAULT 'pl'

### Adding New Language

1. Duplicate all 9 templates with new language code:

```php
// In EmailTemplateSeeder
foreach (['pl', 'en', 'de'] as $lang) {
    EmailTemplate::create([
        'key' => 'appointment-created',
        'language' => $lang,
        'subject' => match($lang) {
            'pl' => 'Potwierdzenie rezerwacji',
            'en' => 'Appointment Confirmation',
            'de' => 'Buchungsbestätigung',
        },
        // ...
    ]);
}
```

2. Update `User` model to support new language in registration form dropdown.

## HTML Email Best Practices

### Use Tables for Layout

```html
<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td align="center">
            <h1>{{ $subject }}</h1>
        </td>
    </tr>
</table>
```

**Dlaczego?** CSS Flexbox/Grid nie działa w większości klientów email (Outlook!).

### Inline CSS

```html
<p style="color: #333; font-size: 16px; line-height: 1.5;">
    Hello {{ $customer_name }}
</p>
```

**Dlaczego?** `<style>` tag często ignorowany przez klienty email.

### Avoid JavaScript

Żaden klient email nie uruchomi JS. Używaj tylko HTML + CSS.

### Test in Multiple Clients

- Gmail (Web + Mobile App)
- Outlook (Desktop)
- Apple Mail
- Yahoo Mail

**Tool:** https://www.mail-tester.com/ (free email spam score)

## Template Variables Reference

**Uaktualniona lista zmiennych** znajduje się w `EmailTemplateResource::getExampleData()` (lines 276-323).

**Jak dodać nową zmienną:**

1. Zaktualizuj `getExampleData()` w EmailTemplateResource
2. Zaktualizuj seeder z nową zmienną w `variables` JSON
3. Użyj w template: `{{ $new_variable }}`
4. Dokumentuj w tej sekcji

## Troubleshooting

### Template Not Found

```
Exception: Email template not found: appointment-created (pl)
```

**Fix:**
```bash
# Run seeder
php artisan db:seed --class=EmailTemplateSeeder

# Or create manually in Filament
```

### Undefined Variable in Template

```
ErrorException: Undefined variable: customer_name
```

**Fix:** Upewnij się że przekazujesz zmienną w `data` array:

```php
$emailService->sendFromTemplate(
    // ...
    data: [
        'customer_name' => 'Jan Kowalski', // ✅ Add this
    ]
);
```

### Template Renders as Plain Text

**Problem:** Zmienne nie są renderowane, widoczne jako `{{ $variable }}`.

**Przyczyna:** Blade engine nie został użyty (prawdopodobnie wysłano `html_body` bezpośrednio).

**Fix:** Użyj `EmailTemplate::render()`:

```php
$html = $template->render($data); // ✅ Correct
$html = $template->html_body;     // ❌ Wrong - not rendered
```

## Next Steps

- **[Notifications](./notifications.md)** - Jak eventy triggerują wysyłkę
- **[Scheduled Jobs](./scheduled-jobs.md)** - Automated reminders i digests
- **[Filament Admin](./filament-admin.md)** - Zarządzanie przez panel admina
- **[Troubleshooting](./troubleshooting.md)** - Więcej problemów i rozwiązań
