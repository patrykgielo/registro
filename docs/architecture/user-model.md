# User Model & Authentication

**Last Updated:** November 2025
**Status:** ✅ Production Ready

## Overview

The User model (`app/Models/User.php`) stores user data with **separate first_name and last_name fields** for flexibility, but provides a **`name` accessor** for backward compatibility and Laravel conventions.

**Key Pattern** (November 2025):
```php
$user->name        // Returns "Jan Kowalski" (via accessor)
$user->full_name   // Returns "Jan Kowalski" (via getFullNameAttribute)
$user->first_name  // Returns "Jan"
$user->last_name   // Returns "Kowalski"
```

## Name Accessor Pattern

### Why This Matters

- Email notifications use `$user->name` in templates
- Blade views expect `$user->name` for display
- Third-party packages assume `$user->name` exists
- API responses often include `name` field

### Implementation

**Location:** `app/Models/User.php` (lines 73-100)

```php
/**
 * Get the user's full name via the 'name' attribute.
 *
 * @return string Full name (first_name + last_name)
 */
public function getNameAttribute(): string
{
    return $this->getFullNameAttribute();
}
```

### Usage Examples

**In Notifications:**
```php
public function toMail($notifiable)
{
    return (new MailMessage)
        ->greeting('Hello ' . $notifiable->name);  // ✅ Works via accessor
}
```

**In Blade Templates:**
```php
<p>Welcome, {{ $user->name }}!</p>  {{-- ✅ Clean and simple --}}
```

**In API Resources:**
```php
public function toArray($request)
{
    return [
        'name' => $this->name,  // ✅ Accessor handles concatenation
    ];
}
```

## Important: Don't Use Direct Database Field Access

### ❌ WRONG

```php
// Don't access first_name/last_name directly for display
$userName = $user->first_name . ' ' . $user->last_name;

// Don't expect 'name' column in database queries
User::where('name', 'Jan Kowalski')->first(); // ❌ 'name' is not a column
```

### ✅ CORRECT

```php
// Use the accessor for all display purposes
$userName = $user->name;

// For database queries, search by first_name or last_name
User::where('first_name', 'Jan')->where('last_name', 'Kowalski')->first();
// OR use full-text search if needed
```

## Troubleshooting

### Error: Property [name] does not exist on this collection instance

- **Cause:** Trying to access `$user->name` before accessor was added (October 2025)
- **Fix:** Accessor added November 2025, error should not occur anymore
- **See:** [ADR-006](../decisions/ADR-006-user-model-name-accessor.md) for full context and decision rationale

### Error: Undefined variable: customer_name in email templates

- **Cause:** Email template uses `{{ $customer_name }}` but data not passed
- **Fix:** Pass `'customer_name' => $user->name` in notification data array
- **See:** [Email Templates](../features/email-system/templates.md) for variable reference

## Related Documentation

- **Architecture Decision:** [ADR-006: User Model Name Accessor Pattern](../decisions/ADR-006-user-model-name-accessor.md)
- **Email System:** [Email Templates](../features/email-system/templates.md)
- **Troubleshooting:** [Email System Troubleshooting](../features/email-system/troubleshooting.md)

## See Also

- [Database Schema](./database-schema.md) - Full database structure including users table
- [Email System](../features/email-system/README.md) - How notifications use user data
