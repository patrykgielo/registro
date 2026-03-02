# SMS Templates

## Overview

SMS templates are pre-defined message structures with variable placeholders. Templates support:
- **Multi-language** (PL, EN)
- **Blade rendering** (`{{variable}}` syntax)
- **Character limits** (160 GSM, 70 Unicode)
- **Admin management** via Filament

## Template Structure

### Database Schema

```sql
sms_templates (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    key             VARCHAR(255) NOT NULL,          -- e.g., 'appointment-reminder-24h'
    language        VARCHAR(10) NOT NULL,           -- 'pl' or 'en'
    message_body    TEXT NOT NULL,                  -- SMS message with {{placeholders}}
    variables       JSON NOT NULL,                  -- ['customer_name', 'appointment_date', ...]
    max_length      INT NOT NULL DEFAULT 160,       -- 160 for GSM, 70 for Unicode
    active          BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP,

    UNIQUE KEY unique_template (key, language)      -- One template per key+language
)
```

### Model

**Location:** `app/Models/SmsTemplate.php`

**Key Methods:**
```php
$template = SmsTemplate::forKey('appointment-reminder-24h')
    ->forLanguage('pl')
    ->active()
    ->firstOrFail();

// Render template with data
$rendered = $template->render([
    'customer_name' => 'Jan Kowalski',
    'appointment_date' => '2025-11-15',
    'appointment_time' => '14:00',
]);

// Check if message exceeds limit
if ($template->exceedsMaxLength($rendered)) {
    $rendered = $template->truncateMessage($rendered);
}
```

---

## Template Types

### 1. appointment-created
**Purpose:** Booking confirmation after customer creates appointment

**Variables:**
- `customer_name` - Customer's first name
- `service_name` - Name of booked service
- `appointment_date` - Date in YYYY-MM-DD format
- `appointment_time` - Time in HH:MM format
- `app_name` - Application name (e.g., "Registro")

**Polish Template:**
```
Witaj {{customer_name}}! Rezerwacja na {{service_name}} dnia {{appointment_date}} o {{appointment_time}} utworzona. {{app_name}}
```

**English Template:**
```
Hi {{customer_name}}! Your {{service_name}} booking on {{appointment_date}} at {{appointment_time}} is confirmed. {{app_name}}
```

**Typical Length:** ~120-130 characters

---

### 2. appointment-confirmed
**Purpose:** Admin manually confirms pending appointment

**Variables:**
- `customer_name` - Customer's first name
- `service_name` - Name of booked service
- `appointment_date` - Date in YYYY-MM-DD format
- `appointment_time` - Time in HH:MM format
- `app_name` - Application name

**Polish Template:**
```
Witaj {{customer_name}}! Twoja wizyta ({{service_name}}) {{appointment_date}} o {{appointment_time}} potwierdzona. Do zobaczenia! {{app_name}}
```

**English Template:**
```
Hi {{customer_name}}! Your appointment ({{service_name}}) on {{appointment_date}} at {{appointment_time}} is confirmed. See you! {{app_name}}
```

**Typical Length:** ~115-125 characters

---

### 3. appointment-rescheduled
**Purpose:** Appointment date/time changed

**Variables:**
- `customer_name` - Customer's first name
- `service_name` - Name of booked service
- `appointment_date` - NEW date in YYYY-MM-DD format
- `appointment_time` - NEW time in HH:MM format
- `app_name` - Application name

**Polish Template:**
```
Witaj {{customer_name}}! Twoja wizyta ({{service_name}}) przeniesiona na {{appointment_date}} o {{appointment_time}}. {{app_name}}
```

**English Template:**
```
Hi {{customer_name}}! Your appointment ({{service_name}}) has been rescheduled to {{appointment_date}} at {{appointment_time}}. {{app_name}}
```

**Typical Length:** ~110-120 characters

---

### 4. appointment-cancelled
**Purpose:** Appointment cancellation notice

**Variables:**
- `customer_name` - Customer's first name
- `service_name` - Name of booked service
- `appointment_date` - Date in YYYY-MM-DD format
- `appointment_time` - Time in HH:MM format
- `contact_phone` - Support phone number
- `app_name` - Application name

**Polish Template:**
```
Witaj {{customer_name}}! Twoja wizyta ({{service_name}}) {{appointment_date}} o {{appointment_time}} anulowana. Kontakt: {{contact_phone}}. {{app_name}}
```

**English Template:**
```
Hi {{customer_name}}! Your appointment ({{service_name}}) on {{appointment_date}} at {{appointment_time}} has been cancelled. Contact: {{contact_phone}}. {{app_name}}
```

**Typical Length:** ~135-145 characters

---

### 5. appointment-reminder-24h
**Purpose:** Reminder 24 hours before appointment

**Variables:**
- `service_name` - Name of booked service
- `appointment_date` - Date in YYYY-MM-DD format
- `appointment_time` - Time in HH:MM format
- `location_address` - Full address of service location
- `app_name` - Application name

**Polish Template:**
```
Przypomnienie! Jutro masz wizytę: {{service_name}}, {{appointment_date}} o {{appointment_time}}. Lokalizacja: {{location_address}}. {{app_name}}
```

**English Template:**
```
Reminder! Your appointment tomorrow: {{service_name}}, {{appointment_date}} at {{appointment_time}}. Location: {{location_address}}. {{app_name}}
```

**Typical Length:** ~135-145 characters

**Note:** No `customer_name` (shorter message for better delivery)

---

### 6. appointment-reminder-2h
**Purpose:** Reminder 2 hours before appointment

**Variables:**
- `service_name` - Name of booked service
- `appointment_time` - Time in HH:MM format
- `location_address` - Full address of service location
- `app_name` - Application name

**Polish Template:**
```
Przypomnienie! Za 2h wizyta: {{service_name}} o {{appointment_time}}. Lokalizacja: {{location_address}}. Do zobaczenia! {{app_name}}
```

**English Template:**
```
Reminder! In 2 hours: {{service_name}} at {{appointment_time}}. Location: {{location_address}}. See you soon! {{app_name}}
```

**Typical Length:** ~115-125 characters

**Note:** No `appointment_date` (it's today), no `customer_name` (shorter)

---

### 7. appointment-followup
**Purpose:** Post-service feedback request

**Variables:**
- `customer_name` - Customer's first name
- `service_name` - Name of completed service
- `app_name` - Application name
- `contact_phone` - Support phone number

**Polish Template:**
```
Witaj {{customer_name}}! Dziękujemy za skorzystanie z {{service_name}}. Bylibyśmy wdzięczni za opinię. {{app_name}} {{contact_phone}}
```

**English Template:**
```
Hi {{customer_name}}! Thank you for using {{service_name}}. We would appreciate your feedback. {{app_name}} {{contact_phone}}
```

**Typical Length:** ~125-135 characters

---

## Character Limits

### GSM-7 Encoding (160 characters)
**Allowed characters:**
- Latin alphabet (A-Z, a-z)
- Digits (0-9)
- Basic punctuation: `@ £ $ ¥ è é ù ì ò Ç Ø ø Å å Δ _ Φ Γ Λ Ω Π Ψ Σ Θ Ξ Æ æ ß É ! " # ¤ % & ' ( ) * + , - . / : ; < = > ? ¡ Ä Ö Ñ Ü § ¿ ä ö ñ ü à`

**SMS Parts:**
- 1 part: 1-160 characters
- 2 parts: 161-306 characters (2× cost)
- 3 parts: 307-459 characters (3× cost)

### Unicode Encoding (70 characters)
**Required for:**
- Polish diacritics: `ą ć ę ł ń ó ś ź ż Ą Ć Ę Ł Ń Ó Ś Ź Ż`
- Emojis, Chinese, Arabic, etc.

**SMS Parts:**
- 1 part: 1-70 characters
- 2 parts: 71-134 characters (2× cost)
- 3 parts: 135-201 characters (3× cost)

### Best Practices
1. **Keep under 160 characters** if possible (1 SMS part)
2. **For Polish templates:** Aim for <70 characters (due to Unicode)
3. **Remove diacritics** if message is too long (e.g., "a" instead of "ą")
4. **Use abbreviations:** "ul." instead of "ulica", "tel." instead of "telefon"
5. **Test message length** before deploying

---

## Seeding Templates

### SmsTemplateSeeder
**Location:** `database/seeders/SmsTemplateSeeder.php`

**Run seeder:**
```bash
php artisan db:seed --class=SmsTemplateSeeder
```

**What it creates:**
- 14 templates total
- 7 template types × 2 languages (PL, EN)
- All templates active by default
- Max length: 160 characters

**Idempotent:** Re-running seeder updates existing templates (uses `updateOrCreate`)

### Seeder Structure
```php
$templates = [
    [
        'key' => 'appointment-created',
        'language' => 'pl',
        'message_body' => 'Witaj {{customer_name}}! ...',
        'variables' => ['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'app_name'],
        'max_length' => 160,
        'active' => true,
    ],
    // ... 13 more templates
];

foreach ($templates as $template) {
    SmsTemplate::updateOrCreate(
        ['key' => $template['key'], 'language' => $template['language']],
        $template
    );
}
```

---

## Managing Templates in Filament

### SmsTemplateResource
**Location:** `app/Filament/Resources/SmsTemplateResource.php`
**URL:** https://registro.local:8444/admin/sms-templates

**Features:**
- **List View:** Key, Language, Message Preview (truncated), Max Length, Active status
- **Filters:** Active/Inactive, Language (PL/EN), Template Type
- **Actions:**
  - **View** - Read-only view of template
  - **Test Send** - Send test SMS from template
  - **Edit** - Modify template (⚠️ be careful with variables!)
  - Bulk Activate/Deactivate

### Test Send Action
1. Click **"Test Send"** on any template
2. Enter phone number (E.164 format: `+48501234567`)
3. Template will use example data:
   - `customer_name` → "Jan Kowalski"
   - `service_name` → "Detailing zewnętrzny"
   - `appointment_date` → Tomorrow's date
   - `appointment_time` → "14:00"
   - `location_address` → "ul. Marszałkowska 1, Warszawa"
   - `app_name` → Config value (`app.name`)
   - `contact_phone` → Settings value (`contact.phone`)
4. SMS sent to provided number
5. Check **SMS Sends** resource for delivery status

---

## Variable Reference

### Common Variables

| Variable              | Type   | Format              | Example                         |
|-----------------------|--------|---------------------|---------------------------------|
| `customer_name`       | string | First name          | "Jan"                           |
| `service_name`        | string | Service name        | "Detailing zewnętrzny"          |
| `appointment_date`    | string | YYYY-MM-DD          | "2025-11-15"                    |
| `appointment_time`    | string | HH:MM               | "14:00"                         |
| `location_address`    | string | Full address        | "ul. Marszałkowska 1, Warszawa" |
| `app_name`            | string | Application name    | "Registro"                     |
| `contact_phone`       | string | E.164 format        | "+48123456789"                  |

### Variable Sources

**From Appointment:**
- `service_name` → `$appointment->service->name`
- `appointment_date` → `$appointment->appointment_date->format('Y-m-d')`
- `appointment_time` → `$appointment->appointment_time->format('H:i')`
- `location_address` → `$appointment->location_address`

**From User:**
- `customer_name` → `$appointment->user->first_name`

**From Settings:**
- `app_name` → `config('app.name')`
- `contact_phone` → `app(SettingsManager::class)->get('contact.phone')`

---

## Rendering Templates

### Blade Rendering

Templates use Blade syntax (`{{variable}}`):

```php
// Template:
"Witaj {{customer_name}}! Wizyta {{appointment_date}} o {{appointment_time}}."

// Data:
$data = [
    'customer_name' => 'Jan',
    'appointment_date' => '2025-11-15',
    'appointment_time' => '14:00',
];

// Rendered:
"Witaj Jan! Wizyta 2025-11-15 o 14:00."
```

### Fallback Rendering

If Blade rendering fails (e.g., syntax error), fallback to simple string replacement:

```php
$content = $template->message_body;
foreach ($data as $key => $value) {
    $content = str_replace('{{' . $key . '}}', (string) $value, $content);
}
```

---

## Best Practices

### Writing SMS Templates

1. **Be Concise:** SMS is limited to 160 characters
2. **Start with Greeting:** "Witaj {{customer_name}}!" (Polish) or "Hi {{customer_name}}!" (English)
3. **Include Key Info:** Service name, date, time, location
4. **End with App Name:** "{{app_name}}" for branding
5. **Add Contact Info:** "{{contact_phone}}" for support

### Variable Naming

1. **Use snake_case:** `customer_name`, not `customerName`
2. **Be Descriptive:** `appointment_date`, not `date`
3. **Consistent Naming:** Same variables across all templates

### Testing Templates

1. **Test with Real Data:** Use actual customer names, dates, addresses
2. **Test with Long Values:** Ensure message doesn't exceed 160 chars
3. **Test with Polish Characters:** Check Unicode encoding (ą, ć, ę, ...)
4. **Test on Real Phone:** Send test SMS to your phone

### Updating Templates

⚠️ **Warning:** Changing templates affects all future SMS sends.

**Steps:**
1. Test new template thoroughly (use "Test Send" action)
2. Check message length (should be <160 chars)
3. Verify all variables are included
4. Update template in Filament
5. Send test SMS to confirm changes

---

## Troubleshooting

### Template Not Found

**Error:** "SMS template 'appointment-reminder-24h' not found for language 'pl'"

**Cause:** Template missing in database

**Solution:**
```bash
php artisan db:seed --class=SmsTemplateSeeder
```

### Message Too Long

**Error:** "SMS message exceeds max length (160 characters)"

**Cause:** Rendered message > 160 characters

**Solution:**
- Shorten template text
- Use abbreviations ("ul." instead of "ulica")
- Remove Polish diacritics (if acceptable)
- Split into multi-part SMS (2× cost)

### Variable Not Rendering

**Symptom:** `{{customer_name}}` appears in SMS instead of actual name

**Cause:** Variable not provided in `$data` array

**Solution:**
```php
// Ensure all variables are provided:
$data = [
    'customer_name' => $appointment->user->first_name,
    'service_name' => $appointment->service->name,
    // ... all required variables
];
```

---

## Related Documentation

- **[Architecture](./architecture.md)** - Services, Models, Flow
- **[SMSAPI Integration](./smsapi-integration.md)** - Configuration, webhook
- **[README](./README.md)** - Overview and quick start

---

**Last Updated:** November 2025
