# Filament Admin Panel - Email Management

4 Filament resources dla zarządzania systemem emailowym.

## Navigation Group: "Email"

### 1. Email Templates
- **Icon:** heroicon-o-envelope
- **Permission:** `manage email templates`
- **URL:** `/admin/email-templates`

**Features:**
- Full CRUD for templates
- ~~Preview with sample data~~ *(Temporarily disabled - use Test Send instead)*
- **Test Send** action (wyślij testowego maila)
- Filters: language, key, active status
- Form fields: key (select), language (select), subject, html_body, text_body, variables (tags), blade_path, active (toggle)

**Test Send Action** (lines 194-241):

```php
Tables\Actions\Action::make('testSend')
    ->label('Test Send')
    ->icon('heroicon-o-paper-airplane')
    ->form([
        Forms\Components\TextInput::make('email')
            ->email()
            ->required()
    ])
    ->action(function (EmailTemplate $record, array $data): void {
        $emailService = app(EmailService::class);
        
        $result = $emailService->sendFromTemplate(
            templateKey: $record->key,
            language: $record->language,
            recipient: $data['email'],
            data: self::getExampleData($record),
            metadata: []
        );
        
        if ($result) {
            Notification::make()->success()->title('Test email sent!')->send();
        }
    })
```

### 2. Email Sends (Logs)
- **Icon:** heroicon-o-paper-airplane
- **Permission:** `view email logs`
- **URL:** `/admin/email-sends`

**Features:**
- Read-only logs
- **View** action (full HTML preview in iframe)
- **Resend** action (retry failed emails)
- **Export CSV** bulk action
- Filters: status, template_key, date range
- Status badges: `sent` (green), `failed` (red), `bounced` (yellow), `pending` (gray)

**Table Columns:**
- template_key (badge)
- recipient_email (searchable)
- subject (truncated)
- status (badge with icon)
- sent_at (datetime)

### 3. Email Events
- **Icon:** heroicon-o-chart-bar
- **Permission:** `view email events`
- **URL:** `/admin/email-events`

**Features:**
- Delivery event timeline (sent, delivered, bounced, complained, opened, clicked)
- **View Email** action (redirect to EmailSendResource)
- **Add to Suppression** action (only for bounced/complained)
- Filters: event_type, date range

**Event Types:**
- `sent` - Email wysłany
- `delivered` - Dostarczono
- `bounced` - Odbił się
- `complained` - Spam complaint
- `opened` - Otwarty (not tracked yet)
- `clicked` - Clicked link (not tracked yet)

### 4. Email Suppressions
- **Icon:** heroicon-o-no-symbol
- **Permission:** `manage suppressions`
- **URL:** `/admin/email-suppressions`

**Features:**
- Full CRUD for suppression list
- **Delete** action (re-enable sending)
- **Bulk Unsuppress** action
- Filters: reason (bounced, complained, unsubscribed, manual)
- Table: email, reason (badge), notes, suppressed_at

**Reasons:**
- `bounced` - Hard bounce (mailbox doesn't exist)
- `complained` - Marked as spam
- `unsubscribed` - User unsubscribed
- `manual` - Admin blocked

## Access Control (Permissions)

Defined in `RolePermissionSeeder`:

```php
'manage email templates' → super-admin, admin
'view email logs'        → super-admin, admin, staff
'view email events'      → super-admin, admin, staff
'manage suppressions'    → super-admin, admin
```

**Apply in Resource:**

```php
public static function canViewAny(): bool
{
    return auth()->user()?->can('manage email templates') ?? false;
}
```

## Common Admin Tasks

### Send Test Email

1. Open Email Templates: `/admin/email-templates`
2. Click "Test Send" on any template
3. Enter email: `patryk3580@gmail.com`
4. Check inbox (and spam folder)

### View Sent Emails

1. Open Email Sends: `/admin/email-sends`
2. Filter by status/template/date
3. Click "View" to see rendered HTML
4. Check `error_message` for failures

### Handle Bounces

1. Open Email Events: `/admin/email-events`
2. Filter by `event_type = bounced`
3. Click "Add to Suppression" → email blocked
4. Alternatively: Open Email Suppressions → manually add

### Export Email Logs

1. Open Email Sends
2. Filter desired range
3. Select rows → **Export CSV** bulk action
4. Download CSV with all data

## Customization

### Change Navigation Order

In Resource:

```php
protected static ?int $navigationSort = 1; // Lower = higher in menu
```

### Add Custom Action

```php
Tables\Actions\Action::make('customAction')
    ->label('My Action')
    ->icon('heroicon-o-star')
    ->action(fn (EmailTemplate $record) => /* logic */);
```

### Add Filter

```php
Tables\Filters\SelectFilter::make('custom_field')
    ->options([...]);
```

## Next Steps

- [Templates](./templates.md) - Manage templates from admin panel
- [Troubleshooting](./troubleshooting.md) - Admin panel issues
